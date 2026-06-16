<?php
// 講師情報の CSV 一括エクスポート / インポート（admin）。
//   エクスポート：?export=csv で全講師をCSV出力（id 列つき＝再取込で更新できる）。
//   インポート：CSVをアップロード→プレビュー→確定。id か メール一致で更新、無ければ新規。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role('admin');
$user = current_user();

$cols = staff_columns();

// ---- エクスポート（実在カラムのみ出力） ----
if (isset($_GET['export'])) {
  $out = array_values(array_filter(staff_export_columns(), fn($c) => isset($cols[$c])));
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="staff_' . date('Ymd') . '.csv"');
  echo "\xEF\xBB\xBF";
  $fh = fopen('php://output', 'w');
  fputcsv($fh, $out);
  foreach (db()->query("SELECT * FROM staff WHERE tenant_id = 1 ORDER BY name")->fetchAll() as $s) {
    $line = [];
    foreach ($out as $c) { $line[] = (string)($s[$c] ?? ''); }
    fputcsv($fh, $line);
  }
  fclose($fh);
  exit;
}

// ---- 取込テンプレート ----
if (isset($_GET['template'])) {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="staff_template.csv"');
  echo "\xEF\xBB\xBF";
  echo implode(',', staff_export_columns()) . "\n";
  exit;
}

$flash = '';
$err   = '';
$preview = null;

function staff_read_csv_rows($path) {
  $raw = file_get_contents($path);
  if (!mb_check_encoding($raw, 'UTF-8')) { $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win'); }
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $rows = [];
  $fh = fopen('php://temp', 'r+');
  fwrite($fh, $raw); rewind($fh);
  while (($r = fgetcsv($fh)) !== false) {
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;
    $rows[] = $r;
  }
  fclose($fh);
  return $rows;
}
function staff_map_headers($headers) {
  $alias = staff_import_alias_map();
  $map = []; $unmatched = [];
  foreach ($headers as $i => $hRaw) {
    $h = trim((string)$hRaw);
    if ($h === '') continue;
    $col = $alias[$h] ?? ($alias[mb_strtolower($h)] ?? null);
    if ($col !== null) { $map[$i] = $col; } else { $unmatched[] = $h; }
  }
  return [$map, $unmatched];
}
// 既存の id / email（小文字）→ id を取得
function staff_existing_keys() {
  $ids = [];
  foreach (db()->query("SELECT id FROM staff")->fetchAll(PDO::FETCH_COLUMN) as $i) { $ids[(int)$i] = true; }
  $emails = [];
  foreach (db()->query("SELECT id, email FROM staff WHERE email <> ''")->fetchAll() as $e) {
    $emails[strtolower($e['email'])] = (int)$e['id'];
  }
  return [$ids, $emails];
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
      $dest  = $dir . '/staff_' . $token . '.csv';
      if (!move_uploaded_file($f['tmp_name'], $dest) && !@rename($f['tmp_name'], $dest)) {
        $err = 'アップロードの保存に失敗しました。';
      } else {
        $rows = staff_read_csv_rows($dest);
        if (count($rows) < 2) {
          $err = 'データ行がありません（1行目は見出し）。';
          @unlink($dest);
        } else {
          $headers = array_shift($rows);
          [$map, $unmatched] = staff_map_headers($headers);
          $dataCols = array_values(array_filter($map, fn($c) => $c !== 'id'));
          if (!$dataCols) {
            $err = '取り込める列見出しが見つかりませんでした。テンプレートをご確認ください。';
            @unlink($dest);
          } else {
            [$exIds, $exEmails] = staff_existing_keys();
            $newCount = 0; $updCount = 0; $skip = 0; $sample = [];
            foreach ($rows as $idx => $r) {
              $rec = [];
              foreach ($map as $i => $col) { $rec[$col] = normalize_staff_value($col, $r[$i] ?? ''); }
              $matchId = null;
              if (isset($rec['id']) && $rec['id'] && isset($exIds[$rec['id']])) { $matchId = $rec['id']; }
              elseif (!empty($rec['email']) && isset($exEmails[strtolower($rec['email'])])) { $matchId = $exEmails[strtolower($rec['email'])]; }
              if ($matchId === null && trim((string)($rec['name'] ?? '')) === '') { $skip++; continue; }
              if ($matchId) { $updCount++; } else { $newCount++; }
              if (count($sample) < 15) {
                $rec['_kind'] = $matchId ? '更新' : '新規';
                $sample[] = $rec;
              }
            }
            $preview = [
              'mapped' => $map, 'dataCols' => $dataCols, 'unmatched' => $unmatched,
              'rows' => $sample, 'total' => count($rows),
              'new' => $newCount, 'upd' => $updCount, 'skip' => $skip, 'token' => $token,
            ];
          }
        }
      }
    }
  } elseif ($action === 'commit') {
    $token = preg_replace('/[^a-f0-9]/', '', $_POST['token'] ?? '');
    $path  = import_dir() . '/staff_' . $token . '.csv';
    if ($token === '' || !is_file($path)) {
      $err = '取り込みファイルが見つかりません。もう一度アップロードしてください。';
    } else {
      $rows = staff_read_csv_rows($path);
      $headers = array_shift($rows);
      [$map] = staff_map_headers($headers);
      [$exIds, $exEmails] = staff_existing_keys();
      $inserted = 0; $updated = 0; $skipped = 0;
      db()->beginTransaction();
      try {
        foreach ($rows as $r) {
          $rec = [];
          foreach ($map as $i => $col) { $rec[$col] = normalize_staff_value($col, $r[$i] ?? ''); }
          $matchId = null;
          if (isset($rec['id']) && $rec['id'] && isset($exIds[$rec['id']])) { $matchId = $rec['id']; }
          elseif (!empty($rec['email']) && isset($exEmails[strtolower($rec['email'])])) { $matchId = $exEmails[strtolower($rec['email'])]; }

          if ($matchId) {
            // 更新：マップされた設定列のうち、実在し値が null でないものを更新
            $set = []; $vals = [];
            foreach (staff_import_columns() as $c) {
              if (!isset($cols[$c]) || !in_array($c, $map, true)) continue;
              $v = $rec[$c] ?? null;
              if ($v === null) continue; // 空の日付/在籍は据え置き
              $set[] = "$c = ?"; $vals[] = $v;
            }
            if ($set) {
              $vals[] = $matchId;
              db()->prepare("UPDATE staff SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
              $updated++;
            }
          } else {
            if (trim((string)($rec['name'] ?? '')) === '') { $skipped++; continue; }
            $names = ['tenant_id']; $ph = ['?']; $vals = [1];
            foreach (staff_import_columns() as $c) {
              if (!isset($cols[$c]) || !in_array($c, $map, true)) continue;
              $v = $rec[$c] ?? null;
              if ($c === 'is_active'  && $v === null) { $v = 1; }
              if ($c === 'use_payroll' && $v === null) { $v = 0; }
              if ($c === 'color_rank' && ($v === null || $v === '')) { $v = 'WHITE'; }
              $names[] = $c; $ph[] = '?'; $vals[] = $v;
            }
            // 在籍列がCSVに無い場合のデフォルト
            if (isset($cols['is_active']) && !in_array('is_active', $map, true)) { $names[] = 'is_active'; $ph[] = '?'; $vals[] = 1; }
            db()->prepare("INSERT INTO staff (" . implode(',', $names) . ") VALUES (" . implode(',', $ph) . ")")->execute($vals);
            $inserted++;
          }
        }
        db()->commit();
        $flash = "取り込み完了：新規 {$inserted}件 / 更新 {$updated}件"
               . ($skipped ? "（氏名なしの新規 {$skipped}件をスキップ）" : '') . "。";
      } catch (Throwable $e) {
        db()->rollBack();
        $err = '取り込み中にエラー: ' . $e->getMessage();
      }
      @unlink($path);
    }
  }
}

render_header('講師情報 CSV入出力', $user, 'users.php');
?>
  <div class="container py-4" style="max-width:980px">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">講師情報 CSV入出力</h4>
      <a href="staff_accounts.php" class="btn btn-sm btn-outline-secondary">← 講師アカウント作成へ</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-3">
      <div class="card-header">エクスポート</div>
      <div class="card-body">
        <p class="small text-muted mb-2">全講師（在籍・退職とも）を CSV で書き出します。<code>id</code> 列があるので、編集して再取り込みすると<strong>更新</strong>になります。</p>
        <a href="staff_io.php?export=csv" class="btn btn-sm btn-success">講師情報をCSVエクスポート</a>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header">インポート</div>
      <div class="card-body">
        <p class="small text-muted mb-2">
          見出しは日本語（氏名・部門・カラー 等）でも snake_case でも自動対応します。
          <code>id</code> またはメールが既存と一致すれば<strong>更新</strong>、無ければ<strong>新規</strong>。まずプレビューで確認できます。
        </p>
        <a href="staff_io.php?template=1" class="btn btn-sm btn-outline-secondary mb-2">CSVテンプレートをダウンロード</a>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="preview">
          <div class="col-auto">
            <label class="form-label small mb-0">CSVファイル</label>
            <input type="file" name="csv" accept=".csv,text/csv" class="form-control form-control-sm" required>
          </div>
          <div class="col-auto"><button class="btn btn-sm btn-primary">プレビュー</button></div>
        </form>
      </div>
    </div>

    <?php if ($preview): ?>
      <div class="card shadow-sm">
        <div class="card-header">
          プレビュー（全 <?= (int)$preview['total'] ?> 行）：
          <span class="badge bg-primary">新規 <?= (int)$preview['new'] ?></span>
          <span class="badge bg-warning text-dark">更新 <?= (int)$preview['upd'] ?></span>
          <?php if ($preview['skip']): ?><span class="badge bg-secondary">スキップ <?= (int)$preview['skip'] ?></span><?php endif; ?>
        </div>
        <div class="card-body">
          <?php if ($preview['unmatched']): ?>
            <div class="alert alert-warning py-2 small">対応づけできなかった見出し（無視）：<?= h(implode(' / ', $preview['unmatched'])) ?></div>
          <?php endif; ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light"><tr>
                <th class="small">区分</th>
                <?php foreach ($preview['dataCols'] as $col): ?><th class="small"><?= h($col) ?></th><?php endforeach; ?>
              </tr></thead>
              <tbody>
                <?php foreach ($preview['rows'] as $rec): ?>
                  <tr>
                    <td><span class="badge <?= $rec['_kind'] === '更新' ? 'bg-warning text-dark' : 'bg-primary' ?>"><?= h($rec['_kind']) ?></span></td>
                    <?php foreach ($preview['dataCols'] as $col): ?>
                      <td class="small"><?= h((string)($rec[$col] ?? '')) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="small text-muted">先頭 <?= count($preview['rows']) ?> 行を表示。空の日付/在籍セルは更新時に据え置きます。</p>
          <form method="post" onsubmit="return confirm('<?= (int)$preview['total'] ?>行を取り込みます（新規 <?= (int)$preview['new'] ?> / 更新 <?= (int)$preview['upd'] ?>）。よろしいですか？');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="commit">
            <input type="hidden" name="token" value="<?= h($preview['token']) ?>">
            <button class="btn btn-success">この内容で取り込む</button>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
<?php render_footer(); ?>
