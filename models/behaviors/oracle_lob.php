<?php
/**
 * Behaviour to fix oracle lobs
 */
class OracleLobBehavior extends ModelBehavior {
/**
 * Array of lob fields being saved
 *
 * @var array
 * @access public
 */
    var $saveFields= array();
/**
 * Array of lob fields being found
 *
 * @var array
 * @access public
 */
    var $findFields= array();
/**
 * Array of object types
 *
 * @var array
 * @access protected
 */
    var $_types = array(
        'blobs',
        'clobs'
    );
/**
 * Get the lob fields.
 *
 * @param object $Model Model using the behavior
 * @param array $settings Settings to override for model.
 * @return null
 * @access public
 */
    function setup(&$Model, $settings = array()) {
        foreach ($this->_types as $type) {
            if (!isset($this->{$type}) || !is_array($this->{$type})) {
                $this->{$type} = array();
            }
            if (isset($settings[$type])) {
                if (is_string($settings[$type])) {
                    $this->{$type}[$Model->alias][] = $settings[$type];
                } else {
                    $this->{$type}[$Model->alias] = $settings[$type];
                }
            }
        }
    }
/**
 * Capture lob fields and store for processing in the afterSave
 *
 * @param object $Model Model using the behaviour
 * @return true
 * @access public
 */
    function beforeSave(&$Model) {
        foreach ($this->_types as $type) {
            $this->saveFields[$Model->alias][$type] = array();
            if (isset($this->{$type}[$Model->alias])) {
                foreach ($this->{$type}[$Model->alias] as $field) {
                    if (isset($Model->data[$Model->alias][$field])) {
                        if (method_exists($Model, 'processData')) {
                            $this->saveFields[$Model->alias][$type][$field] = $Model->processData($field, $Model->data[$Model->alias][$field]);
                        } else {
                            $this->saveFields[$Model->alias][$type][$field] = $Model->data[$Model->alias][$field];
                        }
                        unset($Model->data[$Model->alias][$field]);
                    }
                }
            }
        }
        return true;
    }
/**
 * Process lob fields
 *
 * @param object $Model Model using the behaviour
 * @return true
 * @access public
 */
    function afterSave(&$Model) {
        $db =& ConnectionManager::getDataSource($Model->useDbConfig);
        foreach ($this->saveFields[$Model->alias]['blobs'] as $field => $data) {
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
        foreach ($this->saveFields[$Model->alias]['clobs'] as $field => $data) {
            $s = oci_parse($db->connection, 'update ' . $Model->useTable . ' set ' . $field . ' = empty_clob() where ' . $Model->primaryKey . ' = \'' . $Model->id . '\' returning ' . $field . ' into :clobdata');
            $lob = oci_new_descriptor($db->connection, OCI_D_LOB);
            oci_bind_by_name($s, ':clobdata', $lob, -1, OCI_B_CLOB);
            $time = getMicrotime();
            oci_execute($s, OCI_DEFAULT);
            $time = round((getMicrotime() - $time) * 1000, 0);
            $lob->save($data);
            oci_commit($db->connection);
            $lob->close();
            $Model->data[$Model->alias][$field] = $data;
            $db->_queriesLog[] = array(
                'query' => 'update ' . $Model->useTable . ' set ' . $field . ' = empty_clob() where ' . $Model->primaryKey . ' = \'' . $Model->id . '\' returning ' . $field . ' into :clobdata',
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
 * Capture lob fields and store for processing in the afterFind
 *
 * @param object $Model Model using the behaviour
 * @param array $queryData Query data
 * @return true
 * @access public
 */
    function beforeFind(&$Model, $queryData) {
        foreach ($this->_types as $type) {
            $this->findFields[$Model->alias][$type] = array();
            if (isset($queryData['fields'])) {
                if (isset($this->{$type}[$Model->alias])) {
                    foreach ($this->{$type}[$Model->alias] as $field) {
                        if (is_array($queryData['fields'])) {
                            if (in_array($field, $queryData['fields'])) {
                                $this->findFields[$Model->alias][$type][] = $field;
                                unset($queryData['fields'][array_search($field, $queryData['fields'])]);
                            } elseif (in_array($Model->alias . '.' . $field, $queryData['fields'])) {
                                $this->findFields[$Model->alias][$type][] = $field;
                                unset($queryData['fields'][array_search($Model->alias . '.' . $field, $queryData['fields'])]);
                            }
                        } else {
                            if ($queryData['fields'] === $field || $queryData['fields'] === $Model->alias . '.' . $field) {
                                $this->findFields[$Model->alias][$type][] = $field;
                                $queryData['fields'] = null;
                            }
                        }
                    }
                }
            }
        }
        return $queryData;
    }
/**
 * Process lob fields
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
        foreach ($this->_types as $type) {
            if ($primary && $this->findFields[$Model->alias][$type] !== array()) {
                foreach ($results as $key => $result) {
                    if (isset($result[$Model->alias][$Model->primaryKey])) {
                        $db =& ConnectionManager::getDataSource($Model->useDbConfig);
                        foreach ($this->findFields[$Model->alias][$type] as $field) {
                            $s = oci_parse ($db->connection, 'select ' . $field . ' from ' . $Model->useTable . ' where ' . $Model->primaryKey . ' = :id');
                            oci_bind_by_name($s, ':id', $result[$Model->alias][$Model->primaryKey]);
                            $time = getMicrotime();
                            oci_execute($s);
                            $time = round((getMicrotime() - $time) * 1000, 0);
                            $arr = oci_fetch_array($s, OCI_ASSOC);
                            if (is_object($arr[strtoupper($field)])) {
                                $data = $arr[strtoupper($field)]->load();
                                $arr[strtoupper($field)]->free();
                                $result[$Model->alias][$field] = $data;
                            }
                            $db->_queriesLog[] = array(
                                'query' => 'select ' . $field . ' from ' . $Model->useTable . ' where ' . $Model->primaryKey . ' = :id',
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
        }
        unset($this->findFields[$Model->alias]);
        return $results;
    }
}
