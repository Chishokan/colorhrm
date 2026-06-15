<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$id    = (int)($_GET['id'] ?? 0);
$flash = '';
$err   = '';
$ocrResult = null; // OCR読み取り結果（参考表示用）

// 編集対象フィールド（フォーム→DB）
$fields = [
  'applied_month', 'applied_day', 'name', 'age', 'note', 'assignee',
  'employment_type', 'department', 'school', 'job_type', 'recruiting_media',
  'referrer', 'interview_date', 'selection_result', 'hire_date',
  'three_month_check_date', 'continued',
];
$checkboxes = ['referral_reward_paid', 'special_recruiting', 'continuation_reward_paid', 'initial_response'];
$dateCols   = ['interview_date', 'hire_date', 'three_month_check_date'];
$intCols    = ['applied_month', 'applied_day', 'age'];

function collect_candidate_input($fields, $checkboxes, $dateCols, $intCols) {
  $data = [];
  foreach ($fields as $f) {
    $v = trim($_POST[$f] ?? '');
    if (in_array($f, $dateCols, true) || in_array($f, $intCols, true)) {
      $data[$f] = ($v === '') ? null : ($_POST[$f]); // 空はNULL
    } else {
      $data[$f] = $v;
    }
  }
  foreach ($checkboxes as $c) {
    $data[$c] = isset($_POST[$c]) ? 1 : 0;
  }
  return $data;
}

// ------------------------------------------------------------
// POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'save') {
    $data = collect_candidate_input($fields, $checkboxes, $dateCols, $intCols);
    if ($data['name'] === '') {
      $err = '氏名は必須です。';
    } else {
      $cols = array_merge($fields, $checkboxes);
      if ($id) {
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $vals = array_map(fn($c) => $data[$c], $cols);
        $vals[] = $id;
        db()->prepare("UPDATE candidates SET $set WHERE id = ?")->execute($vals);
        $flash = '保存しました。';
      } else {
        $maxNo = (int)db()->query("SELECT COALESCE(MAX(no),0) FROM candidates WHERE tenant_id=1")->fetchColumn();
        $allCols = array_merge(['tenant_id', 'no'], $cols);
        $place   = implode(', ', array_fill(0, count($allCols), '?'));
        $vals    = array_merge([1, $maxNo + 1], array_map(fn($c) => $data[$c], $cols));
        db()->prepare("INSERT INTO candidates (" . implode(',', $allCols) . ") VALUES ($place)")->execute($vals);
        $id = (int)db()->lastInsertId();
        $flash = '応募者を登録しました。';
      }
    }
  } elseif ($action === 'convert') {
    // 採用決定 → 講師化（CandidateService.gs#convertToStaff の移植）
    $c = null;
    if ($id) {
      $st = db()->prepare("SELECT * FROM candidates WHERE id = ? LIMIT 1");
      $st->execute([$id]);
      $c = $st->fetch();
    }
    if (!$c) {
      $err = '応募者が見つかりません。';
    } elseif ($c['selection_result'] !== '採用') {
      $err = '採用決定済み（選考結果＝採用）の応募者のみ講師登録できます。';
    } elseif ((int)$c['converted_to_staff'] === 1) {
      $err = '既に講師登録済みです。';
    } else {
      $sql = "INSERT INTO staff
                (tenant_id, candidate_id, name, departments, school, hire_date,
                 employment_type, color_rank, target_rank, recruiting_media, referrer, is_active)
              VALUES (?, ?, ?, ?, ?, ?, ?, 'WHITE', 'GREEN', ?, ?, 1)";
      db()->prepare($sql)->execute([
        (int)$c['tenant_id'], (int)$c['id'], $c['name'], $c['department'], $c['school'],
        $c['hire_date'] ?: null, $c['employment_type'], $c['recruiting_media'], $c['referrer'],
      ]);
      db()->prepare("UPDATE candidates SET converted_to_staff = 1 WHERE id = ?")->execute([$id]);
      $flash = "「{$c['name']}」を講師として登録しました（カラー: WHITE / 目標: GREEN）。";
    }
  } elseif ($action === 'upload_resume') {
    // 履歴書画像のアップロード（PII：認証付き保存）
    if (!$id) {
      $err = '先に応募者を保存してからアップロードしてください。';
    } else {
      try {
        $saved = save_resume_upload($id, $_FILES['resume'] ?? []);
        db()->prepare("UPDATE candidates SET resume_file = ?, ocr_extracted = 0 WHERE id = ?")
            ->execute([$saved, $id]);
        $flash = '履歴書をアップロードしました。';
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    }
  } elseif ($action === 'ocr') {
    // 履歴書OCR → フィールド推定（Vision API。未設定なら案内）
    $st = db()->prepare("SELECT resume_file FROM candidates WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $rf = (string)$st->fetchColumn();
    if ($rf === '') {
      $err = '先に履歴書をアップロードしてください。';
    } elseif (!ocr_enabled()) {
      $err = 'OCRが未設定です。config.php に vision_api_key を設定すると有効になります。';
    } else {
      try {
        $text = vision_ocr_text(resume_dir() . '/' . basename($rf));
        $ocrResult = parse_resume_text($text);
        db()->prepare("UPDATE candidates SET ocr_extracted = 1 WHERE id = ?")->execute([$id]);
        $flash = 'OCRで読み取りました。下の「OCR結果」を確認し、必要な項目をフォームに反映して保存してください。';
      } catch (Throwable $e) {
        $err = 'OCRに失敗しました: ' . $e->getMessage();
      }
    }
  }
}

// ------------------------------------------------------------
// 表示データ取得
// ------------------------------------------------------------
$c = null;
if ($id) {
  $st = db()->prepare("SELECT * FROM candidates WHERE id = ? LIMIT 1");
  $st->execute([$id]);
  $c = $st->fetch();
  if (!$c) { $err = $err ?: '応募者が見つかりません。'; }
}
// 新規・未取得時の空デフォルト
$val = function ($k) use ($c) { return $c[$k] ?? ''; };
$chk = function ($k) use ($c) { return !empty($c[$k]) ? 'checked' : ''; };
$isNew = !$c;

render_header($isNew ? '新規応募者' : '応募者: ' . ($c['name'] ?? ''), $user, 'candidates.php');
?>
  <div class="container py-4" style="max-width:880px">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0"><?= $isNew ? '新規応募者' : h($c['name']) ?></h4>
      <a href="candidates.php" class="btn btn-sm btn-outline-secondary">← 一覧へ</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <?php if (!$isNew): ?>
      <!-- 採用決定 → 講師化 -->
      <div class="card shadow-sm mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            <span class="badge <?= selection_badge_class($c['selection_result']) ?>"><?= h($c['selection_result'] ?: '未選考') ?></span>
            <?php if ($c['converted_to_staff']): ?>
              <span class="badge bg-success ms-1">講師化済み</span>
            <?php endif; ?>
          </div>
          <?php if ($c['selection_result'] === '採用' && !$c['converted_to_staff']): ?>
            <form method="post" onsubmit="return confirm('この応募者を講師として登録します。よろしいですか？');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="convert">
              <button class="btn btn-success">採用決定 → 講師登録</button>
            </form>
          <?php elseif ($c['converted_to_staff']): ?>
            <a href="index.php" class="btn btn-sm btn-outline-success">講師一覧で確認</a>
          <?php else: ?>
            <span class="text-muted small">選考結果を「採用」にすると講師登録できます</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- 履歴書（PII：認証付き保存・閲覧） -->
      <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>履歴書</span>
          <?php if (!empty($c['resume_file'])): ?>
            <span class="badge bg-success">登録済み<?= $c['ocr_extracted'] ? '・OCR済' : '' ?></span>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if (!empty($c['resume_file'])): ?>
              <a href="resume_view.php?id=<?= (int)$c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">履歴書を開く</a>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ocr">
                <button class="btn btn-sm btn-outline-secondary" <?= ocr_enabled() ? '' : 'disabled title="config.php に vision_api_key を設定すると有効"' ?>>OCRで読み取り</button>
              </form>
              <?php if (!ocr_enabled()): ?><span class="text-muted small">OCRは未設定（任意・後で有効化可）</span><?php endif; ?>
            <?php else: ?>
              <span class="text-muted small">履歴書は未登録です。</span>
            <?php endif; ?>
          </div>
          <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_resume">
            <div class="col-auto">
              <label class="form-label small mb-0">履歴書画像（JPG/PNG・8MBまで）</label>
              <input type="file" name="resume" accept="image/jpeg,image/png" class="form-control form-control-sm" required>
            </div>
            <div class="col-auto">
              <button class="btn btn-sm btn-primary"><?= !empty($c['resume_file']) ? '差し替え' : 'アップロード' ?></button>
            </div>
          </form>

          <?php if ($ocrResult): ?>
            <div class="alert alert-info mt-3 mb-0 py-2 small">
              <div class="fw-bold mb-1">OCR結果（参考・自動入力は氏名のみ）</div>
              <div>氏名: <?= h($ocrResult['name'] ?: '—') ?></div>
              <div>生年月日: <?= h($ocrResult['birth_date'] ?: '—') ?></div>
              <div>電話: <?= h($ocrResult['phone'] ?: '—') ?> / メール: <?= h($ocrResult['email'] ?: '—') ?></div>
              <?php if ($ocrResult['motivation']): ?><div>志望動機: <?= h($ocrResult['motivation']) ?></div><?php endif; ?>
              <?php if ($ocrResult['self_pr']): ?><div>自己PR: <?= h($ocrResult['self_pr']) ?></div><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- 編集フォーム -->
    <form method="post" class="card shadow-sm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small mb-0">氏名 <span class="text-danger">*</span></label>
            <input name="name" value="<?= h($val('name') ?: ($ocrResult['name'] ?? '')) ?>" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">年齢</label>
            <input name="age" type="number" value="<?= h($val('age')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">応募月</label>
            <input name="applied_month" type="number" value="<?= h($val('applied_month')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">応募日</label>
            <input name="applied_day" type="number" value="<?= h($val('applied_day')) ?>" class="form-control form-control-sm">
          </div>

          <div class="col-md-3">
            <label class="form-label small mb-0">部署</label>
            <input name="department" value="<?= h($val('department')) ?>" class="form-control form-control-sm" placeholder="RED / 智翔館 等">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">校舎</label>
            <input name="school" value="<?= h($val('school')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">職種</label>
            <input name="job_type" value="<?= h($val('job_type')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">担当者</label>
            <input name="assignee" value="<?= h($val('assignee')) ?>" class="form-control form-control-sm">
          </div>

          <div class="col-md-3">
            <label class="form-label small mb-0">雇用形態</label>
            <select name="employment_type" class="form-select form-select-sm">
              <option value="">（未設定）</option>
              <?php foreach (candidate_employment_types() as $e): ?>
                <option value="<?= h($e) ?>" <?= $val('employment_type') === $e ? 'selected' : '' ?>><?= h($e) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">求人媒体</label>
            <input name="recruiting_media" value="<?= h($val('recruiting_media')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">紹介者</label>
            <input name="referrer" value="<?= h($val('referrer')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3 d-flex align-items-end gap-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="referral_reward_paid" <?= $chk('referral_reward_paid') ?> id="rrp"><label class="form-check-label small" for="rrp">紹介謝礼済</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="special_recruiting" <?= $chk('special_recruiting') ?> id="sr"><label class="form-check-label small" for="sr">企画求人</label></div>
          </div>

          <div class="col-12"><hr class="my-1"></div>

          <div class="col-md-3">
            <label class="form-label small mb-0">面接日</label>
            <input name="interview_date" type="date" value="<?= h($val('interview_date')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">選考結果</label>
            <select name="selection_result" class="form-select form-select-sm">
              <option value="">未選考</option>
              <?php foreach (candidate_selection_results() as $r): ?>
                <option value="<?= h($r) ?>" <?= $val('selection_result') === $r ? 'selected' : '' ?>><?= h($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">入社日</label>
            <input name="hire_date" type="date" value="<?= h($val('hire_date')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">3か月判断日</label>
            <input name="three_month_check_date" type="date" value="<?= h($val('three_month_check_date')) ?>" class="form-control form-control-sm">
          </div>

          <div class="col-md-3">
            <label class="form-label small mb-0">継続</label>
            <select name="continued" class="form-select form-select-sm">
              <option value="" <?= $val('continued') === '' ? 'selected' : '' ?>>—</option>
              <option value="〇" <?= $val('continued') === '〇' ? 'selected' : '' ?>>〇</option>
              <option value="✕" <?= $val('continued') === '✕' ? 'selected' : '' ?>>✕</option>
            </select>
          </div>
          <div class="col-md-9 d-flex align-items-end gap-3">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="initial_response" <?= $chk('initial_response') ?> id="ir"><label class="form-check-label small" for="ir">初期対応済</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="continuation_reward_paid" <?= $chk('continuation_reward_paid') ?> id="crp"><label class="form-check-label small" for="crp">継続謝礼済</label></div>
          </div>

          <div class="col-12">
            <label class="form-label small mb-0">備考</label>
            <input name="note" value="<?= h($val('note')) ?>" class="form-control form-control-sm" placeholder="学校名など">
          </div>
        </div>
      </div>
      <div class="card-footer text-end">
        <button class="btn btn-primary"><?= $isNew ? '登録' : '保存' ?></button>
      </div>
    </form>

    <?php if (!$isNew): ?>
      <p class="text-muted small mt-2">
        OCR処理済み: <?= $c['ocr_extracted'] ? '✓' : '—' ?>
        <?php if (!ocr_enabled()): ?>（OCRを有効化するには config.php に vision_api_key を設定）<?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
<?php render_footer(); ?>
