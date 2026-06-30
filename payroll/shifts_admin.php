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
// 1件の申請を、入力された時間・教室・授業分で確定する（単体／一括で共用）。
$confirmApp = function ($appId, $st, $et, $roomIn, $cls) use ($inScope, $teacherRooms, $hasRoom) {
  $a = db()->prepare("SELECT * FROM shift_applications WHERE id=? AND status='申請中'");
  $a->execute([(int)$appId]);
  $a = $a->fetch();
  if (!$a || !$inScope($a['staff_id'])) return false;
  // 確定する時間（申請＝可能時間を既定とし、入力があればその時間で確定）
  $st = trim((string)$st); $et = trim((string)$et);
  $stN = preg_match('/^\d{1,2}:\d{2}$/', $st) ? $st : hm($a['start_time']);
  $etN = preg_match('/^\d{1,2}:\d{2}$/', $et) ? $et : hm($a['end_time']);
  $tot = shift_minutes($stN, $etN);
  if ($tot <= 0) { $stN = hm($a['start_time']); $etN = hm($a['end_time']); $tot = shift_minutes($a['start_time'], $a['end_time']); }
  $cls = max(0, (int)$cls); if ($cls > $tot) $cls = $tot;
  $room = norm_room($a['staff_id'], $roomIn, $teacherRooms);
  insert_shift_day($a['staff_id'], $a['work_date'], $stN, $etN, $cls, $a['note'], (int)$appId, $room, $hasRoom);
  db()->prepare("UPDATE shift_applications SET status='確定' WHERE id=?")->execute([(int)$appId]);
  return true;
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'confirm') {
    if ($confirmApp($_POST['id'] ?? 0, $_POST['start_time'] ?? '', $_POST['end_time'] ?? '', $_POST['room'] ?? '', $_POST['class_minutes'] ?? 0)) {
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
  } elseif ($action === 'confirm_bulk') {
    // 画面で「表示中」の各行を、入力された時間・教室・授業分で確定（絞り込みを尊重）
    $payload = json_decode((string)($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) $payload = [];
    db()->beginTransaction();
    try {
      $n = 0;
      foreach ($payload as $row) {
        if (!is_array($row)) continue;
        if ($confirmApp($row['id'] ?? 0, $row['start'] ?? '', $row['end'] ?? '', $row['room'] ?? '', $row['cls'] ?? 0)) $n++;
      }
      db()->commit();
      $flash = "{$n}件を確定しました（入力した時間・授業分で確定）。";
    } catch (Throwable $e) {
      db()->rollBack();
      $err = '一括確定に失敗しました: ' . $e->getMessage();
    }
  } elseif ($action === 'add_ctpl') {
    if (!confirm_templates_table_exists()) { $err = '確定用テンプレートのテーブルがありません。migrations/023_confirm_templates.sql を実行してください。'; }
    else {
      $label = trim($_POST['label'] ?? '');
      $st = trim($_POST['start_time'] ?? ''); $et = trim($_POST['end_time'] ?? '');
      if (!preg_match('/^\d{1,2}:\d{2}$/', $st) || !preg_match('/^\d{1,2}:\d{2}$/', $et) || shift_minutes($st, $et) <= 0) {
        $err = '開始・終了（終了は開始より後）を正しく入力してください。';
      } else {
        $c = db()->prepare("SELECT COUNT(*) FROM confirm_templates WHERE user_id=?"); $c->execute([(int)$user['id']]); $cnt = (int)$c->fetchColumn();
        if ($cnt >= 30) { $err = 'テンプレートは30件まで登録できます。'; }
        else {
          if ($label === '') { $label = $st . '-' . $et; }
          db()->prepare("INSERT INTO confirm_templates (tenant_id,user_id,label,start_time,end_time,sort_order) VALUES (1,?,?,?,?,?)")
              ->execute([(int)$user['id'], mb_substr($label, 0, 50), $st, $et, $cnt]);
          $flash = '確定用テンプレートを追加しました。';
        }
      }
    }
  } elseif ($action === 'del_ctpl') {
    db()->prepare("DELETE FROM confirm_templates WHERE id=? AND user_id=?")->execute([(int)($_POST['id'] ?? 0), (int)$user['id']]);
    $flash = 'テンプレートを削除しました。';
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

// 確定用 時間テンプレート（ログイン中スタッフ個人）
$ctpls = confirm_templates_for((int)$user['id']);
$ctplOptsHtml = '';
foreach ($ctpls as $t) {
  $ctplOptsHtml .= '<option data-st="' . h(hm($t['start_time'])) . '" data-et="' . h(hm($t['end_time'])) . '">'
                 . h($t['label']) . '（' . h(hm($t['start_time'])) . '〜' . h(hm($t['end_time'])) . '）</option>';
}

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

    <?php if (confirm_templates_table_exists()): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header">確定用 時間テンプレート <span class="text-muted small">よく使う確定時間を登録すると、各行の「テンプレ」から選んで入力できます（直接入力も可）</span></div>
      <div class="card-body">
        <?php if ($ctpls): ?>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($ctpls as $t): ?>
              <span class="border rounded d-inline-flex align-items-center gap-2 px-2 py-1 bg-light">
                <span class="small"><strong><?= h($t['label']) ?></strong> <span class="text-muted"><?= h(hm($t['start_time'])) ?>〜<?= h(hm($t['end_time'])) ?></span></span>
                <form method="post" class="d-inline" onsubmit="return confirm('このテンプレートを削除しますか？');"><?= csrf_field() ?><input type="hidden" name="action" value="del_ctpl"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn-close" style="font-size:9px" aria-label="削除"></button></form>
              </span>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="small text-muted">まだテンプレートがありません。よく使う確定時間を追加してください。</p>
        <?php endif; ?>
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_ctpl">
          <div class="col-auto"><label class="form-label small mb-0">名称（任意）</label><input name="label" class="form-control form-control-sm" placeholder="例：通常授業" maxlength="50" style="max-width:160px"></div>
          <div class="col-auto"><label class="form-label small mb-0">開始</label><input type="time" name="start_time" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">終了</label><input type="time" name="end_time" class="form-control form-control-sm" required></div>
          <div class="col-auto"><button class="btn btn-sm btn-outline-primary">テンプレ追加</button></div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>申請中（<?= count($pending) ?>件）— 時間・授業分を入れて「確定」</span>
        <?php if ($pending): ?>
          <button type="button" class="btn btn-sm btn-success" onclick="bulkConfirm()" title="絞り込み表示中の行を、入力した時間・授業分で確定します">表示中をまとめて確定</button>
          <form method="post" id="bulkForm" class="d-none"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_bulk"><input type="hidden" name="payload" id="bulkPayload"></form>
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
                    <?php if ($ctpls): ?><select class="form-select form-select-sm ctpl-sel" style="max-width:150px" onchange="applyCtpl(this)" title="テンプレから時間を入力"><option value="">テンプレ…</option><?= $ctplOptsHtml ?></select><?php endif; ?>
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
        var KEY_SA='flt_shifts_admin';
        (function(){
          var fs=document.getElementById('fStaff'), fr=document.getElementById('fRoom'),
              fd=document.getElementById('fDate'),
              fc=document.getElementById('fClear'), cnt=document.getElementById('fCount'),
              rows=[].slice.call(document.querySelectorAll('#pendingBody tr[data-staff]'));
          function persist(){ try{ sessionStorage.setItem(KEY_SA, JSON.stringify({s:fs?fs.value:'',rm:fr?fr.value:'',d:fd?fd.value:''})); }catch(e){} }
          function restore(){ try{ var o=JSON.parse(sessionStorage.getItem(KEY_SA)||'{}'); if(fs&&o.s!=null)fs.value=o.s; if(fr&&o.rm!=null)fr.value=o.rm; if(fd&&o.d!=null)fd.value=o.d; }catch(e){} }
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
            persist();
          }
          fs&&fs.addEventListener('change',apply);
          fr&&fr.addEventListener('change',apply);
          fd&&fd.addEventListener('input',apply);
          fc&&fc.addEventListener('click',function(){ if(fs)fs.value=''; if(fr)fr.value=''; if(fd)fd.value=''; apply(); });
          restore(); apply();   // 保存後の再読み込みでも絞り込みを維持
        })();
        // 行ごとにテンプレから確定時間を入力（直接入力も可）
        function applyCtpl(sel){
          var opt=sel.options[sel.selectedIndex]; if(!opt||!opt.value){ return; }
          var f=sel.closest('form'); if(!f){ sel.selectedIndex=0; return; }
          var s=f.querySelector('input[name=start_time]'), e=f.querySelector('input[name=end_time]');
          if(s) s.value=opt.getAttribute('data-st')||'';
          if(e) e.value=opt.getAttribute('data-et')||'';
          sel.selectedIndex=0;
        }
        // 「表示中をまとめて確定」：絞り込み表示中の行を、入力した時間・教室・授業分で確定
        function bulkConfirm(){
          var rows=document.querySelectorAll('#pendingBody tr[data-staff]'), list=[];
          for (var i=0;i<rows.length;i++){
            var tr=rows[i]; if(tr.style.display==='none') continue;
            var f=tr.querySelector('form[id^="cf"]'); if(!f) continue;
            var g=function(sel){ var el=f.querySelector(sel); return el?el.value:''; };
            list.push({id:g('input[name=id]'),start:g('input[name=start_time]'),end:g('input[name=end_time]'),room:g('select[name=room]'),cls:g('input[name=class_minutes]')});
          }
          if(!list.length){ alert('表示中の申請がありません。'); return; }
          if(!confirm('表示中の '+list.length+' 件を、入力した時間・授業分で確定します。よろしいですか？')) return;
          document.getElementById('bulkPayload').value=JSON.stringify(list);
          document.getElementById('bulkForm').submit();
        }
      </script>
      <?php endif; ?>
    </div>
  </div>
<?php render_footer(); ?>
