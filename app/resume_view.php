<?php
// 履歴書画像の認証付き配信。
// 直URLでは見せず（uploads/resumes/ は .htaccess で遮断）、ここを通してのみ閲覧可能。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT resume_file FROM candidates WHERE id = ? LIMIT 1");
$st->execute([$id]);
$file = (string)$st->fetchColumn();

if ($file === '') {
  http_response_code(404);
  exit('履歴書がありません。');
}

// パストラバーサル防止：保存名のみ許可
$file = basename($file);
$path = resume_dir() . '/' . $file;
if (!is_file($path)) {
  http_response_code(404);
  exit('ファイルが見つかりません。');
}

$ext   = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = resume_allowed_ext();
$mime  = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $file . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
