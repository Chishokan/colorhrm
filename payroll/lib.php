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
    $links['shifts.php'] = 'シフト可能登録';
  }
  if ($role === 'admin' || $role === 'staff') {
    $links['index.php']        = 'ダッシュボード';
    $links['shifts_admin.php'] = 'シフト管理';
    $links['payroll.php']      = '給与計算';
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

// 曜日（日本語）
function jp_weekdays() { return ['日', '月', '火', '水', '木', '金', '土']; }

// 指定月の各日 [['date'=>'Y-m-d','day'=>int,'dow'=>0..6], ...]
function month_days($month) {
  $month = valid_month($month);
  $n = (int)date('t', strtotime($month . '-01'));
  $out = [];
  for ($d = 1; $d <= $n; $d++) {
    $date = sprintf('%s-%02d', $month, $d);
    $out[] = ['date' => $date, 'day' => $d, 'dow' => (int)date('w', strtotime($date))];
  }
  return $out;
}

// シフト可能登録ができる月の範囲 [当月, 6か月先]
function shift_month_window() {
  return [date('Y-m'), date('Y-m', strtotime('first day of this month +6 month'))];
}

// 交通費（GAS版準拠）：勤務日数 ≤5 は 日数×200、超過は ceil(日数/5)×1000。
function transport_allowance($days) {
  $days = max(0, (int)$days);
  if ($days === 0) return 0;
  if ($days <= 5) return $days * 200;
  return (int) (ceil($days / 5) * 1000);
}

// 月次の給与計算。shift_days × pay_rates から講師ごとに集計して配列で返す。
//   返り値: [ ['staff'=>..., 'days'=>, 'class_min'=>, 'ops_min'=>,
//             'class_rate'=>, 'ops_rate'=>, 'class_pay'=>, 'ops_pay'=>,
//             'transport'=>, 'total'=>], ... ]（講師名順）
function compute_month_payroll($month) {
  // use_payroll 列の有無
  $hasUsePayroll = false;
  foreach (db()->query("SHOW COLUMNS FROM staff")->fetchAll() as $c) {
    if ($c['Field'] === 'use_payroll') { $hasUsePayroll = true; }
  }
  $sql = "SELECT s.id, s.name, s.color_rank, s.departments"
       . ($hasUsePayroll ? ", s.use_payroll" : "")
       . " , sd.work_date, sd.start_time, sd.end_time, sd.class_minutes"
       . " FROM shift_days sd JOIN staff s ON s.id = sd.staff_id"
       . " WHERE DATE_FORMAT(sd.work_date,'%Y-%m') = ?"
       . " ORDER BY s.name, sd.work_date";
  $q = db()->prepare($sql);
  $q->execute([$month]);

  $agg = [];
  foreach ($q->fetchAll() as $r) {
    $sid = (int)$r['id'];
    if (!isset($agg[$sid])) {
      $agg[$sid] = [
        'staff' => ['id' => $sid, 'name' => $r['name'], 'color_rank' => $r['color_rank'],
                    'departments' => $r['departments'],
                    'use_payroll' => $hasUsePayroll ? $r['use_payroll'] : 1],
        'days_set' => [], 'class_min' => 0, 'ops_min' => 0,
      ];
    }
    $worked = shift_minutes($r['start_time'], $r['end_time']);
    $cls = min((int)$r['class_minutes'], $worked);
    $agg[$sid]['class_min'] += $cls;
    $agg[$sid]['ops_min']   += ($worked - $cls);
    $agg[$sid]['days_set'][$r['work_date']] = true;
  }

  $out = [];
  foreach ($agg as $a) {
    $rate = compute_class_rate($a['staff']);
    $classPay = (int) round($a['class_min'] / 60 * $rate['class_rate']);
    $opsPay   = (int) round($a['ops_min']   / 60 * $rate['ops_rate']);
    $days     = count($a['days_set']);
    $transport = transport_allowance($days);
    $out[] = [
      'staff' => $a['staff'], 'days' => $days,
      'class_min' => $a['class_min'], 'ops_min' => $a['ops_min'],
      'class_rate' => $rate['class_rate'], 'ops_rate' => $rate['ops_rate'],
      'class_pay' => $classPay, 'ops_pay' => $opsPay,
      'transport' => $transport, 'total' => $classPay + $opsPay + $transport,
    ];
  }
  return $out;
}
