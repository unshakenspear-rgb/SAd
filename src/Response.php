<?php
namespace App;
class Response {
  public static function json($status, $data) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
  }
  public static function error($status, $code, $message) {
    self::json($status, ['error' => $code, 'message' => $message]);
  }
}

