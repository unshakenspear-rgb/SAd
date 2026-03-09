<?php
namespace App;
class Http {
  public static function postJson($url, $payload, $timeout = 5) {
    $ch = curl_init($url);
    $data = json_encode($payload);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Content-Length: ' . strlen($data)],
      CURLOPT_POSTFIELDS => $data,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
      $err = curl_error($ch);
      curl_close($ch);
      throw new \RuntimeException('http_error: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
      throw new \RuntimeException('http_status_' . $code);
    }
    if (!is_array($json)) {
      throw new \RuntimeException('invalid_json_response');
    }
    return $json;
  }
}

