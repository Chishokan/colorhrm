<?php
// 研修コンテンツ（動画/資料）管理（admin）。module_key で研修項目と連携。
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
  if ($action === 'create' || $action === 'update') {
    $mk    = trim($_POST['module_key'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $sort  = (int)($_POST['sort_order'] ?? 0);
    $mat   = trim($_POST['material'] ?? '');
    $vurl  = trim($_POST['video_url'] ?? '');
    $vdur  = trim($_POST['video_duration'] ?? '');
    $note  = trim($_POST['note'] ?? '');
    if ($mk === '' || $title === '') {
      $err = 'モジュールキーとタイトルは必須です。';
    } elseif ($action === 'create') {
      db()->prepare("INSERT INTO lessons (tenant_id, module_key, sort_order, title, material, video_url, video_duration, note) VALUES (1,?,?,?,?,?,?,?)")
          ->execute([$mk, $sort, $title, $mat, $vurl, $vdur, $note]);
      $flash = 'レッスンを追加しました。';
    } else {
      $id = (int)($_POST['id'] ?? 0);
      db()->prepare("UPDATE lessons SET module_key=?, sort_order=?, title=?, material=?, video_url=?, video_duration=?, note=? WHERE id=?")
          ->execute([$mk, $sort, $title, $mat, $vurl, $vdur, $note, $id]);
      $flash = 'レッスンを更新しました。';
    }
  } elseif ($action === 'delete') {
    db()->prepare("DELETE FROM lessons WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
    $flash = 'レッスンを削除しました。';
  }
}

// module_key の候補（lessons ＋ training_items から）
$moduleKeys = db()->query("SELECT DISTINCT module_key FROM lessons WHERE module_key<>'' UNION SELECT DISTINCT module_key FROM training_items WHERE module_key<>'' ORDER BY module_key")->fetchAll(PDO::FETCH_COLUMN);
$rows = db()->query("SELECT * FROM lessons ORDER BY module_key, sort_order, id")->fetchAll();
$byMod = [];
foreach ($rows as $r) { $byMod[$r['module_key']][] = $r; }

render_header('研修動画/資料', $user, 'lessons.php');
?>
  <div class="container py-4">
    <h4 class="mb-3">研修コンテンツ（動画/資料）</h4>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-4">
      <div class="card-header">レッスンを追加</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="col-md-2"><label class="form-label small mb-0">モジュール</label>
            <input name="module_key" list="mklist" class="form-control form-control-sm" required>
            <datalist id="mklist"><?php foreach ($moduleKeys as $m): ?><option value="<?= h($m) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="col-md-1"><label class="form-label small mb-0">順</label><input name="sort_order" type="number" value="0" class="form-control form-control-sm"></div>
          <div class="col-md-3"><label class="form-label small mb-0">タイトル</label><input name="title" class="form-control form-control-sm" required></div>
          <div class="col-md-3"><label class="form-label small mb-0">資料URL</label><input name="material" class="form-control form-control-sm"></div>
          <div class="col-md-2"><label class="form-label small mb-0">動画URL</label><input name="video_url" class="form-control form-control-sm"></div>
          <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">追加</button></div>
        </form>
      </div>
    </div>

    <?php if (!$rows): ?><div class="alert alert-light border">レッスンはまだありません。</div><?php endif; ?>
    <?php foreach ($byMod as $mk => $list): ?>
      <h6 class="mb-1">モジュール: <span class="badge bg-dark"><?= h($mk) ?></span>
        <a href="lessons_view.php?module=<?= rawurlencode($mk) ?>" target="_blank" class="small">プレビュー</a></h6>
      <div class="card shadow-sm mb-3"><div class="card-body py-2">
        <?php foreach ($list as $l): ?>
          <form method="post" class="row g-1 align-items-center mb-2 border-bottom pb-2">
            <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <div class="col-md-1"><input name="module_key" value="<?= h($l['module_key']) ?>" class="form-control form-control-sm"></div>
            <div class="col-md-1"><input name="sort_order" type="number" value="<?= (int)$l['sort_order'] ?>" class="form-control form-control-sm"></div>
            <div class="col-md-3"><input name="title" value="<?= h($l['title']) ?>" class="form-control form-control-sm"></div>
            <div class="col-md-3"><input name="material" value="<?= h($l['material']) ?>" class="form-control form-control-sm" placeholder="資料URL"></div>
            <div class="col-md-2"><input name="video_url" value="<?= h($l['video_url']) ?>" class="form-control form-control-sm" placeholder="動画URL"></div>
            <div class="col-md-1"><input name="video_duration" value="<?= h($l['video_duration']) ?>" class="form-control form-control-sm" placeholder="時間"></div>
            <div class="col-md-1 d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary">保存</button>
            </div>
            <div class="col-12"><input name="note" value="<?= h($l['note']) ?>" class="form-control form-control-sm mt-1" placeholder="メモ"></div>
          </form>
          <form method="post" class="text-end mb-2" onsubmit="return confirm('削除しますか？');">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <button class="btn btn-sm btn-link text-danger p-0">削除</button>
          </form>
        <?php endforeach; ?>
      </div></div>
    <?php endforeach; ?>
  </div>
<?php render_footer(); ?>
