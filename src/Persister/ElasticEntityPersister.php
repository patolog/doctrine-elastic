<?php

namespace DoctrineElastic\Persister;

use Doctrine\Common\Annotations\AnnotationException;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use DoctrineElastic\Elastic\DoctrineElasticEvents;
use DoctrineElastic\Elastic\SearchParams;
use DoctrineElastic\ElasticEntityManager;
use DoctrineElastic\Event\EntityEventArgs;
use DoctrineElastic\Exception\ElasticConstraintException;
use DoctrineElastic\Exception\ElasticOperationException;
use DoctrineElastic\Exception\InvalidParamsException;
use DoctrineElastic\Hydrate\AnnotationEntityHydrator;
use DoctrineElastic\Mapping\Field;
use DoctrineElastic\Mapping\MetaField;
use DoctrineElastic\Mapping\Type;
use DoctrineElastic\Service\ElasticSearchService;
use Elasticsearch\Client;

/**
 * Entity Persister for this doctrine elastic extension
 * This class implements some crud operations
 *
 * @author Ands
 */
class ElasticEntityPersister extends AbstractEntityPersister {

    /** @var array */
    protected $queuedInserts = [];
    /** @var AnnotationReader */
    private $annotationReader;
    /** @var ElasticSearchService */
    private $elasticSearchService;
    /** @var AnnotationEntityHydrator */
    private $hydrator;

    public function __construct(ElasticEntityManager $em, ClassMetadata $class, Client $elastic) {
        parent::__construct($em, $class, $elastic);
        $this->annotationReader = new AnnotationReader();
        $this->elasticSearchService = new ElasticSearchService($em->getConnection());
        $this->hydrator = new AnnotationEntityHydrator();
        $this->validateEntity($class->name);
    }

    private function validateEntity($className) {
        $type = $this->annotationReader->getClassAnnotation($this->class->getReflectionClass(), Type::class);

        if (!($type instanceof Type)) {
            throw new AnnotationException(sprintf('%s annotation is missing for %s entity class',
                Type::class, get_class()));
        }

        if (!$type->isValid()) {
            $errorMessage = $type->getErrorMessage() . ' for %s entity class';
            throw new AnnotationException(sprintf($errorMessage, $className));
        }

        $_idSearch = $this->hydrator->extractWithAnnotation(new $className(), MetaField::class);
        $has_id = !empty($_idSearch);

        if (!$has_id) {
            $errorMessage = '_id metaField is missing in %s entity class';
            throw new AnnotationException(sprintf($errorMessage, $className));
        }
    }

    public function getAnnotionReader() {
        return $this->annotationReader;
    }

    public function executeInserts() {
        foreach ($this->queuedInserts as $entity) {
            $type = $this->getEntityType();
            $entityCopy = clone $entity;

            $this->em->getEventManager()->dispatchEvent(
                DoctrineElasticEvents::beforeInsert, new EntityEventArgs($entityCopy)
            );

            $fieldsData = $this->hydrator->extractWithAnnotation($entityCopy, Field::class);
            $metaFieldsData = $this->hydrator->extractWithAnnotation($entityCopy, MetaField::class);
            $mergeParams = [];

            if (array_key_exists('_id', $metaFieldsData) && !empty($metaFieldsData['_id'])) {
                $mergeParams['id'] = $metaFieldsData['_id'];
            }

            $this->createTypeIfNotExists();

            $this->checkIndentityConstraints($entityCopy);
            $return = [];

            $inserted = $this->em->getConnection()->insert(
                $type->getIndex(), $type->getName(), $fieldsData, $mergeParams, $return
            );

            if ($inserted) {
                $this->hydrateEntityByResult($entity, $return);
                $this->em->getEventManager()->dispatchEvent(
                    DoctrineElasticEvents::postInsert, new EntityEventArgs($entity)
                );
            } else {
                throw new ElasticOperationException(sprintf('Unable to complete update operation, '
                    . 'with the following elastic return: <br><pre>%s</pre>', var_export($return)));
            }
        }
    }

    private function createTypeIfNotExists() {
        $type = $this->getEntityType();
        $indexName = $type->getIndex();
        $typeName = $type->getName();
        $className = $this->class->reflClass->name;

        if (!$this->em->getConnection()->typeExists($indexName, $typeName)) {
            $propertiesMapping = [];
            /** @var Field[] $ESFields */
            $ESFields = $this->hydrator->extractSpecAnnotations($className, Field::class);

            foreach ($ESFields as $ESField) {
                if ($ESField instanceof Field) {
                    $propertiesMapping[$ESField->name] = ['type' => $ESField->type];

                    foreach ($ESField->getArrayCopy() as $prop => $propValue) {
                        if ($ESField->type == 'nested' && ($prop == 'boost' || $prop == 'index')) {
                            continue;
                        }
                        if (!is_null($propValue) && $prop != 'name') {
                            $propertiesMapping[$ESField->name][$prop] = $propValue;
                        }
                    }
                }
            }

            $mappings = array(
                $typeName => array(
                    'properties' => $propertiesMapping
                )
            );

            if (!$this->em->getConnection()->indexExists($indexName)) {
                $this->em->getConnection()->createIndex($indexName, $mappings);
            } else {
                $this->em->getConnection()->createType($indexName, $typeName, $mappings);
            }
        }
    }

    /**
     * Check Identity values for entity, if there are identity fields,
     * check if already exists items with such value (unique constraint verification)
     *
     * @param object $entity
     * @throws ElasticConstraintException
     */
    private function checkIndentityConstraints($entity) {
        $identities = $this->getClassMetadata()->getIdentifierValues($entity);

        foreach ($identities as $property => $value) {
            $element = $this->load([$property => $value]);

            if (boolval($element)) {
                throw new ElasticConstraintException(sprintf("Unique/IDENTITY field %s already has "
                    . "a document with value '%s'", $property, $value));
            }
        }
    }

    public function load(
        array $criteria, $entity = null, $assoc = null, array $hints = [],
        $lockMode = null, $limit = null, array $orderBy = null
    ) {
        $results = $this->loadAll($criteria, $orderBy, $limit);

        return count($results) ? $results[0] : null;
    }

    public function loadAll(array $criteria = [], array $orderBy = null, $limit = null, $offset = null) {
        $classMetadata = $this->getClassMetadata();
        $className = $classMetadata->getName();
        $type = $this->getEntityType();

        $sort = $must = [];
        $body = ['query' => ['bool' => ['must' => $must]]];
        /** @var Field $annotationProperty */
        $fieldAnnotations = $this->hydrator->extractSpecAnnotations($className, Field::class);
        /** @var MetaField[] $metaFieldAnnotations */
        $metaFieldAnnotations = $this->hydrator->extractSpecAnnotations($className, MetaField::class);

        foreach ($criteria as $columnName => $value) {
            $annotation = null;
            if (isset($fieldAnnotations[$columnName])) {
                $annotation = $fieldAnnotations[$columnName];
            } else if (isset($metaFieldAnnotations[$columnName])) {
                $annotation = $metaFieldAnnotations[$columnName];
            }

            if (is_null($annotation)) {
                $msg = sprintf("field/metafield for column '%s' doesn't exist in %s entity class",
                    $columnName, $classMetadata->getName());
                throw new InvalidParamsException($msg);
            }

            $must[] = array(
                'match' => array(
                    $annotation->name => $criteria[$columnName],
                )
            );
        }

        if (is_array($orderBy)) {
            foreach ($orderBy as $columnName => $order) {
                if (isset($fieldAnnotations[$columnName])) {
                    $sort[$fieldAnnotations[$columnName]->name] = $order;
                } else if (isset($metaFieldAnnotations[$columnName])) {
                    $sort[$metaFieldAnnotations[$columnName]->name] = $order;
                }
            }
        }

        $body['query']['bool']['must'] = $must;

        $searchParams = new SearchParams();
        $searchParams->setIndex($type->getIndex());
        $searchParams->setType($type->getName());
        $searchParams->setBody($body);
        $searchParams->setSize($limit);
        $searchParams->setSort($sort);
        $searchParams->setFrom($offset);

        $arrayResults = $this->elasticSearchService->searchAsIterator($searchParams)->getArrayCopy();
        $results = [];
        $entityClass = $classMetadata->name;

        foreach ($arrayResults as $arrayResult) {
            $results[] = $this->hydrateEntityByResult(new $entityClass(), $arrayResult);
        }

        return $results;
    }

    /**
     * @return Type
     * @throws MappingException
     */
    private function getEntityType() {
        $type = $this->annotationReader->getClassAnnotation(
            $this->getClassMetadata()->getReflectionClass(), Type::class
        );

        if ($type instanceof Type) {
            return $type;
        } else {
            throw new MappingException(sprintf('Unable to get Type Mapping of %s entity', $this->class->name));
        }
    }

    private function hydrateEntityByResult($entity, array $searchResult) {
        if (isset($searchResult['_source'])) {
            $searchResult = array_merge($searchResult, $searchResult['_source']);
        }

        $this->hydrator->hydrate($entity, $searchResult);
        $this->hydrator->hydrateByAnnotation($entity, Field::class, $searchResult);
        $this->hydrator->hydrateByAnnotation($entity, MetaField::class, $searchResult);

        return $entity;
    }

    public function update($entity) {
        $type = $this->getEntityType();
        $dataUpdate = $this->hydrator->extractWithAnnotation($entity, Field::class);
        $_id = $this->hydrator->extract($entity, '_id');
        $return = [];

        $updated = $this->em->getConnection()->update(
            $type->getIndex(), $type->getName(), $_id, $dataUpdate, [], $return
        );

        if ($updated) {
            $this->hydrateEntityByResult($entity, $return);
        } else {
            throw new ElasticOperationException(sprintf('Unable to complete update operation, '
                . 'with the following elastic return: <br><pre>%s</pre>', var_export($return)));
        }
    }

    public function addInsert($entity) {
        $oid = spl_object_hash($entity);
        $this->queuedInserts[$oid] = $entity;
    }

    public function loadById(array $_idArray, $entity = null) {
        $type = $this->getEntityType();

        if (is_object($entity) && get_class($entity) != $this->class->name) {
            throw new \InvalidArgumentException('You can only get an element by _id with its properly persister');
        }

        $id = isset($_idArray['_id']) ? $_idArray['_id'] : reset($_idArray);

        $documentData = $this->em->getConnection()->get($type->getIndex(), $type->getName(), $id);

        if ($documentData) {
            $entity = is_object($entity) ? $entity : new $this->class->name;
            $this->hydrateEntityByResult($entity, $documentData);

            return $entity;
        }

        return null;
    }

    public function delete($entity) {
        $type = $this->getEntityType();
        $return = [];
        $_id = $this->hydrator->extract($entity, '_id');

        $deletion = $this->em->getConnection()->delete(
            $type->getIndex(), $type->getName(), $_id, [], $return
        );

        if ($deletion) {
            return true;
        } else {
            throw new ElasticOperationException(sprintf('Unable to complete update operation, '
                . 'with the following elastic return: <br><pre>%s</pre>', var_export($return)));
        }
    }
}
