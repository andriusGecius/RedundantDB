<?php
//bibis?
namespace RedundantDB;

use \Memcached;
use \Pdo;

class Connection
{
	//CHARSET has not been set anywhere!
	public function __construct($config, $charset = 'UTF-8')
	{
		$this->config = $config;
		$this->charset = $charset;

		$this->memc = new \Memcached;
		$this->memc->addServer($config['memc']['host'], $config['memc']['port']);
	}

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
		}
		catch (\PDOException $e) {
			return false;
		}
	}

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

	private function determineDSN()
	{
		switch ($this->type) {
			case 'mariadb':
				$type = 'mysql';

			case 'mysql':
				if (isset($this->socket)) {
					$dsn = $this->type . ':unix_socket=' . $this->socket . ';dbname=' . $this->database;
				} else {
					$dsn = $this->type . ':host=' . $this->host . ($this->port ? ';port=' . $this->port : '') . ';dbname=' . $this->database;
				}
				break;

			case 'pgsql':
				$dsn = $this->type . ':host=' . $this->host . ($this->port ? ';port=' . $this->port : '') . ';dbname=' . $this->database;
				break;

			case 'sybase':
				$dsn = 'dblib:host=' . $this->host . ($this->port ? ':' . $this->port : '') . ';dbname=' . $this->database;
				break;

			case 'oracle':
				$dbname = $this->host ?
					'//' . $this->host . ($this->port ? ':' . $this->port : ':1521') . '/' . $this->database :
					$this->database;

				$dsn = 'oci:dbname=' . $this->database . ($this->charset ? ';charset=' . $this->charset : '');
				break;

			case 'mssql':
				$dsn = strstr(PHP_OS, 'WIN') ?
					'sqlsrv:server=' . $this->host . ($is_port ? ',' . $this->port : '') . ';database=' . $this->database :
					'dblib:host=' . $this->host . ($is_port ? ':' . $this->port : '') . ';dbname=' . $this->database;
				break;

			case 'sqlite':
				$dsn = $this->type . ':' . $this->database_file;
				$this->username = null;
				$this->password = null;
				break;
		}
		return $dsn;
	}

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

	private function verifyConnection($connect)
	{
		if ($connect === false) {
			//throw new \Exception('Database connection failed. Both hosts denied the connection.');
		}
	}

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

	private function determineFasterOrAliveServer($db1, $db2)
	{
		if ( ($db1['connectTime'] < $db2['connectTime'] && $db1['connectTime'] > 0)
			   || $db2['connectTime'] == 0) {

			return 1;

		} else if( ($db2['connectTime'] < $db1['connectTime'] && $db2 ['connectTime'] > 0)
					|| $db1['connectTime'] == 0) {
			return 2;
		}
	}

	private function getBackupServer()
	{
		if ($this->db_main == 1) {
			$this->db_main = 2;
		}else{
			$this->db_main = 1;
		}
	}

	public function getConnectedServer()
	{
		return $this->db_main;
	}

	private function setMemc($code, $data)
	{
		$this->memc->set('db_server_'.$code, $data, 3600);
	}

	private function getMemc($code)
	{
		return $this->memc->get('db_server_'.$code);
	}



}