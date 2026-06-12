<?php
// 共通ビュー / 権限 / CSRF ヘルパー
// 前提：auth.php を先に require しておくこと（session_start / current_user / h / db を使う）。

// ------------------------------------------------------------
// 研修ステータス
// ------------------------------------------------------------
function training_statuses() {
  // 未着手 → 申告中 → （承認）合格/不合格、（差戻し）差戻し。対象外は除外扱い。
  return ['未着手', '申告中', '受講済', '合格', '不合格', '差戻し', '対象外'];
}

function status_badge_class($s) {
  switch ($s) {
    case '合格':   return 'bg-success';
    case '受講済': return 'bg-info text-dark';
    case '申告中': return 'bg-warning text-dark';
    case '差戻し': return 'bg-danger';
    case '不合格': return 'bg-danger';
    case '対象外': return 'bg-secondary';
    default:       return 'bg-light text-dark border'; // 未着手 等
  }
}

// カラーランクのバッジ用インラインスタイル（index.php と共通化）
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

// 育成目標になり得るカラー（WHITE は開始点なので対象外）
function training_target_colors() {
  return ['GREEN', 'BLUE', 'YELLOW', 'RED'];
}

// ------------------------------------------------------------
// 採用（candidates）の選択肢
// ------------------------------------------------------------
function candidate_selection_results() {
  return ['採用', '不採用(書類)', '不採用(面接後)', '辞退(面接前)', '辞退(面接後)', 'お断り', '音信不通', 'その他'];
}

function candidate_employment_types() {
  return ['アルバイト', '社員：新卒', '社員：中途'];
}

// 選考結果のバッジ色
function selection_badge_class($s) {
  switch ($s) {
    case '採用':           return 'bg-success';
    case '不採用(書類)':
    case '不採用(面接後)': return 'bg-danger';
    case '辞退(面接前)':
    case '辞退(面接後)':   return 'bg-secondary';
    case 'お断り':         return 'bg-dark';
    case '音信不通':       return 'bg-warning text-dark';
    case '':               return 'bg-light text-dark border'; // 未選考
    default:               return 'bg-info text-dark';
  }
}

// ------------------------------------------------------------
// 権限制御
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
// CSRF（状態変更POST用の軽量トークン）
// ------------------------------------------------------------
function csrf_token() {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['csrf'];
}

function csrf_field() {
  return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check() {
  if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) {
    http_response_code(400);
    exit('不正なリクエストです（CSRFトークン不一致）。');
  }
}

// ------------------------------------------------------------
// 共通レイアウト（ヘッダ + ロール別ナビ / フッタ）
// ------------------------------------------------------------
function nav_links_for($role) {
  $links = [];
  if ($role === 'teacher') {
    $links['mypage.php'] = 'マイページ';
  }
  if ($role === 'admin' || $role === 'staff') {
    $links['dashboard.php']  = 'ダッシュボード';
    $links['candidates.php'] = '採用';
    $links['index.php']      = '講師一覧';
    $links['training.php']   = '研修管理';
  }
  if ($role === 'admin') {
    $links['training_master.php'] = '研修マスター';
    $links['users.php']           = 'ユーザー管理';
  }
  return $links;
}

function render_header($title, $user, $active = '') {
  $role  = $user['role'] ?? '';
  $links = nav_links_for($role);
  echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . h($title) . '</title>';
  echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
  echo '</head><body class="bg-light">';
  echo '<nav class="navbar navbar-dark bg-dark px-3">';
  echo '<span class="navbar-brand">🎓 Color HRM</span>';
  echo '<div class="ms-3 me-auto">';
  foreach ($links as $href => $label) {
    $cls = ($href === $active) ? 'btn btn-sm btn-light me-1' : 'btn btn-sm btn-outline-light me-1';
    echo '<a href="' . h($href) . '" class="' . $cls . '">' . h($label) . '</a>';
  }
  echo '</div>';
  echo '<span class="text-white-50 me-3 small">'
     . h($user['display_name'] ?: $user['email']) . '（' . h($role) . '）</span>';
  echo '<a href="logout.php" class="btn btn-sm btn-outline-light">ログアウト</a>';
  echo '</nav>';
}

function render_footer() {
  echo '</body></html>';
}
