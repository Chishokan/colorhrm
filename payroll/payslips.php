<?php
// 給与明細（講師）。発行された自分の明細を一覧し、PDFをダウンロード。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$staffId = (int)($user['staff_id'] ?? 0);

render_header('給与明細', $user, 'payslips.php');
?>
  <div class="container py-4" style="max-width:760px">
    <h4 class="mb-3">給与明細</h4>

    <?php if (!payslips_table_exists()): ?>
      <div class="alert alert-light border">給与明細はまだ利用できません（管理者の設定待ち）。</div>
    <?php elseif ($staffId <= 0): ?>
      <div class="alert alert-warning">
        このアカウントは講師（staff）に紐付いていません。
        <?php if (in_array($user['role'] ?? '', ['admin', 'staff'], true)): ?>
          管理者は <a href="payroll.php">給与計算</a> から明細の発行・確認ができます。
        <?php endif; ?>
      </div>
    <?php else: ?>
      <?php
        $ps = db()->prepare("SELECT * FROM payslips WHERE staff_id = ? ORDER BY month DESC");
        $ps->execute([$staffId]);
        $slips = $ps->fetchAll();
      ?>
      <?php if (!$slips): ?>
        <div class="alert alert-light border">発行された給与明細はまだありません。</div>
      <?php else: ?>
        <div class="card shadow-sm">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light"><tr><th>対象月</th><th class="text-end">支給合計</th><th>発行日</th><th class="text-end">明細</th></tr></thead>
              <tbody>
                <?php foreach ($slips as $p): ?>
                  <tr>
                    <td><?= h($p['month']) ?></td>
                    <td class="text-end fw-bold">¥<?= number_format((int)$p['total']) ?></td>
                    <td class="small text-muted"><?= h(substr((string)$p['issued_at'], 0, 10)) ?></td>
                    <td class="text-end"><a href="payslip_pdf.php?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success">PDFを開く</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <p class="text-muted small mt-2">※ 金額は発行時点の確定シフトに基づきます。</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php render_footer(); ?>
