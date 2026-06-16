<?php
// 講師の顔写真の認証付き配信。?id=staff_id。
//   admin/staff は全員、teacher は自分の写真のみ閲覧可。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
$user = current_user();

$sid = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT id, photo_file FROM staff WHERE id = ? LIMIT 1");
$st->execute([$sid]);
$staff = $st->fetch();

$role = $user['role'] ?? '';
$allowed = in_array($role, ['admin', 'staff'], true)
        || ($role === 'teacher' && (int)($user['staff_id'] ?? 0) === $sid);

if (!$staff || empty($staff['photo_file']) || !$allowed) {
  http_response_code(404);
  exit('Not found');
}
$path = photo_dir() . '/' . basename($staff['photo_file']);
if (!is_file($path)) { http_response_code(404); exit('Not found'); }

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=300');
readfile($path);
