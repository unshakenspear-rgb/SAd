<?php
namespace App;
class Database {
  private static $pdo = null;
  public static function conn() {
    if (!self::$pdo) {
      $config = require __DIR__ . '/../config/config.php';
      $dsn = 'mysql:host=' . $config['db']['host'] . ';dbname=' . $config['db']['name'] . ';charset=utf8mb4';
      $opts = [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false
      ];
      self::$pdo = new \PDO($dsn, $config['db']['user'], $config['db']['pass'], $opts);
    }
    return self::$pdo;
  }
}

