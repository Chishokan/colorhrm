<?php
// 講師 詳細・編集（admin/staff）：プロフィール編集、カラー昇格、退職/復職、育成達成率。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$id    = (int)($_GET['id'] ?? 0);
$flash = '';
$err   = '';

// 編集可能なプロフィール項目（実在カラムのみ採用）。カラーは昇格処理で別管理。
$editable = ['name', 'departments', 'school', 'employment_type', 'hire_date',
             'target_rank', 'target_date', 'mentor', 'recruiting_media', 'referrer', 'email', 'use_payroll'];
$dateCols = ['hire_date', 'target_date'];
$boolCols = ['use_payroll'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $cols   = staff_columns();

  if ($action === 'save_profile') {
    $set = []; $vals = [];
    foreach ($editable as $f) {
      if (!isset($cols[$f])) continue; // 実在しない列はスキップ
      if (in_array($f, $boolCols, true)) {
        $set[] = "$f = ?"; $vals[] = isset($_POST[$f]) ? 1 : 0;
      } else {
        $v = trim($_POST[$f] ?? '');
        if (in_array($f, $dateCols, true)) { $v = ($v === '') ? null : $v; }
        $set[] = "$f = ?"; $vals[] = $v;
      }
    }
    if ($set) {
      $vals[] = $id;
      db()->prepare("UPDATE staff SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
      $flash = 'プロフィールを保存しました。';
    }
  } elseif ($action === 'update_color') {
    $color = $_POST['color_rank'] ?? '';
    if (!in_array($color, color_ranks(), true)) {
      $err = 'カラーの指定が不正です。';
    } else {
      $sql = "UPDATE staff SET color_rank = ?"
           . (isset($cols['color_certified_date']) ? ", color_certified_date = CURDATE()" : "")
           . " WHERE id = ?";
      db()->prepare($sql)->execute([$color, $id]);
      $flash = "カラーを {$color} に更新しました（認定日: 本日）。";
    }
  } elseif ($action === 'resign') {
    $sql = "UPDATE staff SET is_active = 0"
         . (isset($cols['resign_date']) ? ", resign_date = CURDATE()" : "")
         . " WHERE id = ?";
    db()->prepare($sql)->execute([$id]);
    $flash = '退職として登録しました（在籍一覧から外れます）。';
  } elseif ($action === 'reactivate') {
    $sql = "UPDATE staff SET is_active = 1"
         . (isset($cols['resign_date']) ? ", resign_date = NULL" : "")
         . " WHERE id = ?";
    db()->prepare($sql)->execute([$id]);
    $flash = '復職として登録しました。';
  }
}

$st = db()->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
$st->execute([$id]);
$s = $st->fetch();
if (!$s) {
  render_header('講師詳細', $user, 'index.php');
  echo '<div class="container py-4"><div class="alert alert-danger">講師が見つかりません。 <a href="index.php">講師一覧へ</a></div></div>';
  render_footer();
  exit;
}

$gs   = compute_goal_summary($s);
$cols = staff_columns();
$val  = function ($k) use ($s) { return $s[$k] ?? ''; };

render_header('講師: ' . $s['name'], $user, 'index.php');
?>
  <div class="container py-4" style="max-width:900px">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0"><?= h($s['name']) ?>
        <span class="badge" style="<?= color_style($s['color_rank']) ?>"><?= h($s['color_rank']) ?></span>
        <?php if (!$s['is_active']): ?><span class="badge bg-secondary">退職</span><?php endif; ?>
      </h4>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">← 講師一覧へ</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <!-- カラー昇格 ＋ 育成達成率 -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col-md-6">
            <label class="form-label small mb-1">育成進捗（目標: <?= h($s['target_rank'] ?: '—') ?>
              <?php if (!empty($s['target_date'])): ?> / 期限 <?= h($s['target_date']) ?><?php endif; ?>）</label>
            <?= goal_bar_html($gs) ?>
          </div>
          <div class="col-md-6">
            <form method="post" class="d-flex align-items-end gap-2 justify-content-md-end"
                  onsubmit="return confirm('カラーを更新します（認定日=本日）。よろしいですか？');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_color">
              <div>
                <label class="form-label small mb-0">カラー昇格/変更</label>
                <select name="color_rank" class="form-select form-select-sm" style="width:130px">
                  <?php foreach (color_ranks() as $c): ?>
                    <option value="<?= h($c) ?>" <?= $s['color_rank'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-sm btn-primary">更新</button>
            </form>
            <?php if (!empty($s['color_certified_date'])): ?>
              <div class="small text-muted mt-1 text-md-end">現カラー認定日: <?= h($s['color_certified_date']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- プロフィール編集 -->
    <form method="post" class="card shadow-sm mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_profile">
      <div class="card-header">プロフィール</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small mb-0">氏名</label>
            <input name="name" value="<?= h($val('name')) ?>" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">メール（ログインID）</label>
            <input name="email" value="<?= h($val('email')) ?>" class="form-control form-control-sm" <?= isset($cols['email']) ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">部門（複数はカンマ区切り）</label>
            <input name="departments" value="<?= h($val('departments')) ?>" class="form-control form-control-sm" placeholder="例: RED,ネクスタ">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">校舎</label>
            <input name="school" value="<?= h($val('school')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">目標カラー</label>
            <select name="target_rank" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach (color_ranks() as $c): ?>
                <option value="<?= h($c) ?>" <?= $val('target_rank') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">目標期限</label>
            <input name="target_date" type="date" value="<?= h($val('target_date')) ?>" class="form-control form-control-sm" <?= isset($cols['target_date']) ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">メンター</label>
            <input name="mentor" value="<?= h($val('mentor')) ?>" class="form-control form-control-sm">
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
            <label class="form-label small mb-0">入社日</label>
            <input name="hire_date" type="date" value="<?= h($val('hire_date')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">求人媒体</label>
            <input name="recruiting_media" value="<?= h($val('recruiting_media')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">紹介者</label>
            <input name="referrer" value="<?= h($val('referrer')) ?>" class="form-control form-control-sm">
          </div>
          <?php if (isset($cols['use_payroll'])): ?>
          <div class="col-md-3 d-flex align-items-end">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="use_payroll" id="up" <?= !empty($s['use_payroll']) ? 'checked' : '' ?>><label class="form-check-label small" for="up">給与システム対象</label></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-footer text-end">
        <button class="btn btn-primary">プロフィールを保存</button>
      </div>
    </form>

    <!-- 関連リンク・退職処理 -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <a href="training.php?staff_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">研修チェックリストを開く</a>
        <?php if (!empty($s['candidate_id'])): ?>
          <a href="candidate.php?id=<?= (int)$s['candidate_id'] ?>" class="btn btn-sm btn-outline-secondary">応募者情報</a>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($s['is_active']): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('この講師を退職として登録します。よろしいですか？');">
            <?= csrf_field() ?><input type="hidden" name="action" value="resign">
            <button class="btn btn-sm btn-outline-danger">退職にする</button>
          </form>
        <?php else: ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="reactivate">
            <button class="btn btn-sm btn-outline-success">復職にする</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
