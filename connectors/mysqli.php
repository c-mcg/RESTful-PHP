<?php

    class MySQLiConnector extends DatabaseConnector {

        function connect($address, $db_name, $username, $password) {
            $mysqli = new mysqli($address, $username, $password, $db_name);
            $message = true;

            if ($mysqli->connect_errno) {
                $message = 'Could not connect to database server.';
            }

            $this->connection = $mysqli;
            return $message;
        }

        function query($sql) {
            return $this->connection->query($sql);
        }

        function disconnect() {
            return $this->connection->close();
        }
    }
    

?>