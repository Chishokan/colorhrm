<?php
// 講師の新規追加（admin/staff）。最小項目で作成し、詳細画面で続きを編集する。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $cols  = staff_columns();
  $name  = trim($_POST['name'] ?? '');
  $color = in_array($_POST['color_rank'] ?? '', color_ranks(), true) ? $_POST['color_rank'] : 'WHITE';
  $trank = in_array($_POST['target_rank'] ?? '', color_ranks(), true) ? $_POST['target_rank'] : '';
  if ($name === '') {
    $err = '氏名は必須です。';
  } else {
    // 候補値（実在カラムだけ INSERT する）
    $data = [
      'tenant_id'       => 1,
      'name'            => $name,
      'departments'     => trim($_POST['departments'] ?? ''),
      'school'          => trim($_POST['school'] ?? ''),
      'email'           => trim($_POST['email'] ?? ''),
      'employment_type' => trim($_POST['employment_type'] ?? ''),
      'color_rank'      => $color,
      'target_rank'     => $trank,
      'hire_date'       => (trim($_POST['hire_date'] ?? '') ?: null),
      'is_active'       => 1,
    ];
    $names = []; $ph = []; $vals = [];
    foreach ($data as $k => $v) {
      if ($k === 'tenant_id' || isset($cols[$k])) { $names[] = $k; $ph[] = '?'; $vals[] = $v; }
    }
    db()->prepare("INSERT INTO staff (" . implode(',', $names) . ") VALUES (" . implode(',', $ph) . ")")->execute($vals);
    $newId = (int)db()->lastInsertId();
    // 続きの編集（カラー認定・メンター等）は詳細画面で
    header('Location: staff_detail.php?id=' . $newId);
    exit;
  }
}

render_header('講師を追加', $user, 'index.php');
?>
  <div class="container py-4" style="max-width:720px">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">講師を追加</h4>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">← 講師一覧へ</a>
    </div>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-body">
        <form method="post" class="row g-3">
          <?= csrf_field() ?>
          <div class="col-md-6">
            <label class="form-label small mb-0">氏名 <span class="text-danger">*</span></label>
            <input name="name" class="form-control" required autofocus>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">メール（ログインID用・任意）</label>
            <input name="email" type="email" class="form-control" placeholder="後でアカウント発行に使えます">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">部門（カンマ区切り）</label>
            <input name="departments" class="form-control" placeholder="例: 智翔館,ネクスタ">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">校舎</label>
            <input name="school" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">現在のカラー</label>
            <select name="color_rank" class="form-select">
              <?php foreach (color_ranks() as $c): ?>
                <option value="<?= h($c) ?>"><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">育成目標カラー（任意）</label>
            <select name="target_rank" class="form-select">
              <option value="">（なし）</option>
              <?php foreach (training_target_colors() as $c): ?>
                <option value="<?= h($c) ?>"><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">雇用形態（任意）</label>
            <select name="employment_type" class="form-select">
              <option value="">（未設定）</option>
              <?php foreach (candidate_employment_types() as $t): ?>
                <option value="<?= h($t) ?>"><?= h($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">入社日（任意）</label>
            <input name="hire_date" type="date" class="form-control">
          </div>
          <div class="col-12">
            <button class="btn btn-success">追加して詳細を編集</button>
            <span class="text-muted small ms-2">作成後、詳細画面でメンター・1on1・育成項目などを設定できます。</span>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
