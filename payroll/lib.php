<?php
// 給与・シフトアプリ 共通ヘルパー / レイアウト / CSRF。
// 前提：auth.php を先に require（session_start / current_user / h / db）。

// ------------------------------------------------------------
// 設定（config.php）
// ------------------------------------------------------------
function app_config() {
  static $c = null;
  if ($c === null) { $c = require __DIR__ . '/config.php'; }
  return $c;
}
function config_value($key, $default = '') {
  $c = app_config();
  return $c[$key] ?? $default;
}

// ------------------------------------------------------------
// カラー（ColorHRM と共通の定義）
// ------------------------------------------------------------
function color_ranks() {
  return ['WHITE', 'GREEN', 'BLUE', 'YELLOW', 'RED'];
}
function color_style($c) {
  $map = [
    'WHITE'  => 'background:#e0e0e0;color:#555',
    'GREEN'  => 'background:#c8e6c9;color:#2e7d32',
    'BLUE'   => 'background:#bbdefb;color:#1565c0',
    'YELLOW' => 'background:#fff9c4;color:#f57f17',
    'RED'    => 'background:#ffcdd2;color:#c62828',
  ];
  return $map[$c] ?? 'background:#eee;color:#555';
}

// 講師の時給（カラー×部門）：所属部門のうち最も高い授業時給を採用（GAS classRateFor 移植）。
// 運営時給は一律 1031 を基本とするが、pay_rates の ops_rate を尊重。
function compute_class_rate($staff) {
  $color = $staff['color_rank'] ?? '';
  $depts = array_values(array_filter(array_map('trim', explode(',', (string)($staff['departments'] ?? '')))));
  if ($color === '' || !$depts) return ['class_rate' => 1031, 'ops_rate' => 1031];
  try {
    $in = implode(',', array_fill(0, count($depts), '?'));
    $q = db()->prepare("SELECT MAX(class_rate) cr, MAX(ops_rate) opr FROM pay_rates WHERE tenant_id=1 AND color=? AND department IN ($in)");
    $q->execute(array_merge([$color], $depts));
    $r = $q->fetch();
    return ['class_rate' => (int)($r['cr'] ?: 1031), 'ops_rate' => (int)($r['opr'] ?: 1031)];
  } catch (Throwable $e) {
    return ['class_rate' => 1031, 'ops_rate' => 1031];
  }
}

// ------------------------------------------------------------
// 権限
// ------------------------------------------------------------
function require_role($roles) {
  $u = current_user();
  $roles = (array)$roles;
  if (!$u || !in_array($u['role'] ?? '', $roles, true)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8">'
       . '<div style="font-family:sans-serif;padding:2rem">権限がありません。 '
       . '<a href="index.php">戻る</a></div>';
    exit;
  }
}

// ------------------------------------------------------------
// CSRF（ColorHRM と同じセッションキー）
// ------------------------------------------------------------
function csrf_token() {
  if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
  return $_SESSION['csrf'];
}
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function csrf_check() {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    http_response_code(400);
    exit('不正なリクエストです（CSRFトークン不一致）。');
  }
}

// ------------------------------------------------------------
// レイアウト（給与アプリ専用ナビ）
// ------------------------------------------------------------
function nav_links_for($user) {
  $role  = $user['role'] ?? '';
  $links = [];
  if ($role === 'teacher') {
    $links['shifts.php'] = 'シフト申請';
  }
  if ($role === 'admin' || $role === 'staff') {
    $links['index.php']        = 'ダッシュボード';
    $links['shifts_admin.php'] = 'シフト管理';
  }
  if ($role === 'admin') {
    $links['rates.php'] = '時給表';
  }
  return $links;
}

function render_header($title, $user, $active = '') {
  $role  = $user['role'] ?? '';
  $links = nav_links_for($user);
  $colorhrm = config_value('colorhrm_url', '/colorhrm/');
  echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
  echo '</head><body class="bg-light">';
  echo '<nav class="navbar navbar-dark bg-success px-3">';
  echo '<span class="navbar-brand">💴 給与・シフト</span>';
  echo '<div class="ms-3 me-auto">';
  foreach ($links as $href => $label) {
    $cls = ($href === $active) ? 'btn btn-sm btn-light me-1' : 'btn btn-sm btn-outline-light me-1';
    echo '<a href="' . h($href) . '" class="' . $cls . '">' . h($label) . '</a>';
  }
  echo '<a href="' . h($colorhrm) . '" class="btn btn-sm btn-outline-light me-1">🎓 ColorHRMへ</a>';
  echo '</div>';
  echo '<span class="text-white-50 me-3 small">'
     . h(($user['display_name'] ?? '') ?: ($user['email'] ?? '')) . '（' . h($role) . '）</span>';
  echo '<a href="logout.php" class="btn btn-sm btn-outline-light">ログアウト</a>';
  echo '</nav>';
}

function render_footer() { echo '</body></html>'; }

// ------------------------------------------------------------
// シフト時間ヘルパー
// ------------------------------------------------------------
// 開始〜終了（"HH:MM" or "HH:MM:SS"）の稼働分。終了が開始以下なら0。
function shift_minutes($start, $end) {
  $s = strtotime('1970-01-01 ' . $start);
  $e = strtotime('1970-01-01 ' . $end);
  if ($s === false || $e === false || $e <= $s) return 0;
  return (int) round(($e - $s) / 60);
}
// 分 → "H:MM"
function fmt_hm($mins) {
  $mins = max(0, (int)$mins);
  return sprintf('%d:%02d', intdiv($mins, 60), $mins % 60);
}
// "HH:MM:SS" → "HH:MM"
function hm($t) { return substr((string)$t, 0, 5); }
// 月文字列（YYYY-MM）を検証し、不正なら当月を返す
function valid_month($m) {
  return preg_match('/^\d{4}-\d{2}$/', (string)$m) ? $m : date('Y-m');
}
