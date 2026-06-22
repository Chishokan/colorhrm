<?php
// シフト管理（admin/staff）。申請を確定（授業分を入力して shift_days 化）／却下、確定シフトの編集・削除。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

// staff の実在カラム（is_active 有無に強くする）
$staffCols = [];
foreach (db()->query("SHOW COLUMNS FROM staff")->fetchAll() as $c) { $staffCols[$c['Field']] = true; }
$hasIsActive = isset($staffCols['is_active']);

// 担当教室スコープ：admin=全員(null) / staff=担当教室の講師IDのみ
$scope   = scoped_staff_ids($user);
$inScope = fn($sid) => $scope === null || in_array((int)$sid, $scope, true);
$scopeSql = ''; $scopeParams = [];
if (is_array($scope)) {
  if ($scope) { $scopeSql = ' AND staff_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $scopeParams = $scope; }
  else { $scopeSql = ' AND 1=0'; }
}

$flash = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'confirm') {
    $appId = (int)($_POST['id'] ?? 0);
    $cls   = max(0, (int)($_POST['class_minutes'] ?? 0));
    $a = db()->prepare("SELECT * FROM shift_applications WHERE id=? AND status='申請中'");
    $a->execute([$appId]);
    $a = $a->fetch();
    if ($a && $inScope($a['staff_id'])) {
      $tot = shift_minutes($a['start_time'], $a['end_time']);
      if ($cls > $tot) $cls = $tot;
      db()->prepare("INSERT INTO shift_days (tenant_id,staff_id,work_date,start_time,end_time,class_minutes,note,application_id) VALUES (1,?,?,?,?,?,?,?)")
          ->execute([$a['staff_id'], $a['work_date'], $a['start_time'], $a['end_time'], $cls, $a['note'], $appId]);
      db()->prepare("UPDATE shift_applications SET status='確定' WHERE id=?")->execute([$appId]);
      $flash = 'シフトを確定しました。';
    }
  } elseif ($action === 'reject') {
    $rid = (int)($_POST['id'] ?? 0);
    $rs = db()->prepare("SELECT staff_id FROM shift_applications WHERE id=? AND status='申請中'");
    $rs->execute([$rid]); $rsid = $rs->fetchColumn();
    if ($rsid !== false && $inScope($rsid)) {
      db()->prepare("UPDATE shift_applications SET status='却下' WHERE id=?")->execute([$rid]);
      $flash = '申請を却下しました。';
    }
  } elseif ($action === 'confirm_month') {
    // その月の申請中をまとめて確定シフト化（授業分は0で確定、後から各行で入力）
    $m = valid_month($_POST['month'] ?? '');
    $list = db()->prepare("SELECT * FROM shift_applications WHERE status='申請中' AND DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql);
    $list->execute(array_merge([$m], $scopeParams));
    $rows = $list->fetchAll();
    db()->beginTransaction();
    try {
      $ins = db()->prepare("INSERT INTO shift_days (tenant_id,staff_id,work_date,start_time,end_time,class_minutes,note,application_id) VALUES (1,?,?,?,?,?,?,?)");
      $upd = db()->prepare("UPDATE shift_applications SET status='確定' WHERE id=?");
      $n = 0;
      foreach ($rows as $a) {
        if (!$inScope($a['staff_id'])) continue;
        $ins->execute([$a['staff_id'], $a['work_date'], $a['start_time'], $a['end_time'], 0, $a['note'], $a['id']]);
        $upd->execute([$a['id']]);
        $n++;
      }
      db()->commit();
      $flash = "{$n}件をまとめて確定しました（授業分は各行で入力してください）。";
    } catch (Throwable $e) {
      db()->rollBack();
      $err = '一括確定に失敗しました: ' . $e->getMessage();
    }
  } elseif ($action === 'add_day') {
    $sid = (int)($_POST['staff_id'] ?? 0);
    $d   = trim($_POST['work_date'] ?? '');
    $st  = trim($_POST['start_time'] ?? '');
    $et  = trim($_POST['end_time'] ?? '');
    $cls = max(0, (int)($_POST['class_minutes'] ?? 0));
    $note= trim($_POST['note'] ?? '');
    if ($sid <= 0 || !$inScope($sid) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || shift_minutes($st, $et) <= 0) {
      $err = '講師・日付・開始/終了（終了は開始より後）を正しく入力してください。';
    } else {
      $tot = shift_minutes($st, $et); if ($cls > $tot) $cls = $tot;
      db()->prepare("INSERT INTO shift_days (tenant_id,staff_id,work_date,start_time,end_time,class_minutes,note) VALUES (1,?,?,?,?,?,?)")
          ->execute([$sid, $d, $st, $et, $cls, $note]);
      $flash = '確定シフトを追加しました。';
    }
  } elseif ($action === 'update_day') {
    $id  = (int)($_POST['id'] ?? 0);
    $st  = trim($_POST['start_time'] ?? '');
    $et  = trim($_POST['end_time'] ?? '');
    $cls = max(0, (int)($_POST['class_minutes'] ?? 0));
    $os = db()->prepare("SELECT staff_id FROM shift_days WHERE id=?"); $os->execute([$id]); $osid = $os->fetchColumn();
    if ($osid !== false && $inScope($osid) && shift_minutes($st, $et) > 0) {
      $tot = shift_minutes($st, $et); if ($cls > $tot) $cls = $tot;
      db()->prepare("UPDATE shift_days SET start_time=?, end_time=?, class_minutes=? WHERE id=?")->execute([$st, $et, $cls, $id]);
      $flash = '確定シフトを更新しました。';
    } else { $err = '終了は開始より後、または権限のあるシフトを指定してください。'; }
  } elseif ($action === 'delete_day') {
    $id = (int)($_POST['id'] ?? 0);
    $os = db()->prepare("SELECT staff_id FROM shift_days WHERE id=?"); $os->execute([$id]); $osid = $os->fetchColumn();
    if ($osid !== false && $inScope($osid)) {
      db()->prepare("DELETE FROM shift_days WHERE id=?")->execute([$id]);
      $flash = '確定シフトを削除しました。';
    }
  }
}

$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));

// 講師名の引き当て
$names = [];
foreach (db()->query("SELECT id, name FROM staff")->fetchAll() as $s) { $names[(int)$s['id']] = $s['name']; }
$staffOpts = db()->query("SELECT id, name FROM staff" . ($hasIsActive ? " WHERE is_active=1" : "") . " ORDER BY name")->fetchAll();
if ($scope !== null) { $staffOpts = array_values(array_filter($staffOpts, fn($s) => in_array((int)$s['id'], $scope, true))); }

// 申請中（担当教室スコープ）
$pending = db()->prepare("SELECT * FROM shift_applications WHERE status='申請中' AND DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql . " ORDER BY work_date, start_time");
$pending->execute(array_merge([$month], $scopeParams));
$pending = $pending->fetchAll();

// 確定シフト（担当教室スコープ）
$days = db()->prepare("SELECT * FROM shift_days WHERE DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql . " ORDER BY work_date, staff_id, start_time");
$days->execute(array_merge([$month], $scopeParams));
$days = $days->fetchAll();

render_header('シフト管理', $user, 'shifts_admin.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">シフト管理</h4>
      <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
        <span class="btn btn-light disabled"><?= h($month) ?></span>
        <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
      </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>申請中（<?= count($pending) ?>件）— 授業分を入れて「確定」</span>
        <?php if ($pending): ?>
          <form method="post" onsubmit="return confirm('<?= h($month) ?> の申請 <?= count($pending) ?>件を授業分0でまとめて確定します。よろしいですか？（授業分は後で各行で入力できます）');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_month">
            <input type="hidden" name="month" value="<?= h($month) ?>">
            <button class="btn btn-sm btn-success">この月をまとめて確定</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>講師</th><th>日付</th><th>時間</th><th class="text-end">稼働</th><th>メモ</th><th style="width:120px">授業(分)</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($pending as $a): $tot = shift_minutes($a['start_time'],$a['end_time']); ?>
              <tr>
                <td><?= h($names[(int)$a['staff_id']] ?? ('#'.$a['staff_id'])) ?></td>
                <td><?= h($a['work_date']) ?></td>
                <td><?= h(hm($a['start_time'])) ?>〜<?= h(hm($a['end_time'])) ?></td>
                <td class="text-end"><?= h(fmt_hm($tot)) ?></td>
                <td class="small text-muted"><?= h($a['note']) ?></td>
                <td>
                  <form method="post" class="d-flex gap-1" id="cf<?= (int)$a['id'] ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="number" name="class_minutes" value="0" min="0" max="<?= $tot ?>" class="form-control form-control-sm">
                  </form>
                </td>
                <td class="text-end text-nowrap">
                  <button form="cf<?= (int)$a['id'] ?>" class="btn btn-sm btn-success">確定</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('却下しますか？');"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-outline-secondary">却下</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$pending): ?><tr><td colspan="7" class="text-center text-muted py-3">申請中はありません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header">確定シフトを直接追加</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_day">
          <div class="col-auto"><label class="form-label small mb-0">講師</label>
            <select name="staff_id" class="form-select form-select-sm" required><option value="">選択</option><?php foreach ($staffOpts as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-auto"><label class="form-label small mb-0">日付</label><input type="date" name="work_date" value="<?= h($month) ?>-01" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">開始</label><input type="time" name="start_time" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">終了</label><input type="time" name="end_time" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">授業(分)</label><input type="number" name="class_minutes" value="0" min="0" class="form-control form-control-sm" style="width:90px"></div>
          <div class="col"><label class="form-label small mb-0">メモ</label><input name="note" class="form-control form-control-sm"></div>
          <div class="col-auto"><button class="btn btn-sm btn-outline-success">追加</button></div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header">確定シフト（<?= h($month) ?>）</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>講師</th><th>日付</th><th style="width:110px">開始</th><th style="width:110px">終了</th><th class="text-end">稼働</th><th style="width:110px">授業(分)</th><th class="text-end">運営</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($days as $d): $tot=shift_minutes($d['start_time'],$d['end_time']); $cls=min((int)$d['class_minutes'],$tot); ?>
              <tr>
                <td><?= h($names[(int)$d['staff_id']] ?? ('#'.$d['staff_id'])) ?></td>
                <td><?= h($d['work_date']) ?></td>
                <form method="post" id="ud<?= (int)$d['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="update_day"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"></form>
                <td><input form="ud<?= (int)$d['id'] ?>" type="time" name="start_time" value="<?= h(hm($d['start_time'])) ?>" class="form-control form-control-sm"></td>
                <td><input form="ud<?= (int)$d['id'] ?>" type="time" name="end_time" value="<?= h(hm($d['end_time'])) ?>" class="form-control form-control-sm"></td>
                <td class="text-end"><?= h(fmt_hm($tot)) ?></td>
                <td><input form="ud<?= (int)$d['id'] ?>" type="number" name="class_minutes" value="<?= (int)$d['class_minutes'] ?>" min="0" max="<?= $tot ?>" class="form-control form-control-sm"></td>
                <td class="text-end"><?= h(fmt_hm($tot - $cls)) ?></td>
                <td class="text-end text-nowrap">
                  <button form="ud<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-primary">保存</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_day"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0">削除</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$days): ?><tr><td colspan="8" class="text-center text-muted py-3">確定シフトはありません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
