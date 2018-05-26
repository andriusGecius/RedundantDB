# RedundantDB
Connection manager for high availability database clusters

## What is this?
Let`s say you have a distributed database cluster whith 2 APIs.
Both APIs point you to exactly the same database.
Which API do you connect to?
What happens if one of those APIs suddenly die?
How fast can you redirect all your database connections to the healthy API?

This is how RedundantDB class helps you!

## Main Features

* **Easy** - just provide connection information and you are ready to connect

* **Smart** - Finds the fastest/shortest route to the API. Immediatelly switches APIs if the current one is unreachable

* **PDO** - Returns PDO object if successfully connected

* **Dependencies** - Depends on \Memcached and \PDO

## Get Started

### Install via composer

Add RedundantDB to composer.json configuration file.
```
$ composer require andriusgecius/RedundantDB
```

And update the composer
```
$ composer update
```

```php
// Require compser autoloader file
require 'vendor/autoload.php';

// Initialize
$dbConfig = [
    1 => [
        'host' => 'HOSTNAME',
        'port' => 3306,
        'database' => 'DBNAME',
        'username' => 'USERNAME',
        'password' => 'PASSWORD',
        'type' => 'mysql'
    ],
    2 => [
        'host' => 'HOSTNAME',
        'port' => 3306,
        'database' => 'DBNAME',
        'username' => 'USERNAME',
        'password' => 'PASSWORD',
        'type' => 'mysql'
    ],
    'memc' => [
        'host' => 'localhost',
        'port' => 11211
    ],
    'charset' => 'utf8'
];

$RedundantDB = new \RedundantDB\Connection($dbConfig);
$connect = $RedundantDB->connect(); //Returns PDO
```

## Disclaimer
This connection manager has been tested only with MySQL cluster. Any contributions from developers who have experience with different high availability relational database clusters are highly appreciated!

## License

Please use it as you please!
