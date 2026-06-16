<?php
// 給与・シフト ダッシュボード。
//   admin/staff：講師ごとの時給（カラー×部門）一覧と各機能への入口。
//   teacher：シフト申請（D-2で実装予定）の入口プレースホルダ。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$role = $user['role'] ?? '';

// staff の実在カラム（本番スキーマ差異に強くする）
$staffCols = [];
foreach (db()->query("SHOW COLUMNS FROM staff")->fetchAll() as $c) { $staffCols[$c['Field']] = true; }
$hasUsePayroll = isset($staffCols['use_payroll']);
$hasIsActive   = isset($staffCols['is_active']);

if ($role === 'teacher') {
  render_header('給与・シフト', $user, '');
  echo '<div class="container py-4"><div class="card shadow-sm"><div class="card-body">';
  echo '<h5>シフト申請</h5><p class="text-muted">シフトの申請・確定状況の確認ができます。</p>';
  echo '<a href="shifts.php" class="btn btn-success btn-sm me-2">シフト申請へ</a>';
  echo '<a href="' . h(config_value('colorhrm_url', '/colorhrm/')) . 'mypage.php" class="btn btn-outline-secondary btn-sm">マイページ（ColorHRM）へ</a>';
  echo '</div></div></div>';
  render_footer();
  exit;
}

require_role(['admin', 'staff']);

// 在籍講師の一覧（時給算出つき）
$sql = "SELECT id, name, color_rank, departments"
     . ($hasUsePayroll ? ", use_payroll" : "")
     . " FROM staff";
$where = $hasIsActive ? " WHERE is_active = 1" : "";
$staff = db()->query($sql . $where . " ORDER BY name")->fetchAll();

$ratesCount = (int) db()->query("SELECT COUNT(*) c FROM pay_rates WHERE tenant_id=1")->fetch()['c'];
$payrollCount = 0;
foreach ($staff as $s) {
  if (!$hasUsePayroll || !empty($s['use_payroll'])) { $payrollCount++; }
}

render_header('給与・シフト', $user, 'index.php');
?>
  <div class="container py-4">
    <h4 class="mb-3">給与・シフト ダッシュボード</h4>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-3">
        <div class="text-muted small">在籍講師</div><div class="fs-4 fw-bold"><?= count($staff) ?> 名</div></div></div></div>
      <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-3">
        <div class="text-muted small">給与計算対象</div><div class="fs-4 fw-bold"><?= $payrollCount ?> 名</div></div></div></div>
      <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-3">
        <div class="text-muted small">時給設定</div><div class="fs-4 fw-bold"><?= $ratesCount ?> 件</div>
        <?php if ($role === 'admin'): ?><a href="rates.php" class="small">時給表を編集</a><?php endif; ?></div></div></div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>講師別 時給（カラー×部門）</span>
      </div>
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

    <p class="text-muted small mt-3"><a href="shifts_admin.php">シフト管理</a>で申請の確定・編集ができます。給与計算＋振込一覧（D-3）は順次追加します。</p>
  </div>
<?php render_footer(); ?>
