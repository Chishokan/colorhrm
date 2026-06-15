<?php
// 共通ビュー / 権限 / CSRF ヘルパー
// 前提：auth.php を先に require しておくこと（session_start / current_user / h / db を使う）。

// ------------------------------------------------------------
// 設定（config.php）の読み込み（DB接続以外の値も参照したいため）
// ------------------------------------------------------------
function app_config() {
  static $c = null;
  if ($c === null) {
    $c = require __DIR__ . '/config.php';
  }
  return $c;
}

function config_value($key, $default = '') {
  $c = app_config();
  return $c[$key] ?? $default;
}

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

// カラー序列（昇格順）。GAS版 StaffService.COLOR_RANKS と一致。
function color_ranks() {
  return ['WHITE', 'GREEN', 'BLUE', 'YELLOW', 'RED'];
}

// staff テーブルの実在カラム集合（本番のスキーマ差異に強くするため動的取得・キャッシュ）
function staff_columns() {
  static $cols = null;
  if ($cols === null) {
    $cols = [];
    foreach (db()->query("SHOW COLUMNS FROM staff")->fetchAll() as $r) {
      $cols[$r['Field']] = true;
    }
  }
  return $cols;
}
function staff_has_column($name) {
  $c = staff_columns();
  return isset($c[$name]);
}
// users テーブルの実在カラム（権限フラグ列の有無判定など）
function users_columns() {
  static $c = null;
  if ($c === null) {
    $c = [];
    foreach (db()->query("SHOW COLUMNS FROM users")->fetchAll() as $r) { $c[$r['Field']] = true; }
  }
  return $c;
}

// 育成達成率（GAS版 goalSummary 移植・サーバ側に集約）
//   現カラーの次〜目標カラーに必要な研修項目（共通＋本人部門）のうち、合格/対象外の割合。
function compute_goal_summary($staff) {
  $ranks = color_ranks();
  $cur = array_search($staff['color_rank'] ?? '', $ranks, true);
  $tgt = array_search(($staff['target_rank'] ?? '') ?: '__none__', $ranks, true);
  if ($cur === false || $tgt === false || $tgt <= $cur) {
    return ['hasGoal' => false, 'label' => '継続', 'rate' => null, 'done' => 0, 'total' => 0];
  }
  $needColors = array_slice($ranks, $cur + 1, $tgt - $cur); // 次〜目標
  $in = implode(',', array_fill(0, count($needColors), '?'));
  $q = db()->prepare("SELECT id, department, target_color FROM training_items WHERE tenant_id = 1 AND target_color IN ($in)");
  $q->execute($needColors);
  $items = $q->fetchAll();

  $myDepts = array_map('trim', explode(',', (string)($staff['departments'] ?? '')));
  $req = array_values(array_filter($items, function ($it) use ($myDepts) {
    return $it['department'] === '' || $it['department'] === '共通' || in_array($it['department'], $myDepts, true);
  }));
  $total = count($req);
  if ($total === 0) {
    return ['hasGoal' => true, 'label' => '対象項目なし', 'rate' => 100, 'done' => 0, 'total' => 0];
  }
  $ids = array_column($req, 'id');
  $in2 = implode(',', array_fill(0, count($ids), '?'));
  $ps = db()->prepare("SELECT training_item_id, status FROM training_progress WHERE staff_id = ? AND training_item_id IN ($in2)");
  $ps->execute(array_merge([(int)$staff['id']], $ids));
  $statusBy = [];
  foreach ($ps->fetchAll() as $r) { $statusBy[$r['training_item_id']] = $r['status']; }
  $done = 0;
  foreach ($req as $it) {
    $s = $statusBy[$it['id']] ?? '未着手';
    if ($s === '合格' || $s === '対象外') { $done++; }
  }
  $rate = (int)round($done / $total * 100);
  return ['hasGoal' => true, 'label' => "{$done}/{$total}", 'rate' => $rate, 'done' => $done, 'total' => $total];
}

// 達成率バーのHTML
function goal_bar_html($gs, $compact = false) {
  if (!$gs['hasGoal']) {
    return '<span class="text-muted small">' . h($gs['label']) . '</span>';
  }
  $rate = (int)$gs['rate'];
  $cls = $rate >= 100 ? 'bg-success' : ($rate >= 50 ? 'bg-info' : 'bg-warning');
  $h = '<div class="progress" style="height:' . ($compact ? '8px' : '16px') . '">'
     . '<div class="progress-bar ' . $cls . '" style="width:' . $rate . '%"></div></div>';
  if (!$compact) {
    $h .= '<div class="small text-muted">達成率 ' . $rate . '%（' . h($gs['label']) . '）</div>';
  }
  return $h;
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
// 細分化権限（GAS版 viewRecruitment / viewStaffList の移植）
//   ※ 列(008)が無い場合は従来挙動にフォールバック：
//     staff は採用閲覧可（既定true）、teacher は講師一覧不可（既定false）。
// ------------------------------------------------------------
function can_view_recruitment($user) {
  $role = $user['role'] ?? '';
  if ($role === 'admin') return true;
  if ($role === 'staff') {
    return array_key_exists('view_recruitment', $user) ? !empty($user['view_recruitment']) : true;
  }
  return false;
}
function can_view_staff_list($user) {
  $role = $user['role'] ?? '';
  if ($role === 'admin' || $role === 'staff') return true;
  if ($role === 'teacher') {
    return array_key_exists('view_staff_list', $user) ? !empty($user['view_staff_list']) : false;
  }
  return false;
}
function require_recruitment_access() {
  if (!can_view_recruitment(current_user())) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;padding:2rem">採用情報の閲覧権限がありません。 <a href="index.php">戻る</a></div>';
    exit;
  }
}
function require_staff_list_access() {
  if (!can_view_staff_list(current_user())) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;padding:2rem">講師一覧の閲覧権限がありません。 <a href="mypage.php">マイページへ</a></div>';
    exit;
  }
}

// ------------------------------------------------------------
// 共通レイアウト（ヘッダ + ロール別ナビ / フッタ）
// ------------------------------------------------------------
function nav_links_for($user) {
  $role  = $user['role'] ?? '';
  $links = [];
  if ($role === 'teacher') {
    $links['mypage.php'] = 'マイページ';
    if (can_view_staff_list($user)) { $links['index.php'] = '講師一覧'; }
  }
  if ($role === 'admin' || $role === 'staff') {
    if (can_view_recruitment($user)) {
      $links['dashboard.php']  = 'ダッシュボード';
      $links['candidates.php'] = '採用';
    }
    $links['index.php']    = '講師一覧';
    $links['training.php'] = '研修管理';
  }
  if ($role === 'admin') {
    $links['training_master.php'] = '研修マスター';
    $links['lessons.php']         = '研修動画';
    $links['questions.php']       = '質問';
    $links['import.php']          = 'データ移行';
    $links['users.php']           = 'ユーザー管理';
  }
  return $links;
}

function render_header($title, $user, $active = '') {
  $role  = $user['role'] ?? '';
  $links = nav_links_for($user);
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

// ------------------------------------------------------------
// 履歴書アップロード（PII）
//   保存先は app/uploads/resumes/。直アクセスは .htaccess で遮断し、
//   閲覧は resume_view.php（要ログイン）経由のみ。
// ------------------------------------------------------------
function resume_dir() {
  return __DIR__ . '/uploads/resumes';
}

function resume_allowed_ext() {
  return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
}

// アップロードを保存し、保存ファイル名（candidates.resume_file に格納する値）を返す。
// 失敗時は例外。
function save_resume_upload($candidateId, $file) {
  if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('アップロードに失敗しました（コード: ' . ($file['error'] ?? '不明') . '）。');
  }
  if ($file['size'] > 8 * 1024 * 1024) {
    throw new RuntimeException('ファイルが大きすぎます（上限8MB）。');
  }
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $allowed = resume_allowed_ext();
  // 実際の中身からMIMEを判定（拡張子偽装対策）
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);
  if (!isset($allowed[$ext]) || !in_array($mime, $allowed, true)) {
    throw new RuntimeException('JPG / PNG 画像のみアップロードできます。');
  }
  $dir = resume_dir();
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    throw new RuntimeException('保存先ディレクトリを作成できません。');
  }
  $name = 'cand' . (int)$candidateId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $dest = $dir . '/' . $name;
  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    // CLIテスト等で move_uploaded_file が使えない場合のフォールバック
    if (!@rename($file['tmp_name'], $dest)) {
      throw new RuntimeException('ファイルの保存に失敗しました。');
    }
  }
  return $name;
}

// 研修テストの証跡画像（PII性は低いが認証付き配信）
function evidence_dir() {
  return __DIR__ . '/uploads/evidence';
}
function save_evidence_upload($staffId, $itemId, $file) {
  if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('アップロードに失敗しました。');
  }
  if ($file['size'] > 8 * 1024 * 1024) { throw new RuntimeException('ファイルが大きすぎます（上限8MB）。'); }
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $allowed = resume_allowed_ext();
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime  = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);
  if (!isset($allowed[$ext]) || !in_array($mime, $allowed, true)) {
    throw new RuntimeException('JPG / PNG 画像のみアップロードできます。');
  }
  $dir = evidence_dir();
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    throw new RuntimeException('保存先を作成できません。');
  }
  $name = 'ev' . (int)$staffId . '_' . (int)$itemId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
  $dest = $dir . '/' . $name;
  if (!move_uploaded_file($file['tmp_name'], $dest) && !@rename($file['tmp_name'], $dest)) {
    throw new RuntimeException('ファイルの保存に失敗しました。');
  }
  return $name;
}

// ------------------------------------------------------------
// OCR：Google Cloud Vision（DOCUMENT_TEXT_DETECTION）でテキスト抽出
//   config.php の 'vision_api_key' が空なら未設定として扱う。
// ------------------------------------------------------------
function ocr_enabled() {
  return config_value('vision_api_key', '') !== '';
}

function vision_ocr_text($imagePath) {
  $apiKey = config_value('vision_api_key', '');
  if ($apiKey === '') {
    throw new RuntimeException('OCRが未設定です（config.php に vision_api_key を設定してください）。');
  }
  $payload = json_encode([
    'requests' => [[
      'image'        => ['content' => base64_encode(file_get_contents($imagePath))],
      'features'     => [['type' => 'DOCUMENT_TEXT_DETECTION']],
      'imageContext' => ['languageHints' => ['ja']],
    ]],
  ]);
  $ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($apiKey));
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
  ]);
  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($res === false) {
    throw new RuntimeException('Vision API 接続エラー: ' . $err);
  }
  $data = json_decode($res, true);
  if ($code !== 200) {
    throw new RuntimeException('Vision API エラー: ' . ($data['error']['message'] ?? ('HTTP ' . $code)));
  }
  return $data['responses'][0]['fullTextAnnotation']['text'] ?? '';
}

// ------------------------------------------------------------
// データ移行（フェーズ6）：CSV → candidates の列マッピング
//   既存スプレッドシートの日本語見出し / GAS版camelCase / snake_case を吸収。
// ------------------------------------------------------------
function candidate_import_alias_map() {
  return [
    // no
    'no' => 'no', 'NO' => 'no', '通し番号' => 'no', 'ナンバー' => 'no',
    // name / age / note
    'name' => 'name', '氏名' => 'name', '名前' => 'name', '応募者氏名' => 'name',
    'age' => 'age', '年齢' => 'age',
    'note' => 'note', '備考' => 'note',
    // applied
    'appliedMonth' => 'applied_month', '応募月' => 'applied_month',
    'appliedDay' => 'applied_day', '応募日' => 'applied_day',
    // assignment
    'assignee' => 'assignee', '担当' => 'assignee', '担当者' => 'assignee',
    'employmentType' => 'employment_type', '雇用形態' => 'employment_type',
    'department' => 'department', '部署' => 'department', '部門' => 'department',
    'school' => 'school', '校舎' => 'school',
    'jobType' => 'job_type', '職種' => 'job_type',
    'recruitingMedia' => 'recruiting_media', '求人媒体' => 'recruiting_media', '応募媒体' => 'recruiting_media',
    'referrer' => 'referrer', '紹介者' => 'referrer',
    // flags
    'referralRewardPaid' => 'referral_reward_paid', '紹介謝礼配布済' => 'referral_reward_paid', '紹介謝礼' => 'referral_reward_paid',
    'specialRecruiting' => 'special_recruiting', '企画求人' => 'special_recruiting',
    'continuationRewardPaid' => 'continuation_reward_paid', '継続謝礼配布済' => 'continuation_reward_paid', '継続謝礼' => 'continuation_reward_paid',
    'initialResponse' => 'initial_response', '初期対応済' => 'initial_response', '初期対応' => 'initial_response',
    // selection / dates
    'interviewDate' => 'interview_date', '面接日' => 'interview_date',
    'selectionResult' => 'selection_result', '選考結果' => 'selection_result', '合否' => 'selection_result', '合否結果' => 'selection_result',
    'hireDate' => 'hire_date', '入社日' => 'hire_date', '採用日' => 'hire_date',
    'threeMonthCheckDate' => 'three_month_check_date', '3か月継続判断日' => 'three_month_check_date', '3か月判断日' => 'three_month_check_date',
    'continued' => 'continued', '継続' => 'continued',
  ];
}

// 取り込み可能な candidates 列（id/作成日時等は対象外）
function candidate_import_columns() {
  return [
    'no', 'applied_month', 'applied_day', 'name', 'age', 'note', 'assignee',
    'employment_type', 'department', 'school', 'job_type', 'recruiting_media', 'referrer',
    'referral_reward_paid', 'special_recruiting', 'interview_date', 'selection_result',
    'hire_date', 'three_month_check_date', 'continued', 'continuation_reward_paid', 'initial_response',
  ];
}

// CSVセル値を列の型に合わせて正規化（NULL/真偽/日付/整数）
function normalize_import_value($col, $val) {
  $val = trim((string)$val);
  $intCols  = ['no', 'applied_month', 'applied_day', 'age'];
  $boolCols = ['referral_reward_paid', 'special_recruiting', 'continuation_reward_paid', 'initial_response'];
  $dateCols = ['interview_date', 'hire_date', 'three_month_check_date'];

  if (in_array($col, $boolCols, true)) {
    return in_array(mb_strtolower($val), ['1', 'true', '○', '〇', 'yes', 'y', '済', 'はい'], true) ? 1 : 0;
  }
  if ($val === '') {
    return in_array($col, array_merge($intCols, $dateCols), true) ? null : '';
  }
  if (in_array($col, $intCols, true)) {
    return preg_match('/-?\d+/', $val, $m) ? (int)$m[0] : null;
  }
  if (in_array($col, $dateCols, true)) {
    $s = str_replace(['/', '.'], '-', $val);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
      return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    return null; // 解釈できない日付は NULL
  }
  return $val;
}

function import_dir() {
  return __DIR__ . '/uploads/imports';
}

// 履歴書テキストからフィールドを推定（GAS版 OcrService.parseResumeText 移植）
function parse_resume_text($text) {
  $result = [
    'name' => '', 'birth_date' => '', 'phone' => '', 'email' => '',
    'self_pr' => '', 'motivation' => '',
  ];

  if (preg_match('/0\d{1,4}[-\s]?\d{2,4}[-\s]?\d{4}/u', $text, $m)) {
    $result['phone'] = preg_replace('/\s/u', '-', $m[0]);
  }
  if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/u', $text, $m)) {
    $result['email'] = $m[0];
  }
  if (preg_match('/(昭和|平成|令和)?\s*(\d+)\s*年\s*(\d+)\s*月\s*(\d+)\s*日/u', $text, $m)) {
    $result['birth_date'] = trim($m[0]);
  }

  $lines = array_values(array_filter(
    array_map('trim', preg_split('/\r\n|\r|\n/', $text)),
    function ($l) { return $l !== ''; }
  ));

  foreach ($lines as $i => $l) {
    if (preg_match('/ふりがな|フリガナ|氏\s*名/u', $l)) {
      if (isset($lines[$i + 1])) {
        $cand = $lines[$i + 1];
        $len  = mb_strlen($cand, 'UTF-8');
        if ($len >= 2 && $len <= 20) {
          $result['name'] = $cand;
        }
      }
      break;
    }
  }
  foreach ($lines as $i => $l) {
    if (preg_match('/志望動機/u', $l)) {
      $result['motivation'] = implode(' ', array_slice($lines, $i + 1, 4));
      break;
    }
  }
  foreach ($lines as $i => $l) {
    if (preg_match('/自己PR|自己紹介/u', $l)) {
      $result['self_pr'] = implode(' ', array_slice($lines, $i + 1, 4));
      break;
    }
  }
  return $result;
}
