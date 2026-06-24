<?php
// 給与・シフトアプリ 共通ヘルパー / レイアウト / CSRF。
// 前提：auth.php を先に require（session_start / current_user / h / db）。
date_default_timezone_set('Asia/Tokyo'); // 打刻・当月判定を日本時間で

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

// ロゴ画像URL（config で差し替え可。既定は智翔館WordPress）
function logo_url() {
  return config_value('logo_url', 'https://chishokan.co.jp/wp/wp-content/uploads/2026/06/8532ffc6a3a5dcc6bbbf34f444229899.png');
}
function logo_mark_url() {
  return config_value('logo_mark_url', 'https://chishokan.co.jp/wp/wp-content/uploads/2026/06/e92512132610dd098d357f2155bf891a.png');
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
    $links['punch.php'] = '打刻';
    $links['shifts.php'] = 'シフト可能登録';
    $links['payslips.php'] = '給与明細';
    $links['help.php'] = 'ヘルプ';
  }
  if ($role === 'admin' || $role === 'staff') {
    $links['index.php']        = 'ダッシュボード';
    $links['shifts_matrix.php'] = 'シフト表';
    $links['shifts_admin.php'] = 'シフト管理';
    $links['payroll.php']      = '給与計算';
  }
  if ($role === 'admin') {
    $links['rates.php'] = '時給表';
  }
  return $links;
}

// admin/staff の左サイドバー用にメニューをグループ化（ColorHRMと同形式）
function nav_groups_for($user) {
  $role = $user['role'] ?? '';
  $g = [];
  $g[] = ['label' => '', 'items' => ['index.php' => 'ダッシュボード']];
  $g[] = ['label' => 'シフト', 'items' => [
    'shifts_matrix.php' => 'シフト表',
    'shifts_admin.php'  => 'シフト管理',
  ]];
  $pay = ['payroll.php' => '給与計算'];
  if ($role === 'admin') { $pay['rates.php'] = '時給表'; }
  $g[] = ['label' => '給与', 'items' => $pay];
  $g[] = ['label' => 'サポート', 'items' => ['help.php' => 'ヘルプ・使い方']];
  return $g;
}

// アプリ内ブラウザ（LINE WORKS 等）検知バナー。標準ブラウザでは何も表示しない。
//   ?inappdebug=1 を付けると強制表示＋UA を出す（検知調整用）。
function inapp_browser_banner() {
  ?>
<script>
(function(){
  try{
    var ua = navigator.userAgent || '';
    var loc = window.location;
    var debug = /[?&]inappdebug=1/.test(loc.search);
    var isAndroid = /Android/i.test(ua), isIOS = /iPhone|iPad|iPod/i.test(ua);
    var explicit = /(Line\/|LINEWORKS|LINE ?Works|NAVER\(inapp|FBAN|FBAV|FB_IAB|Instagram|MicroMessenger|KAKAOTALK)/i.test(ua);
    var androidWV = isAndroid && /; wv\)|\bwv\b/.test(ua);
    var iosWV = isIOS && /AppleWebKit/i.test(ua) && !/Safari/i.test(ua) && !/CriOS|FxiOS|EdgiOS/i.test(ua);
    if (!(explicit || androidWV || iosWV || debug)) return;
    if (document.getElementById('inapp-open-banner')) return;
    function el(tag, css, html){ var e=document.createElement(tag); if(css)e.style.cssText=css; if(html!=null)e.innerHTML=html; return e; }
    var bar = el('div','position:fixed;top:0;left:0;right:0;z-index:99999;background:#fff8e1;border-bottom:1px solid #ffe082;color:#5d4037;padding:10px 12px;font-size:13px;line-height:1.5;box-shadow:0 1px 4px rgba(0,0,0,.15)');
    bar.id='inapp-open-banner';
    var msg = el('div',null,'⚠ アプリ内ブラウザで開いています。表示が崩れる場合は標準ブラウザで開いてください。');
    if(debug){ msg.appendChild(el('div','margin-top:4px;color:#999;word-break:break-all','UA: '+ua)); }
    var btns = el('div','margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;align-items:center');
    var bOpen = el('button','background:#198754;color:#fff;border:0;border-radius:6px;padding:6px 12px;font-size:13px','ブラウザで開く');
    var bCopy = el('button','background:#fff;color:#333;border:1px solid #bbb;border-radius:6px;padding:6px 12px;font-size:13px','URLをコピー');
    var bClose = el('button','margin-left:auto;background:transparent;border:0;color:#888;font-size:18px;line-height:1;padding:0 6px','×');
    var tip = el('div','display:none;margin-top:6px;color:#8d6e63','開かない場合は、画面右上の「︙」や共有アイコンから「ブラウザで開く／既定のブラウザで開く」を選んでください。');
    function setPad(){ try{ document.body.style.paddingTop = bar.offsetHeight + 'px'; }catch(e){} }
    bOpen.onclick=function(){
      var scheme=(loc.protocol||'https:').replace(':','');
      var path=loc.pathname+loc.search;
      if(isAndroid){ window.location.href='intent://'+loc.host+path+'#Intent;scheme='+scheme+';end'; }
      else if(isIOS){ window.location.href='googlechrome'+(scheme==='https'?'s':'')+'://'+loc.host+path; }
      tip.style.display='block'; setPad();
    };
    bCopy.onclick=function(){
      var url=loc.href;
      var done=function(){ bCopy.textContent='コピー済み'; setTimeout(function(){bCopy.textContent='URLをコピー';},1500); };
      if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(url).then(done,function(){window.prompt('URLをコピーしてください', url);}); }
      else { window.prompt('URLをコピーしてください', url); }
    };
    bClose.onclick=function(){ bar.parentNode&&bar.parentNode.removeChild(bar); document.body.style.paddingTop=''; };
    btns.appendChild(bOpen); btns.appendChild(bCopy); btns.appendChild(bClose);
    bar.appendChild(msg); bar.appendChild(btns); bar.appendChild(tip);
    var attach=function(){ document.body.insertBefore(bar, document.body.firstChild); setPad(); };
    if(document.body) attach(); else document.addEventListener('DOMContentLoaded', attach);
  }catch(e){}
})();
</script>
  <?php
}

function render_header($title, $user, $active = '') {
  $role     = $user['role'] ?? '';
  $colorhrm = config_value('colorhrm_url', '/colorhrm/');
  $uname    = ($user['display_name'] ?? '') ?: ($user['email'] ?? '');
  $sidebar  = ($role === 'admin' || $role === 'staff'); // 講師は従来の上部メニュー
  $GLOBALS['payroll_layout'] = $sidebar ? 'sidebar' : 'top';

  echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
  if ($sidebar) {
    echo '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>';
    echo '<style>@media(min-width:992px){.pay-sidebar{position:sticky;top:0;align-self:flex-start;height:100vh;overflow-y:auto}}</style>';
  }
  echo '</head><body class="bg-light">';
  inapp_browser_banner();

  if (!$sidebar) {
    // ---- teacher：従来の上部メニュー（変更なし） ----
    $links = nav_links_for($user);
    echo '<nav class="navbar navbar-dark bg-success px-3">';
    if (logo_mark_url() !== '') {
      echo '<span class="navbar-brand d-flex align-items-center"><img src="' . h(logo_mark_url()) . '" alt="" style="height:30px" class="me-2">給与・シフト</span>';
    } else {
      echo '<span class="navbar-brand">💴 給与・シフト</span>';
    }
    echo '<div class="ms-3 me-auto d-flex flex-wrap gap-2 py-1">';
    foreach ($links as $href => $label) {
      $isActive = ($href === $active);
      // 講師は大きめのボタン。打刻は最頻出のため黄色で強調。
      if ($href === 'punch.php') {
        $cls = 'btn btn-warning fw-bold' . ($isActive ? ' active' : '');
      } else {
        $cls = $isActive ? 'btn btn-light fw-semibold' : 'btn btn-outline-light fw-semibold';
      }
      echo '<a href="' . h($href) . '" class="' . $cls . '">' . h($label) . '</a>';
    }
    echo '<a href="' . h($colorhrm) . '" class="btn btn-outline-light fw-semibold">🎓 ColorHRMへ</a>';
    echo '</div>';
    echo '<span class="text-white-50 me-3 small">' . h($uname) . '（' . h($role) . '）</span>';
    echo '<a href="logout.php" class="btn btn-sm btn-outline-light">ログアウト</a>';
    echo '</nav>';
    return;
  }

  // ---- admin / staff：左サイドバー（ColorHRMと同形式） ----
  $groups = nav_groups_for($user);
  echo '<nav class="navbar navbar-dark bg-success px-3">';
  echo '<button class="navbar-toggler d-lg-none border-0 me-2 p-1" type="button" data-bs-toggle="offcanvas" data-bs-target="#paySidebar" aria-label="メニュー"><span class="navbar-toggler-icon"></span></button>';
  if (logo_mark_url() !== '') {
    echo '<span class="navbar-brand d-flex align-items-center mb-0"><img src="' . h(logo_mark_url()) . '" alt="" style="height:28px" class="me-2">給与・シフト</span>';
  } else {
    echo '<span class="navbar-brand mb-0">💴 給与・シフト</span>';
  }
  echo '<div class="ms-auto d-flex align-items-center gap-2">';
  echo '<a href="' . h($colorhrm) . '" class="btn btn-sm btn-outline-light">🎓 ColorHRM</a>';
  echo '<span class="text-white-50 small d-none d-sm-inline">' . h($uname) . '（' . h($role) . '）</span>';
  echo '<a href="logout.php" class="btn btn-sm btn-outline-light">ログアウト</a>';
  echo '</div></nav>';
  echo '<div class="d-flex">';
  echo '<div class="offcanvas-lg offcanvas-start bg-white border-end pay-sidebar" tabindex="-1" id="paySidebar" style="width:230px">';
  echo '<div class="offcanvas-header d-lg-none"><span class="fw-bold">メニュー</span><button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#paySidebar"></button></div>';
  echo '<div class="offcanvas-body d-block p-2">';
  echo '<ul class="nav nav-pills flex-column mb-0">';
  foreach ($groups as $grp) {
    if (!empty($grp['label'])) {
      echo '<li class="nav-item mt-2 mb-1"><span class="text-muted small fw-bold px-2">' . h($grp['label']) . '</span></li>';
    }
    foreach ($grp['items'] as $href => $label) {
      $act = ($href === $active) ? ' active' : '';
      echo '<li class="nav-item"><a class="nav-link py-1' . $act . '" href="' . h($href) . '">' . h($label) . '</a></li>';
    }
  }
  echo '</ul></div></div>';
  echo '<main class="flex-grow-1" style="min-width:0">';
}

function render_footer() {
  if (($GLOBALS['payroll_layout'] ?? 'top') === 'sidebar') {
    echo '</main></div></body></html>';
  } else {
    echo '</body></html>';
  }
}

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

// ------------------------------------------------------------
// 給与明細（payslips）：発行スナップショット・PDF生成・通知メール
// ------------------------------------------------------------
// 通知メール等のリンク基点（payroll の app_base_url、無ければ実行URLから）
function payroll_base_url() {
  $u = config_value('app_base_url', '');
  if ($u !== '') return rtrim($u, '/') . '/';
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  return $scheme . '://' . $host . $dir . '/';
}

// payslips テーブルの有無（未マイグレーションでも画面が落ちないように）
function payslips_table_exists() {
  static $ok = null;
  if ($ok === null) {
    try { db()->query("SELECT 1 FROM payslips LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}

// その月の明細を発行（発行時点の金額をスナップショット upsert）。
//   $onlyStaffId 指定で個別発行。返り値: 発行した [['staff'=>, 'data'=>], ...]
function issue_payslips($month, $issuedBy, $onlyStaffId = null, $allowedIds = null) {
  $rows = compute_month_payroll($month);
  $up = db()->prepare(
    "INSERT INTO payslips
       (tenant_id,staff_id,month,days,class_min,ops_min,class_rate,ops_rate,class_pay,ops_pay,transport,total,issued_at,issued_by)
     VALUES (1,?,?,?,?,?,?,?,?,?,?,?,NOW(),?)
     ON DUPLICATE KEY UPDATE days=VALUES(days),class_min=VALUES(class_min),ops_min=VALUES(ops_min),
       class_rate=VALUES(class_rate),ops_rate=VALUES(ops_rate),class_pay=VALUES(class_pay),ops_pay=VALUES(ops_pay),
       transport=VALUES(transport),total=VALUES(total),issued_at=NOW(),issued_by=VALUES(issued_by)");
  $issued = [];
  foreach ($rows as $r) {
    $sid = (int)$r['staff']['id'];
    if ($onlyStaffId !== null && $sid !== (int)$onlyStaffId) continue;
    if (is_array($allowedIds) && !in_array($sid, $allowedIds, true)) continue; // 担当教室スコープ
    // 一括時は給与対象外（use_payroll=0）を除外。個別時は明示操作なので発行する。
    if ($onlyStaffId === null && array_key_exists('use_payroll', $r['staff']) && empty($r['staff']['use_payroll'])) continue;
    $up->execute([$sid, $month, $r['days'], $r['class_min'], $r['ops_min'], $r['class_rate'], $r['ops_rate'],
                  $r['class_pay'], $r['ops_pay'], $r['transport'], $r['total'], (int)$issuedBy]);
    $issued[] = ['staff' => $r['staff'], 'data' => $r];
  }
  return $issued;
}

// 明細発行の通知メール（金額は本文に載せず、アプリでDL）。成功でtrue。
function send_payslip_notice($toEmail, $toName, $month) {
  $toEmail = trim((string)$toEmail);
  if (!config_value('mail_enabled', true) || $toEmail === '') return false;
  $from = trim((string)config_value('mail_from', ''));
  if ($from === '') return false;
  $fromName = config_value('mail_from_name', '給与・シフト');
  $url = payroll_base_url() . 'payslips.php';
  $subject = "【給与明細】{$month} 分を発行しました";
  $body  = ($toName !== '' ? "{$toName} 様\n\n" : '')
         . "{$month} 分の給与明細を発行しました。\n下記からログインして明細（PDF）をご確認ください。\n\n"
         . "{$url}\n\n"
         . "※ 金額はこのメールには記載していません（ログインしてご確認ください）。\n"
         . "※ このメールは送信専用です。\n\n智翔館グループ 給与・シフト";
  $prevLang = mb_language(); $prevEnc = mb_internal_encoding();
  mb_language('uni'); mb_internal_encoding('UTF-8');
  $headers  = 'From: ' . mb_encode_mimeheader($fromName) . " <{$from}>\r\nReply-To: {$from}\r\n";
  $ok = @mb_send_mail($toEmail, $subject, $body, $headers, '-f' . $from);
  mb_language($prevLang); mb_internal_encoding($prevEnc);
  return (bool)$ok;
}

// ------------------------------------------------------------
// シフト確定待ち（申請中シフト）の通知
// ------------------------------------------------------------
// 重複防止ログ表の有無（未マイグレーションでも画面が落ちないように）
function shift_notify_table_exists() {
  static $ok = null;
  if ($ok === null) {
    try { db()->query("SELECT 1 FROM shift_notify_log LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}

// 申請中（確定待ち）シフトを講師ごとに集計。
//   返り値: [ ['staff_id'=>, 'name'=>, 'classrooms'=>, 'cnt'=>, 'mind'=>], ... ]（講師名順）
function pending_shift_summary() {
  try {
    return db()->query(
      "SELECT a.staff_id, s.name, s.classrooms, COUNT(*) cnt, MIN(a.work_date) mind
         FROM shift_applications a JOIN staff s ON s.id = a.staff_id
        WHERE a.status = '申請中'
        GROUP BY a.staff_id, s.name, s.classrooms
        ORDER BY s.name")->fetchAll();
  } catch (Throwable $e) { return []; }
}

// 確定待ちのダイジェストを担当スタッフ（staff/admin）へ1日1回メール。
//   通常は cron_notify.php（13:00 定時）から呼ぶ。重複防止に
//   shift_notify_log(notify_date,kind) の UNIQUE + INSERT IGNORE を使用。
//   admin は全件、staff は担当教室（users.classrooms）と講師の配属教室の積集合のみ。
//   $force=true でガードを無視して送信（手動テスト用）。
//   返り値: ['sent'=>送信数, 'recipients'=>対象者数, 'pending_staff'=>確定待ち講師数, 'skipped'=>理由]
function maybe_send_pending_shift_digest($force = false) {
  $res = ['sent' => 0, 'recipients' => 0, 'pending_staff' => 0, 'skipped' => ''];
  if (!config_value('mail_enabled', true)) { $res['skipped'] = 'mail_disabled'; return $res; }
  if (!shift_notify_table_exists()) { $res['skipped'] = 'no_log_table'; return $res; }
  if (trim((string)config_value('mail_from', '')) === '') { $res['skipped'] = 'no_mail_from'; return $res; }

  $pending = pending_shift_summary();
  $res['pending_staff'] = count($pending);
  if (!$pending) { $res['skipped'] = 'no_pending'; return $res; } // 送るものが無い日はガードを立てない

  if (!$force) {
    // 当日まだ送っていなければ rowCount=1。同時実行でも UNIQUE で1通だけ。
    try {
      $ins = db()->prepare("INSERT IGNORE INTO shift_notify_log (tenant_id, notify_date, kind) VALUES (1, ?, 'pending_shift')");
      $ins->execute([date('Y-m-d')]);
      if ($ins->rowCount() === 0) { $res['skipped'] = 'already_sent_today'; return $res; }
    } catch (Throwable $e) { $res['skipped'] = 'log_error'; return $res; }
  }

  try { $users = db()->query("SELECT email, display_name, role, classrooms FROM users WHERE is_active=1 AND role IN ('staff','admin')")->fetchAll(); }
  catch (Throwable $e) { $res['skipped'] = 'users_error'; return $res; }

  foreach ($users as $u) {
    $email = trim((string)$u['email']);
    if ($email === '' || strpos($email, '@') === false) continue;
    if (strtolower((string)substr(strrchr($email, '@'), 1)) === 'chishokan.local') continue; // 内部ダミー除外
    if (($u['role'] ?? '') === 'admin') {
      $mine = $pending;
    } else {
      $rooms = classroom_list($u['classrooms'] ?? '');
      if (!$rooms) continue;
      $mine = array_values(array_filter($pending, fn($p) => (bool)array_intersect($rooms, classroom_list($p['classrooms']))));
    }
    if (!$mine) continue;
    $res['recipients']++;
    if (send_pending_shift_notice($email, (string)($u['display_name'] ?? ''), $mine)) { $res['sent']++; }
  }
  return $res;
}

// 確定待ち通知メール（金額等は載せず、アプリで確定）。成功で true。
function send_pending_shift_notice($toEmail, $toName, $rows) {
  $toEmail = trim((string)$toEmail);
  if (!config_value('mail_enabled', true) || $toEmail === '') return false;
  $from = trim((string)config_value('mail_from', ''));
  if ($from === '') return false;
  $fromName = config_value('mail_from_name', '給与・シフト');
  $url = payroll_base_url() . 'shifts_admin.php';
  $lines = '';
  foreach ($rows as $r) { $lines .= '・' . $r['name'] . '（' . (int)$r['cnt'] . '件）' . "\n"; }
  $subject = '【給与・シフト】シフト確定待ちのお知らせ';
  $body  = ($toName !== '' ? "{$toName} 様\n\n" : '')
         . "講師から登録されたシフトで、確定待ち（申請中）があります。\n\n"
         . $lines . "\n"
         . "下記からログインし、「シフト管理」で確定してください。\n{$url}\n\n"
         . "※ この通知は1日1回送信しています。\n※ このメールは送信専用です。\n\n智翔館グループ 給与・シフト";
  $prevLang = mb_language(); $prevEnc = mb_internal_encoding();
  mb_language('uni'); mb_internal_encoding('UTF-8');
  $headers = 'From: ' . mb_encode_mimeheader($fromName) . " <{$from}>\r\nReply-To: {$from}\r\n";
  $ok = @mb_send_mail($toEmail, $subject, $body, $headers, '-f' . $from);
  mb_language($prevLang); mb_internal_encoding($prevEnc);
  return (bool)$ok;
}

// 給与明細PDFを生成して文字列で返す（tFPDF＋IPAゴシック同梱）。
function build_payslip_pdf($slip, $staffName) {
  require_once __DIR__ . '/tfpdf/tfpdf.php';
  require_once __DIR__ . '/tfpdf/font/unifont/ttfonts.php';
  $pdf = new tFPDF('P', 'mm', 'A4');
  $pdf->SetTitle('payslip');
  $pdf->AddPage();
  $pdf->AddFont('ipag', '', 'ipag.ttf', true);
  // 見出し
  $pdf->SetFont('ipag', '', 18);
  $pdf->Cell(0, 12, '給 与 明 細 書', 0, 1, 'C');
  $pdf->SetFont('ipag', '', 11);
  $pdf->Cell(0, 7, '対象月： ' . $slip['month'], 0, 1, 'C');
  $pdf->Ln(2);
  $pdf->Cell(0, 8, '氏名： ' . $staffName . '　様', 0, 1);
  $pdf->Cell(0, 8, '発行日： ' . substr((string)$slip['issued_at'], 0, 10) . '　／　智翔館グループ', 0, 1);
  $pdf->Ln(2);
  // 明細表
  $pdf->SetFont('ipag', '', 11);
  $w1 = 90; $w2 = 90;
  $row = function ($label, $val, $bold = false) use ($pdf, $w1, $w2) {
    $pdf->SetFont('ipag', '', $bold ? 13 : 11);
    $pdf->Cell($w1, 9, $label, 1, 0, 'L');
    $pdf->Cell($w2, 9, $val, 1, 1, 'R');
  };
  $fmt = function ($n) { return number_format((int)$n); };
  $row('勤務日数', $fmt($slip['days']) . ' 日');
  $row('授業時間 / 授業時給', fmt_hm($slip['class_min']) . ' / ' . $fmt($slip['class_rate']) . ' 円');
  $row('運営時間 / 運営時給', fmt_hm($slip['ops_min']) . ' / ' . $fmt($slip['ops_rate']) . ' 円');
  $pdf->Ln(1);
  $row('授業給与', $fmt($slip['class_pay']) . ' 円');
  $row('運営給与', $fmt($slip['ops_pay']) . ' 円');
  $row('交通費', $fmt($slip['transport']) . ' 円');
  $row('支給合計', $fmt($slip['total']) . ' 円', true);
  $pdf->Ln(6);
  $pdf->SetFont('ipag', '', 9);
  $pdf->MultiCell(0, 5, '※ 本明細は発行時点の確定シフトに基づく金額です。ご不明点は管理者へお問い合わせください。');
  return $pdf->Output('S');
}

// ------------------------------------------------------------
// 教室（校舎）スコープ：staff は担当教室の講師のみ管理できる
// ------------------------------------------------------------
function classrooms_active() {
  static $c = null;
  if ($c !== null) return $c;
  try { $c = db()->query("SELECT name FROM classrooms WHERE is_active=1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_COLUMN); }
  catch (Throwable $e) {
    try { $c = db()->query("SELECT DISTINCT school FROM staff WHERE school<>'' ORDER BY school")->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e2) { $c = []; }
  }
  return $c;
}
function classroom_list($csv) {
  return array_values(array_filter(array_map('trim', explode(',', (string)$csv)), fn($v) => $v !== ''));
}
// ログイン中ユーザーの担当教室（DBから最新取得）
function user_classrooms($user) {
  try {
    $st = db()->prepare("SELECT classrooms FROM users WHERE id=? LIMIT 1");
    $st->execute([(int)($user['id'] ?? 0)]);
    return classroom_list((string)$st->fetchColumn());
  } catch (Throwable $e) { return classroom_list($user['classrooms'] ?? ''); }
}
// 配属教室→staff_id。$room 指定でその教室のみ。返り値: id配列
function staff_ids_in_classrooms($rooms) {
  $rooms = (array)$rooms;
  $ids = [];
  try {
    foreach (db()->query("SELECT id, classrooms FROM staff")->fetchAll() as $s) {
      if (array_intersect($rooms, classroom_list($s['classrooms']))) { $ids[] = (int)$s['id']; }
    }
  } catch (Throwable $e) { return null; } // classrooms 列が無い等は制限しない
  return $ids;
}
// 管理スコープの staff_id 集合。admin=null（制限なし）、staff=担当教室の講師、担当無し=空。
function scoped_staff_ids($user) {
  if (($user['role'] ?? '') === 'admin') return null;
  $mine = user_classrooms($user);
  if (!$mine) return [];
  $ids = staff_ids_in_classrooms($mine);
  return $ids === null ? null : $ids;
}

// ------------------------------------------------------------
// 打刻（attendance）
// ------------------------------------------------------------
function attendance_table_exists() {
  static $ok = null;
  if ($ok === null) {
    try { db()->query("SELECT 1 FROM attendance LIMIT 1"); $ok = true; }
    catch (Throwable $e) { $ok = false; }
  }
  return $ok;
}
// 確定シフト(開始/終了)と打刻から 遅刻/早退/欠勤 を判定（猶予なし＝厳密）。
//   返り値: ['欠勤'] / ['遅刻'] / ['遅刻','早退'] / []（正常）
function attendance_flags($shiftStart, $shiftEnd, $att, $workDate) {
  $flags = [];
  $today = date('Y-m-d');
  $in  = $att['clock_in']  ?? null;
  $out = $att['clock_out'] ?? null;
  if (empty($in)) {
    if ($workDate <= $today) { $flags[] = '欠勤'; }
    return $flags;
  }
  if ($in > $shiftStart)  { $flags[] = '遅刻'; }     // TIME文字列比較（同日内）
  if (!empty($out) && $out < $shiftEnd) { $flags[] = '早退'; }
  return $flags;
}
