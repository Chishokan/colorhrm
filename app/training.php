<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$flash    = '';
$staffId  = (int)($_GET['staff_id'] ?? 0); // 指定時はその講師の進捗グリッド

// ------------------------------------------------------------
// POST：承認 / 差戻し / 直接ステータス更新
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action     = $_POST['action'] ?? '';
  $progressId = (int)($_POST['progress_id'] ?? 0);

  if ($action === 'approve' || $action === 'reject') {
    // 申告に対する承認/差戻し
    $newStatus = $action === 'approve' ? ($_POST['result'] ?? '合格') : '差戻し';
    if (!in_array($newStatus, ['合格', '受講済', '不合格', '差戻し'], true)) {
      $newStatus = '合格';
    }
    $completed = ($newStatus === '合格' || $newStatus === '受講済') ? date('Y-m-d') : null;
    $sql = "UPDATE training_progress
               SET status = ?, approved_by = ?, approved_at = NOW(), completed_date = ?
             WHERE id = ?";
    db()->prepare($sql)->execute([$newStatus, (int)$user['id'], $completed, $progressId]);
    $flash = ($action === 'approve' ? '承認' : '差戻し') . 'しました。';

  } elseif ($action === 'set_status') {
    // 講師の進捗グリッドからの直接更新（upsert）
    $sStaff = (int)($_POST['staff_id'] ?? 0);
    $itemId = (int)($_POST['item_id'] ?? 0);
    $status = $_POST['status'] ?? '未着手';
    if (!in_array($status, training_statuses(), true)) {
      $status = '未着手';
    }
    $completed = ($status === '合格' || $status === '受講済') ? date('Y-m-d') : null;

    // tenant_id は対象 staff から取得
    $ts = db()->prepare("SELECT tenant_id FROM staff WHERE id = ? LIMIT 1");
    $ts->execute([$sStaff]);
    $tenantId = (int)($ts->fetchColumn() ?: 1);

    $sql = "INSERT INTO training_progress
              (tenant_id, staff_id, training_item_id, status, completed_date, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              status=VALUES(status), completed_date=VALUES(completed_date),
              approved_by=VALUES(approved_by), approved_at=NOW()";
    db()->prepare($sql)->execute([$tenantId, $sStaff, $itemId, $status, $completed, (int)$user['id']]);
    $flash = 'ステータスを更新しました。';
    $staffId = $sStaff; // 更新した講師の画面に留まる
  }
}

render_header('研修管理', $user, 'training.php');
?>
  <div class="container py-4">

    <?php if ($flash): ?>
      <div class="alert alert-success py-2"><?= h($flash) ?></div>
    <?php endif; ?>

<?php if (!$staffId): ?>

    <!-- ============ 承認インボックス（申告中の一覧） ============ -->
    <?php
      $sql = "SELECT tp.id, tp.declared_at, tp.memo, tp.evidence_file,
                     s.id AS staff_id, s.name AS staff_name,
                     ti.item_name, ti.department, ti.target_color, ti.is_required, ti.type
              FROM training_progress tp
              JOIN staff s          ON s.id = tp.staff_id
              JOIN training_items ti ON ti.id = tp.training_item_id
              WHERE tp.status = '申告中'
              ORDER BY tp.declared_at";
      $pending = db()->query($sql)->fetchAll();
    ?>
    <h4 class="mb-3">承認待ち <span class="badge bg-warning text-dark"><?= count($pending) ?></span></h4>

    <?php if (!$pending): ?>
      <div class="alert alert-light border">承認待ちの申告はありません。</div>
    <?php else: ?>
      <div class="card shadow-sm mb-4">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>講師</th><th>研修項目</th><th>部門/目標</th><th>申告日時</th><th>メモ</th>
              <th class="text-end" style="width:260px">承認</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending as $p): ?>
              <tr>
                <td><a href="training.php?staff_id=<?= (int)$p['staff_id'] ?>"><?= h($p['staff_name']) ?></a></td>
                <td>
                  <?= h($p['item_name']) ?>
                  <span class="badge bg-light text-dark border"><?= h(training_type_label($p['type'] ?? '')) ?></span>
                  <?= $p['is_required'] ? ' <span class="badge bg-dark">必須</span>' : '' ?>
                </td>
                <td class="small text-muted">
                  <?= h($p['department'] ?: '共通') ?> /
                  <span class="badge" style="<?= color_style($p['target_color']) ?>"><?= h($p['target_color']) ?></span>
                </td>
                <td class="small text-muted"><?= h($p['declared_at']) ?></td>
                <td class="small">
                  <?= h($p['memo']) ?>
                  <?php if (!empty($p['evidence_file'])): ?>
                    <a href="evidence_view.php?id=<?= (int)$p['id'] ?>" target="_blank" class="badge bg-secondary text-decoration-none">写真</a>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <form method="post" class="d-inline-flex gap-1 justify-content-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="progress_id" value="<?= (int)$p['id'] ?>">
                    <select name="result" class="form-select form-select-sm" style="width:90px">
                      <option value="合格">合格</option>
                      <option value="受講済">受講済</option>
                      <option value="不合格">不合格</option>
                    </select>
                    <button name="action" value="approve" class="btn btn-sm btn-success">承認</button>
                    <button name="action" value="reject" class="btn btn-sm btn-outline-danger">差戻し</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- ============ 講師一覧（進捗グリッドへ） ============ -->
    <h5 class="mb-2">講師ごとの進捗</h5>
    <?php $staffList = db()->query("SELECT id, name, color_rank, target_rank, departments FROM staff WHERE is_active = 1 ORDER BY name")->fetchAll(); ?>
    <div class="list-group shadow-sm">
      <?php foreach ($staffList as $s): ?>
        <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
           href="training.php?staff_id=<?= (int)$s['id'] ?>">
          <span><?= h($s['name']) ?> <span class="text-muted small">（<?= h($s['departments']) ?>）</span></span>
          <span>
            <span class="badge" style="<?= color_style($s['color_rank']) ?>"><?= h($s['color_rank']) ?></span>
            <?php if ($s['target_rank']): ?>→ <span class="badge" style="<?= color_style($s['target_rank']) ?>"><?= h($s['target_rank']) ?></span><?php endif; ?>
          </span>
        </a>
      <?php endforeach; ?>
    </div>

<?php else: ?>

    <!-- ============ 講師の進捗グリッド（直接編集） ============ -->
    <?php
      $st = db()->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
      $st->execute([$staffId]);
      $staff = $st->fetch();
    ?>
    <?php if (!$staff): ?>
      <div class="alert alert-danger">講師が見つかりません。 <a href="training.php">戻る</a></div>
    <?php else: ?>
      <?php
        $targetColor = $staff['target_rank'] ?: $staff['color_rank'];
        $q = db()->prepare(
          "SELECT ti.*, tp.status, tp.completed_date, tp.declared_at, tp.approved_at
             FROM training_items ti
             LEFT JOIN training_progress tp
                    ON tp.training_item_id = ti.id AND tp.staff_id = ?
            WHERE ti.target_color = ?
            ORDER BY ti.department, ti.sort_order, ti.id");
        $q->execute([$staffId, $targetColor]);
        $rows = $q->fetchAll();
        $myDepts = array_map('trim', explode(',', (string)$staff['departments']));
      ?>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><?= h($staff['name']) ?> の研修進捗
          <small class="text-muted">（目標: <?= h($targetColor) ?>）</small></h4>
        <a href="training.php" class="btn btn-sm btn-outline-secondary">← 一覧へ</a>
      </div>

      <div class="card shadow-sm">
        <table class="table align-middle mb-0">
          <thead class="table-light">
            <tr><th>部門</th><th>研修項目</th><th>必須</th><th style="width:320px">状態を更新</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $it): ?>
              <?php if (!($it['department'] === '' || $it['department'] === '共通' || in_array($it['department'], $myDepts, true))) continue; ?>
              <?php $status = $it['status'] ?: '未着手'; ?>
              <tr>
                <td class="small text-muted"><?= h($it['department'] ?: '共通') ?></td>
                <td><?= h($it['item_name']) ?> <span class="badge bg-light text-dark border"><?= h(training_type_label($it['type'] ?? '')) ?></span></td>
                <td><?= $it['is_required'] ? '<span class="badge bg-dark">必須</span>' : '<span class="text-muted small">任意</span>' ?></td>
                <td>
                  <form method="post" class="d-inline-flex gap-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="staff_id" value="<?= (int)$staff['id'] ?>">
                    <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                    <select name="status" class="form-select form-select-sm" style="width:130px">
                      <?php foreach (training_statuses() as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $opt === $status ? 'selected' : '' ?>><?= h($opt) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-primary">更新</button>
                    <span class="badge <?= status_badge_class($status) ?> align-self-center"><?= h($status) ?></span>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

<?php endif; ?>
  </div>
<?php render_footer(); ?>
