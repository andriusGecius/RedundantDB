<?php

use PHPUnit\Framework\TestCase;

class ConnectionTest extends TestCase
{

	public function __construct()
	{
		//WARNING: this code has only been tested with MySQL Cluster
		$this->dbConfig = [
			1 => [
				'host' => 'localhost',
				'port' => 3306,
				'database' => 'uptimia',
				'username' => 'root',
				'password' => 'asdsaduk',
				'type' => 'mysql'
			],
			2 => [
				'host' => 'localhost',
				'port' => 3306,
				'database' => 'uptimia',
				'username' => 'root',
				'password' => 'asdsaduk',
				'type' => 'mysql'
			],
			'memc' => [
				'host' => 'localhost',
				'port' => 11211
			]
		];

		if (!isset($this->dbConfig)) throw new \Exception('Please provide your database configuration before starting this test');

		//Initiating Memcached
		$this->memc = new \Memcached;
		$this->memc->addServer($this->dbConfig['memc']['host'],$this->dbConfig['memc']['port']);

		//Testing Memc
		$this->memc->set('test_key', 'test_val', 10);
		$test_memc = $this->memc->get('test_key');

		if($test_memc != 'test_val') throw new \Exception('Redundant Database service depends on Memcached');

		require_once('../Connection.php');
	}


	public function testConnect(){

		$RedundantDB = new \RedundantDB\Connection($this->dbConfig);


		//Getting rid of previous memcached data
		$this->destroyMemc();

		$i = 0;
		while ($i == 0) {
			$connect = $RedundantDB->connect();

			$db_server_1 = $this->getMemc('1');
			$db_server_2 = $this->getMemc('2');
			$db_server_main = $this->getMemc('main');

			$this->displayConnectionInfo(1, $db_server_1);
			$this->displayConnectionInfo(2, $db_server_2);


			//Stop the loop
			if(intval($db_server_main) >= 1){
				$speedDifference = ($db_server_1['connectTime'] > $db_server_2['connectTime'])
				? ($db_server_1['connectTime'] - $db_server_2['connectTime'])
				: ($db_server_2['connectTime'] - $db_server_1['connectTime']);

				print PHP_EOL.PHP_EOL.'Selected fastest (or alive) connection: #'.$db_server_main.' ('.$this->dbConfig[$db_server_main]['host'].'): '.($speedDifference*1000).' ms faster';
				$i = 1;
			} else {
				//Closing the connection if we continue the loop
				$connect = null;
			}
		}


		$tables = $this->connectionTest($connect);

		//Found at least 1 table
		$this->assertGreaterThanOrEqual(1, $tables);

	}


	private function displayConnectionInfo($number, $conn)
	{
		if(is_array($conn))
		{
			print PHP_EOL.'Server '.$number.' info. Number of connections: #'.$conn['count'].', average connection duration: '.$conn['connectTime'];
		}
	}

	private function getMemc($code)
	{
		return $this->memc->get('db_server_'.$code);
	}

	private function destroyMemc()
	{
		$this->memc->delete('db_server_1');
		$this->memc->delete('db_server_2');
		$this->memc->delete('db_server_main');
	}


	public function testFirstServerDown()
	{
		//Deliberately setting the first server as unreachable
		$dbConfigTemp = $this->dbConfig;
		$dbConfigTemp[1]['host'] = '111.111.111.111';
		$RedundantDB = new \RedundantDB\Connection($dbConfigTemp);
		$connect = $RedundantDB->connect();

		$db_selected = $RedundantDB->getConnectedServer();

		echo('DB selected: '.$db_selected);

		$this->assertEquals(2, $db_selected);
	}


	public function testSecondServerDown()
	{
		//Deliberately setting the first server as unreachable
		$dbConfigTemp = $this->dbConfig;
		$dbConfigTemp[2]['host'] = '111.111.111.111';
		$RedundantDB = new \RedundantDB\Connection($dbConfigTemp);
		$connect = $RedundantDB->connect();

		$db_selected = $RedundantDB->getConnectedServer();

		echo('DB selected: '.$db_selected);

		$this->assertEquals(1, $db_selected);
	}

	public function testBothServersDown()
	{
		$this->dbConfig[1]['host'] = '111.111.111.111';
		$this->dbConfig[2]['host'] = '111.111.111.111';
		$RedundantDB = new \RedundantDB\Connection($this->dbConfig);
		$connect = $RedundantDB->connect();

		//Assert exception here!
		$this->assertEquals(false, $connect);
	}


	private function connectionTest($connect)
	{
		$tables = $connect->query('SHOW TABLES')->fetchAll();

		return $tables;
	}

}

?>