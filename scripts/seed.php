<?php
require_once __DIR__ . '/../src/Database.php';
use App\Database;
$pdo = Database::conn();
$pdo->beginTransaction();
$roles = ['Admin','Clerk','Treasurer','Leader','Member'];
foreach ($roles as $r) {
  $stmt = $pdo->prepare('INSERT IGNORE INTO roles (name) VALUES (?)');
  $stmt->execute([$r]);
}
$exists = $pdo->query("SELECT id FROM users WHERE username='admin'")->fetch();
if (!$exists) {
  $roleId = $pdo->query("SELECT id FROM roles WHERE name='Admin'")->fetch()['id'];
  $hash = password_hash('admin123', PASSWORD_BCRYPT);
  $stmt = $pdo->prepare('INSERT INTO users (username,password_hash,role_id,status,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())');
  $stmt->execute(['admin',$hash,$roleId,'active']);
}
$pdo->commit();
echo 'ok';

