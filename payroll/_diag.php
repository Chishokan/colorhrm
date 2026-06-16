<?php
// 一時診断ページ（原因特定後に削除する）。機密値（パスワード等）は表示しない。
header('Content-Type: text/plain; charset=utf-8');
echo "=== payroll diag ===\n";
echo "PHP: " . PHP_VERSION . "\n";

$cfgPath = __DIR__ . '/config.php';
echo "config.php exists: " . (file_exists($cfgPath) ? 'YES' : 'NO') . "\n";
if (!file_exists($cfgPath)) {
  echo "→ config.php がこのフォルダに見つかりません。colorhrm-pay 直下に置いてください。\n";
  exit;
}

$c = require $cfgPath;
if (!is_array($c)) { echo "→ config.php が配列を return していません（構文を確認）。\n"; exit; }

foreach (['db_host','db_name','db_user','db_pass','db_charset'] as $k) {
  $v = $c[$k] ?? '(未設定)';
  if ($k === 'db_pass') { $v = ($v === '' ? '(空)' : '(設定あり・伏字)'); }
  // テンプレ初期値が残っていないか検査
  $placeholder = in_array($v, ['あなたのDB名','あなたのDBユーザー名','あなたのDBパスワード','mysqlXXXX.xserver.jp'], true) ? '  ★テンプレ初期値のまま！' : '';
  echo sprintf("  %-10s = %s%s\n", $k, $v, $placeholder);
}

echo "--- DB接続テスト ---\n";
try {
  $dsn = "mysql:host={$c['db_host']};dbname={$c['db_name']};charset={$c['db_charset']}";
  $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  $n = $pdo->query("SELECT COUNT(*) FROM pay_rates")->fetchColumn();
  echo "接続OK。pay_rates 件数 = {$n}\n";
  $u = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  echo "users 件数 = {$u}\n";
  echo "→ 接続成功。500の原因はDB接続ではありません。\n";
} catch (Throwable $e) {
  echo "接続NG: " . $e->getMessage() . "\n";
  echo "→ db_host / db_name / db_user / db_pass のいずれかが ColorHRM と一致していない可能性が高いです。\n";
}
