<?php
// 質問管理（admin）：講師への質問を作成・編集。全講師向け or 特定講師向け。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role('admin');
$user = current_user();

$flash = '';
$err   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $text   = trim($_POST['text'] ?? '');
    $target = ($_POST['target_staff_id'] ?? '') !== '' ? (int)$_POST['target_staff_id'] : null;
    $sort   = (int)($_POST['sort_order'] ?? 0);
    if ($text === '') {
      $err = '質問文を入力してください。';
    } else {
      db()->prepare("INSERT INTO questions (tenant_id, text, target_staff_id, is_active, sort_order) VALUES (1, ?, ?, 1, ?)")
          ->execute([$text, $target, $sort]);
      $flash = '質問を追加しました。';
    }
  } elseif ($action === 'update') {
    $id     = (int)($_POST['id'] ?? 0);
    $text   = trim($_POST['text'] ?? '');
    $target = ($_POST['target_staff_id'] ?? '') !== '' ? (int)$_POST['target_staff_id'] : null;
    $sort   = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    db()->prepare("UPDATE questions SET text = ?, target_staff_id = ?, sort_order = ?, is_active = ? WHERE id = ?")
        ->execute([$text, $target, $sort, $active, $id]);
    $flash = '質問を更新しました。';
  } elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
      $pdo->prepare("DELETE FROM answers WHERE question_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$id]);
      $pdo->commit();
      $flash = '質問を削除しました（回答も削除）。';
    } catch (Throwable $e) { $pdo->rollBack(); $err = '削除に失敗しました。'; }
  }
}

$staffOptions = db()->query("SELECT id, name FROM staff WHERE is_active = 1 ORDER BY name")->fetchAll();
$staffName = [];
foreach ($staffOptions as $s) { $staffName[(int)$s['id']] = $s['name']; }

$rows = db()->query(
  "SELECT q.*, (SELECT COUNT(*) FROM answers a WHERE a.question_id = q.id) AS answer_count
     FROM questions q WHERE q.tenant_id = 1 ORDER BY q.sort_order, q.id")->fetchAll();

render_header('質問管理', $user, 'questions.php');
?>
  <div class="container py-4" style="max-width:900px">
    <h4 class="mb-3">質問管理</h4>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-4">
      <div class="card-header">質問を追加</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="col-md-6">
            <label class="form-label small mb-0">質問文</label>
            <input name="text" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">対象</label>
            <select name="target_staff_id" class="form-select form-select-sm">
              <option value="">全講師向け</option>
              <?php foreach ($staffOptions as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">表示順</label>
            <input name="sort_order" type="number" value="0" class="form-control form-control-sm">
          </div>
          <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">追加</button></div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body py-2">
        <?php if (!$rows): ?>
          <div class="text-muted small py-2">質問はまだありません。</div>
        <?php endif; ?>
        <?php foreach ($rows as $q): ?>
          <form method="post" class="row g-2 align-items-center mb-2 border-bottom pb-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <div class="col-md-6"><input name="text" value="<?= h($q['text']) ?>" class="form-control form-control-sm"></div>
            <div class="col-md-2">
              <select name="target_staff_id" class="form-select form-select-sm">
                <option value="">全講師</option>
                <?php foreach ($staffOptions as $s): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= (int)$q['target_staff_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-1"><input name="sort_order" type="number" value="<?= (int)$q['sort_order'] ?>" class="form-control form-control-sm"></div>
            <div class="col-md-1 form-check">
              <input class="form-check-input" type="checkbox" name="is_active" <?= $q['is_active'] ? 'checked' : '' ?> id="a<?= (int)$q['id'] ?>">
              <label class="form-check-label small" for="a<?= (int)$q['id'] ?>">有効</label>
            </div>
            <div class="col-md-1 text-muted small">回答<?= (int)$q['answer_count'] ?></div>
            <div class="col-md-1 d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary">保存</button>
            </div>
          </form>
          <form method="post" class="mb-3 text-end" onsubmit="return confirm('この質問を削除します（回答も削除）。よろしいですか？');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
            <button class="btn btn-sm btn-link text-danger p-0">この質問を削除</button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
