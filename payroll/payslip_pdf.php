<?php
// 給与明細PDFの認証付き配信（?id=payslip_id）。
//   admin/staff は全員、teacher は自分の明細のみ。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();

if (!payslips_table_exists()) { http_response_code(404); exit('明細がありません。'); }
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT p.*, s.name AS staff_name FROM payslips p JOIN staff s ON s.id = p.staff_id WHERE p.id = ? LIMIT 1");
$st->execute([$id]);
$slip = $st->fetch();
if (!$slip) { http_response_code(404); exit('明細が見つかりません。'); }

$role = $user['role'] ?? '';
$own  = (int)($user['staff_id'] ?? 0) === (int)$slip['staff_id'];
if (!in_array($role, ['admin', 'staff'], true) && !$own) { http_response_code(403); exit('権限がありません。'); }

$pdf = build_payslip_pdf($slip, $slip['staff_name']);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="payslip_' . $slip['month'] . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $pdf;
