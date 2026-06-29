<?php
// 打刻・確定シフト（admin/staff）。確定済みシフトの一覧・打刻状況の確認と、
// 時間/教室/授業分の修正・削除・直接追加を行う。申請の確認・確定は shifts_admin.php。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']); // 修正・追加・削除は staff 権限以上
$user = current_user();

// staff の実在カラム（is_active 有無に強くする）
$staffCols = [];
foreach (db()->query("SHOW COLUMNS FROM staff")->fetchAll() as $c) { $staffCols[$c['Field']] = true; }
$hasIsActive = isset($staffCols['is_active']);

// 担当教室スコープ
$scope   = scoped_staff_ids($user);
$inScope = fn($sid) => $scope === null || in_array((int)$sid, $scope, true);
$scopeSql = ''; $scopeParams = [];
if (is_array($scope)) {
  if ($scope) { $scopeSql = ' AND staff_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $scopeParams = $scope; }
  else { $scopeSql = ' AND 1=0'; }
}

$sdCols = [];
foreach (db()->query("SHOW COLUMNS FROM shift_days")->fetchAll() as $c) { $sdCols[$c['Field']] = true; }
$hasRoom = isset($sdCols['room']);
$teacherRooms = teacher_rooms_map();

$flash = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'add_day') {
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
      $room = norm_room($sid, $_POST['room'] ?? '', $teacherRooms);
      insert_shift_day($sid, $d, $st, $et, $cls, $note, null, $room, $hasRoom);
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
      if ($hasRoom) {
        $room = norm_room($osid, $_POST['room'] ?? '', $teacherRooms);
        db()->prepare("UPDATE shift_days SET room=?, start_time=?, end_time=?, class_minutes=? WHERE id=?")->execute([$room, $st, $et, $cls, $id]);
      } else {
        db()->prepare("UPDATE shift_days SET start_time=?, end_time=?, class_minutes=? WHERE id=?")->execute([$st, $et, $cls, $id]);
      }
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

$names = [];
foreach (db()->query("SELECT id, name FROM staff")->fetchAll() as $s) { $names[(int)$s['id']] = $s['name']; }
$staffOpts = db()->query("SELECT id, name FROM staff" . ($hasIsActive ? " WHERE is_active=1" : "") . " ORDER BY name")->fetchAll();
if ($scope !== null) { $staffOpts = array_values(array_filter($staffOpts, fn($s) => in_array((int)$s['id'], $scope, true))); }

// 確定シフト（担当教室スコープ）
$days = db()->prepare("SELECT * FROM shift_days WHERE DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql . " ORDER BY work_date, staff_id, start_time");
$days->execute(array_merge([$month], $scopeParams));
$days = $days->fetchAll();
// フィルタ用：確定シフトに含まれる講師（名前順）と教室
$dayStaff = [];
foreach ($days as $d) { $dayStaff[(int)$d['staff_id']] = $names[(int)$d['staff_id']] ?? ('#' . $d['staff_id']); }
asort($dayStaff, SORT_FLAG_CASE | SORT_STRING);
$dayRooms = [];
foreach ($days as $d) { $rm = trim((string)($d['room'] ?? '')); if ($rm !== '') { $dayRooms[$rm] = true; } }
$dayRooms = array_keys($dayRooms); sort($dayRooms, SORT_STRING);

// 打刻（入退室）を [staff_id|date] で引けるように
$attBy = [];
if (attendance_table_exists()) {
  $aa = db()->prepare("SELECT * FROM attendance WHERE DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql);
  $aa->execute(array_merge([$month], $scopeParams));
  foreach ($aa->fetchAll() as $r) { $attBy[$r['staff_id'] . '|' . $r['work_date']] = $r; }
}

render_header('打刻・確定シフト', $user, 'shifts_done.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">打刻・確定シフト</h4>
      <div class="d-flex gap-2">
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
          <span class="btn btn-light disabled"><?= h($month) ?></span>
          <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="shifts_admin.php?m=<?= h($month) ?>">← シフト申請・確定</a>
      </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

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
          <div class="col-auto"><label class="form-label small mb-0">教室</label>
            <select name="room" class="form-select form-select-sm"><option value="">（自動）</option><?php foreach (classrooms_active() as $rm): ?><option value="<?= h($rm) ?>"><?= h($rm) ?></option><?php endforeach; ?></select></div>
          <div class="col-auto"><label class="form-label small mb-0">授業(分)</label><input type="number" name="class_minutes" value="0" min="0" class="form-control form-control-sm" style="width:90px"></div>
          <div class="col"><label class="form-label small mb-0">メモ</label><input name="note" class="form-control form-control-sm"></div>
          <div class="col-auto"><button class="btn btn-sm btn-outline-success">追加</button></div>
        </form>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>確定シフト（<?= h($month) ?>）<span class="text-muted small ms-1"><?= count($days) ?>件</span></span>
      </div>
      <?php if ($days): ?>
      <div class="card-body py-2 border-bottom bg-light">
        <div class="row g-2 align-items-end">
          <div class="col-auto">
            <label class="form-label small mb-0" for="dStaff">講師で絞り込み</label>
            <select id="dStaff" class="form-select form-select-sm" style="min-width:150px">
              <option value="">（すべて）</option>
              <?php foreach ($dayStaff as $sid => $nm): ?><option value="<?= (int)$sid ?>"><?= h($nm) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php if ($dayRooms): ?>
          <div class="col-auto">
            <label class="form-label small mb-0" for="dRoom">教室で絞り込み</label>
            <select id="dRoom" class="form-select form-select-sm" style="min-width:130px">
              <option value="">（すべて）</option>
              <?php foreach ($dayRooms as $rm): ?><option value="<?= h($rm) ?>"><?= h($rm) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-auto">
            <label class="form-label small mb-0" for="dDate">日付で絞り込み</label>
            <input type="date" id="dDate" class="form-control form-control-sm" min="<?= h($month) ?>-01" max="<?= h(date('Y-m-t', strtotime($month . '-01'))) ?>">
          </div>
          <div class="col-auto"><button id="dClear" type="button" class="btn btn-sm btn-outline-secondary">クリア</button></div>
          <div class="col-auto"><span id="dCount" class="small text-muted"></span></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>講師</th><th>日付</th><th>教室</th><th style="width:104px">開始</th><th style="width:104px">終了</th><th class="text-end">稼働</th><th style="width:104px">授業(分)</th><th class="text-end">運営</th><th>出勤</th><th>退勤</th><th>判定</th><th></th></tr></thead>
          <tbody id="daysBody">
            <?php foreach ($days as $d): $bd = shift_work_breakdown($d['start_time'],$d['end_time'],$d['class_minutes']);
              $att = $attBy[$d['staff_id'].'|'.$d['work_date']] ?? null;
              ?>
              <tr data-staff="<?= (int)$d['staff_id'] ?>" data-date="<?= h($d['work_date']) ?>" data-room="<?= h($d['room'] ?? '') ?>">
                <td><form method="post" id="ud<?= (int)$d['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="update_day"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"></form><?= h($names[(int)$d['staff_id']] ?? ('#'.$d['staff_id'])) ?></td>
                <td><?= h($d['work_date']) ?></td>
                <td>
                  <?php if ($hasRoom): $trs = $teacherRooms[(int)$d['staff_id']] ?? []; $cur = $d['room'] ?? ''; ?>
                    <select form="ud<?= (int)$d['id'] ?>" name="room" class="form-select form-select-sm" style="min-width:90px">
                      <?php foreach ($trs as $rm): ?><option value="<?= h($rm) ?>" <?= $cur === $rm ? 'selected' : '' ?>><?= h($rm) ?></option><?php endforeach; ?>
                      <?php if ($cur !== '' && !in_array($cur, $trs, true)): ?><option value="<?= h($cur) ?>" selected><?= h($cur) ?></option><?php endif; ?>
                      <?php if (!$trs && $cur === ''): ?><option value="">—</option><?php endif; ?>
                    </select>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><input form="ud<?= (int)$d['id'] ?>" type="time" name="start_time" value="<?= h(hm($d['start_time'])) ?>" class="form-control form-control-sm"></td>
                <td><input form="ud<?= (int)$d['id'] ?>" type="time" name="end_time" value="<?= h(hm($d['end_time'])) ?>" class="form-control form-control-sm"></td>
                <td class="text-end"<?= $bd['break'] ? ' title="拘束 ' . h(fmt_hm($bd['gross'])) . ' − 休憩45分"' : '' ?>><?= h(fmt_hm($bd['net'])) ?><?php if ($bd['break']): ?><span class="text-muted" style="font-size:10px"> 休憩45</span><?php endif; ?></td>
                <td><input form="ud<?= (int)$d['id'] ?>" type="number" name="class_minutes" value="<?= (int)$d['class_minutes'] ?>" min="0" max="<?= $bd['gross'] ?>" class="form-control form-control-sm"></td>
                <td class="text-end"><?= h(fmt_hm($bd['ops'])) ?></td>
                <td class="small"><?= $att && !empty($att['clock_in']) ? h(hm($att['clock_in'])).'<br><span class="text-muted">'.h($att['in_room']).'</span>' : '—' ?></td>
                <td class="small"><?= $att && !empty($att['clock_out']) ? h(hm($att['clock_out'])).'<br><span class="text-muted">'.h($att['out_room']).'</span>' : '—' ?></td>
                <td><?= attendance_judgment_cell($d['start_time'], $d['end_time'], $att, $d['work_date']) ?></td>
                <td class="text-end text-nowrap">
                  <button form="ud<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-primary">保存</button>
                  <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_day"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-link text-danger p-0">削除</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$days): ?><tr><td colspan="12" class="text-center text-muted py-3">確定シフトはありません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($days): ?>
      <script>
        (function(){
          var KEY_SD='flt_shifts_done';
          var fs=document.getElementById('dStaff'), fr=document.getElementById('dRoom'),
              fd=document.getElementById('dDate'),
              fc=document.getElementById('dClear'), cnt=document.getElementById('dCount'),
              rows=[].slice.call(document.querySelectorAll('#daysBody tr[data-staff]'));
          function persist(){ try{ sessionStorage.setItem(KEY_SD, JSON.stringify({s:fs?fs.value:'',rm:fr?fr.value:'',d:fd?fd.value:''})); }catch(e){} }
          function restore(){ try{ var o=JSON.parse(sessionStorage.getItem(KEY_SD)||'{}'); if(fs&&o.s!=null)fs.value=o.s; if(fr&&o.rm!=null)fr.value=o.rm; if(fd&&o.d!=null)fd.value=o.d; }catch(e){} }
          function apply(){
            var s=fs?fs.value:'', rm=fr?fr.value:'', d=fd?fd.value:'', n=0;
            rows.forEach(function(r){
              var ok=(s===''||r.getAttribute('data-staff')===s)
                   &&(rm===''||r.getAttribute('data-room')===rm)
                   &&(d===''||r.getAttribute('data-date')===d);
              r.style.display=ok?'':'none'; if(ok)n++;
            });
            if(cnt) cnt.textContent=(s||rm||d)?('表示 '+n+' 件'):'';
            persist();
          }
          fs&&fs.addEventListener('change',apply);
          fr&&fr.addEventListener('change',apply);
          fd&&fd.addEventListener('input',apply);
          fc&&fc.addEventListener('click',function(){ if(fs)fs.value=''; if(fr)fr.value=''; if(fd)fd.value=''; apply(); });
          restore(); apply();   // 保存後の再読み込みでも絞り込みを維持
        })();
      </script>
      <?php endif; ?>
    </div>
  </div>
<?php render_footer(); ?>
