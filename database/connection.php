<?php

    #Author: Nik M
    #Website: https://github.com/nik-m2/RESTful-PHP

    class DatabaseQuery {

        function __construct($conn, $query, $first_query=null, $prev_query=null) {
            $this->conn = $conn;
            $this->query = $query;

            $this->first_query = $first_query;
            $this->next_query = null;

            if ($prev_query) {
                $prev_query->next_query = this;
            }
        }

        function run() {
            $query_str = "";

            $curr_query = $this->first_query;
            do {
                $query_str .= $curr_query->query . ($curr_query->next_query ? ',' : '');
            } while ($query = $curr_query->next_query);

            return $this->db->connector->multi_query($query_str);
        }

    }

    class DatabaseConnection {

        static $table_config;

        function __construct($connector) {
            $this->connector = $connector;
            $this->connected = true;
            $this->error = false;
            $this->message = '';
        }

        function verify_table($table_name) {

            if (!array_key_exists($table_name, self::$table_config)) {
                $this->message = 'Could not find configuration for table ' . $table_name;
                $this->error = true;
                return false;
            }

            $table_config = self::$table_config->$table_name;

            $query = "CREATE TABLE IF NOT EXISTS `" . $this->connector->escape_string($table_name) . "` (";

            $primary_key = "";
            foreach ($table_config as $key => $value) {
                if ($key === 'primary_key') {
                    $primary_key = $value;
                }
            }

            $foreign_keys = [];
            $len = count((array) $table_config);
            $index = 0;
            foreach ($table_config as $key => $value) {

                if ($key === 'primary_key') {
                    continue;
                }

                $value = (array) $value;

                if ($index != 0) {
                    $query .= ', ';
                }

                if (!array_key_exists('type', $value)) {
                    $this->message = "Could not find type for column: " . $key;
                    $this->error = true;
                    return false;
                }

                $type = $value['type'];
                $nullable = array_key_exists('nullable', $value) ? $value['nullable'] : true;
                $unique = array_key_exists('unique', $value) ? $value['unique'] : false;
                $auto_increment = array_key_exists('auto_increment', $value) ? $value['auto_increment'] : false;
                $default = array_key_exists('default', $value) ? $value['default'] : null;
                $foreign_key = array_key_exists('foreign_key', $value) ? (array) $value['foreign_key'] : null;

                if ($default !== null)  {
                    if (!is_string($default)) {
                        $default = var_export($default, true);
                    }
                }

                $query .= '`' . $this->connector->escape_string($key) . '` ' . $type . 
                    ($unique ? ' UNIQUE' : '') . 
                    ($nullable ? ($primary_key === $key ? '' : ' NULL') : ' NOT NULL') . 
                    ($default === null ? '' : ' DEFAULT ' . $default) . 
                    ($auto_increment ? ' AUTO_INCREMENT' : '');

                if ($foreign_key !== null) {

                    if (!isset($foreign_key['table'])) {
                        $this->message = "No 'table' key in foreign_key: " . $key;
                        $this->error = true;
                        return false;
                    }

                    if (!isset($foreign_key['column'])) {
                        $this->message = "A foreign key must have either a `column` key. local_col=" . $key;
                        $this->error = true;
                        return false;
                    }

                    $VALID_ACTIONS = [
                        'cascade',
                        'restrict',
                        'no action',
                        'set default',
                        'set null'
                    ];

                    if (isset($foreign_key['on_delete'])) {
                        $found = false;
                        foreach ($VALID_ACTIONS as $value) {
                            if (strtolower($foreign_key['on_delete']) === $value) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $this->message = 'Error with column ' . $key . 'on_delete must be one of ' . join(' ', $VALID_ACTIONS);
                            $this->error = true;
                            return false;
                        }
                    }

                    if (isset($foreign_key['on_update'])) {
                        $found = false;
                        foreach ($VALID_ACTIONS as $value) {
                            if (strtolower($foreign_key['on_update']) === $value) {
                                $found = true;
                                break;
                            }
                        }

                        if (!$found) {
                            $this->message = 'Error with column ' . $key . 'on_update must be one of ' . join(' ', $VALID_ACTIONS);
                            $this->error = true;
                            return false;
                        }
                    }

                    $foreign_key['og_col'] = $key;

                    $foreign_keys[] = $foreign_key;
                }



                $index++;
            }

            if ($primary_key !== "") {
                $query .= ', CONSTRAINT ' . $this->connector->escape_string($table_config->primary_key) . ' PRIMARY KEY (`' . $this->connector->escape_string($table_config->primary_key) . '`)';
            }

            foreach ($foreign_keys as $foreign_key) {

                $query .= ', CONSTRAINT fk_' . $table_name . '_' . $this->connector->escape_string($foreign_key['og_col']) . 
                    ' FOREIGN KEY(' . $this->connector->escape_string($foreign_key['og_col']) . ')' .
                    ' REFERENCES ' . $this->connector->escape_string($foreign_key['table']) . '(' . $this->connector->escape_string($foreign_key['column']) . ')';

                if (isset($foreign_key['on_update'])) {
                    $query .= ' ON UPDATE ' . $this->connector->escape_string($foreign_key['on_update']);
                }

                if (isset($foreign_key['on_delete'])) {
                    $query .= ' ON DELETE ' . $this->connector->escape_string($foreign_key['on_delete']);
                }
             }

            $query .= ');';

            $result = $this->connector->query($query);
            if (!$result) {
                $this->message = "Could not verify table " . $table_name . "<br/>Query: " . $query . '<br/> Error: ' . $this->connector->connection->error;
                return false;
            }

            return true;
        }

        function insert($table, $values) {
            $query = "INSERT INTO {$table} (";


            $first = true;
            foreach ($values as $key => $value) {
                if (!$first) {
                    $query .= ',';
                }


                $query .= $this->connector->escape_string($key);
                $first = false;
            }

            $query .= ") VALUES (";

            $first = true;
            foreach ($values as $key => $value) {
                if (!$first) {
                    $query .= ',';
                }

                if ($value === null) {
                    $query .= 'NULL';
                } else {
                    $query .= "'" . $this->connector->escape_string($value) . "'";
                }
                $first = false;
            }
            
            $query .= ")";

            $result = $this->connector->query($query);

            if (!$result) {
                $this->error = true;
                $this->message = 'Could not insert into table ' . $table . ' <br/> Error: ' . $this->connector->connection->errno . ' ' . $this->connector->connection->error . '\n<br\> Query: ' . $query;

                return false;
            }

            return true;
        }

        function insert_id() {
            return $this->connector->insert_id();
        }

        function num_rows() {
            return $this->connector->num_rows();
        }

        // Params: columns (string[]), where (string), group_by (string), order_by (string), limit (number)
        function select($table, $params) {
            $columns = isset($params['columns']) ? $params['columns'] : null;
            $where = isset($params['where']) ? $params['where'] : null;
            $group_by = isset($params['group_by']) ? $params['group_by'] : null;
            $order_by = isset($params['order_by']) ? $params['order_by'] : null;
            $join = isset($params['join']);
            $limit = isset($params['limit']) ? $params['limit'] : null;

            $query = "SELECT ";

            if ($columns) {
                $first = true;
                foreach ($columns as $key => $value) {
                    if (!$first) {
                        $query .= ",";
                    }
                    $query .= $this->connector->escape_string($value);
                    $first = false;
                }
            } else {
                $query .= '* ';
            }

            $query .= " FROM " . $this->connector->escape_string($table) . " ";

            if ($where) {
                $query .= "WHERE " . $where . " ";
            }

            if ($group_by) {
                $query .= "GROUP BY" . $group_by . " ";
            }

            
            if ($order_by) {
                $query .= "ORDER BY " . $order_by . " ";
            }

            if ($limit) {
                $query .= "LIMIT " . $limit;
            }

            $result = $this->connector->query($query);

            if (!$result) {
                if ($GLOBALS['debug']) {
                    $this->error = true;
                    $this->message = 'Could not select item from table ' . $table . ' Error: ' . $this->connector->connection->error . ' Query: ' . $query;
                }
            }

            return $result;
        }

        function update($table, $values, $where) {
            $query = "UPDATE " . $table . ' SET ';

            if (!$where) {
                if ($GLOBALS['debug']) {
                    echo "You almost changed the whole table! You need a where clause.";
                }

                return null;
            }

            $first = true;
            foreach ($values as $key => $value) {
                if (!$first) {
                    $query .= ',';
                }
                $query .= $key . "=" . $value;
                $first = false;
            }

            if ($where) {
                $query .= " WHERE " . $where . " ";
            }

            $result = $this->connector->query($query);

            if (!$result) {
                if ($GLOBALS['debug']) {
                    echo $query, $this->connector->connection->error . '<br/>';
                    echo var_dump($result);
                }
            }

            return $result;
        }

        function update_all($table, $values) {
            $query = "UPDATE " . $table . ' SET ';


            $first = true;
            foreach ($values as $key => $value) {
                if (!$first) {
                    $query .= ',';
                }
                $query .= $this->connector->escape_string($key) . '=\'' . $this->connector->escape_string($value) . '\' ';
                $first = false;
            }

            $result = $this->connector->query($query);

            return $result;
        }

        function delete($table, $where) {

            if (!$where) {
                if ($GLOBALS['debug']) {
                    echo "You almost deleted the whole table! You need a where clause.";
                }

                return null;
            }

            $query = "DELETE FROM " . $table . ' ';

            $query .= "WHERE " . $where . " ";

            $result = $this->connector->query($query);

            return $result;
        }

        function delete_all($table) {
            $result = $this->connector->query("DELETE FROM " . $table . ' ');

            return $result;
        }

        function disconnect($is_error=false, $message = '') {
            $this->error = $is_error;

            if ($message != '') {
                $this->message = $message;
            }

            $this->connector->disconnect();
            $this->connected = false;
        }

    }


?>