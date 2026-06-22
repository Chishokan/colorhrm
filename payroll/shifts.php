<?php
// シフト可能登録（講師）。月間の表で1日=1区分（開始〜終了）を一括登録。
//   ・当月〜6か月先まで登録可。過去/範囲外は閲覧のみ。
//   ・「上をコピー」で前日の時間帯を複製。確定済みの日は編集不可。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$staffId = (int)($user['staff_id'] ?? 0);

if ($staffId <= 0) {
  render_header('シフト可能登録', $user, 'shifts.php');
  echo '<div class="container py-4"><div class="alert alert-warning">このアカウントは講師（staff）に紐付いていないため、シフト登録はできません。管理者にお問い合わせください。</div></div>';
  render_footer();
  exit;
}

[$winMin, $winMax] = shift_month_window();
$flash = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_month') {
  csrf_check();
  $m = valid_month($_POST['month'] ?? '');
  if ($m < $winMin || $m > $winMax) {
    $err = "登録できるのは当月（{$winMin}）〜6か月先（{$winMax}）です。";
  } else {
    $starts = (array)($_POST['start_time'] ?? []);
    $ends   = (array)($_POST['end_time'] ?? []);
    $notes  = (array)($_POST['note'] ?? []);
    // 確定済みの日は触らない
    $confirmed = [];
    $cf = db()->prepare("SELECT work_date FROM shift_applications WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? AND status='確定'");
    $cf->execute([$staffId, $m]);
    foreach ($cf->fetchAll(PDO::FETCH_COLUMN) as $wd) { $confirmed[$wd] = true; }
    db()->beginTransaction();
    try {
      db()->prepare("DELETE FROM shift_applications WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? AND status<>'確定'")
          ->execute([$staffId, $m]);
      $ins = db()->prepare("INSERT INTO shift_applications (tenant_id,staff_id,work_date,start_time,end_time,note,status) VALUES (1,?,?,?,?,?,'申請中')");
      $count = 0;
      foreach ($starts as $date => $st) {
        if (isset($confirmed[$date]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) continue;
        $st = trim((string)$st); $et = trim((string)($ends[$date] ?? '')); $note = trim((string)($notes[$date] ?? ''));
        if ($st === '' || $et === '' || shift_minutes($st, $et) <= 0) continue;
        $ins->execute([$staffId, $date, $st, $et, $note]);
        $count++;
      }
      db()->commit();
      $flash = "{$m} のシフト可能を保存しました（{$count}日）。";
    } catch (Throwable $e) {
      db()->rollBack();
      $err = '保存に失敗しました: ' . $e->getMessage();
    }
  }
}

$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));
$editable = ($month >= $winMin && $month <= $winMax);

$apps = db()->prepare("SELECT * FROM shift_applications WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=?");
$apps->execute([$staffId, $month]);
$appByDate = [];
foreach ($apps->fetchAll() as $a) { $appByDate[$a['work_date']] = $a; }

$days = db()->prepare("SELECT * FROM shift_days WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? ORDER BY work_date, start_time");
$days->execute([$staffId, $month]);
$days = $days->fetchAll();

$wd = jp_weekdays();
render_header('シフト可能登録', $user, 'shifts.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">シフト可能登録</h4>
      <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
        <span class="btn btn-light disabled"><?= h($month) ?></span>
        <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
      </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <?php if (!$editable): ?>
      <div class="alert alert-light border">この月は登録対象外です（登録できるのは当月〜6か月先：<?= h($winMin) ?>〜<?= h($winMax) ?>）。下に確定シフトのみ表示します。</div>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_month">
        <input type="hidden" name="month" value="<?= h($month) ?>">
        <div class="card shadow-sm mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span>シフト可能（<?= h($month) ?>）— 入れる日だけ時間を入力</span>
            <button class="btn btn-sm btn-success">保存する</button>
          </div>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light"><tr><th style="width:130px">日付</th><th style="width:40px">曜</th><th style="width:120px">開始</th><th style="width:120px">終了</th><th>メモ</th><th style="width:110px"></th></tr></thead>
              <tbody>
                <?php foreach (month_days($month) as $d): $date = $d['date']; $a = $appByDate[$date] ?? null; ?>
                  <?php $rowcls = $d['dow'] === 0 ? 'table-danger-subtle' : ($d['dow'] === 6 ? 'table-primary-subtle' : ''); ?>
                  <tr class="<?= $rowcls ?>" data-date="<?= h($date) ?>">
                    <td class="small"><?= (int)$d['day'] ?>日</td>
                    <td class="small <?= $d['dow'] === 0 ? 'text-danger' : ($d['dow'] === 6 ? 'text-primary' : 'text-muted') ?>"><?= h($wd[$d['dow']]) ?></td>
                    <?php if ($a && $a['status'] === '確定'): ?>
                      <td colspan="3" class="small"><?= h(hm($a['start_time'])) ?>〜<?= h(hm($a['end_time'])) ?> <span class="badge bg-success">確定</span> <span class="text-muted"><?= h($a['note']) ?></span></td>
                      <td></td>
                    <?php else: ?>
                      <td><input class="st form-control form-control-sm" type="time" name="start_time[<?= h($date) ?>]" value="<?= $a ? h(hm($a['start_time'])) : '' ?>"></td>
                      <td><input class="et form-control form-control-sm" type="time" name="end_time[<?= h($date) ?>]" value="<?= $a ? h(hm($a['end_time'])) : '' ?>"></td>
                      <td><input class="nt form-control form-control-sm" name="note[<?= h($date) ?>]" value="<?= $a ? h($a['note']) : '' ?>" placeholder="任意（校舎・備考）"></td>
                      <td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="copyPrev(this)">↑ 上をコピー</button></td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="card-footer text-end"><button class="btn btn-sm btn-success">保存する</button></div>
        </div>
      </form>
    <?php endif; ?>

    <div class="card shadow-sm">
      <div class="card-header">確定シフト（<?= h($month) ?>）</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>日付</th><th>教室</th><th>時間</th><th class="text-end">稼働</th><th class="text-end">授業</th><th class="text-end">運営</th><th>メモ</th></tr></thead>
          <tbody>
            <?php $tt = 0; $tc = 0; foreach ($days as $d): $tot = shift_minutes($d['start_time'], $d['end_time']); $cls = min((int)$d['class_minutes'], $tot); $ops = $tot - $cls; $tt += $tot; $tc += $cls; ?>
              <tr>
                <td><?= h($d['work_date']) ?></td>
                <td class="small"><?= h($d['room'] ?? '') ?: '—' ?></td>
                <td><?= h(hm($d['start_time'])) ?>〜<?= h(hm($d['end_time'])) ?></td>
                <td class="text-end"><?= h(fmt_hm($tot)) ?></td>
                <td class="text-end"><?= h(fmt_hm($cls)) ?></td>
                <td class="text-end"><?= h(fmt_hm($ops)) ?></td>
                <td class="small text-muted"><?= h($d['note']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$days): ?><tr><td colspan="7" class="text-center text-muted py-3">確定シフトはまだありません。</td></tr>
            <?php else: ?><tr class="table-light fw-bold"><td colspan="3" class="text-end">合計</td><td class="text-end"><?= h(fmt_hm($tt)) ?></td><td class="text-end"><?= h(fmt_hm($tc)) ?></td><td class="text-end"><?= h(fmt_hm($tt - $tc)) ?></td><td></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <script>
    function copyPrev(btn){
      var tr = btn.closest('tr');
      var prev = tr.previousElementSibling;
      while (prev && !prev.querySelector('.st')) prev = prev.previousElementSibling;
      if (!prev) return;
      tr.querySelector('.st').value = prev.querySelector('.st').value;
      tr.querySelector('.et').value = prev.querySelector('.et').value;
      var p = prev.querySelector('.nt'), c = tr.querySelector('.nt');
      if (p && c) c.value = p.value;
    }
  </script>
<?php render_footer(); ?>
