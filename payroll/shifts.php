<?php
// シフト申請（講師）。自分の「シフト可能」を月ごとに申請・取消し、確定状況を確認する。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$staffId = (int)($user['staff_id'] ?? 0);

// 講師（staff 紐付け）以外は申請できない
if ($staffId <= 0) {
  render_header('シフト申請', $user, 'shifts.php');
  echo '<div class="container py-4"><div class="alert alert-warning">このアカウントは講師（staff）に紐付いていないため、シフト申請はできません。管理者にお問い合わせください。</div></div>';
  render_footer();
  exit;
}

$flash = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'add') {
    $d  = trim($_POST['work_date'] ?? '');
    $st = trim($_POST['start_time'] ?? '');
    $et = trim($_POST['end_time'] ?? '');
    $note = trim($_POST['note'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $st === '' || $et === '') {
      $err = '日付・開始・終了を入力してください。';
    } elseif (shift_minutes($st, $et) <= 0) {
      $err = '終了時刻は開始時刻より後にしてください。';
    } else {
      db()->prepare("INSERT INTO shift_applications (tenant_id,staff_id,work_date,start_time,end_time,note,status) VALUES (1,?,?,?,?,?,'申請中')")
          ->execute([$staffId, $d, $st, $et, $note]);
      $flash = 'シフトを申請しました。';
    }
  } elseif ($action === 'cancel') {
    // 自分の「申請中」のみ取消可
    db()->prepare("DELETE FROM shift_applications WHERE id=? AND staff_id=? AND status='申請中'")
        ->execute([(int)($_POST['id'] ?? 0), $staffId]);
    $flash = '申請を取り消しました。';
  }
}

$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));

$apps = db()->prepare("SELECT * FROM shift_applications WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? ORDER BY work_date, start_time");
$apps->execute([$staffId, $month]);
$apps = $apps->fetchAll();

$days = db()->prepare("SELECT * FROM shift_days WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? ORDER BY work_date, start_time");
$days->execute([$staffId, $month]);
$days = $days->fetchAll();

function status_badge($s) {
  $map = ['申請中' => 'bg-warning text-dark', '確定' => 'bg-success', '却下' => 'bg-secondary'];
  return $map[$s] ?? 'bg-light text-dark border';
}

render_header('シフト申請', $user, 'shifts.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">シフト申請</h4>
      <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
        <span class="btn btn-light disabled"><?= h($month) ?></span>
        <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
      </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
      <div class="card-header">シフトを申請</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="add">
          <div class="col-auto"><label class="form-label small mb-0">日付</label><input type="date" name="work_date" value="<?= h($month) ?>-01" min="<?= h($month) ?>-01" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">開始</label><input type="time" name="start_time" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">終了</label><input type="time" name="end_time" class="form-control form-control-sm" required></div>
          <div class="col"><label class="form-label small mb-0">メモ</label><input name="note" class="form-control form-control-sm" placeholder="任意（校舎・備考など）"></div>
          <div class="col-auto"><button class="btn btn-sm btn-success">申請する</button></div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header">申請状況（<?= h($month) ?>）</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>日付</th><th>時間</th><th class="text-end">稼働</th><th>メモ</th><th>状態</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($apps as $a): $m = shift_minutes($a['start_time'], $a['end_time']); ?>
              <tr>
                <td><?= h($a['work_date']) ?></td>
                <td><?= h(hm($a['start_time'])) ?>〜<?= h(hm($a['end_time'])) ?></td>
                <td class="text-end"><?= h(fmt_hm($m)) ?></td>
                <td class="small text-muted"><?= h($a['note']) ?></td>
                <td><span class="badge <?= status_badge($a['status']) ?>"><?= h($a['status']) ?></span></td>
                <td class="text-end">
                  <?php if ($a['status'] === '申請中'): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('取り消しますか？');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0">取消</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$apps): ?><tr><td colspan="6" class="text-center text-muted py-3">この月の申請はありません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header">確定シフト（<?= h($month) ?>）</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>日付</th><th>時間</th><th class="text-end">稼働</th><th class="text-end">授業</th><th class="text-end">運営</th><th>メモ</th></tr></thead>
          <tbody>
            <?php $tt=0;$tc=0; foreach ($days as $d): $tot=shift_minutes($d['start_time'],$d['end_time']); $cls=min((int)$d['class_minutes'],$tot); $ops=$tot-$cls; $tt+=$tot;$tc+=$cls; ?>
              <tr>
                <td><?= h($d['work_date']) ?></td>
                <td><?= h(hm($d['start_time'])) ?>〜<?= h(hm($d['end_time'])) ?></td>
                <td class="text-end"><?= h(fmt_hm($tot)) ?></td>
                <td class="text-end"><?= h(fmt_hm($cls)) ?></td>
                <td class="text-end"><?= h(fmt_hm($ops)) ?></td>
                <td class="small text-muted"><?= h($d['note']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$days): ?><tr><td colspan="6" class="text-center text-muted py-3">確定シフトはまだありません。</td></tr>
            <?php else: ?><tr class="table-light fw-bold"><td colspan="2" class="text-end">合計</td><td class="text-end"><?= h(fmt_hm($tt)) ?></td><td class="text-end"><?= h(fmt_hm($tc)) ?></td><td class="text-end"><?= h(fmt_hm($tt-$tc)) ?></td><td></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
