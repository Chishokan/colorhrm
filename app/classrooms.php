<?php
// 教室マスター管理（admin）。配属教室・担当教室の選択肢になる。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role('admin');
$user = current_user();

$flash = ''; $err = '';

// テーブル有無（未マイグレーションでも落ちない）
$hasTable = true;
try { db()->query("SELECT 1 FROM classrooms LIMIT 1"); }
catch (Throwable $e) { $hasTable = false; }

if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { $err = '教室名を入力してください。'; }
    else {
      try {
        db()->prepare("INSERT INTO classrooms (tenant_id,name,sort_order,is_active) VALUES (1,?,?,1)")
            ->execute([$name, (int)($_POST['sort_order'] ?? 0)]);
        $flash = "教室「{$name}」を追加しました。";
      } catch (Throwable $e) { $err = 'その教室名は既に存在します。'; }
    }
  } elseif ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    if ($name !== '') {
      try {
        db()->prepare("UPDATE classrooms SET name=?, sort_order=?, is_active=? WHERE id=?")->execute([$name, $sort, $active, $id]);
        $flash = '教室を更新しました。';
      } catch (Throwable $e) { $err = 'その教室名は既に存在します。'; }
    }
  } elseif ($action === 'delete') {
    db()->prepare("DELETE FROM classrooms WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
    $flash = '教室を削除しました（講師・staffの配属設定の文字列は変わりません）。';
  }
}

$rows = $hasTable ? db()->query("SELECT * FROM classrooms ORDER BY sort_order, name")->fetchAll() : [];

render_header('教室マスター', $user, 'classrooms.php');
?>
  <div class="container py-4" style="max-width:720px">
    <h4 class="mb-3">教室マスター</h4>
    <?php if (!$hasTable): ?>
      <div class="alert alert-warning">教室テーブル（classrooms）がありません。<code>migrations/012_classrooms.sql</code> を実行してください。</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <?php if ($hasTable): ?>
    <div class="card shadow-sm mb-4">
      <div class="card-header">教室を追加</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="add">
          <div class="col-md-6"><label class="form-label small mb-0">教室名</label><input name="name" class="form-control form-control-sm" required></div>
          <div class="col-md-3"><label class="form-label small mb-0">表示順</label><input name="sort_order" type="number" value="0" class="form-control form-control-sm"></div>
          <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">追加</button></div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>教室名</th><th style="width:90px">表示順</th><th style="width:80px">有効</th><th class="text-end" style="width:160px">操作</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <form method="post" id="u<?= (int)$r['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"></form>
              <td><input form="u<?= (int)$r['id'] ?>" name="name" value="<?= h($r['name']) ?>" class="form-control form-control-sm"></td>
              <td><input form="u<?= (int)$r['id'] ?>" name="sort_order" type="number" value="<?= (int)$r['sort_order'] ?>" class="form-control form-control-sm"></td>
              <td class="text-center"><input form="u<?= (int)$r['id'] ?>" type="checkbox" name="is_active" class="form-check-input" <?= $r['is_active'] ? 'checked' : '' ?>></td>
              <td class="text-end">
                <button form="u<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">保存</button>
                <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0">削除</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="4" class="text-center text-muted py-3">教室がまだありません。</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-muted small mt-3">※ ここで作った教室が、講師詳細の「配属教室」とユーザー管理の「担当教室」の選択肢になります。</p>
    <?php endif; ?>
  </div>
<?php render_footer(); ?>
