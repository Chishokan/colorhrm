<?php
// シフト申請・確定（admin/staff）。講師の申請（申請中）を確認し、時間・授業分を入れて確定／却下する。
// 確定済みシフトの一覧・修正・打刻状況は「打刻・確定シフト」(shifts_done.php) で行う。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

// 担当教室スコープ：admin=全員(null) / staff=担当教室の講師IDのみ
$scope   = scoped_staff_ids($user);
$inScope = fn($sid) => $scope === null || in_array((int)$sid, $scope, true);
$scopeSql = ''; $scopeParams = [];
if (is_array($scope)) {
  if ($scope) { $scopeSql = ' AND staff_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $scopeParams = $scope; }
  else { $scopeSql = ' AND 1=0'; }
}

// 確定シフトの教室列（015）と講師ごとの配属教室
$sdCols = [];
foreach (db()->query("SHOW COLUMNS FROM shift_days")->fetchAll() as $c) { $sdCols[$c['Field']] = true; }
$hasRoom = isset($sdCols['room']);
$teacherRooms = teacher_rooms_map();

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
      // 確定する時間（申請＝可能時間を既定とし、入力があればその時間で確定）
      $st = trim($_POST['start_time'] ?? '');
      $et = trim($_POST['end_time'] ?? '');
      $stN = preg_match('/^\d{1,2}:\d{2}$/', $st) ? $st : hm($a['start_time']);
      $etN = preg_match('/^\d{1,2}:\d{2}$/', $et) ? $et : hm($a['end_time']);
      $tot = shift_minutes($stN, $etN);
      if ($tot <= 0) { // 不正な時間は申請時間にフォールバック
        $stN = hm($a['start_time']); $etN = hm($a['end_time']);
        $tot = shift_minutes($a['start_time'], $a['end_time']);
      }
      if ($cls > $tot) $cls = $tot;
      $room = norm_room($a['staff_id'], $_POST['room'] ?? '', $teacherRooms);
      insert_shift_day($a['staff_id'], $a['work_date'], $stN, $etN, $cls, $a['note'], $appId, $room, $hasRoom);
      db()->prepare("UPDATE shift_applications SET status='確定' WHERE id=?")->execute([$appId]);
      $flash = $room !== '' ? "シフトを確定しました（{$stN}〜{$etN}／教室：{$room}）。" : "シフトを確定しました（{$stN}〜{$etN}）。";
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
      $upd = db()->prepare("UPDATE shift_applications SET status='確定' WHERE id=?");
      $n = 0;
      foreach ($rows as $a) {
        if (!$inScope($a['staff_id'])) continue;
        $room = norm_room($a['staff_id'], '', $teacherRooms); // 配属の先頭教室で確定（後で変更可）
        insert_shift_day($a['staff_id'], $a['work_date'], $a['start_time'], $a['end_time'], 0, $a['note'], $a['id'], $room, $hasRoom);
        $upd->execute([$a['id']]);
        $n++;
      }
      db()->commit();
      $flash = "{$n}件をまとめて確定しました（時間・授業分は「打刻・確定シフト」で調整できます）。";
    } catch (Throwable $e) {
      db()->rollBack();
      $err = '一括確定に失敗しました: ' . $e->getMessage();
    }
  }
}

$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));

// 講師名の引き当て
$names = [];
foreach (db()->query("SELECT id, name FROM staff")->fetchAll() as $s) { $names[(int)$s['id']] = $s['name']; }

// 申請中（担当教室スコープ）
$pending = db()->prepare("SELECT * FROM shift_applications WHERE status='申請中' AND DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql . " ORDER BY work_date, start_time");
$pending->execute(array_merge([$month], $scopeParams));
$pending = $pending->fetchAll();
// フィルタ用：申請中に含まれる講師（名前順）と、その講師の配属教室
$pendStaff = []; $pendRooms = [];
foreach ($pending as $a) {
  $pendStaff[(int)$a['staff_id']] = $names[(int)$a['staff_id']] ?? ('#' . $a['staff_id']);
  foreach ($teacherRooms[(int)$a['staff_id']] ?? [] as $rm) { $pendRooms[$rm] = true; }
}
asort($pendStaff, SORT_FLAG_CASE | SORT_STRING);
$pendRooms = array_keys($pendRooms); sort($pendRooms, SORT_STRING);

render_header('シフト申請・確定', $user, 'shifts_admin.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">シフト申請・確定</h4>
      <div class="d-flex gap-2">
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
          <span class="btn btn-light disabled"><?= h($month) ?></span>
          <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="shifts_done.php?m=<?= h($month) ?>">打刻・確定シフト →</a>
      </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>申請中（<?= count($pending) ?>件）— 時間・授業分を入れて「確定」</span>
        <?php if ($pending): ?>
          <form method="post" onsubmit="return confirm('<?= h($month) ?> の申請 <?= count($pending) ?>件を申請時間・授業分0でまとめて確定します。よろしいですか？（時間・授業分は後で「打刻・確定シフト」で調整できます）');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_month">
            <input type="hidden" name="month" value="<?= h($month) ?>">
            <button class="btn btn-sm btn-success">この月をまとめて確定</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if ($pending): ?>
      <div class="card-body py-2 border-bottom bg-light">
        <div class="row g-2 align-items-end">
          <div class="col-auto">
            <label class="form-label small mb-0" for="fStaff">講師で絞り込み</label>
            <select id="fStaff" class="form-select form-select-sm" style="min-width:150px">
              <option value="">（すべて）</option>
              <?php foreach ($pendStaff as $sid => $nm): ?><option value="<?= (int)$sid ?>"><?= h($nm) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php if ($pendRooms): ?>
          <div class="col-auto">
            <label class="form-label small mb-0" for="fRoom">教室で絞り込み</label>
            <select id="fRoom" class="form-select form-select-sm" style="min-width:130px">
              <option value="">（すべて）</option>
              <?php foreach ($pendRooms as $rm): ?><option value="<?= h($rm) ?>"><?= h($rm) ?></option><?php endforeach; ?>
            </select>
            <div class="form-text small mb-0" style="font-size:11px">※配属教室で絞り込み</div>
          </div>
          <?php endif; ?>
          <div class="col-auto">
            <label class="form-label small mb-0" for="fDate">日付で絞り込み</label>
            <input type="date" id="fDate" class="form-control form-control-sm" min="<?= h($month) ?>-01" max="<?= h(date('Y-m-t', strtotime($month . '-01'))) ?>">
          </div>
          <div class="col-auto"><button id="fClear" type="button" class="btn btn-sm btn-outline-secondary">クリア</button></div>
          <div class="col-auto"><span id="fCount" class="small text-muted"></span></div>
        </div>
      </div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>講師</th><th>日付</th><th>申請時間</th><th class="text-end">稼働</th><th>メモ</th><th style="min-width:340px">確定 時間／教室／授業(分)</th><th></th></tr></thead>
          <tbody id="pendingBody">
            <?php foreach ($pending as $a): $tot = shift_minutes($a['start_time'],$a['end_time']); ?>
              <tr data-staff="<?= (int)$a['staff_id'] ?>" data-date="<?= h($a['work_date']) ?>" data-rooms="<?= h(implode(',', $teacherRooms[(int)$a['staff_id']] ?? [])) ?>">
                <td><?= h($names[(int)$a['staff_id']] ?? ('#'.$a['staff_id'])) ?></td>
                <td><?= h($a['work_date']) ?></td>
                <td><?= h(hm($a['start_time'])) ?>〜<?= h(hm($a['end_time'])) ?></td>
                <td class="text-end"><?= h(fmt_hm($tot)) ?></td>
                <td class="small text-muted"><?= h($a['note']) ?></td>
                <td>
                  <form method="post" class="d-flex gap-1 flex-wrap align-items-center" id="cf<?= (int)$a['id'] ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="time" name="start_time" value="<?= h(hm($a['start_time'])) ?>" class="form-control form-control-sm" style="max-width:92px" title="確定 開始時刻">
                    <span class="text-muted">〜</span>
                    <input type="time" name="end_time" value="<?= h(hm($a['end_time'])) ?>" class="form-control form-control-sm" style="max-width:92px" title="確定 終了時刻">
                    <?php if ($hasRoom): $trs = $teacherRooms[(int)$a['staff_id']] ?? []; ?>
                      <select name="room" class="form-select form-select-sm" style="max-width:104px" title="確定する教室">
                        <?php foreach ($trs as $rm): ?><option value="<?= h($rm) ?>"><?= h($rm) ?></option><?php endforeach; ?>
                        <?php if (!$trs): ?><option value="">（配属なし）</option><?php endif; ?>
                      </select>
                    <?php endif; ?>
                    <input type="number" name="class_minutes" value="0" min="0" max="<?= $tot ?>" class="form-control form-control-sm" style="max-width:78px" title="授業分">
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
      <?php if ($pending): ?>
      <script>
        (function(){
          var fs=document.getElementById('fStaff'), fr=document.getElementById('fRoom'),
              fd=document.getElementById('fDate'),
              fc=document.getElementById('fClear'), cnt=document.getElementById('fCount'),
              rows=[].slice.call(document.querySelectorAll('#pendingBody tr[data-staff]'));
          function apply(){
            var s=fs?fs.value:'', rm=fr?fr.value:'', d=fd?fd.value:'', n=0;
            rows.forEach(function(r){
              var rooms=','+(r.getAttribute('data-rooms')||'')+',';
              var ok=(s===''||r.getAttribute('data-staff')===s)
                   &&(rm===''||rooms.indexOf(','+rm+',')>=0)
                   &&(d===''||r.getAttribute('data-date')===d);
              r.style.display=ok?'':'none'; if(ok)n++;
            });
            if(cnt) cnt.textContent=(s||rm||d)?('表示 '+n+' 件'):'';
          }
          fs&&fs.addEventListener('change',apply);
          fr&&fr.addEventListener('change',apply);
          fd&&fd.addEventListener('input',apply);
          fc&&fc.addEventListener('click',function(){ if(fs)fs.value=''; if(fr)fr.value=''; if(fd)fd.value=''; apply(); });
          apply();
        })();
      </script>
      <?php endif; ?>
    </div>
  </div>
<?php render_footer(); ?>
