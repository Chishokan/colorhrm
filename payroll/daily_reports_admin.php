<?php
// 日報（報告）一覧（admin/staff）。staff は担当教室の講師のみ。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();
$scope = scoped_staff_ids($user);

$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));

$names = [];
foreach (db()->query("SELECT id, name FROM staff")->fetchAll() as $s) { $names[(int)$s['id']] = $s['name']; }

$reports = [];
if (daily_reports_table_exists()) {
  $scopeSql = ''; $params = [$month];
  if (is_array($scope)) {
    if ($scope) { $scopeSql = ' AND staff_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $params = array_merge($params, $scope); }
    else { $scopeSql = ' AND 1=0'; }
  }
  $q = db()->prepare("SELECT * FROM daily_reports WHERE DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql . " ORDER BY work_date DESC, id DESC");
  $q->execute($params);
  $reports = $q->fetchAll();
}

// 講師で絞り込み用
$repStaff = [];
foreach ($reports as $r) { $repStaff[(int)$r['staff_id']] = $names[(int)$r['staff_id']] ?? ('#' . $r['staff_id']); }
asort($repStaff, SORT_FLAG_CASE | SORT_STRING);

render_header('報告一覧', $user, 'daily_reports_admin.php');
$field = function ($label, $val) {
  $val = trim((string)$val);
  if ($val === '') return '';
  return '<div class="mb-1"><span class="fw-semibold">' . h($label) . '：</span> <span style="white-space:pre-wrap">' . h($val) . '</span></div>';
};
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">報告一覧（日報）</h4>
      <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
        <span class="btn btn-light disabled"><?= h($month) ?></span>
        <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
      </div>
    </div>
    <?php if (!daily_reports_table_exists()): ?>
      <div class="alert alert-warning py-2 small">日報テーブルがありません。<code>migrations/022_daily_reports.sql</code> を実行してください。</div>
    <?php endif; ?>

    <?php if ($reports): ?>
      <div class="card shadow-sm mb-3">
        <div class="card-body py-2 d-flex align-items-end gap-2">
          <div><label class="form-label small mb-0" for="fS">講師で絞り込み</label>
            <select id="fS" class="form-select form-select-sm" style="min-width:160px" onchange="filt()">
              <option value="">（すべて）</option>
              <?php foreach ($repStaff as $sid => $nm): ?><option value="<?= (int)$sid ?>"><?= h($nm) ?></option><?php endforeach; ?>
            </select></div>
          <span id="fC" class="small text-muted"></span>
        </div>
      </div>
    <?php endif; ?>

    <div id="repList">
      <?php foreach ($reports as $r): ?>
        <div class="card shadow-sm mb-2 rep" data-staff="<?= (int)$r['staff_id'] ?>">
          <div class="card-body py-2 small">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
              <strong><?= h($r['work_date']) ?></strong>
              <span><?= h($names[(int)$r['staff_id']] ?? ('#' . $r['staff_id'])) ?></span>
              <span class="badge bg-light text-dark border"><?= h($r['report_type']) ?></span>
              <span class="badge <?= $r['shift_over'] === 'シフト超過' ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= h($r['shift_over'] ?: '—') ?></span>
              <?php if (trim((string)$r['shift_over_detail']) !== ''): ?><span class="text-muted">（<?= h($r['shift_over_detail']) ?>）</span><?php endif; ?>
              <span class="text-muted ms-auto">送信 <?= h(substr((string)$r['created_at'], 0, 16)) ?></span>
            </div>
            <?= $field('新規体験生', $r['new_trial']) ?>
            <?= $field('イレギュラー', $r['irregular']) ?>
            <?= $field('改善なし', $r['no_improve']) ?>
            <?= $field('保護者共有', $r['parent_share']) ?>
            <?= $field('休憩時間', $r['break_time']) ?>
            <?= $field('業務終了', $r['work_end']) ?>
            <?= $field('業務内容', $r['work_content']) ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$reports): ?><div class="text-center text-muted py-4"><?= daily_reports_table_exists() ? 'この月の報告はありません。' : 'migrations/022 未実行のため報告はありません。' ?></div><?php endif; ?>
    </div>
  </div>
  <script>
    function filt(){
      var s=document.getElementById('fS').value, rows=document.querySelectorAll('#repList .rep'), n=0;
      for(var i=0;i<rows.length;i++){ var ok=(s===''||rows[i].getAttribute('data-staff')===s); rows[i].style.display=ok?'':'none'; if(ok)n++; }
      var c=document.getElementById('fC'); if(c) c.textContent=s?('表示 '+n+' 件'):'';
    }
  </script>
<?php render_footer(); ?>
