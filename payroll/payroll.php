<?php
// 給与計算＋振込一覧（admin/staff）。確定シフト（shift_days）×時給表（pay_rates）から月次集計。
//   授業給与=round(授業分/60×授業時給)、運営給与=round(運営分/60×運営時給)、交通費=GAS準拠。
//   CSVダウンロード（?export=csv）とテキストコピーに対応。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$month = valid_month($_GET['m'] ?? date('Y-m'));

// 明細の発行・送信（POST）：issue_all=全員 / issue_one=個別
$flash = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['issue_all', 'issue_one'], true)) {
  csrf_check();
  if (!payslips_table_exists()) {
    $err = '給与明細テーブル（payslips）がありません。migrations/011_payslips.sql を実行してください。';
  } else {
    $onlyId = ($_POST['action'] === 'issue_one') ? (int)($_POST['staff_id'] ?? 0) : null;
    $issued = issue_payslips($month, (int)$user['id'], $onlyId);
    $mailed = 0;
    if ($issued) {
      $ids = array_map(fn($it) => (int)$it['staff']['id'], $issued);
      $in  = implode(',', array_fill(0, count($ids), '?'));
      $em  = db()->prepare("SELECT id, name, email FROM staff WHERE id IN ($in)");
      $em->execute($ids);
      $byId = []; foreach ($em->fetchAll() as $s) { $byId[(int)$s['id']] = $s; }
      $note = db()->prepare("UPDATE payslips SET notified_at=NOW() WHERE staff_id=? AND month=?");
      foreach ($issued as $it) {
        $sid = (int)$it['staff']['id']; $s = $byId[$sid] ?? null;
        if ($s && trim((string)$s['email']) !== '' && send_payslip_notice($s['email'], $s['name'], $month)) {
          $note->execute([$sid, $month]); $mailed++;
        }
      }
    }
    $flash = count($issued) . '件の明細を発行しました'
           . ($mailed ? "（{$mailed}件にメール通知）" : '（メール通知0件：メール未登録、またはメール未設定）') . '。';
  }
}

$rows  = compute_month_payroll($month);

// 発行済み明細（月内）を staff_id で引けるように
$slipBy = [];
if (payslips_table_exists()) {
  $ps = db()->prepare("SELECT * FROM payslips WHERE month=?");
  $ps->execute([$month]);
  foreach ($ps->fetchAll() as $p) { $slipBy[(int)$p['staff_id']] = $p; }
}

// CSVダウンロード（ヘッダ出力のため render より前で処理）
if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="payroll_' . $month . '.csv"');
  echo "\xEF\xBB\xBF"; // Excel 用 BOM
  $out = fopen('php://output', 'w');
  fputcsv($out, ['講師', 'カラー', '部門', '勤務日数', '授業分', '運営分', '授業時給', '運営時給', '授業給与', '運営給与', '交通費', '合計']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['staff']['name'], $r['staff']['color_rank'], $r['staff']['departments'],
      $r['days'], $r['class_min'], $r['ops_min'], $r['class_rate'], $r['ops_rate'],
      $r['class_pay'], $r['ops_pay'], $r['transport'], $r['total'],
    ]);
  }
  fclose($out);
  exit;
}

$prev = date('Y-m', strtotime($month . '-01 -1 month'));
$next = date('Y-m', strtotime($month . '-01 +1 month'));

$sumClass = $sumOps = $sumTrans = $sumTotal = 0;
foreach ($rows as $r) { $sumClass += $r['class_pay']; $sumOps += $r['ops_pay']; $sumTrans += $r['transport']; $sumTotal += $r['total']; }

// コピー用TSV（振込連携などへの貼り付け用）
$tsv = "講師\tカラー\t勤務日数\t授業給与\t運営給与\t交通費\t合計\n";
foreach ($rows as $r) {
  $tsv .= $r['staff']['name'] . "\t" . $r['staff']['color_rank'] . "\t" . $r['days'] . "\t"
        . $r['class_pay'] . "\t" . $r['ops_pay'] . "\t" . $r['transport'] . "\t" . $r['total'] . "\n";
}

render_header('給与計算', $user, 'payroll.php');
?>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">給与計算・振込一覧</h4>
      <div class="d-flex gap-2 align-items-center">
        <div class="btn-group btn-group-sm">
          <a class="btn btn-outline-secondary" href="?m=<?= h($prev) ?>">← <?= h($prev) ?></a>
          <span class="btn btn-light disabled"><?= h($month) ?></span>
          <a class="btn btn-outline-secondary" href="?m=<?= h($next) ?>"><?= h($next) ?> →</a>
        </div>
        <a class="btn btn-sm btn-success" href="?m=<?= h($month) ?>&export=csv">CSVダウンロード</a>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="copyTsv()">コピー</button>
        <form method="post" class="d-inline" onsubmit="return confirm('<?= h($month) ?> の給与明細を対象者全員に発行し、メール通知します。よろしいですか？');">
          <?= csrf_field() ?><input type="hidden" name="action" value="issue_all">
          <button class="btn btn-sm btn-primary">全員に発行・送信</button>
        </form>
      </div>
    </div>
    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>
    <p class="text-muted small">確定シフト（シフト管理で確定したもの）と時給表から計算します。授業給与=round(授業分/60×授業時給)、運営給与=round(運営分/60×運営時給)、交通費は勤務日数で算定（≤5日:日数×200／超過:切上げ(日数/5)×1000）。</p>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>講師</th><th>カラー</th><th class="text-end">勤務日数</th>
              <th class="text-end">授業</th><th class="text-end">運営</th>
              <th class="text-end">授業給与</th><th class="text-end">運営給与</th>
              <th class="text-end">交通費</th><th class="text-end">合計</th><th>明細</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr<?= empty($r['staff']['use_payroll']) ? ' class="table-warning"' : '' ?>>
                <td><?= h($r['staff']['name']) ?><?= empty($r['staff']['use_payroll']) ? ' <span class="badge bg-secondary">対象外</span>' : '' ?></td>
                <td><span class="badge" style="<?= color_style($r['staff']['color_rank']) ?>"><?= h($r['staff']['color_rank']) ?></span></td>
                <td class="text-end"><?= (int)$r['days'] ?>日</td>
                <td class="text-end small text-muted"><?= h(fmt_hm($r['class_min'])) ?></td>
                <td class="text-end small text-muted"><?= h(fmt_hm($r['ops_min'])) ?></td>
                <td class="text-end">¥<?= number_format($r['class_pay']) ?></td>
                <td class="text-end">¥<?= number_format($r['ops_pay']) ?></td>
                <td class="text-end">¥<?= number_format($r['transport']) ?></td>
                <td class="text-end fw-bold">¥<?= number_format($r['total']) ?></td>
                <td class="text-nowrap">
                  <?php $sl = $slipBy[(int)$r['staff']['id']] ?? null; ?>
                  <?php if ($sl): ?>
                    <a href="payslip_pdf.php?id=<?= (int)$sl['id'] ?>" target="_blank" class="badge bg-success text-decoration-none">PDF</a>
                    <?php if (!empty($sl['notified_at'])): ?><span class="badge bg-info text-dark" title="<?= h($sl['notified_at']) ?>">通知済</span><?php endif; ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="issue_one"><input type="hidden" name="staff_id" value="<?= (int)$r['staff']['id'] ?>"><button class="btn btn-sm btn-link p-0">再発行</button></form>
                  <?php else: ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="issue_one"><input type="hidden" name="staff_id" value="<?= (int)$r['staff']['id'] ?>"><button class="btn btn-sm btn-outline-primary">発行・送信</button></form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="10" class="text-center text-muted py-4">この月の確定シフトはありません。<a href="shifts_admin.php?m=<?= h($month) ?>">シフト管理</a>で確定してください。</td></tr>
            <?php else: ?>
              <tr class="table-light fw-bold">
                <td colspan="5" class="text-end">合計</td>
                <td class="text-end">¥<?= number_format($sumClass) ?></td>
                <td class="text-end">¥<?= number_format($sumOps) ?></td>
                <td class="text-end">¥<?= number_format($sumTrans) ?></td>
                <td class="text-end">¥<?= number_format($sumTotal) ?></td>
                <td></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <textarea id="tsv" class="d-none"><?= h($tsv) ?></textarea>
  </div>
  <script>
    function copyTsv(){
      var t=document.getElementById('tsv');
      navigator.clipboard.writeText(t.value).then(function(){ alert('コピーしました（表計算ソフトに貼り付けできます）。'); },
        function(){ t.classList.remove('d-none'); t.select(); document.execCommand('copy'); t.classList.add('d-none'); alert('コピーしました。'); });
    }
  </script>
<?php render_footer(); ?>
