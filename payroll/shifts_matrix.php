<?php
// シフト表（admin/staff）。教室ごとに、当月1日〜月末×講師 の表で
// 申請（申請中）/確定 のシフト時間を一覧する。staff は担当教室のみ。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

// 表示できる教室：admin=全教室 / staff=担当教室
$rooms = (($user['role'] ?? '') === 'admin') ? classrooms_active() : user_classrooms($user);
$room  = (string)($_GET['room'] ?? '');
if ($room === '' || !in_array($room, $rooms, true)) { $room = $rooms[0] ?? ''; }

$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));

// 対象教室に配属の講師
$teachers = [];
if ($room !== '') {
  foreach (db()->query("SELECT id, name, classrooms FROM staff WHERE is_active=1 ORDER BY name")->fetchAll() as $s) {
    if (in_array($room, classroom_list($s['classrooms']), true)) {
      $teachers[(int)$s['id']] = $s['name'];
    }
  }
}
$tids = array_keys($teachers);

// 申請（申請中/確定）と確定シフトを月内で取得し、[staff_id][date] に整理
$appBy = []; $dayBy = [];
if ($tids) {
  $in = implode(',', array_fill(0, count($tids), '?'));
  $qa = db()->prepare("SELECT staff_id, work_date, start_time, end_time, status FROM shift_applications
                        WHERE DATE_FORMAT(work_date,'%Y-%m')=? AND staff_id IN ($in)");
  $qa->execute(array_merge([$month], $tids));
  foreach ($qa->fetchAll() as $r) { $appBy[(int)$r['staff_id']][$r['work_date']] = $r; }
  $qd = db()->prepare("SELECT staff_id, work_date, start_time, end_time FROM shift_days
                        WHERE DATE_FORMAT(work_date,'%Y-%m')=? AND staff_id IN ($in)");
  $qd->execute(array_merge([$month], $tids));
  foreach ($qd->fetchAll() as $r) { $dayBy[(int)$r['staff_id']][$r['work_date']] = $r; }
}

$wd = jp_weekdays();
render_header('シフト表', $user, 'shifts_matrix.php');
?>
  <div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
      <h4 class="mb-0">シフト表</h4>
      <div class="d-flex gap-2 align-items-center">
        <form method="get" class="d-flex gap-1 align-items-center">
          <input type="hidden" name="m" value="<?= h($month) ?>">
          <label class="small text-muted">教室</label>
          <select name="room" class="form-select form-select-sm" onchange="this.form.submit()" style="width:140px">
            <?php foreach ($rooms as $rm): ?><option value="<?= h($rm) ?>" <?= $rm === $room ? 'selected' : '' ?>><?= h($rm) ?></option><?php endforeach; ?>
          </select>
        </form>
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>&room=<?= rawurlencode($room) ?>">← <?= h($prev) ?></a>
          <span class="btn btn-light disabled"><?= h($month) ?></span>
          <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>&room=<?= rawurlencode($room) ?>"><?= h($next) ?> →</a>
        </div>
      </div>
    </div>

    <div class="mb-2 small">
      <span class="badge bg-success">確定</span>
      <span class="badge bg-warning text-dark">申請中</span>
      <span class="text-muted ms-2">教室：<strong><?= h($room ?: '（担当教室なし）') ?></strong> ／ 講師 <?= count($teachers) ?>名</span>
    </div>

    <?php if (!$rooms): ?>
      <div class="alert alert-warning">表示できる教室がありません（staff は担当教室を ColorHRM のユーザー管理で設定してください）。</div>
    <?php elseif (!$teachers): ?>
      <div class="alert alert-light border">この教室に配属された在籍講師がいません。</div>
    <?php else: ?>
      <div class="card shadow-sm">
        <div class="table-responsive" style="max-height:75vh">
          <table class="table table-sm table-bordered align-middle mb-0" style="font-size:12px">
            <thead class="table-light" style="position:sticky;top:0;z-index:1">
              <tr>
                <th style="position:sticky;left:0;background:#f8f9fa;z-index:2;min-width:64px">日付</th>
                <?php foreach ($teachers as $tid => $name): ?><th style="min-width:96px"><?= h($name) ?></th><?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach (month_days($month) as $d): $date = $d['date']; ?>
                <tr>
                  <th style="position:sticky;left:0;background:#fff;z-index:1" class="<?= $d['dow'] === 0 ? 'text-danger' : ($d['dow'] === 6 ? 'text-primary' : '') ?>">
                    <?= (int)$d['day'] ?>(<?= h($wd[$d['dow']]) ?>)
                  </th>
                  <?php foreach ($teachers as $tid => $name): ?>
                    <?php
                      $cell = ''; $cls = '';
                      if (isset($dayBy[$tid][$date])) {
                        $r = $dayBy[$tid][$date]; $cell = hm($r['start_time']) . '-' . hm($r['end_time']); $cls = 'bg-success text-white';
                      } elseif (isset($appBy[$tid][$date]) && $appBy[$tid][$date]['status'] === '申請中') {
                        $r = $appBy[$tid][$date]; $cell = hm($r['start_time']) . '-' . hm($r['end_time']); $cls = 'bg-warning';
                      }
                    ?>
                    <td class="text-center p-1"><?php if ($cell !== ''): ?><span class="badge <?= $cls ?>" style="font-weight:500"><?= h($cell) ?></span><?php endif; ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-muted small mt-2">※ 緑＝確定シフト、黄＝申請中。確定・却下などの操作は「シフト管理」で行います。</p>
    <?php endif; ?>
  </div>
<?php render_footer(); ?>
