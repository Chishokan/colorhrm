<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role('admin');
$user = current_user();

$flash = '';
$err   = '';

function valid_color($c) {
  return in_array($c, training_target_colors(), true);
}

// ------------------------------------------------------------
// POST：作成 / 更新 / 削除
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'create' || $action === 'update') {
    $dept     = trim($_POST['department'] ?? '');
    $color    = $_POST['target_color'] ?? '';
    $name     = trim($_POST['item_name'] ?? '');
    $sort     = (int)($_POST['sort_order'] ?? 0);
    $required = isset($_POST['is_required']) ? 1 : 0;

    if ($name === '') {
      $err = '研修項目名は必須です。';
    } elseif (!valid_color($color)) {
      $err = '対象カラーが不正です。';
    } elseif ($action === 'create') {
      $sql = "INSERT INTO training_items
                (tenant_id, department, target_color, item_name, sort_order, is_required)
              VALUES (1, ?, ?, ?, ?, ?)";
      db()->prepare($sql)->execute([$dept, $color, $name, $sort, $required]);
      $flash = "研修項目「{$name}」を追加しました。";
    } else { // update
      $id  = (int)($_POST['id'] ?? 0);
      $sql = "UPDATE training_items
                 SET department = ?, target_color = ?, item_name = ?, sort_order = ?, is_required = ?
               WHERE id = ?";
      db()->prepare($sql)->execute([$dept, $color, $name, $sort, $required, $id]);
      $flash = '研修項目を更新しました。';
    }

  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    // 紐づく進捗も一緒に削除（INNER JOIN 表示のため孤児行を残さない）
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare("DELETE FROM training_progress WHERE training_item_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM training_items WHERE id = ?")->execute([$id]);
      $pdo->commit();
      $flash = '研修項目を削除しました（関連する進捗も削除）。';
    } catch (Throwable $e) {
      $pdo->rollBack();
      $err = '削除に失敗しました。';
    }
  }
}

// ------------------------------------------------------------
// 一覧（対象カラー → 部門 → 表示順）
// ------------------------------------------------------------
$rows = db()->query(
  "SELECT ti.*,
          (SELECT COUNT(*) FROM training_progress tp WHERE tp.training_item_id = ti.id) AS progress_count
     FROM training_items ti
    ORDER BY FIELD(ti.target_color,'GREEN','BLUE','YELLOW','RED'), ti.department, ti.sort_order, ti.id"
)->fetchAll();

// 対象カラーごとにグルーピング
$byColor = [];
foreach ($rows as $r) {
  $byColor[$r['target_color']][] = $r;
}

render_header('研修マスター', $user, 'training_master.php');
?>
  <div class="container py-4">

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <!-- 新規追加 -->
    <div class="card shadow-sm mb-4">
      <div class="card-header">研修項目を追加</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="col-md-2">
            <label class="form-label small mb-0">部門</label>
            <input name="department" type="text" class="form-control form-control-sm" placeholder="空＝共通">
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">対象カラー</label>
            <select name="target_color" class="form-select form-select-sm">
              <?php foreach (training_target_colors() as $c): ?>
                <option value="<?= h($c) ?>"><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">研修項目名</label>
            <input name="item_name" type="text" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-1">
            <label class="form-label small mb-0">表示順</label>
            <input name="sort_order" type="number" value="0" class="form-control form-control-sm">
          </div>
          <div class="col-md-1 form-check ms-2 mb-1">
            <input class="form-check-input" type="checkbox" name="is_required" value="1" id="req_new" checked>
            <label class="form-check-label small" for="req_new">必須</label>
          </div>
          <div class="col-md-1">
            <button class="btn btn-sm btn-primary w-100">追加</button>
          </div>
        </form>
      </div>
    </div>

    <!-- 一覧（カラーごと） -->
    <?php if (!$rows): ?>
      <div class="alert alert-light border">研修項目がまだ登録されていません。上のフォームから追加してください。</div>
    <?php endif; ?>

    <?php foreach (training_target_colors() as $color): ?>
      <?php if (empty($byColor[$color])) continue; ?>
      <h5 class="mb-2">
        対象カラー: <span class="badge" style="<?= color_style($color) ?>"><?= h($color) ?></span>
        <small class="text-muted"><?= count($byColor[$color]) ?>項目</small>
      </h5>
      <div class="card shadow-sm mb-4">
        <div class="card-body py-2">
          <div class="row g-2 text-muted small fw-bold border-bottom pb-1 mb-2 d-none d-md-flex">
            <div class="col-md-2">部門</div>
            <div class="col-md-4">研修項目名</div>
            <div class="col-md-2">対象カラー</div>
            <div class="col-md-1">表示順</div>
            <div class="col-md-1">必須</div>
            <div class="col-md-2 text-end">操作（進捗数）</div>
          </div>

          <?php foreach ($byColor[$color] as $it): ?>
            <!-- 1項目 = 1フォーム（table を使わず行ごとに form を完結させる） -->
            <form method="post" class="row g-2 align-items-center mb-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <div class="col-6 col-md-2">
                <input name="department" value="<?= h($it['department']) ?>" class="form-control form-control-sm" placeholder="共通">
              </div>
              <div class="col-6 col-md-4">
                <input name="item_name" value="<?= h($it['item_name']) ?>" class="form-control form-control-sm" required>
              </div>
              <div class="col-6 col-md-2">
                <select name="target_color" class="form-select form-select-sm">
                  <?php foreach (training_target_colors() as $c): ?>
                    <option value="<?= h($c) ?>" <?= $c === $it['target_color'] ? 'selected' : '' ?>><?= h($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-3 col-md-1">
                <input name="sort_order" type="number" value="<?= (int)$it['sort_order'] ?>" class="form-control form-control-sm">
              </div>
              <div class="col-2 col-md-1 form-check">
                <input class="form-check-input" type="checkbox" name="is_required" value="1" <?= $it['is_required'] ? 'checked' : '' ?>>
              </div>
              <div class="col-md-2 text-end">
                <button class="btn btn-sm btn-outline-primary">保存</button>
                <span class="text-muted small">(<?= (int)$it['progress_count'] ?>)</span>
              </div>
            </form>
            <!-- 削除は別フォーム -->
            <form method="post" class="mb-3 text-end"
                  onsubmit="return confirm('「<?= h($it['item_name']) ?>」を削除します。<?= (int)$it['progress_count'] ?>件の進捗も削除されます。よろしいですか？');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
              <button class="btn btn-sm btn-link text-danger p-0">この項目を削除</button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <p class="text-muted small">
      ※ 「部門」を空にすると、その対象カラーの全講師に共通の項目になります。
      講師には <code>departments</code> と <code>target_rank</code>（育成目標）の一致で表示されます。
    </p>
  </div>
<?php render_footer(); ?>
