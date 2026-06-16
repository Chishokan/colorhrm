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
$rows  = compute_month_payroll($month);

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
      </div>
    </div>
    <p class="text-muted small">確定シフト（シフト管理で確定したもの）と時給表から計算します。授業給与=round(授業分/60×授業時給)、運営給与=round(運営分/60×運営時給)、交通費は勤務日数で算定（≤5日:日数×200／超過:切上げ(日数/5)×1000）。</p>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>講師</th><th>カラー</th><th class="text-end">勤務日数</th>
              <th class="text-end">授業</th><th class="text-end">運営</th>
              <th class="text-end">授業給与</th><th class="text-end">運営給与</th>
              <th class="text-end">交通費</th><th class="text-end">合計</th>
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
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
              <tr><td colspan="9" class="text-center text-muted py-4">この月の確定シフトはありません。<a href="shifts_admin.php?m=<?= h($month) ?>">シフト管理</a>で確定してください。</td></tr>
            <?php else: ?>
              <tr class="table-light fw-bold">
                <td colspan="5" class="text-end">合計</td>
                <td class="text-end">¥<?= number_format($sumClass) ?></td>
                <td class="text-end">¥<?= number_format($sumOps) ?></td>
                <td class="text-end">¥<?= number_format($sumTrans) ?></td>
                <td class="text-end">¥<?= number_format($sumTotal) ?></td>
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
