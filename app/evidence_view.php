<?php
// テスト証跡画像の認証付き配信。admin/staff（メンター）と本人のみ。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
$user = current_user();

$pid = (int)($_GET['id'] ?? 0); // training_progress.id
$st = db()->prepare("SELECT staff_id, evidence_file FROM training_progress WHERE id = ? LIMIT 1");
$st->execute([$pid]);
$row = $st->fetch();
if (!$row || $row['evidence_file'] === '') { http_response_code(404); exit('証跡がありません。'); }

// 権限：admin/staff か、本人（teacher の staff_id 一致）
$role = $user['role'] ?? '';
$isOwner = ($user['staff_id'] ?? null) && (int)$user['staff_id'] === (int)$row['staff_id'];
if (!in_array($role, ['admin', 'staff'], true) && !$isOwner) { http_response_code(403); exit('権限がありません。'); }

$file = basename($row['evidence_file']);
$path = evidence_dir() . '/' . $file;
if (!is_file($path)) { http_response_code(404); exit('ファイルが見つかりません。'); }
$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = resume_allowed_ext()[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $file . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
