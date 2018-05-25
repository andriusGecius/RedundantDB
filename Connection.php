<?php

/**
* Redundant database connection manager
*
* Redundant database (for example, MySQL cluster) connection layer.
* Finds the shortest path to the healthy database API.
*
* @author Andrius Gecius <andrius.gecius@gmail.com>
*
*/

namespace RedundantDB;

use \Memcached;
use \Pdo;
use RBM\Utils\Dsn;

class Connection
{
    /**
    * @param array $config
    */
    public function __construct($config)
    {
        $this->config = $config;

        $this->charset = (empty($this->config['charset']) === true ? 'utf8' : $this->config['charset']);

        $this->memc = new \Memcached;
        $this->memc->addServer($config['memc']['host'], $config['memc']['port']);
    }

    /**
    * Use this method to instantiate a connection
    *
    * @return \PDO
    * @throws \Execption
    */
    public function connect()
    {
        $this->getMainServer();
        $connect = $this->connectionAttempt();

        //Second attempt with another server if the first one failed
        if ($connect == false) {
            $this->getBackupServer();
            $connect = $this->connectionAttempt();
        }

        $this->verifyConnection($connect);

        return $connect;
    }

    /**
    * @return \PDO
    */
    private function connectionAttempt()
    {
        $this->determineConnectionData();
        try {
            //We use microtime to measure connection speed and use that information to determine the fastest connection
            $connectStart = microtime(true);

            $connect = new \PDO(
                $this->determineDSN(),
                $this->username,
                $this->password,
                array(PDO::ATTR_TIMEOUT => 1,
                      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
            );

            $connectEnd = microtime(true);

            //Record connection speed data if we still don`t know which connection is the fastest
            if (!isset($this->stopRecording)) {
                $this->recordConnectionSpeed($connectEnd - $connectStart);
            }

            return $connect;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
    * Extracts database config data from the supplied external value to internal private variables
    *
    * @return void
    */
    private function determineConnectionData()
    {
        $config =  $this->config[$this->db_main];

        $this->type = $config['type'];
        $this->host = $config['host'];
        $this->port = $config['port'];
        $this->database = $config['database'];
        $this->username = $config['username'];
        $this->password = $config['password'];
    }

    /**
    * @return string
    */
    private function determineDSN()
    {
        $dsn = new Dsn($this->type, [
            "host" => $this->host,
            "port" => $this->port,
            "dbname" => $this->database,
            "user" => $this->username,
            "password" => $this->password,
            "charset" => $this->charset
        ]);

        return $dsn;
    }

    /**
    * Record how fast the connection was established to Memcached
    *
    * @param int $time
    * @return void
    */
    private function recordConnectionSpeed($time)
    {
        $serverArr = $this->getMemc($this->db_main);
        if (!is_array($serverArr)) {
            $serverArr = ['count' => 1, 'connectTime' => $time];
        } else {
            $serverArr['connectTime'] = ($serverArr['connectTime'] * $serverArr['count'] + $time) / ($serverArr['count']+1);
            $serverArr['count'] = $serverArr['count'] + 1;
        }
        $this->setMemc($this->db_main, $serverArr);
    }

    /**
    * @param mixed $connect PDO or boolean
    */
    private function verifyConnection($connect)
    {
        if ($connect === false) {
            throw new \Exception('Database connection failed. Both hosts denied the connection.');
        }
    }

    /**
    * Determine which alive server is the fastest, if there is enough data
    *
    * @return void
    */
    private function getMainServer()
    {
        $this->db_main = $this->getMemc('main');
        if (intval($this->db_main) == 0) {
            $db1 = $this->getMemc('1');
            $db2 = $this->getMemc('2');

            //Determining the main server when one (or both) of them had at least 20 connections
            if ($db1['count'] >= 20 || $db2['count'] >= 20) {
                $this->db_main = $this->determineFasterOrAliveServer($db1, $db2);
                $this->setMemc('main', $this->db_main);
            } else {
                $this->db_main = rand(1, 2);
            }
        } else {
            //Do not calculate connection speed anymore if we have already determined the fastest connection
            $this->stopRecording = true;
        }
    }

    /**
    * Compare connection speeds
    *
    * @return void
    */
    private function determineFasterOrAliveServer($db1, $db2)
    {
        if (($db1['connectTime'] < $db2['connectTime'] && $db1['connectTime'] > 0)
                    || $db2['connectTime'] == 0) {
            return 1;
        } elseif (($db2['connectTime'] < $db1['connectTime'] && $db2 ['connectTime'] > 0)
                    || $db1['connectTime'] == 0) {
            return 2;
        }
    }

    /**
    * Determine the oposite server
    *
    * @return void
    */
    private function getBackupServer()
    {
        if ($this->db_main == 1) {
            $this->db_main = 2;
        } else {
            $this->db_main = 1;
        }
    }

    /**
    * @return void
    */
    public function getConnectedServer()
    {
        return $this->db_main;
    }

    /**
    * @return void
    */
    private function setMemc($code, $data)
    {
        $this->memc->set('db_server_'.$code, $data, 3600);
    }

    /**
    * @return void
    */
    private function getMemc($code)
    {
        return $this->memc->get('db_server_'.$code);
    }
}
