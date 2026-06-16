<?php
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
$user = current_user();

// teacher は users.staff_id 経由で自分の講師レコードに紐づく。
// admin/staff も自分に staff_id があれば閲覧可（無ければ案内を出す）。
$staffId = $user['staff_id'] ?? null;

$flash = '';
$err   = '';
$staff = null;

if ($staffId) {
  $st = db()->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
  $st->execute([$staffId]);
  $staff = $st->fetch();
}

// 育成目標カラー（target_rank 優先、無ければ現カラー）
$targetColor = $staff ? ($staff['target_rank'] ?: $staff['color_rank']) : null;

// ------------------------------------------------------------
// 自己申告（POST）：対象研修項目を「申告中」にする
// ------------------------------------------------------------
if ($staff && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'declare') {
  csrf_check();
  $itemId = (int)($_POST['item_id'] ?? 0);
  $memo   = trim($_POST['memo'] ?? '');

  // 申告対象が自分の研修項目であることを確認（target_color の一致）
  $chk = db()->prepare("SELECT id FROM training_items WHERE id = ? LIMIT 1");
  $chk->execute([$itemId]);
  if ($itemId && $chk->fetch()) {
    // uq_staff_item により upsert。差戻し後の再申告は承認情報をクリアする。
    $sql = "INSERT INTO training_progress
              (tenant_id, staff_id, training_item_id, status, memo, declared_by, declared_at)
            VALUES (?, ?, ?, '申告中', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
              status='申告中', memo=VALUES(memo),
              declared_by=VALUES(declared_by), declared_at=NOW(),
              approved_by=NULL, approved_at=NULL";
    db()->prepare($sql)->execute([
      (int)$staff['tenant_id'], (int)$staff['id'], $itemId, $memo, (int)$user['id'],
    ]);
    $flash = '申告しました。承認をお待ちください。';
  }
}

// ------------------------------------------------------------
// テスト証跡の提出（POST）：type=テスト の項目を証跡画像つきで「申告中」に
// ------------------------------------------------------------
if ($staff && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_test') {
  csrf_check();
  $itemId = (int)($_POST['item_id'] ?? 0);
  $chk = db()->prepare("SELECT id FROM training_items WHERE id = ? LIMIT 1");
  $chk->execute([$itemId]);
  if ($itemId && $chk->fetch()) {
    try {
      $saved = save_evidence_upload((int)$staff['id'], $itemId, $_FILES['evidence'] ?? []);
      $sql = "INSERT INTO training_progress
                (tenant_id, staff_id, training_item_id, status, evidence_file, submitted_by, declared_by, declared_at)
              VALUES (?, ?, ?, '申告中', ?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE
                status='申告中', evidence_file=VALUES(evidence_file), submitted_by=VALUES(submitted_by),
                declared_by=VALUES(declared_by), declared_at=NOW(), approved_by=NULL, approved_at=NULL";
      db()->prepare($sql)->execute([
        (int)$staff['tenant_id'], (int)$staff['id'], $itemId, $saved, $staff['name'], (int)$user['id'],
      ]);
      $flash = 'テスト証跡を提出しました。承認をお待ちください。';
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

// ------------------------------------------------------------
// 質問への回答（POST）
// ------------------------------------------------------------
if ($staff && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'answer') {
  csrf_check();
  $qid = (int)($_POST['question_id'] ?? 0);
  $ans = trim($_POST['answer'] ?? '');
  // 対象の質問が本人に表示可能か確認（有効 かつ 全員向け or 本人指定）
  $chk = db()->prepare("SELECT id FROM questions WHERE id = ? AND is_active = 1 AND (target_staff_id IS NULL OR target_staff_id = 0 OR target_staff_id = ?) LIMIT 1");
  $chk->execute([$qid, (int)$staff['id']]);
  if ($qid && $chk->fetch()) {
    db()->prepare("INSERT INTO answers (tenant_id, question_id, staff_id, answer) VALUES (1, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE answer = VALUES(answer), answered_at = NOW()")
        ->execute([$qid, (int)$staff['id'], $ans]);
    $flash = '回答を保存しました。';
  }
}

// 本人に表示する質問＋自分の回答（テーブル未作成でも落ちないようガード）
$questions = [];
if ($staff) {
  try {
    $qq = db()->prepare(
      "SELECT q.id, q.text, a.answer
         FROM questions q
         LEFT JOIN answers a ON a.question_id = q.id AND a.staff_id = ?
        WHERE q.is_active = 1 AND (q.target_staff_id IS NULL OR q.target_staff_id = 0 OR q.target_staff_id = ?)
        ORDER BY q.sort_order, q.id");
    $qq->execute([(int)$staff['id'], (int)$staff['id']]);
    $questions = $qq->fetchAll();
  } catch (Throwable $e) { $questions = []; }
}

// ------------------------------------------------------------
// 研修項目 + 自分の進捗を取得（部門 × 育成目標カラー）
// ------------------------------------------------------------
$items = [];
if ($staff && $targetColor) {
  $sql = "SELECT ti.*, tp.status, tp.memo AS progress_memo, tp.completed_date,
                 tp.declared_at, tp.approved_at
          FROM training_items ti
          LEFT JOIN training_progress tp
                 ON tp.training_item_id = ti.id AND tp.staff_id = ?
          WHERE ti.target_color = ?
          ORDER BY ti.department, ti.sort_order, ti.id";
  $q = db()->prepare($sql);
  $q->execute([(int)$staff['id'], $targetColor]);
  $all = $q->fetchAll();

  // 部門でフィルタ（staff.departments はカンマ区切りの場合がある。department='' は全員対象）
  $myDepts = array_map('trim', explode(',', (string)$staff['departments']));
  foreach ($all as $row) {
    if ($row['department'] === '' || $row['department'] === '共通' || in_array($row['department'], $myDepts, true)) {
      $items[] = $row;
    }
  }
}

render_header('マイページ', $user, 'mypage.php');
?>
  <div class="container py-4" style="max-width:880px">

    <?php if ($flash): ?>
      <div class="alert alert-success py-2"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="alert alert-danger py-2"><?= h($err) ?></div>
    <?php endif; ?>

    <?php if (!$staff): ?>
      <div class="alert alert-warning">
        あなたのアカウントは講師レコードに紐づいていません（<code>users.staff_id</code> 未設定）。
        管理者にお問い合わせください。
      </div>
    <?php else: ?>

      <!-- プロフィール -->
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <h4 class="mb-1"><?= h($staff['name']) ?></h4>
              <div class="text-muted small">
                <?= h($staff['departments']) ?> / <?= h($staff['school']) ?>
                <?php if (!empty($staff['mentor'])): ?> ・ メンター: <?= h($staff['mentor']) ?><?php endif; ?>
              </div>
            </div>
            <div class="text-end">
              <div class="mb-1">現カラー:
                <span class="badge" style="<?= color_style($staff['color_rank']) ?>"><?= h($staff['color_rank']) ?></span>
              </div>
              <?php if (!empty($staff['target_rank'])): ?>
                <div>目標:
                  <span class="badge" style="<?= color_style($staff['target_rank']) ?>"><?= h($staff['target_rank']) ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($staff['target_date'])): ?>
                <div class="small text-muted">達成期日: <?= h($staff['target_date']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- 育成達成率 -->
      <?php $gs = compute_goal_summary($staff); ?>
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <div class="small text-muted mb-1">目標カラー <?= h($staff['target_rank'] ?: '—') ?> までの育成達成率<?php if (!empty($staff['target_date'])): ?> <span class="ms-1">（達成期日: <?= h($staff['target_date']) ?>）</span><?php endif; ?></div>
          <?= goal_bar_html($gs) ?>
        </div>
      </div>

      <!-- 研修進捗 -->
      <h5 class="mb-2">研修進捗
        <small class="text-muted">（育成目標カラー: <?= h($targetColor) ?>）</small>
      </h5>

      <?php if (!$items): ?>
        <div class="alert alert-light border">対象の研修項目がまだ登録されていません。</div>
      <?php else: ?>
        <div class="card shadow-sm">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>部門</th>
                <th>研修項目</th>
                <th class="text-center">必須</th>
                <th>状態</th>
                <th class="text-end">操作</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <?php $status = $it['status'] ?: '未着手'; ?>
                <tr>
                  <td class="small text-muted"><?= h($it['department'] ?: '共通') ?></td>
                  <td>
                    <?= h($it['item_name']) ?>
                    <?php if (!empty($it['module_key'])): ?>
                      <a href="lessons_view.php?module=<?= rawurlencode($it['module_key']) ?>" target="_blank" class="badge bg-info text-dark text-decoration-none">📺 教材</a>
                    <?php endif; ?>
                    <?php if (($it['type'] ?? '') === 'テスト'): ?><span class="badge bg-light text-dark border">テスト</span><?php endif; ?>
                    <?php if (!empty($it['progress_memo'])): ?>
                      <div class="small text-muted">メモ: <?= h($it['progress_memo']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <?= $it['is_required'] ? '<span class="badge bg-dark">必須</span>' : '<span class="text-muted small">任意</span>' ?>
                  </td>
                  <td><span class="badge <?= status_badge_class($status) ?>"><?= h($status) ?></span></td>
                  <td class="text-end">
                    <?php if (in_array($status, ['未着手', '差戻し', '不合格'], true)): ?>
                      <?php if (($it['type'] ?? '') === 'テスト'): ?>
                        <form method="post" enctype="multipart/form-data" class="d-inline-flex gap-1">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="submit_test">
                          <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                          <input type="file" name="evidence" accept="image/jpeg,image/png" class="form-control form-control-sm" style="width:170px" required>
                          <button class="btn btn-sm btn-primary">証跡提出</button>
                        </form>
                      <?php else: ?>
                        <form method="post" class="d-inline-flex gap-1">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="declare">
                          <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                          <input type="text" name="memo" class="form-control form-control-sm" style="width:140px" placeholder="点数・実施日など">
                          <button class="btn btn-sm btn-primary">申告</button>
                        </form>
                      <?php endif; ?>
                    <?php elseif ($status === '申告中'): ?>
                      <span class="text-muted small">承認待ち</span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <!-- 管理者からの質問 -->
      <?php if ($questions): ?>
        <h5 class="mb-2 mt-4">管理者からの質問</h5>
        <div class="card shadow-sm">
          <div class="card-body">
            <?php foreach ($questions as $q): ?>
              <form method="post" class="mb-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="answer">
                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                <label class="form-label small mb-1 fw-bold"><?= h($q['text']) ?></label>
                <div class="input-group input-group-sm">
                  <textarea name="answer" class="form-control" rows="2"><?= h($q['answer'] ?? '') ?></textarea>
                  <button class="btn btn-outline-primary">保存</button>
                </div>
              </form>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  </div>
<?php render_footer(); ?>
