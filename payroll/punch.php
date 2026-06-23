<?php
// 打刻（講師）。確定シフトがある日に、配属教室から出勤/退勤教室を選んで打刻。
//   給与計算はシフト時間で行うため、打刻時刻は遅刻/早退/欠勤の判定・表示のみに使う。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$staffId = (int)($user['staff_id'] ?? 0);

if ($staffId <= 0) {
  render_header('打刻', $user, 'punch.php');
  echo '<div class="container py-4"><div class="alert alert-warning">このアカウントは講師（staff）に紐付いていないため、打刻できません。管理者にお問い合わせください。</div></div>';
  render_footer();
  exit;
}

$today = date('Y-m-d');
$now   = date('H:i:s');
$flash = ''; $err = ''; $warn = '';

// 本人の配属教室
$st = db()->prepare("SELECT name, classrooms FROM staff WHERE id=? LIMIT 1");
$st->execute([$staffId]);
$me = $st->fetch();
$rooms = classroom_list($me['classrooms'] ?? '');

// 本日の確定シフト
$todayShift = null;
$ts = db()->prepare("SELECT * FROM shift_days WHERE staff_id=? AND work_date=? ORDER BY start_time LIMIT 1");
$ts->execute([$staffId, $today]);
$todayShift = $ts->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && attendance_table_exists()) {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $room   = trim($_POST['room'] ?? '');
  // シフトがない日でも打刻は可能。判定用に「シフトなし」を注意表示する。
  $noShift = !$todayShift;
  if ($room === '' || ($rooms && !in_array($room, $rooms, true))) {
    $err = '配属教室から教室を選んでください。';
  } else {
    // 本日の打刻行
    $a = db()->prepare("SELECT * FROM attendance WHERE staff_id=? AND work_date=? LIMIT 1");
    $a->execute([$staffId, $today]);
    $att = $a->fetch();
    if ($action === 'clock_in') {
      if ($att && !empty($att['clock_in'])) { $err = 'すでに出勤打刻済みです。'; }
      else {
        db()->prepare("INSERT INTO attendance (tenant_id,staff_id,work_date,clock_in,in_room) VALUES (1,?,?,?,?)
                       ON DUPLICATE KEY UPDATE clock_in=VALUES(clock_in), in_room=VALUES(in_room)")
            ->execute([$staffId, $today, $now, $room]);
        $flash = "出勤を打刻しました（{$room} / " . hm($now) . "）。";
        if ($noShift) { $warn = '本日は確定シフトがありません（シフトなしで打刻しました）。'; }
      }
    } elseif ($action === 'clock_out') {
      if (!$att || empty($att['clock_in'])) { $err = '先に出勤打刻をしてください。'; }
      elseif (!empty($att['clock_out'])) { $err = 'すでに退勤打刻済みです。'; }
      else {
        db()->prepare("UPDATE attendance SET clock_out=?, out_room=? WHERE id=?")->execute([$now, $room, (int)$att['id']]);
        $flash = "退勤を打刻しました（{$room} / " . hm($now) . "）。";
        if ($noShift) { $warn = '本日は確定シフトがありません（シフトなしで打刻しました）。'; }
      }
    }
  }
}

// 再取得（本日）
$todayAtt = null;
if (attendance_table_exists()) {
  $a = db()->prepare("SELECT * FROM attendance WHERE staff_id=? AND work_date=? LIMIT 1");
  $a->execute([$staffId, $today]);
  $todayAtt = $a->fetch();
}

// 当月の確定シフト＋打刻（一覧・遅刻/早退/欠勤）
$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));
$list = [];
$mShifts = db()->prepare("SELECT * FROM shift_days WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? ORDER BY work_date, start_time");
$mShifts->execute([$staffId, $month]);
$mShifts = $mShifts->fetchAll();
$attByDate = [];
if (attendance_table_exists()) {
  $aa = db()->prepare("SELECT * FROM attendance WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=?");
  $aa->execute([$staffId, $month]);
  foreach ($aa->fetchAll() as $r) { $attByDate[$r['work_date']] = $r; }
}
// シフト日＋打刻日（シフトなしで打刻した日も含む）を日付順に統合
$shiftByDate = [];
foreach ($mShifts as $sd) { $shiftByDate[$sd['work_date']][] = $sd; }
$allDates = array_unique(array_merge(array_keys($shiftByDate), array_keys($attByDate)));
sort($allDates);
$rows = [];
foreach ($allDates as $d) {
  if (!empty($shiftByDate[$d])) {
    foreach ($shiftByDate[$d] as $i => $sd) {
      // 打刻は1日1行のため、最初のシフト行にだけ紐付ける
      $rows[] = ['date' => $d, 'shift' => $sd, 'att' => ($i === 0 ? ($attByDate[$d] ?? null) : null)];
    }
  } else {
    $rows[] = ['date' => $d, 'shift' => null, 'att' => $attByDate[$d] ?? null];
  }
}

function flag_badges($flags) {
  if (!$flags) return '<span class="badge bg-success">OK</span>';
  $map = ['遅刻' => 'bg-danger', '早退' => 'bg-danger', '欠勤' => 'bg-dark'];
  $h = '';
  foreach ($flags as $f) { $h .= '<span class="badge ' . ($map[$f] ?? 'bg-secondary') . ' me-1">' . h($f) . '</span>'; }
  return $h;
}

render_header('打刻', $user, 'punch.php');
?>
  <div class="container py-4" style="max-width:820px">
    <h4 class="mb-3">打刻</h4>
    <?php if (!attendance_table_exists()): ?>
      <div class="alert alert-warning">打刻はまだ利用できません（<code>migrations/014_attendance.sql</code> 未適用）。</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($warn): ?><div class="alert alert-warning py-2"><?= h($warn) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-4">
      <div class="card-header"><?= h(date('Y-m-d (')) . jp_weekdays()[date('w')] . ')' ?> の打刻</div>
      <div class="card-body">
        <?php if (!$rooms): ?>
          <div class="alert alert-warning mb-0">配属教室が未設定のため打刻できません。ColorHRMの講師詳細で配属教室を設定してください。</div>
        <?php else: ?>
          <?php if (!$todayShift): ?>
            <p class="small text-warning-emphasis mb-2">本日は確定シフトがありません。<strong>シフトなしでも打刻できます</strong>（判定は「シフトなし」になります）。</p>
          <?php else: ?>
            <p class="small text-muted mb-2">本日のシフト：<strong><?= h(hm($todayShift['start_time'])) ?>〜<?= h(hm($todayShift['end_time'])) ?></strong><?php if (!empty($todayShift['room'])): ?> <span class="badge bg-secondary"><?= h($todayShift['room']) ?></span><?php endif; ?></p>
          <?php endif; ?>
          <div class="row g-3">
            <div class="col-md-6">
              <div class="border rounded p-3">
                <div class="fw-bold mb-1">出勤</div>
                <?php if ($todayAtt && !empty($todayAtt['clock_in'])): ?>
                  <div class="text-success">済 <?= h(hm($todayAtt['clock_in'])) ?>（<?= h($todayAtt['in_room']) ?>）</div>
                <?php else: ?>
                  <form method="post" class="d-flex gap-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="clock_in">
                    <select name="room" class="form-select form-select-sm" required>
                      <option value="">出勤教室</option>
                      <?php foreach ($rooms as $rm): ?><option value="<?= h($rm) ?>"><?= h($rm) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-success text-nowrap">出勤打刻</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-md-6">
              <div class="border rounded p-3">
                <div class="fw-bold mb-1">退勤</div>
                <?php if ($todayAtt && !empty($todayAtt['clock_out'])): ?>
                  <div class="text-success">済 <?= h(hm($todayAtt['clock_out'])) ?>（<?= h($todayAtt['out_room']) ?>）</div>
                <?php elseif ($todayAtt && !empty($todayAtt['clock_in'])): ?>
                  <form method="post" class="d-flex gap-2">
                    <?= csrf_field() ?><input type="hidden" name="action" value="clock_out">
                    <select name="room" class="form-select form-select-sm" required>
                      <option value="">退勤教室</option>
                      <?php foreach ($rooms as $rm): ?><option value="<?= h($rm) ?>"><?= h($rm) ?></option><?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-danger text-nowrap">退勤打刻</button>
                  </form>
                <?php else: ?>
                  <div class="text-muted small">出勤打刻後に押せます</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>当月の打刻状況（<?= h($month) ?>）</span>
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">←</a>
          <span class="btn btn-light disabled"><?= h($month) ?></span>
          <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>">→</a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>日付</th><th>教室</th><th>シフト</th><th>出勤</th><th>退勤</th><th>判定</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $row): $sd = $row['shift']; $att = $row['att']; ?>
              <?php $flags = $sd ? attendance_flags($sd['start_time'], $sd['end_time'], $att, $row['date']) : null; ?>
              <tr>
                <td><?= h($row['date']) ?></td>
                <td class="small"><?= h(($sd['room'] ?? '') ?: ($att['in_room'] ?? '')) ?: '—' ?></td>
                <td class="small"><?= $sd ? h(hm($sd['start_time'])) . '〜' . h(hm($sd['end_time'])) : '<span class="text-muted">—</span>' ?></td>
                <td class="small"><?= $att && !empty($att['clock_in']) ? h(hm($att['clock_in'])) . '（' . h($att['in_room']) . '）' : '—' ?></td>
                <td class="small"><?= $att && !empty($att['clock_out']) ? h(hm($att['clock_out'])) . '（' . h($att['out_room']) . '）' : '—' ?></td>
                <td><?= $sd ? flag_badges($flags) : '<span class="badge bg-secondary">シフトなし</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-3">この月の確定シフト・打刻はありません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
