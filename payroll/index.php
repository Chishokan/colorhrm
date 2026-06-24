<?php
// 給与・シフト ホーム。
//   teacher：当月の確定シフト＋各機能への入口。
//   admin/staff：講師別の時給一覧＋当月の確定シフト（担当教室スコープ）。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$role = $user['role'] ?? '';

// staff の実在カラム
$staffCols = [];
foreach (db()->query("SHOW COLUMNS FROM staff")->fetchAll() as $c) { $staffCols[$c['Field']] = true; }
$hasUsePayroll = isset($staffCols['use_payroll']);
$hasIsActive   = isset($staffCols['is_active']);

// shift_days の教室列
$sdCols = [];
foreach (db()->query("SHOW COLUMNS FROM shift_days")->fetchAll() as $c) { $sdCols[$c['Field']] = true; }
$hasRoom = isset($sdCols['room']);

// 当月（前月/翌月へ移動）
$month = valid_month($_GET['m'] ?? date('Y-m'));
$prev  = date('Y-m', strtotime($month . '-01 -1 month'));
$next  = date('Y-m', strtotime($month . '-01 +1 month'));

// 確定シフト表（共通描画）
function render_month_shifts($days, $hasRoom, $names = null) {
  ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light"><tr>
        <?php if ($names !== null): ?><th>講師</th><?php endif; ?>
        <th>日付</th><?php if ($hasRoom): ?><th>教室</th><?php endif; ?><th>時間</th>
        <th class="text-end">稼働</th><th class="text-end">授業</th><th class="text-end">運営</th>
      </tr></thead>
      <tbody>
        <?php $tt = 0; foreach ($days as $d): $tot = shift_minutes($d['start_time'], $d['end_time']); $cls = min((int)$d['class_minutes'], $tot); $tt += $tot; ?>
          <tr>
            <?php if ($names !== null): ?><td><?= h($names[(int)$d['staff_id']] ?? ('#' . $d['staff_id'])) ?></td><?php endif; ?>
            <td class="small"><?= h($d['work_date']) ?></td>
            <?php if ($hasRoom): ?><td class="small"><?= h($d['room'] ?? '') ?: '—' ?></td><?php endif; ?>
            <td class="small"><?= h(hm($d['start_time'])) ?>〜<?= h(hm($d['end_time'])) ?></td>
            <td class="text-end small"><?= h(fmt_hm($tot)) ?></td>
            <td class="text-end small"><?= h(fmt_hm($cls)) ?></td>
            <td class="text-end small"><?= h(fmt_hm($tot - $cls)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$days): ?><tr><td colspan="7" class="text-center text-muted py-3">この月の確定シフトはありません。</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
}

function month_nav_html($month, $prev, $next) {
  return '<div class="btn-group btn-group-sm">'
    . '<a class="btn btn-outline-secondary" href="?m=' . h($prev) . '">← ' . h($prev) . '</a>'
    . '<span class="btn btn-light disabled">' . h($month) . '</span>'
    . '<a class="btn btn-outline-secondary" href="?m=' . h($next) . '">' . h($next) . ' →</a></div>';
}

// ============================== teacher ==============================
if ($role === 'teacher') {
  $staffId = (int)($user['staff_id'] ?? 0);
  $days = [];
  if ($staffId) {
    $q = db()->prepare("SELECT * FROM shift_days WHERE staff_id=? AND DATE_FORMAT(work_date,'%Y-%m')=? ORDER BY work_date, start_time");
    $q->execute([$staffId, $month]);
    $days = $q->fetchAll();
  }
  $colorhrm = config_value('colorhrm_url', '/colorhrm/');
  render_header('給与・シフト', $user, 'index.php');
  ?>
  <div class="container py-4">
    <div class="card shadow-sm mb-3">
      <div class="card-body d-flex flex-wrap gap-2">
        <a href="punch.php" class="btn btn-sm btn-success">打刻</a>
        <a href="shifts.php" class="btn btn-sm btn-outline-success">シフト可能登録</a>
        <a href="payslips.php" class="btn btn-sm btn-outline-success">給与明細</a>
        <a href="<?= h($colorhrm) ?>mypage.php" class="btn btn-sm btn-outline-secondary">マイページ（ColorHRM）へ</a>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>当月の確定シフト（<?= h($month) ?>）</span>
        <?= month_nav_html($month, $prev, $next) ?>
      </div>
      <?php render_month_shifts($days, $hasRoom); ?>
    </div>
  </div>
  <?php
  render_footer();
  exit;
}

// ============================== admin / staff ==============================
require_role(['admin', 'staff']);

$staff = db()->query("SELECT id, name, color_rank, departments" . ($hasUsePayroll ? ", use_payroll" : "") . " FROM staff"
       . ($hasIsActive ? " WHERE is_active = 1" : "") . " ORDER BY name")->fetchAll();
$ratesCount = (int) db()->query("SELECT COUNT(*) c FROM pay_rates WHERE tenant_id=1")->fetch()['c'];
$payrollCount = 0;
foreach ($staff as $s) { if (!$hasUsePayroll || !empty($s['use_payroll'])) { $payrollCount++; } }

// 当月の確定シフト（担当教室スコープ）
$scope = scoped_staff_ids($user);
$scopeSql = ''; $scopeParams = [];
if (is_array($scope)) {
  if ($scope) { $scopeSql = ' AND staff_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $scopeParams = $scope; }
  else { $scopeSql = ' AND 1=0'; }
}
$mdays = db()->prepare("SELECT * FROM shift_days WHERE DATE_FORMAT(work_date,'%Y-%m')=?" . $scopeSql . " ORDER BY work_date, staff_id, start_time");
$mdays->execute(array_merge([$month], $scopeParams));
$mdays = $mdays->fetchAll();
$names = [];
foreach (db()->query("SELECT id, name FROM staff")->fetchAll() as $s) { $names[(int)$s['id']] = $s['name']; }

// シフト確定待ち（申請中）を講師ごとに集計（担当教室スコープ・全期間）
$pendBy = [];
try {
  $pq = db()->prepare("SELECT staff_id, COUNT(*) cnt, MIN(work_date) mind FROM shift_applications WHERE status='申請中'" . $scopeSql . " GROUP BY staff_id ORDER BY MIN(work_date)");
  $pq->execute($scopeParams);
  $pendBy = $pq->fetchAll();
} catch (Throwable $e) { $pendBy = []; }
$pendTotal = array_sum(array_map(fn($p) => (int)$p['cnt'], $pendBy));
$pendMonth = $pendBy ? substr((string)$pendBy[0]['mind'], 0, 7) : $month;

// 確定待ちがあれば1日1回まとめてメール通知（その日最初の staff/admin アクセス時）
maybe_send_pending_shift_digest();

render_header('給与・シフト', $user, 'index.php');
?>
  <div class="container py-4">
    <h4 class="mb-3">給与・シフト ダッシュボード</h4>

    <?php if ($pendBy): ?>
    <div class="alert alert-warning shadow-sm d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <strong>⚠ シフト確定待ちがあります（<?= $pendTotal ?>件）</strong>
        <div class="small mt-1">
          <?= implode('、', array_map(fn($p) => h($names[(int)$p['staff_id']] ?? ('#' . $p['staff_id'])) . '（' . (int)$p['cnt'] . '件）', $pendBy)) ?>
          からのシフト確定待ちがあります。
        </div>
      </div>
      <a href="shifts_admin.php?m=<?= h($pendMonth) ?>" class="btn btn-sm btn-warning text-nowrap">シフト管理で確定</a>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-3">
        <div class="text-muted small">在籍講師</div><div class="fs-4 fw-bold"><?= count($staff) ?> 名</div></div></div></div>
      <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-3">
        <div class="text-muted small">給与計算対象</div><div class="fs-4 fw-bold"><?= $payrollCount ?> 名</div></div></div></div>
      <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-3">
        <div class="text-muted small">時給設定</div><div class="fs-4 fw-bold"><?= $ratesCount ?> 件</div>
        <?php if ($role === 'admin'): ?><a href="rates.php" class="small">時給表を編集</a><?php endif; ?></div></div></div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>当月の確定シフト（<?= h($month) ?>）<span class="text-muted small ms-1"><?= count($mdays) ?>件</span></span>
        <div class="d-flex gap-2">
          <?= month_nav_html($month, $prev, $next) ?>
          <a href="shifts_admin.php?m=<?= h($month) ?>" class="btn btn-sm btn-outline-primary">シフト管理</a>
        </div>
      </div>
      <?php render_month_shifts($mdays, $hasRoom, $names); ?>
    </div>

    <div class="card shadow-sm">
      <div class="card-header">講師別 時給（カラー×部門）</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>講師</th><th>カラー</th><th>部門</th><th class="text-end">授業時給</th><th class="text-end">運営時給</th><?php if ($hasUsePayroll): ?><th>給与計算</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($staff as $s): $rate = compute_class_rate($s); ?>
              <tr>
                <td><?= h($s['name']) ?></td>
                <td><span class="badge" style="<?= color_style($s['color_rank']) ?>"><?= h($s['color_rank']) ?></span></td>
                <td class="small text-muted"><?= h($s['departments']) ?></td>
                <td class="text-end">¥<?= number_format($rate['class_rate']) ?></td>
                <td class="text-end">¥<?= number_format($rate['ops_rate']) ?></td>
                <?php if ($hasUsePayroll): ?><td><?= !empty($s['use_payroll']) ? '<span class="badge bg-success">対象</span>' : '<span class="badge bg-secondary">対象外</span>' ?></td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
            <?php if (!$staff): ?><tr><td colspan="6" class="text-center text-muted py-3">在籍講師がいません。</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php render_footer(); ?>
