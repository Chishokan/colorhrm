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
