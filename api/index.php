<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Response.php';
require_once __DIR__ . '/../src/Audit.php';
require_once __DIR__ . '/../src/Http.php';
use App\Database;
use App\Response;
use App\Audit;
use App\Http;
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$path = '/';
if (strlen($uri) >= strlen($base)) {
  $path = substr($uri, strlen($base));
}
$path = '/' . ltrim($path, '/');
$segments = array_values(array_filter(explode('/', $path)));
if (count($segments) > 0 && $segments[0] === 'index.php') {
  array_shift($segments);
}
function require_db() {
  try { Database::conn(); return true; } catch (\Throwable $e) { Response::error(500, 'db_connection_failed', $e->getMessage()); }
}
function current_user() {
  if (!isset($_SESSION['uid'])) return null;
  try {
    $pdo = Database::conn();
    $stmt = $pdo->prepare('SELECT u.id, u.username, u.status, r.name AS role, u.member_id FROM users u JOIN roles r ON u.role_id=r.id WHERE u.id=?');
    $stmt->execute([intval($_SESSION['uid'])]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'active') return null;
    return ['id'=>intval($row['id']),'username'=>$row['username'],'role'=>$row['role'],'member_id'=>$row['member_id'] ? intval($row['member_id']) : null];
  } catch (\Throwable $e) { return null; }
}
function require_auth() {
  $u = current_user();
  if (!$u) Response::error(401, 'unauthorized', 'login required');
  return $u;
}
function require_role($roles) {
  $u = require_auth();
  if (!in_array($u['role'], $roles)) Response::error(403, 'forbidden', 'insufficient_role');
  return $u;
}
if (empty($segments)) {
  Response::json(200, ['status' => 'ok']);
}
if ($segments[0] === 'health') {
  $ok = true;
  try { Database::conn()->query('SELECT 1'); } catch (\Throwable $e) { $ok = false; }
  Response::json($ok ? 200 : 500, ['db' => $ok]);
}
if ($segments[0] === 'auth') {
  require_db();
  if ($method === 'POST' && isset($segments[1]) && $segments[1] === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $username = trim($input['username'] ?? '');
    $password = strval($input['password'] ?? '');
    if ($username === '' || $password === '') Response::error(400, 'validation_error', 'username and password required');
    $pdo = Database::conn();
    $stmt = $pdo->prepare('SELECT u.id, u.username, u.password_hash, u.status, r.name AS role, u.member_id FROM users u JOIN roles r ON u.role_id=r.id WHERE u.username=?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'active') Response::error(401, 'invalid_credentials', 'invalid username or password');
    if (!password_verify($password, $row['password_hash'])) Response::error(401, 'invalid_credentials', 'invalid username or password');
    $_SESSION['uid'] = intval($row['id']);
    Response::json(200, ['user' => ['id'=>intval($row['id']),'username'=>$row['username'],'role'=>$row['role'],'member_id'=>$row['member_id'] ? intval($row['member_id']) : null]]);
  }
  if ($method === 'POST' && isset($segments[1]) && $segments[1] === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    Response::json(204, []);
  }
  if ($method === 'GET' && isset($segments[1]) && $segments[1] === 'me') {
    $u = current_user();
    if (!$u) Response::error(401, 'unauthorized', 'login required');
    Response::json(200, $u);
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'members') {
  require_db();
  require_role(['Admin','Clerk','Leader','Treasurer']);
  $pdo = Database::conn();
  if (count($segments) === 4 && ctype_digit($segments[1]) && $segments[2] === 'face' && $segments[3] === 'enroll' && $method === 'POST') {
    $u = require_role(['Admin','Clerk','Leader']);
    $memberId = intval($segments[1]);
    $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM members WHERE id=?');
    $stmt->execute([$memberId]);
    $member = $stmt->fetch();
    if (!$member) Response::error(404, 'not_found', 'member not found');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $img = $input['image_base64'] ?? '';
    $consent = isset($input['consent']) ? (bool)$input['consent'] : false;
    if ($img === '' || !$consent) Response::error(400, 'validation_error', 'image_base64 and consent required');
    $config = require __DIR__ . '/../config/config.php';
    $base = $config['face_service']['base_url'] ?? 'http://127.0.0.1:8001';
    try {
      $resp = Http::postJson(rtrim($base, '/') . '/face/enroll', ['member_id' => $memberId, 'image_base64' => $img]);
    } catch (\Throwable $e) {
      Response::error(502, 'face_service_unavailable', 'face service error');
    }
    $embeddingB64 = $resp['embedding'] ?? null;
    if (!$embeddingB64) Response::error(502, 'face_service_bad_response', 'missing embedding');
    $embedding = base64_decode($embeddingB64, true);
    if ($embedding === false) Response::error(502, 'face_service_bad_response', 'invalid embedding');
    $version = isset($resp['version']) ? intval($resp['version']) : null;
    $sql = 'INSERT INTO face_templates (member_id, embedding, consent_flag, version, created_at, updated_at)
            VALUES (?,?,?,?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE embedding=VALUES(embedding), consent_flag=VALUES(consent_flag), version=' . ($version ? 'VALUES(version)' : 'version+1') . ', updated_at=NOW()';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$memberId, $embedding, $consent ? 1 : 0, $version ?? 1]);
    Audit::log($u['id'], 'update', 'face_templates', $memberId, null, ['member_id'=>$memberId,'version'=>$version ?? 1]);
    Response::json(201, ['status'=>'enrolled','member_id'=>$memberId,'version'=>$version ?? 1]);
  }
  if (count($segments) === 3 && ctype_digit($segments[1]) && $segments[2] === 'face' && $method === 'DELETE') {
    $u = require_role(['Admin','Clerk','Leader']);
    $memberId = intval($segments[1]);
    $stmt = $pdo->prepare('SELECT * FROM face_templates WHERE member_id=?');
    $stmt->execute([$memberId]);
    $before = $stmt->fetch();
    $stmt = $pdo->prepare('DELETE FROM face_templates WHERE member_id=?');
    $stmt->execute([$memberId]);
    Audit::log($u['id'], 'delete', 'face_templates', $memberId, $before, null);
    Response::json(204, []);
  }
  if ($method === 'GET' && count($segments) === 1) {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, intval($_GET['pageSize'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    if ($q !== '') {
      $like = '%' . $q . '%';
      $stmt = $pdo->prepare('SELECT SQL_CALC_FOUND_ROWS id, first_name, middle_name, last_name, status FROM members WHERE CONCAT(first_name," ",last_name) LIKE ? OR last_name LIKE ? OR first_name LIKE ? ORDER BY last_name, first_name LIMIT ? OFFSET ?');
      $stmt->bindValue(1, $like);
      $stmt->bindValue(2, $like);
      $stmt->bindValue(3, $like);
      $stmt->bindValue(4, $pageSize, \PDO::PARAM_INT);
      $stmt->bindValue(5, $offset, \PDO::PARAM_INT);
      $stmt->execute();
    } else {
      $stmt = $pdo->prepare('SELECT SQL_CALC_FOUND_ROWS id, first_name, middle_name, last_name, status FROM members ORDER BY last_name, first_name LIMIT ? OFFSET ?');
      $stmt->bindValue(1, $pageSize, \PDO::PARAM_INT);
      $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
      $stmt->execute();
    }
    $items = $stmt->fetchAll();
    $total = $pdo->query('SELECT FOUND_ROWS() as t')->fetch()['t'] ?? 0;
    Response::json(200, ['items' => $items, 'total' => intval($total), 'page' => $page, 'pageSize' => $pageSize]);
  }
  if ($method === 'POST' && count($segments) === 1) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $first = trim($input['first_name'] ?? '');
    $last = trim($input['last_name'] ?? '');
    $status = $input['status'] ?? 'active';
    if ($first === '' || $last === '') Response::error(400, 'validation_error', 'first_name and last_name required');
    $stmt = $pdo->prepare('INSERT INTO members (first_name, last_name, status, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())');
    $stmt->execute([$first, $last, $status]);
    Response::json(201, ['id' => intval($pdo->lastInsertId())]);
  }
  if (count($segments) === 2 && ctype_digit($segments[1])) {
    $id = intval($segments[1]);
    if ($method === 'GET') {
      $stmt = $pdo->prepare('SELECT * FROM members WHERE id=?');
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) Response::error(404, 'not_found', 'member not found');
      Response::json(200, $row);
    }
    if ($method === 'PUT') {
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $fields = ['first_name','middle_name','last_name','suffix','birthdate','gender','contact_no','email','address_line','barangay','city','province','postal_code','status'];
      $sets = [];
      $values = [];
      foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
          $sets[] = "$f=?";
          $values[] = $input[$f];
        }
      }
      if (empty($sets)) Response::error(400, 'validation_error', 'no updatable fields');
      $values[] = $id;
      $sql = 'UPDATE members SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($values);
      Response::json(200, ['id' => $id]);
    }
    if ($method === 'DELETE') {
      $stmt = $pdo->prepare('DELETE FROM members WHERE id=?');
      $stmt->execute([$id]);
      Response::json(204, []);
    }
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'attendance' && isset($segments[1]) && $segments[1] === 'events') {
  require_db();
  require_role(['Admin','Clerk','Leader']);
  $pdo = Database::conn();
  if ($method === 'GET' && count($segments) === 2) {
    $date = $_GET['date'] ?? null;
    if ($date) {
      $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE date=? ORDER BY start_time');
      $stmt->execute([$date]);
    } else {
      $stmt = $pdo->query('SELECT * FROM attendance_events ORDER BY date DESC, start_time');
    }
    $items = $stmt->fetchAll();
    Response::json(200, ['items' => $items, 'total' => count($items)]);
  }
  if ($method === 'POST' && count($segments) === 2) {
    $u = require_role(['Admin','Clerk','Leader']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $name = trim($input['name'] ?? '');
    $type = $input['type'] ?? 'other';
    $date = $input['date'] ?? null;
    $start = $input['start_time'] ?? null;
    $end = $input['end_time'] ?? null;
    if ($name === '' || !$date) Response::error(400, 'validation_error', 'name and date required');
    $stmt = $pdo->prepare('INSERT INTO attendance_events (name,type,date,start_time,end_time,created_at) VALUES (?,?,?,?,?,NOW())');
    $stmt->execute([$name, $type, $date, $start, $end]);
    $eid = intval($pdo->lastInsertId());
    Audit::log($u['id'], 'create', 'attendance_events', $eid, null, ['id'=>$eid,'name'=>$name,'type'=>$type,'date'=>$date,'start_time'=>$start,'end_time'=>$end]);
    Response::json(201, ['id' => $eid]);
  }
  if (count($segments) === 3 && ctype_digit($segments[2])) {
    $id = intval($segments[2]);
    if ($method === 'GET') {
      $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE id=?');
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) Response::error(404, 'not_found', 'event not found');
      Response::json(200, $row);
    }
    if ($method === 'GET' && isset($segments[3]) && $segments[3] === 'logs') {
      $page = max(1, intval($_GET['page'] ?? 1));
      $pageSize = min(200, max(1, intval($_GET['pageSize'] ?? 50)));
      $offset = ($page - 1) * $pageSize;
      $stmt = $pdo->prepare('SELECT SQL_CALC_FOUND_ROWS al.* FROM attendance_logs al WHERE al.attendance_event_id=? ORDER BY al.timestamp ASC LIMIT ? OFFSET ?');
      $stmt->bindValue(1, $id, \PDO::PARAM_INT);
      $stmt->bindValue(2, $pageSize, \PDO::PARAM_INT);
      $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
      $stmt->execute();
      $items = $stmt->fetchAll();
      $total = $pdo->query('SELECT FOUND_ROWS() as t')->fetch()['t'] ?? 0;
      Response::json(200, ['items'=>$items,'total'=>intval($total),'page'=>$page,'pageSize'=>$pageSize]);
    }
    if ($method === 'POST' && isset($segments[3]) && $segments[3] === 'checkin' && isset($segments[4]) && $segments[4] === 'face') {
      $u = require_role(['Admin','Clerk','Leader']);
      $stmt = $pdo->prepare('SELECT id FROM attendance_events WHERE id=?');
      $stmt->execute([$id]);
      if (!$stmt->fetch()) Response::error(404, 'not_found', 'event not found');
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $img = $input['image_base64'] ?? '';
      if ($img === '') Response::error(400, 'validation_error', 'image_base64 required');
      $config = require __DIR__ . '/../config/config.php';
      $base = $config['face_service']['base_url'] ?? 'http://127.0.0.1:8001';
      try {
        $resp = \App\Http::postJson(rtrim($base, '/') . '/face/match', ['image_base64' => $img]);
      } catch (\Throwable $e) {
        Response::error(502, 'face_service_unavailable', 'face service error');
      }
      $memberId = isset($resp['member_id']) && $resp['member_id'] !== null ? intval($resp['member_id']) : 0;
      $confidence = isset($resp['confidence']) ? floatval($resp['confidence']) : null;
      if ($memberId <= 0) Response::error(404, 'face_no_match', 'no matching member');
      $stmt = $pdo->prepare('SELECT consent_flag FROM face_templates WHERE member_id=?');
      $stmt->execute([$memberId]);
      $tpl = $stmt->fetch();
      if (!$tpl || intval($tpl['consent_flag']) !== 1) Response::error(403, 'face_consent_missing', 'member has no active face template');
      $stmt = $pdo->prepare('SELECT id FROM attendance_logs WHERE attendance_event_id=? AND member_id=?');
      $stmt->execute([$id, $memberId]);
      if ($stmt->fetch()) Response::error(409, 'already_checked_in', 'member already logged for this event');
      $stmt = $pdo->prepare('INSERT INTO attendance_logs (attendance_event_id, member_id, method, timestamp, confidence, status) VALUES (?,?,?,?,?,?)');
      $stmt->execute([$id, $memberId, 'face', date('Y-m-d H:i:s'), $confidence, 'present']);
      $logId = intval($pdo->lastInsertId());
      Audit::log($u['id'], 'create', 'attendance_logs', $logId, null, ['attendance_event_id'=>$id,'member_id'=>$memberId,'status'=>'present','method'=>'face','confidence'=>$confidence]);
      Response::json(200, ['member_id'=>$memberId, 'confidence'=>$confidence, 'status'=>'present']);
    }
    if ($method === 'POST' && isset($segments[3]) && $segments[3] === 'checkin' && isset($segments[4]) && $segments[4] === 'manual') {
      $u = require_role(['Admin','Clerk','Leader']);
      $stmt = $pdo->prepare('SELECT id FROM attendance_events WHERE id=?');
      $stmt->execute([$id]);
      if (!$stmt->fetch()) Response::error(404, 'not_found', 'event not found');
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $memberId = isset($input['member_id']) ? intval($input['member_id']) : 0;
      $status = $input['status'] ?? 'present';
      $allowedStatuses = ['present','late','excused'];
      if ($memberId <= 0 || !in_array($status, $allowedStatuses)) Response::error(400, 'validation_error', 'member_id and valid status required');
      $stmt = $pdo->prepare('SELECT id FROM members WHERE id=?');
      $stmt->execute([$memberId]);
      if (!$stmt->fetch()) Response::error(404, 'not_found', 'member not found');
      $stmt = $pdo->prepare('SELECT id FROM attendance_logs WHERE attendance_event_id=? AND member_id=?');
      $stmt->execute([$id, $memberId]);
      if ($stmt->fetch()) Response::error(409, 'already_checked_in', 'member already logged for this event');
      $stmt = $pdo->prepare('INSERT INTO attendance_logs (attendance_event_id, member_id, method, timestamp, confidence, status) VALUES (?,?,?,?,NULL,?)');
      $stmt->execute([$id, $memberId, 'manual', date('Y-m-d H:i:s'), $status]);
      $logId = intval($pdo->lastInsertId());
      Audit::log($u['id'], 'create', 'attendance_logs', $logId, null, ['attendance_event_id'=>$id,'member_id'=>$memberId,'status'=>$status,'method'=>'manual']);
      Response::json(201, ['id'=>$logId]);
    }
    if ($method === 'PUT') {
      $u = require_role(['Admin','Clerk','Leader']);
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $fields = ['name','type','date','start_time','end_time'];
      $sets = [];
      $values = [];
      $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
          $sets[] = "$f=?";
          $values[] = $input[$f];
        }
      }
      if (empty($sets)) Response::error(400, 'validation_error', 'no updatable fields');
      $values[] = $id;
      $sql = 'UPDATE attendance_events SET ' . implode(',', $sets) . ' WHERE id=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($values);
      $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE id=?');
      $stmt->execute([$id]);
      $after = $stmt->fetch();
      Audit::log($u['id'], 'update', 'attendance_events', $id, $before, $after);
      Response::json(200, ['id' => $id]);
    }
    if ($method === 'DELETE') {
      $u = require_role(['Admin','Clerk','Leader']);
      $stmt = $pdo->prepare('SELECT * FROM attendance_events WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $stmt = $pdo->prepare('DELETE FROM attendance_events WHERE id=?');
      $stmt->execute([$id]);
      Audit::log($u['id'], 'delete', 'attendance_events', $id, $before, null);
      Response::json(204, []);
    }
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'funds') {
  require_db();
  require_role(['Admin','Treasurer']);
  $pdo = Database::conn();
  if ($method === 'GET' && count($segments) === 1) {
    $stmt = $pdo->query('SELECT * FROM funds ORDER BY name');
    $items = $stmt->fetchAll();
    Response::json(200, ['items' => $items, 'total' => count($items)]);
  }
  if ($method === 'POST' && count($segments) === 1) {
    $u = require_role(['Admin','Treasurer']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $name = trim($input['name'] ?? '');
    $desc = $input['description'] ?? null;
    if ($name === '') Response::error(400, 'validation_error', 'name required');
    $stmt = $pdo->prepare('INSERT INTO funds (name, description) VALUES (?,?)');
    $stmt->execute([$name, $desc]);
    $id = intval($pdo->lastInsertId());
    Audit::log($u['id'], 'create', 'funds', $id, null, ['id'=>$id,'name'=>$name,'description'=>$desc]);
    Response::json(201, ['id' => $id]);
  }
  if (count($segments) === 2 && ctype_digit($segments[1])) {
    $id = intval($segments[1]);
    if ($method === 'GET') {
      $stmt = $pdo->prepare('SELECT * FROM funds WHERE id=?');
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) Response::error(404, 'not_found', 'fund not found');
      Response::json(200, $row);
    }
    if ($method === 'PUT') {
      $u = require_role(['Admin','Treasurer']);
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $stmt = $pdo->prepare('SELECT * FROM funds WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $name = array_key_exists('name',$input) ? $input['name'] : $before['name'];
      $desc = array_key_exists('description',$input) ? $input['description'] : $before['description'];
      $stmt = $pdo->prepare('UPDATE funds SET name=?, description=? WHERE id=?');
      $stmt->execute([$name, $desc, $id]);
      $stmt = $pdo->prepare('SELECT * FROM funds WHERE id=?');
      $stmt->execute([$id]);
      $after = $stmt->fetch();
      Audit::log($u['id'], 'update', 'funds', $id, $before, $after);
      Response::json(200, ['id' => $id]);
    }
    if ($method === 'DELETE') {
      $u = require_role(['Admin','Treasurer']);
      $stmt = $pdo->prepare('SELECT * FROM funds WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $stmt = $pdo->prepare('DELETE FROM funds WHERE id=?');
      $stmt->execute([$id]);
      Audit::log($u['id'], 'delete', 'funds', $id, $before, null);
      Response::json(204, []);
    }
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'contributions') {
  require_db();
  require_role(['Admin','Treasurer']);
  $pdo = Database::conn();
  if ($method === 'GET' && count($segments) === 1) {
    $clauses = [];
    $params = [];
    if (isset($_GET['member_id']) && ctype_digit($_GET['member_id'])) { $clauses[] = 'member_id=?'; $params[] = intval($_GET['member_id']); }
    if (isset($_GET['fund_id']) && ctype_digit($_GET['fund_id'])) { $clauses[] = 'fund_id=?'; $params[] = intval($_GET['fund_id']); }
    if (isset($_GET['from'])) { $clauses[] = 'date>=?'; $params[] = $_GET['from']; }
    if (isset($_GET['to'])) { $clauses[] = 'date<=?'; $params[] = $_GET['to']; }
    $where = empty($clauses) ? '' : ' WHERE ' . implode(' AND ', $clauses);
    $stmt = $pdo->prepare('SELECT * FROM contributions' . $where . ' ORDER BY date DESC, id DESC');
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    Response::json(200, ['items' => $items, 'total' => count($items)]);
  }
  if ($method === 'POST' && count($segments) === 1) {
    $u = require_role(['Admin','Treasurer']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $memberId = isset($input['member_id']) && $input['member_id'] !== null ? intval($input['member_id']) : null;
    $fundId = isset($input['fund_id']) ? intval($input['fund_id']) : 0;
    $amount = isset($input['amount']) ? floatval($input['amount']) : null;
    $date = $input['date'] ?? null;
    $ref = $input['reference_no'] ?? null;
    if ($fundId <= 0 || $amount === null || !$date) Response::error(400, 'validation_error', 'fund_id, amount, date required');
    $stmt = $pdo->prepare('INSERT INTO contributions (member_id,fund_id,amount,date,reference_no,recorded_by,created_at) VALUES (?,?,?,?,?,?,NOW())');
    $stmt->execute([$memberId, $fundId, $amount, $date, $ref, $u['id']]);
    $id = intval($pdo->lastInsertId());
    Audit::log($u['id'], 'create', 'contributions', $id, null, ['id'=>$id,'member_id'=>$memberId,'fund_id'=>$fundId,'amount'=>$amount,'date'=>$date,'reference_no'=>$ref]);
    Response::json(201, ['id' => $id]);
  }
  if (count($segments) === 2 && ctype_digit($segments[1])) {
    $id = intval($segments[1]);
    if ($method === 'GET') {
      $stmt = $pdo->prepare('SELECT * FROM contributions WHERE id=?');
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) Response::error(404, 'not_found', 'contribution not found');
      Response::json(200, $row);
    }
    if ($method === 'DELETE') {
      $u = require_role(['Admin','Treasurer']);
      $stmt = $pdo->prepare('SELECT * FROM contributions WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $stmt = $pdo->prepare('DELETE FROM contributions WHERE id=?');
      $stmt->execute([$id]);
      Audit::log($u['id'], 'delete', 'contributions', $id, $before, null);
      Response::json(204, []);
    }
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'reports' && isset($segments[1]) && $segments[1] === 'finance' && isset($segments[2]) && $segments[2] === 'period') {
  require_db();
  require_role(['Admin','Treasurer']);
  $pdo = Database::conn();
  if ($method === 'GET') {
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    if (!$from || !$to) Response::error(400, 'validation_error', 'from and to required');
    $stmt = $pdo->prepare('SELECT f.id as fund_id, f.name as fund_name, SUM(c.amount) as total FROM contributions c JOIN funds f ON c.fund_id=f.id WHERE c.date BETWEEN ? AND ? GROUP BY f.id, f.name ORDER BY f.name');
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll();
    Response::json(200, ['from'=>$from,'to'=>$to,'totals'=>$rows]);
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'lessons') {
  require_db();
  require_role(['Admin','Leader']);
  $pdo = Database::conn();
  if ($method === 'GET' && count($segments) === 1) {
    $clauses = [];
    $params = [];
    if (isset($_GET['category'])) { $clauses[] = 'category=?'; $params[] = $_GET['category']; }
    if (isset($_GET['week_no'])) { $clauses[] = 'week_no=?'; $params[] = intval($_GET['week_no']); }
    if (isset($_GET['date'])) { $clauses[] = 'date=?'; $params[] = $_GET['date']; }
    $where = empty($clauses) ? '' : ' WHERE ' . implode(' AND ', $clauses);
    $stmt = $pdo->prepare('SELECT * FROM lessons' . $where . ' ORDER BY date DESC, id DESC');
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    Response::json(200, ['items'=>$items,'total'=>count($items)]);
  }
  if ($method === 'POST' && count($segments) === 1) {
    $u = require_role(['Admin','Leader']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $title = trim($input['title'] ?? '');
    $category = $input['category'] ?? null;
    $weekNo = isset($input['week_no']) ? intval($input['week_no']) : null;
    $date = $input['date'] ?? null;
    $content = $input['content'] ?? null;
    if ($title === '' || !$category) Response::error(400, 'validation_error', 'title and category required');
    $stmt = $pdo->prepare('INSERT INTO lessons (title,category,week_no,date,content,published_at,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
    $publishedAt = isset($input['published_at']) ? $input['published_at'] : null;
    $stmt->execute([$title,$category,$weekNo,$date,$content,$publishedAt,$u['id']]);
    $id = intval($pdo->lastInsertId());
    Audit::log($u['id'], 'create', 'lessons', $id, null, ['id'=>$id,'title'=>$title,'category'=>$category]);
    Response::json(201, ['id'=>$id]);
  }
  if (count($segments) === 2 && ctype_digit($segments[1])) {
    $id = intval($segments[1]);
    if ($method === 'GET') {
      $stmt = $pdo->prepare('SELECT * FROM lessons WHERE id=?');
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) Response::error(404, 'not_found', 'lesson not found');
      Response::json(200, $row);
    }
    if ($method === 'PUT') {
      $u = require_role(['Admin','Leader']);
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $stmt = $pdo->prepare('SELECT * FROM lessons WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $fields = ['title','category','week_no','date','file_uri','content','published_at'];
      $sets = [];
      $values = [];
      foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
          $sets[] = "$f=?";
          $values[] = $input[$f];
        }
      }
      if (empty($sets)) Response::error(400, 'validation_error', 'no updatable fields');
      $values[] = $id;
      $sql = 'UPDATE lessons SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($values);
      $stmt = $pdo->prepare('SELECT * FROM lessons WHERE id=?');
      $stmt->execute([$id]);
      $after = $stmt->fetch();
      Audit::log($u['id'], 'update', 'lessons', $id, $before, $after);
      Response::json(200, ['id'=>$id]);
    }
    if ($method === 'DELETE') {
      $u = require_role(['Admin','Leader']);
      $stmt = $pdo->prepare('SELECT * FROM lessons WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $stmt = $pdo->prepare('DELETE FROM lessons WHERE id=?');
      $stmt->execute([$id]);
      Audit::log($u['id'], 'delete', 'lessons', $id, $before, null);
      Response::json(204, []);
    }
  }
  if (count($segments) === 3 && ctype_digit($segments[1]) && $segments[2] === 'file') {
    $u = require_role(['Admin','Leader']);
    if ($method !== 'POST') {
      Response::error(405, 'method_not_allowed', 'unsupported method or route');
    }
    $id = intval($segments[1]);
    $stmt = $pdo->prepare('SELECT * FROM lessons WHERE id=?');
    $stmt->execute([$id]);
    $lesson = $stmt->fetch();
    if (!$lesson) Response::error(404, 'not_found', 'lesson not found');
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
      Response::error(400, 'validation_error', 'file is required');
    }
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
      Response::error(400, 'upload_error', 'failed to upload file');
    }
    $config = require __DIR__ . '/../config/config.php';
    $dir = $config['uploads']['lessons'] ?? (__DIR__ . '/../uploads/lessons');
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $maxSize = 10 * 1024 * 1024;
    if ($_FILES['file']['size'] > $maxSize) {
      Response::error(400, 'validation_error', 'file too large');
    }
    $allowed = ['pdf','doc','docx','ppt','pptx','txt','jpg','jpeg','png'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
      Response::error(400, 'validation_error', 'unsupported file type');
    }
    $safeName = time() . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safeName;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
      Response::error(500, 'upload_error', 'could not save file');
    }
    $fileUri = '/uploads/lessons/' . $safeName;
    $stmt = $pdo->prepare('UPDATE lessons SET file_uri=?, updated_at=NOW() WHERE id=?');
    $stmt->execute([$fileUri, $id]);
    Audit::log($u['id'], 'update', 'lessons', $id, ['file_uri'=>$lesson['file_uri']], ['file_uri'=>$fileUri]);
    Response::json(200, ['id'=>$id, 'file_uri'=>$fileUri]);
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'events' && (!isset($segments[1]) || ctype_digit($segments[1]))) {
  require_db();
  require_role(['Admin','Leader','Clerk']);
  $pdo = Database::conn();
  if ($method === 'GET' && count($segments) === 1) {
    $stmt = $pdo->query('SELECT * FROM events ORDER BY start_datetime DESC, id DESC');
    $items = $stmt->fetchAll();
    Response::json(200, ['items'=>$items,'total'=>count($items)]);
  }
  if ($method === 'POST' && count($segments) === 1) {
    $u = require_role(['Admin','Leader','Clerk']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $title = trim($input['title'] ?? '');
    $start = $input['start_datetime'] ?? null;
    $end = $input['end_datetime'] ?? null;
    $desc = $input['description'] ?? null;
    $rr = $input['recurrence_rule'] ?? null;
    $aud = $input['audience'] ?? null;
    $loc = $input['location'] ?? null;
    if ($title === '' || !$start) Response::error(400, 'validation_error', 'title and start_datetime required');
    $stmt = $pdo->prepare('INSERT INTO events (title,description,start_datetime,end_datetime,recurrence_rule,audience,location,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([$title,$desc,$start,$end,$rr,$aud,$loc,$u['id']]);
    $id = intval($pdo->lastInsertId());
    Audit::log($u['id'], 'create', 'events', $id, null, ['id'=>$id,'title'=>$title]);
    Response::json(201, ['id'=>$id]);
  }
  if (count($segments) === 2 && ctype_digit($segments[1])) {
    $id = intval($segments[1]);
    if ($method === 'GET') {
      $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) Response::error(404, 'not_found', 'event not found');
      Response::json(200, $row);
    }
    if ($method === 'PUT') {
      $u = require_role(['Admin','Leader','Clerk']);
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $fields = ['title','description','start_datetime','end_datetime','recurrence_rule','audience','location'];
      $sets = [];
      $values = [];
      foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
          $sets[] = "$f=?";
          $values[] = $input[$f];
        }
      }
      if (empty($sets)) Response::error(400, 'validation_error', 'no updatable fields');
      $values[] = $id;
      $sql = 'UPDATE events SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($values);
      $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
      $stmt->execute([$id]);
      $after = $stmt->fetch();
      Audit::log($u['id'], 'update', 'events', $id, $before, $after);
      Response::json(200, ['id'=>$id]);
    }
    if ($method === 'DELETE') {
      $u = require_role(['Admin','Leader','Clerk']);
      $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $stmt = $pdo->prepare('DELETE FROM events WHERE id=?');
      $stmt->execute([$id]);
      Audit::log($u['id'], 'delete', 'events', $id, $before, null);
      Response::json(204, []);
    }
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
if ($segments[0] === 'announcements') {
  require_db();
  require_role(['Admin','Leader','Clerk']);
  $pdo = Database::conn();
  if ($method === 'GET' && count($segments) === 1) {
    $stmt = $pdo->query('SELECT * FROM announcements ORDER BY publish_from DESC, id DESC');
    $items = $stmt->fetchAll();
    Response::json(200, ['items'=>$items,'total'=>count($items)]);
  }
  if ($method === 'POST' && count($segments) === 1) {
    $u = require_role(['Admin','Leader','Clerk']);
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
    $title = trim($input['title'] ?? '');
    $body = trim($input['body'] ?? '');
    $aud = $input['audience'] ?? null;
    $from = $input['publish_from'] ?? null;
    $to = $input['publish_to'] ?? null;
    $channel = $input['channel'] ?? 'board';
    if ($title === '' || $body === '') Response::error(400, 'validation_error', 'title and body required');
    $stmt = $pdo->prepare('INSERT INTO announcements (title,body,audience,publish_from,publish_to,channel,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())');
    $stmt->execute([$title,$body,$aud,$from,$to,$channel,$u['id']]);
    $id = intval($pdo->lastInsertId());
    Audit::log($u['id'], 'create', 'announcements', $id, null, ['id'=>$id,'title'=>$title]);
    Response::json(201, ['id'=>$id]);
  }
  if (count($segments) === 2 && ctype_digit($segments[1])) {
    $id = intval($segments[1]);
    if ($method === 'GET') {
      $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id=?');
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      if (!$row) Response::error(404, 'not_found', 'announcement not found');
      Response::json(200, $row);
    }
    if ($method === 'PUT') {
      $u = require_role(['Admin','Leader','Clerk']);
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) $input = [];
      $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $fields = ['title','body','audience','publish_from','publish_to','channel'];
      $sets = [];
      $values = [];
      foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
          $sets[] = "$f=?";
          $values[] = $input[$f];
        }
      }
      if (empty($sets)) Response::error(400, 'validation_error', 'no updatable fields');
      $values[] = $id;
      $sql = 'UPDATE announcements SET ' . implode(',', $sets) . ', updated_at=NOW() WHERE id=?';
      $stmt = $pdo->prepare($sql);
      $stmt->execute($values);
      $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id=?');
      $stmt->execute([$id]);
      $after = $stmt->fetch();
      Audit::log($u['id'], 'update', 'announcements', $id, $before, $after);
      Response::json(200, ['id'=>$id]);
    }
    if ($method === 'DELETE') {
      $u = require_role(['Admin','Leader','Clerk']);
      $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id=?');
      $stmt->execute([$id]);
      $before = $stmt->fetch();
      $stmt = $pdo->prepare('DELETE FROM announcements WHERE id=?');
      $stmt->execute([$id]);
      Audit::log($u['id'], 'delete', 'announcements', $id, $before, null);
      Response::json(204, []);
    }
  }
  Response::error(405, 'method_not_allowed', 'unsupported method or route');
}
Response::error(404, 'not_found', 'route not found');
