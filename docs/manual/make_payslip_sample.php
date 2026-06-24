<?php
// マニュアル用：給与明細サンプルPDFを生成する。
//   実行: php docs/manual/make_payslip_sample.php
// 本番の build_payslip_pdf()（payroll/lib.php）と同じレイアウトを、
// ダミーデータで再現する（DB不要）。レイアウト変更時は本ファイルも合わせて更新。

require_once __DIR__ . '/../../payroll/tfpdf/tfpdf.php';
require_once __DIR__ . '/../../payroll/tfpdf/font/unifont/ttfonts.php';

function fmt_hm($mins) {
  $mins = max(0, (int)$mins);
  return sprintf('%d:%02d', intdiv($mins, 60), $mins % 60);
}

// payroll/lib.php の build_payslip_pdf() と同一ロジック（サンプル生成用に複製）
function build_payslip_pdf($slip, $staffName) {
  $pdf = new tFPDF('P', 'mm', 'A4');
  $pdf->SetTitle('payslip');
  $pdf->AddPage();
  $pdf->AddFont('ipag', '', 'ipag.ttf', true);
  $pdf->SetFont('ipag', '', 18);
  $pdf->Cell(0, 12, '給 与 明 細 書', 0, 1, 'C');
  $pdf->SetFont('ipag', '', 11);
  $pdf->Cell(0, 7, '対象月： ' . $slip['month'], 0, 1, 'C');
  $pdf->Ln(2);
  $pdf->Cell(0, 8, '氏名： ' . $staffName . '　様', 0, 1);
  $pdf->Cell(0, 8, '発行日： ' . substr((string)$slip['issued_at'], 0, 10) . '　／　智翔館グループ', 0, 1);
  $pdf->Ln(2);
  $pdf->SetFont('ipag', '', 11);
  $w1 = 90; $w2 = 90;
  $row = function ($label, $val, $bold = false) use ($pdf, $w1, $w2) {
    $pdf->SetFont('ipag', '', $bold ? 13 : 11);
    $pdf->Cell($w1, 9, $label, 1, 0, 'L');
    $pdf->Cell($w2, 9, $val, 1, 1, 'R');
  };
  $fmt = function ($n) { return number_format((int)$n); };
  $row('勤務日数', $fmt($slip['days']) . ' 日');
  $row('授業時間 / 授業時給', fmt_hm($slip['class_min']) . ' / ' . $fmt($slip['class_rate']) . ' 円');
  $row('運営時間 / 運営時給', fmt_hm($slip['ops_min']) . ' / ' . $fmt($slip['ops_rate']) . ' 円');
  $pdf->Ln(1);
  $row('授業給与', $fmt($slip['class_pay']) . ' 円');
  $row('運営給与', $fmt($slip['ops_pay']) . ' 円');
  $row('交通費', $fmt($slip['transport']) . ' 円');
  $row('支給合計', $fmt($slip['total']) . ' 円', true);
  $pdf->Ln(6);
  $pdf->SetFont('ipag', '', 9);
  $pdf->MultiCell(0, 5, '※ 本明細は発行時点の確定シフトに基づく金額です。ご不明点は管理者へお問い合わせください。');
  return $pdf->Output('S');
}

// ----- サンプルデータ（実際の計算式と整合するダミー値）-----
// 授業給与 = 授業分/60 * 授業時給、運営給与 = 運営分/60 * 運営時給
function make_slip($name, $month, $issued, $days, $classMin, $opsMin, $classRate, $opsRate, $transport) {
  $classPay = (int)round($classMin / 60 * $classRate);
  $opsPay   = (int)round($opsMin / 60 * $opsRate);
  $total    = $classPay + $opsPay + $transport;
  return [
    'name' => $name,
    'slip' => [
      'month' => $month, 'issued_at' => $issued, 'days' => $days,
      'class_min' => $classMin, 'ops_min' => $opsMin,
      'class_rate' => $classRate, 'ops_rate' => $opsRate,
      'class_pay' => $classPay, 'ops_pay' => $opsPay,
      'transport' => $transport, 'total' => $total,
    ],
  ];
}

$samples = [
  // GREENの講師（標準例）
  make_slip('智翔 花子（サンプル）', '2026-05', '2026-06-01', 18, 1620, 480, 1100, 1031, 7200),
  // BLUEの講師（コマ数多め・別例）
  make_slip('育成 太郎（サンプル）', '2026-05', '2026-06-01', 22, 2400, 600, 1250, 1100, 9900),
];

$outDir = __DIR__;
$files = [];
foreach ($samples as $i => $s) {
  $pdf = build_payslip_pdf($s['slip'], $s['name']);
  $path = $outDir . '/colorhrm-payslip-sample' . ($i === 0 ? '' : '-' . ($i + 1)) . '.pdf';
  file_put_contents($path, $pdf);
  $files[] = $path;
  printf("wrote: %s  (支給合計 %s 円)\n", $path, number_format($s['slip']['total']));
}
