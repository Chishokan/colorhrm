<?php
// 退勤チェックリスト管理＋退勤報告一覧（admin/staff）。staff は担当教室のみ。
//   チェック項目は退勤打刻（punch.php）時に教室別で表示され、全チェックで退勤打刻＝報告記録。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$scope = scoped_staff_ids($user); // null=全員 / 配列=担当教室の講師ID
// 編集できる教室：admin=全教室 / staff=担当教室
$rooms = (($user['role'] ?? '') === 'admin') ? classrooms_active() : user_classrooms($user);

$flash = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  if (($_POST['action'] ?? '') === 'save_checklist') {
    $cl = trim($_POST['classroom'] ?? '');
    if (!in_array($cl, $rooms, true)) { $err = 'その教室は編集できません。'; }
    elseif (!clockout_checklist_table_exists()) { $err = '退勤チェックリスト用テーブルがありません。migrations/021_clockout_checklist.sql を実行してください。'; }
    else {
      $lines = preg_split('/\r\n|\r|\n/', (string)($_POST['items'] ?? ''));
      set_clockout_checklist($cl, $lines);
      $flash = "「{$cl}」の退勤チェックリストを保存しました。";
    }
  }
}

$itemsByRoom = [];
foreach ($rooms as $rm) { $itemsByRoom[$rm] = clockout_checklist_for($rm); }

// 講師名
$names = [];
foreach (db()->query("SELECT id, name FROM staff")->fetchAll() as $s) { $names[(int)$s['id']] = $s['name']; }

// 報告一覧（月・担当教室スコープ）
$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));
$reports = [];
if (clockout_reports_table_exists()) {
  $scopeSql = ''; $params = [$month];
  if (is_array($scope)) {
    if ($scope) { $scopeSql = ' AND staff_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $params = array_merge($params, $scope); }
    else { $scopeSql = ' AND 1=0'; }
  }
  $q = db()->prepare("SELECT * FROM clockout_reports WHERE DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql . " ORDER BY work_date DESC, id DESC");
  $q->execute($params);
  $reports = $q->fetchAll();
}

render_header('退勤チェック', $user, 'clockout.php');
?>
  <div class="container py-4">
    <h4 class="mb-3">退勤チェック</h4>
    <?php if (!clockout_checklist_table_exists()): ?>
      <div class="alert alert-warning py-2 small">退勤チェックリスト用テーブルがありません。<code>migrations/021_clockout_checklist.sql</code> を実行してください。</div>
    <?php endif; ?>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-4">
      <div class="card-header">退勤チェックリスト（教室別）<span class="text-muted small ms-1">1行＝1項目。退勤打刻時に表示され、全チェックで退勤打刻できます。</span></div>
      <div class="card-body">
        <?php if (!$rooms): ?>
          <div class="text-muted small">編集できる教室がありません（staff は担当教室を設定してください）。</div>
        <?php else: ?>
          <div class="row g-3">
            <?php foreach ($rooms as $rm): ?>
              <div class="col-md-6 col-lg-4">
                <form method="post">
                  <?= csrf_field() ?><input type="hidden" name="action" value="save_checklist"><input type="hidden" name="classroom" value="<?= h($rm) ?>">
                  <label class="form-label small mb-1 fw-bold"><?= h($rm) ?></label>
                  <textarea name="items" rows="5" class="form-control form-control-sm" placeholder="例：&#10;エアコンの電源オフ&#10;トイレ電気男女の電源オフ"><?= h(implode("\n", $itemsByRoom[$rm])) ?></textarea>
                  <div class="text-end mt-1"><button class="btn btn-sm btn-outline-primary">保存</button></div>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>退勤チェック記録（<?= h($month) ?>）<span class="text-muted small ms-1"><?= count($reports) ?>件</span></span>
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
          <span class="btn btn-light disabled"><?= h($month) ?></span>
          <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>日付</th><th>講師</th><th>教室</th><th>チェック項目</th><th>報告時刻</th></tr></thead>
          <tbody>
            <?php foreach ($reports as $r): $its = json_decode((string)$r['items'], true); if (!is_array($its)) $its = []; ?>
              <tr>
                <td class="small"><?= h($r['work_date']) ?></td>
                <td><?= h($names[(int)$r['staff_id']] ?? ('#' . $r['staff_id'])) ?></td>
                <td class="small"><?= h($r['classroom']) ?></td>
                <td class="small text-muted"><?= $its ? h(implode(' / ', $its)) : '—' ?></td>
                <td class="small"><?= h(substr((string)$r['created_at'], 11, 5)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$reports): ?><tr><td colspan="5" class="text-center text-muted py-3"><?= clockout_reports_table_exists() ? 'この月の退勤チェック記録はありません。' : 'migrations/021 未実行のため報告は記録されません。' ?></td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
