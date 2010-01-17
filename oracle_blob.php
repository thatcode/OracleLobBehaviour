<?php
/**
 * Behaviour to fix oracle blobs
 */
class OracleBlobBehavior extends ModelBehavior {
/**
 * Array of blob fields being saved
 *
 * @var array
 * @access public
 */
    var $saveFields= array();
/**
 * Array of blob fields being found
 *
 * @var array
 * @access public
 */
    var $findFields= array();
/**
 * Array of blob fields
 *
 * @var array
 * @access public
 */
    var $blobs = array();
/**
 * Get the blob fields.
 *
 * @param object $Model Model using the behavior
 * @param array $settings Settings to override for model.
 * @return null
 * @access public
 */
    function setup(&$Model, $settings = array()) {
        if (is_string($settings)) {
            $this->blobs[$Model->alias][] = $settings;
        } else {
            $this->blobs[$Model->alias] = $settings;
        }
    }
/**
 * Capture blob fields and store for processing in the afterSave
 *
 * @param object $Model Model using the behaviour
 * @return true
 * @access public
 */
    function beforeSave(&$Model) {
        $this->saveFields[$Model->alias] = array();
        foreach ($this->blobs[$Model->alias] as $blob) {
            if (isset($Model->data[$Model->alias][$blob])) {
                if (method_exists($Model, 'processData')) {
                    $this->saveFields[$Model->alias][$blob] = $Model->processData($blob, $Model->data[$Model->alias][$blob]);
                } else {
                    $this->saveFields[$Model->alias][$blob] = $Model->data[$Model->alias][$blob];
                }
                unset($Model->data[$Model->alias][$blob]);
            }
        }
        return true;
    }
/**
 * Process blob fields
 *
 * @param object $Model Model using the behaviour
 * @return true
 * @access public
 */
    function afterSave(&$Model) {
        $db =& ConnectionManager::getDataSource($Model->useDbConfig);
        foreach ($this->saveFields[$Model->alias] as $field => $data) {
            $s = oci_parse($db->connection, 'update ' . $Model->useTable . ' set ' . $field . ' = empty_blob() where ' . $Model->primaryKey . ' = \'' . $Model->id . '\' returning ' . $field . ' into :blobdata');
            $lob = oci_new_descriptor($db->connection, OCI_D_LOB);
            oci_bind_by_name($s, ':blobdata', $lob, -1, OCI_B_BLOB);
            $time = getMicrotime();
            oci_execute($s, OCI_DEFAULT);
            $time = round((getMicrotime() - $time) * 1000, 0);
            $lob->save($data);
            oci_commit($db->connection);
            $lob->close();
            $Model->data[$Model->alias][$field] = $data;
            $db->_queriesLog[] = array(
                'query' => 'update ' . $Model->useTable . ' set ' . $field . ' = empty_blob() where ' . $Model->primaryKey . ' = \'' . $Model->id . '\' returning ' . $field . ' into :blobdata',
                'error' => false,
                'affected' => 1,
                'numRows' => 1,
                'took' => round(1000 * $time)
            );
        }
        unset($this->saveFields[$Model->alias]);
        return true;
    }
/**
 * Capture blob fields and store for processing in the afterFind
 *
 * @param object $Model Model using the behaviour
 * @param array $queryData Query data
 * @return true
 * @access public
 */
    function beforeFind(&$Model, $queryData) {
        $this->findFields[$Model->alias] = array();
        if (isset($queryData['fields'])) {
            if (is_string($queryData['fields'])) {
                $queryData['fields'] = array($queryData['fields'],  $Model->alias . '.' . $Model->primaryKey);
            } else {
                $queryData['fields'][] = $Model->alias . '.' . $Model->primaryKey;
            }
            foreach ($this->blobs[$Model->alias] as $blob) {
                if (in_array($blob, $queryData['fields'])) {
                    $this->findFields[$Model->alias][] = $blob;
                    unset($queryData['fields'][array_search($blob, $queryData['fields'])]);
                } elseif (in_array($Model->alias . '.' . $blob, $queryData['fields'])) {
                    $this->findFields[$Model->alias][] = $blob;
                    unset($queryData['fields'][array_search($Model->alias . '.' . $blob, $queryData['fields'])]);
                }
            }
        }
        return $queryData;
    }
/**
 * Process blob fields
 *
 * Possible TODO - support non-primary model finds
 *
 * @param object $Model Model using the behaviour
 * @param array $results Results
 * @param boolean $primary Is this the primary model?
 * @return true
 * @access public
 */
    function afterFind(&$Model, $results, $primary) {
        if ($primary && $this->findFields[$Model->alias] !== array()) {
            foreach ($results as $key => $result) {
                if (isset($result[$Model->alias][$Model->primaryKey])) {
                    $db =& ConnectionManager::getDataSource($Model->useDbConfig);
                    foreach ($this->findFields[$Model->alias] as $blob) {
                        $s = oci_parse ($db->connection, 'select ' . $blob . ' from ' . $Model->useTable . ' where ' . $Model->primaryKey . ' = :id');
                        oci_bind_by_name($s, ':id', $result[$Model->alias][$Model->primaryKey]);
                        $time = getMicrotime();
                        oci_execute($s);
                        $time = round((getMicrotime() - $time) * 1000, 0);
                        $arr = oci_fetch_array($s, OCI_ASSOC);
                        if (is_object($arr[strtoupper($blob)])) {
                            $data = $arr[strtoupper($blob)]->load();
                            $arr[strtoupper($blob)]->free();
                            $result[$Model->alias][$blob] = $data;
                        }
                        $db->_queriesLog[] = array(
                            'query' => 'update ' . $Model->useTable . ' set ' . $field . ' = empty_blob() where ' . $Model->primaryKey . ' = \'' . $Model->id . '\' returning ' . $field . ' into :blobdata',
                            'error' => false,
                            'affected' => 1,
                            'numRows' => 1,
                            'took' => round(1000 * $time)
                        );
                    }
                }
                $results[$key] = $result;
            }
        }
        unset($this->findFields[$Model->alias]);
        return $results;
    }
}
