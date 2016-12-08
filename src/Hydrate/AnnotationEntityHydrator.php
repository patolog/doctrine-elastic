<?php

namespace DoctrineElastic\Hydrate;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use DoctrineElastic\Mapping\Field;
use DoctrineElastic\Mapping\MetaField;

class AnnotationEntityHydrator extends SimpleEntityHydrator {

    /** @var AnnotationReader */
    protected $annotationReader;

    public function __construct() {
        parent::__construct();
        $this->annotationReader = new AnnotationReader();
    }

    /**
     * @param $entity
     * @param null $specAnnotationClass
     * @return array
     */
    public function extractWithAnnotation($entity, $specAnnotationClass = Field::class) {
        $properties = $this->reflectionPropertiesGetter->getProperties(get_class($entity));
        $values = $this->extract($entity);
        $data = [];

        foreach ($properties as $prop) {
            /** @var Annotation $specAnnotation */
            $specAnnotation = $this->annotationReader->getPropertyAnnotation(
                $prop, $specAnnotationClass
            );

            $name = self::decamelizeString($prop->name);

            if (!is_null($specAnnotation) && in_array($name, array_keys($values))) {
                $data[$name] = $values[$name];
            }
        }

        return $data;
    }

    /**
     * @param $entity
     * @param string $specAnnotationClass
     * @return array
     */
    public function extractSpecAnnotations($entity, $specAnnotationClass) {
        $properties = $this->reflectionPropertiesGetter->getProperties(get_class($entity));
        $annotations = [];

        foreach ($properties as $prop) {
            /** @var Annotation $specAnnotation */
            $specAnnotation = $this->annotationReader->getPropertyAnnotation(
                $prop, $specAnnotationClass
            );

            if ($specAnnotation) {
                $annotations[$prop->name] = $specAnnotation;
            }
        }

        return $annotations;
    }
}