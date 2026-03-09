<?php
namespace App;
class Audit {
  public static function log($userId, $action, $entity, $entityId, $before = null, $after = null) {
    try {
      $pdo = Database::conn();
      $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, entity, entity_id, before_json, after_json, timestamp) VALUES (?,?,?,?,?,?,NOW())');
      $bj = $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null;
      $aj = $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null;
      $stmt->execute([$userId, $action, $entity, $entityId, $bj, $aj]);
    } catch (\Throwable $e) {
    }
  }
}

