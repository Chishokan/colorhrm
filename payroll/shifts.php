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

// シフトテンプレート（講師ごと）の追加・削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['add_template', 'delete_template'], true)) {
  csrf_check();
  if (!shift_templates_table_exists()) {
    $err = 'テンプレート用テーブル（shift_templates）がありません。管理者が migrations/018_shift_templates.sql を実行してください。';
  } elseif (($_POST['action']) === 'add_template') {
    $label = trim((string)($_POST['label'] ?? ''));
    $st = trim((string)($_POST['start_time'] ?? ''));
    $et = trim((string)($_POST['end_time'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    if (!preg_match('/^\d{1,2}:\d{2}$/', $st) || !preg_match('/^\d{1,2}:\d{2}$/', $et) || shift_minutes($st, $et) <= 0) {
      $err = '開始・終了（終了は開始より後）を正しく入力してください。';
    } else {
      $c = db()->prepare("SELECT COUNT(*) FROM shift_templates WHERE staff_id=?"); $c->execute([$staffId]); $cnt = (int)$c->fetchColumn();
      if ($cnt >= 20) {
        $err = 'テンプレートは20件まで登録できます。';
      } else {
        if ($label === '') { $label = $st . '-' . $et; }
        if (mb_strlen($label) > 50) { $label = mb_substr($label, 0, 50); }
        db()->prepare("INSERT INTO shift_templates (tenant_id,staff_id,label,start_time,end_time,note,sort_order) VALUES (1,?,?,?,?,?,?)")
            ->execute([$staffId, $label, $st, $et, $note, $cnt]);
        $flash = 'テンプレートを追加しました。';
      }
    }
  } else { // delete_template
    db()->prepare("DELETE FROM shift_templates WHERE id=? AND staff_id=?")->execute([(int)($_POST['id'] ?? 0), $staffId]);
    $flash = 'テンプレートを削除しました。';
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

$templates = shift_templates_for($staffId);

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

    <?php if (shift_templates_table_exists()): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header">マイ シフトテンプレート <span class="text-muted small">よく使う時間帯を登録して、月間表へ一括入力</span></div>
      <div class="card-body">
        <?php if ($editable && $templates): ?>
          <div class="row g-2 align-items-end mb-3 pb-3 border-bottom">
            <div class="col-auto">
              <label class="form-label small mb-0" for="tplSel">テンプレート</label>
              <select id="tplSel" class="form-select form-select-sm" style="min-width:220px">
                <?php foreach ($templates as $t): ?>
                  <option data-st="<?= h(hm($t['start_time'])) ?>" data-et="<?= h(hm($t['end_time'])) ?>" data-nt="<?= h($t['note']) ?>"><?= h($t['label']) ?>（<?= h(hm($t['start_time'])) ?>〜<?= h(hm($t['end_time'])) ?>）</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label small mb-0 d-block">対象曜日</label>
              <?php $dowLabels = ['日','月','火','水','木','金','土']; foreach ([1,2,3,4,5,6,0] as $dw): ?>
                <label class="me-2 small text-nowrap"><input type="checkbox" class="tpl-dow" value="<?= $dw ?>" <?= ($dw >= 1 && $dw <= 5) ? 'checked' : '' ?>> <?= $dowLabels[$dw] ?></label>
              <?php endforeach; ?>
            </div>
            <div class="col-auto">
              <button type="button" class="btn btn-sm btn-success" onclick="applyTemplate(false)">空欄に適用</button>
              <button type="button" class="btn btn-sm btn-outline-success" onclick="applyTemplate(true)">上書き適用</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearDows()">対象曜日をクリア</button>
            </div>
            <div class="col-12"><div class="form-text small mb-0">※ 適用後に下の「保存する」を押すと反映されます。確定済みの日は変更されません。</div></div>
          </div>
        <?php elseif (!$editable): ?>
          <p class="small text-muted mb-3">テンプレートの適用は、登録できる月（当月〜6か月先）で行えます。</p>
        <?php endif; ?>

        <?php if ($templates): ?>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($templates as $t): ?>
              <span class="border rounded d-inline-flex align-items-center gap-2 px-2 py-1 bg-light">
                <span class="small"><strong><?= h($t['label']) ?></strong> <span class="text-muted"><?= h(hm($t['start_time'])) ?>〜<?= h(hm($t['end_time'])) ?><?php if ($t['note'] !== ''): ?> ・<?= h($t['note']) ?><?php endif; ?></span></span>
                <form method="post" class="d-inline" onsubmit="return confirm('このテンプレートを削除しますか？');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn-close" style="font-size:9px" aria-label="削除"></button></form>
              </span>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="small text-muted">まだテンプレートがありません。よく使う時間帯を追加してください。</p>
        <?php endif; ?>

        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_template">
          <div class="col-auto"><label class="form-label small mb-0">名称（任意）</label><input name="label" class="form-control form-control-sm" placeholder="例：通常・土曜" maxlength="50" style="max-width:160px"></div>
          <div class="col-auto"><label class="form-label small mb-0">開始</label><input type="time" name="start_time" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">終了</label><input type="time" name="end_time" class="form-control form-control-sm" required></div>
          <div class="col-auto"><label class="form-label small mb-0">メモ（任意）</label><input name="note" class="form-control form-control-sm" placeholder="校舎・備考" maxlength="255" style="max-width:160px"></div>
          <div class="col-auto"><button class="btn btn-sm btn-outline-primary">テンプレ追加</button></div>
        </form>
      </div>
    </div>
    <?php endif; ?>

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
              <tbody id="shiftBody">
                <?php foreach (month_days($month) as $d): $date = $d['date']; $a = $appByDate[$date] ?? null; ?>
                  <?php $rowcls = $d['dow'] === 0 ? 'table-danger-subtle' : ($d['dow'] === 6 ? 'table-primary-subtle' : ''); ?>
                  <tr class="<?= $rowcls ?>" data-date="<?= h($date) ?>" data-dow="<?= (int)$d['dow'] ?>">
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
    function tplCheckedDows(){
      var d={}; var cs=document.querySelectorAll('.tpl-dow:checked');
      for (var i=0;i<cs.length;i++) d[cs[i].value]=1; return d;
    }
    function applyTemplate(overwrite){
      var sel=document.getElementById('tplSel'); if(!sel) return;
      var opt=sel.options[sel.selectedIndex]; if(!opt) return;
      var st=opt.getAttribute('data-st')||'', et=opt.getAttribute('data-et')||'', nt=opt.getAttribute('data-nt')||'';
      var dows=tplCheckedDows();
      var rows=document.querySelectorAll('#shiftBody tr[data-date]'), n=0;
      for (var i=0;i<rows.length;i++){
        var tr=rows[i], s=tr.querySelector('.st'); if(!s) continue;            // 確定行は入力欄なし→スキップ
        if(!dows[tr.getAttribute('data-dow')]) continue;
        if(!overwrite && s.value) continue;                                    // 空欄のみ
        s.value=st; var e=tr.querySelector('.et'); if(e) e.value=et;
        var note=tr.querySelector('.nt'); if(note && (overwrite || !note.value)) note.value=nt;
        n++;
      }
      if(n===0) alert('対象（選択した曜日'+(overwrite?'':'の空欄')+'）がありませんでした。');
    }
    function clearDows(){
      var dows=tplCheckedDows(), rows=document.querySelectorAll('#shiftBody tr[data-date]');
      for (var i=0;i<rows.length;i++){
        var tr=rows[i], s=tr.querySelector('.st'); if(!s) continue;
        if(!dows[tr.getAttribute('data-dow')]) continue;
        s.value=''; var e=tr.querySelector('.et'); if(e) e.value='';
      }
    }
  </script>
<?php render_footer(); ?>
