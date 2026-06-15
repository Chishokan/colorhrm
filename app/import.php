<?php
// データ移行（フェーズ6）：既存スプレッドシート（応募者）→ candidates の CSV 取り込み。
// 日本語見出し / GAS版camelCase / snake_case を吸収してマッピング。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role('admin');
$user = current_user();

$flash = '';
$err   = '';
$preview = null; // ['headers'=>, 'mapped'=>, 'unmatched'=>, 'rows'=>, 'total'=>, 'token'=>]

// CSVテンプレート（snake_case見出し）のダウンロード
if (isset($_GET['template'])) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="candidates_template.csv"');
  echo "\xEF\xBB\xBF"; // UTF-8 BOM（Excelの文字化け防止）
  echo implode(',', candidate_import_columns()) . "\n";
  exit;
}

// アップロードCSVをUTF-8の行配列へ
function read_csv_rows($path) {
  $raw = file_get_contents($path);
  if (!mb_check_encoding($raw, 'UTF-8')) {
    $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win');
  }
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM除去
  $rows = [];
  $fh = fopen('php://temp', 'r+');
  fwrite($fh, $raw);
  rewind($fh);
  while (($r = fgetcsv($fh)) !== false) {
    if (count($r) === 1 && trim((string)$r[0]) === '') continue; // 空行
    $rows[] = $r;
  }
  fclose($fh);
  return $rows;
}

// ヘッダ→candidates列 のマッピングを作る
function map_headers($headers) {
  $alias = candidate_import_alias_map();
  $map = [];        // index => column
  $unmatched = [];
  foreach ($headers as $i => $hRaw) {
    $h = trim((string)$hRaw);
    if ($h === '') continue;
    $col = $alias[$h] ?? ($alias[mb_strtolower($h)] ?? null);
    if ($col !== null) { $map[$i] = $col; } else { $unmatched[] = $h; }
  }
  return [$map, $unmatched];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'preview') {
    $f = $_FILES['csv'] ?? [];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $err = 'CSVファイルを選択してください。';
    } else {
      $dir = import_dir();
      if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
      $token = bin2hex(random_bytes(8));
      $dest  = $dir . '/' . $token . '.csv';
      if (!move_uploaded_file($f['tmp_name'], $dest) && !@rename($f['tmp_name'], $dest)) {
        $err = 'アップロードの保存に失敗しました。';
      } else {
        $rows = read_csv_rows($dest);
        if (count($rows) < 2) {
          $err = 'データ行がありません（1行目は見出し）。';
          @unlink($dest);
        } else {
          $headers = array_shift($rows);
          list($map, $unmatched) = map_headers($headers);
          if (!$map) {
            $err = '対応する列見出しが見つかりませんでした。テンプレートをご確認ください。';
            @unlink($dest);
          } else {
            $sample = [];
            foreach (array_slice($rows, 0, 15) as $r) {
              $rec = [];
              foreach ($map as $i => $col) { $rec[$col] = normalize_import_value($col, $r[$i] ?? ''); }
              $sample[] = $rec;
            }
            $preview = [
              'mapped' => $map, 'unmatched' => $unmatched,
              'rows' => $sample, 'total' => count($rows), 'token' => $token,
            ];
          }
        }
      }
    }
  } elseif ($action === 'commit') {
    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    $path  = import_dir() . '/' . $token . '.csv';
    if ($token === '' || !is_file($path)) {
      $err = '取り込みファイルが見つかりません。もう一度アップロードしてください。';
    } else {
      $rows = read_csv_rows($path);
      $headers = array_shift($rows);
      list($map) = map_headers($headers);
      $cols = array_values(array_unique($map));
      $hasNo = in_array('no', $cols, true);
      $insertCols = array_values(array_unique(array_merge(['tenant_id', 'no'], $cols)));
      $place = implode(',', array_fill(0, count($insertCols), '?'));
      $sql = "INSERT INTO candidates (" . implode(',', $insertCols) . ") VALUES ($place)";
      $stmt = db()->prepare($sql);
      $maxNo = (int)db()->query("SELECT COALESCE(MAX(no),0) FROM candidates WHERE tenant_id=1")->fetchColumn();
      $inserted = 0; $skipped = 0;
      db()->beginTransaction();
      try {
        foreach ($rows as $r) {
          $rec = [];
          foreach ($map as $i => $col) { $rec[$col] = normalize_import_value($col, $r[$i] ?? ''); }
          // 氏名が空＝実質空行はスキップ
          if (trim((string)($rec['name'] ?? '')) === '') { $skipped++; continue; }
          $no = ($hasNo && $rec['no'] !== null) ? (int)$rec['no'] : (++$maxNo);
          $vals = [1, $no];
          foreach ($cols as $c) { $vals[] = $rec[$c] ?? null; }
          $stmt->execute($vals);
          $inserted++;
        }
        db()->commit();
        $flash = "取り込み完了：{$inserted}件を登録しました" . ($skipped ? "（氏名なし {$skipped}件をスキップ）" : '') . "。";
      } catch (Throwable $e) {
        db()->rollBack();
        $err = '取り込み中にエラー: ' . $e->getMessage();
      }
      @unlink($path);
    }
  }
}

render_header('データ移行（CSV取り込み）', $user, 'import.php');
?>
  <div class="container py-4" style="max-width:960px">
    <h4 class="mb-3">データ移行：応募者CSVの取り込み</h4>

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <p class="mb-2 small text-muted">
          既存スプレッドシート（応募者シート）を CSV で書き出してアップロードしてください。
          見出しは日本語（氏名・部署 等）でも、GAS版の名前でも自動で対応づけます。
          まず内容を確認（プレビュー）してから取り込みます。
        </p>
        <a href="import.php?template=1" class="btn btn-sm btn-outline-secondary mb-2">CSVテンプレートをダウンロード</a>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="preview">
          <div class="col-auto">
            <label class="form-label small mb-0">CSVファイル</label>
            <input type="file" name="csv" accept=".csv,text/csv" class="form-control form-control-sm" required>
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-primary">プレビュー</button>
          </div>
        </form>
      </div>
    </div>

    <?php if ($preview): ?>
      <div class="card shadow-sm">
        <div class="card-header">
          プレビュー（全 <?= (int)$preview['total'] ?> 行 / 先頭 <?= count($preview['rows']) ?> 行を表示）
        </div>
        <div class="card-body">
          <?php if ($preview['unmatched']): ?>
            <div class="alert alert-warning py-2 small">
              対応づけできなかった見出し（無視されます）：<?= h(implode(' / ', $preview['unmatched'])) ?>
            </div>
          <?php endif; ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light"><tr>
                <?php foreach ($preview['mapped'] as $col): ?><th class="small"><?= h($col) ?></th><?php endforeach; ?>
              </tr></thead>
              <tbody>
                <?php foreach ($preview['rows'] as $rec): ?>
                  <tr>
                    <?php foreach ($preview['mapped'] as $col): ?>
                      <td class="small"><?= h((string)($rec[$col] ?? '')) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <form method="post" onsubmit="return confirm('<?= (int)$preview['total'] ?>行を取り込みます。よろしいですか？');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="commit">
            <input type="hidden" name="token" value="<?= h($preview['token']) ?>">
            <button class="btn btn-success">この内容で取り込む</button>
            <span class="text-muted small ms-2">※ 氏名が空の行はスキップ。`no` 列が無ければ自動採番します。</span>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php render_footer(); ?>
