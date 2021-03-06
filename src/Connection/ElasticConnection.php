<?php

namespace DoctrineElastic\Connection;

use Elasticsearch\Client;

/**
 * Default elastic connection class for general operations
 *
 * @author Ands
 */
class ElasticConnection implements ElasticConnectionInterface {

    /** Override default elastic limit size query */
    const DEFAULT_MAX_RESULTS = 10000;

    /** @var Client */
    protected $elastic;

    public function __construct(Client $elastic) {
        $this->elastic = $elastic;
    }

    public function createIndex(
        $index, array $mappings = null, array $settings = null, array $aliases = null, array &$return = null
    ) {
        if ($this->indexExists($index)) {
            throw new \InvalidArgumentException(sprintf("'%s' index already exists", $index));
        }

        $params = array(
            'index' => $index,
            'update_all_types' => true,
            'body' => []
        );

        if (boolval($mappings)) {
            foreach ($mappings as $typeName => $mapping) {
                $properties = $mapping['properties'];

                foreach ($properties as $fieldName => $fieldMap) {
                    if (isset($fieldMap['type']) && in_array($fieldMap['type'], ['string', 'text', 'keyword'])) {
                        continue;
                    }

                    if (isset($mappings[$typeName]['properties'][$fieldName]['index'])) {
                        unset($mappings[$typeName]['properties'][$fieldName]['index']);
                    }

                    if (isset($mappings[$typeName]['properties'][$fieldName]['boost'])) {
                        unset($mappings[$typeName]['properties'][$fieldName]['boost']);
                    }
                }
            }
            $params['body']['mappings'] = $mappings;
        }

        if (boolval($settings)) {
            $params['body']['settings'] = $settings;
        }

        $return = $this->elastic->indices()->create($params);

        if (isset($return['acknowledged'])) {
            return $return['acknowledged'];
        }

        return false;
    }

    public function deleteIndex($index, array &$return = null) {
        if (!$this->indexExists($index)) {
            throw new \InvalidArgumentException(sprintf("'%s' index does not exists", $index));
        }

        if (is_string($index) && !strstr('_all', $index) && !strstr('*', $index)) {
            $return = $this->elastic->indices()->delete(['index' => $index]);

            if (isset($return['acknowledged'])) {
                return $return['acknowledged'];
            }
        }

        return false;
    }

    public function createType($index, $type, array $mappings = [], array &$return = null) {
        if (!$this->indexExists($index)) {
            throw new \InvalidArgumentException(sprintf("%s' index does not exists", $index));
        }

        if ($this->typeExists($index, $type)) {
            throw new \InvalidArgumentException(sprintf("Type 's%' already exists on index %s", $type, $index));
        }

        $return = $this->elastic->indices()->putMapping(array(
            'index' => $index,
            'type' => $type,
            'update_all_types' => true,
            'body' => $mappings
        ));

        if (isset($return['acknowledged'])) {
            return $return['acknowledged'];
        }

        return false;
    }

    public function insert($index, $type, array $body, array $mergeParams = [], array &$return = null) {
        if (!$this->indexExists($index)) {
            trigger_error("$index index does not exists at insert attempt");
            return false;
        }

        if (!$this->typeExists($index, $type)) {
            trigger_error("$type type does not exists at insert attempt");
            return false;
        }

        $defaultParams = array(
            'index' => $index,
            'type' => $type,
            'op_type' => 'create',
            'timestamp' => time(),
            'refresh' => "true",
            'body' => $body
        );

        $params = array_merge_recursive($defaultParams, $mergeParams);

        $return = $this->elastic->create($params);

        if (isset($return['created'])) {
            return $return['created'];
        }

        return false;
    }

    public function update($index, $type, $_id, array $body = [], array $mergeParams = [], array &$return = null) {
        if (!$this->indexExists($index)) {
            return false;
        }

        $defaultParams = array(
            'id' => $_id,
            'index' => $index,
            'type' => $type,
            'refresh' => "true",
            'body' => array(
                'doc' => $body
            )
        );

        $params = array_merge_recursive($defaultParams, $mergeParams);

        $return = $this->elastic->update($params);

        if (isset($return['_id'])) {
            return true;
        }

        return false;
    }

    public function delete($index, $type, $_id, array $mergeParams = [], array &$return = null) {
        if (!$this->indexExists($index)) {
            return false;
        }

        $defaultParams = array(
            'id' => $_id,
            'index' => $index,
            'type' => $type,
            'refresh' => "true"
        );

        $params = array_merge_recursive($defaultParams, $mergeParams);

        $return = $this->elastic->delete($params);

        if (isset($return['found']) && isset($return['_shards']['successful'])) {
            return boolval($return['_shards']['successful']);
        }

        return false;
    }

    public function updateWhere($index, $type, array $where, array &$return = null) {
        // TODO: Implement updateWhere() method.
    }

    public function deleteWhere($index, $type, array $where, array &$return = null) {
        // TODO: Implement deleteWhere() method.
    }

    public function get($index, $type, $_id, array $mergeParams = [], array &$return = null) {
        if (!$this->indexExists($index)) {
            return null;
        }

        $defaultParams = array(
            'id' => $_id,
            'index' => $index,
            'type' => $type,
            'refresh' => "true",
            '_source' => true,
            '_source_exclude' => []
        );

        $params = array_merge_recursive($defaultParams, $mergeParams);
        $existsParams = array_filter($params, function ($key) {
            return in_array($key, ['id', 'index', 'type', 'refresh']);
        }, ARRAY_FILTER_USE_KEY);

        if ($this->elastic->exists($existsParams)) {
            $return = $this->elastic->get($params);

            if (isset($return['found']) && $return['found']) {
                return $return;
            }
        }

        return null;
    }

    public function search($index, $type, array $body = [], array $mergeParams = []) {
        if (!$this->indexExists($index)) {
            return [];
        }

        $defaultParams = array(
            'index' => $index,
            'type' => $type,
            '_source' => true,
            'query_cache' => false,
            'request_cache' => false,
            'default_operator' => 'AND',
            'size' => self::DEFAULT_MAX_RESULTS,
            'body' => $body
        );

        $params = array_merge_recursive($defaultParams, $mergeParams);

        $docment = $this->elastic->search($params);

        if (isset($docment['found']) && $docment['found']) {
            return $docment;
        }

        return [];
    }

    public function indexExists($index) {
        return $this->elastic->indices()->exists(['index' => $index]);
    }

    public function typeExists($index, $type) {
        return $this->elastic->indices()->existsType(array(
            'index' => $index,
            'type' => $type,
            'ignore_unavailable' => true
        ));
    }

    /**
     * @return Client
     */
    public function getElasticClient() {
        return $this->elastic;
    }
}
