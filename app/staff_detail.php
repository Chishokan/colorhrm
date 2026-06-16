<?php
// 講師 詳細・編集（admin/staff）：プロフィール編集、カラー昇格、退職/復職、育成達成率。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
require_role(['admin', 'staff']);
$user = current_user();

$id    = (int)($_GET['id'] ?? 0);
$flash = '';
$err   = '';

// 編集可能なプロフィール項目（実在カラムのみ採用）。カラーは昇格処理で別管理。
$editable = ['name', 'departments', 'school', 'employment_type', 'hire_date',
             'target_rank', 'target_date', 'mentor', 'recruiting_media', 'referrer', 'email', 'use_payroll'];
$dateCols = ['hire_date', 'target_date'];
$boolCols = ['use_payroll'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $cols   = staff_columns();

  if ($action === 'save_profile') {
    $set = []; $vals = [];
    foreach ($editable as $f) {
      if (!isset($cols[$f])) continue; // 実在しない列はスキップ
      if (in_array($f, $boolCols, true)) {
        $set[] = "$f = ?"; $vals[] = isset($_POST[$f]) ? 1 : 0;
      } else {
        $v = trim($_POST[$f] ?? '');
        if (in_array($f, $dateCols, true)) { $v = ($v === '') ? null : $v; }
        $set[] = "$f = ?"; $vals[] = $v;
      }
    }
    if ($set) {
      $vals[] = $id;
      db()->prepare("UPDATE staff SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
      $flash = 'プロフィールを保存しました。';
    }
  } elseif ($action === 'update_color') {
    $color = $_POST['color_rank'] ?? '';
    if (!in_array($color, color_ranks(), true)) {
      $err = 'カラーの指定が不正です。';
    } else {
      $sql = "UPDATE staff SET color_rank = ?"
           . (isset($cols['color_certified_date']) ? ", color_certified_date = CURDATE()" : "")
           . " WHERE id = ?";
      db()->prepare($sql)->execute([$color, $id]);
      $flash = "カラーを {$color} に更新しました（認定日: 本日）。";
    }
  } elseif ($action === 'resign') {
    $sql = "UPDATE staff SET is_active = 0"
         . (isset($cols['resign_date']) ? ", resign_date = CURDATE()" : "")
         . " WHERE id = ?";
    db()->prepare($sql)->execute([$id]);
    $flash = '退職として登録しました（在籍一覧から外れます）。';
  } elseif ($action === 'reactivate') {
    $sql = "UPDATE staff SET is_active = 1"
         . (isset($cols['resign_date']) ? ", resign_date = NULL" : "")
         . " WHERE id = ?";
    db()->prepare($sql)->execute([$id]);
    $flash = '復職として登録しました。';
  } elseif ($action === 'add_meeting') {
    $mdate   = trim($_POST['meeting_date'] ?? '') ?: null;
    $content = trim($_POST['content'] ?? '');
    $ndate   = trim($_POST['next_date'] ?? '') ?: null;
    $mentor  = trim($_POST['mentor_name'] ?? '') ?: (current_user()['display_name'] ?? '');
    if ($content === '' && $mdate === null) {
      $err = '面談日または内容を入力してください。';
    } else {
      db()->prepare("INSERT INTO meetings (tenant_id, staff_id, meeting_date, mentor_name, content, next_date) VALUES (1, ?, ?, ?, ?, ?)")
          ->execute([$id, $mdate, $mentor, $content, $ndate]);
      $flash = '面談記録を追加しました。';
    }
  } elseif ($action === 'delete_meeting') {
    db()->prepare("DELETE FROM meetings WHERE id = ? AND staff_id = ?")->execute([(int)($_POST['meeting_id'] ?? 0), $id]);
    $flash = '面談記録を削除しました。';
  }
}

$st = db()->prepare("SELECT * FROM staff WHERE id = ? LIMIT 1");
$st->execute([$id]);
$s = $st->fetch();
if (!$s) {
  render_header('講師詳細', $user, 'index.php');
  echo '<div class="container py-4"><div class="alert alert-danger">講師が見つかりません。 <a href="index.php">講師一覧へ</a></div></div>';
  render_footer();
  exit;
}

$gs   = compute_goal_summary($s);
$cols = staff_columns();
$val  = function ($k) use ($s) { return $s[$k] ?? ''; };

render_header('講師: ' . $s['name'], $user, 'index.php');
?>
  <div class="container py-4" style="max-width:900px">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0"><?= h($s['name']) ?>
        <span class="badge" style="<?= color_style($s['color_rank']) ?>"><?= h($s['color_rank']) ?></span>
        <?php if (!$s['is_active']): ?><span class="badge bg-secondary">退職</span><?php endif; ?>
      </h4>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">← 講師一覧へ</a>
    </div>

    <?php if ($flash): ?><div class="alert alert-success py-2"><?= h($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endif; ?>

    <!-- カラー昇格 ＋ 育成達成率 -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="row g-3 align-items-center">
          <div class="col-md-6">
            <label class="form-label small mb-1">育成進捗（目標: <?= h($s['target_rank'] ?: '—') ?>
              <?php if (!empty($s['target_date'])): ?> / 期限 <?= h($s['target_date']) ?><?php endif; ?>）</label>
            <?= goal_bar_html($gs) ?>
          </div>
          <div class="col-md-6">
            <form method="post" class="d-flex align-items-end gap-2 justify-content-md-end"
                  onsubmit="return confirm('カラーを更新します（認定日=本日）。よろしいですか？');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_color">
              <div>
                <label class="form-label small mb-0">カラー昇格/変更</label>
                <select name="color_rank" class="form-select form-select-sm" style="width:130px">
                  <?php foreach (color_ranks() as $c): ?>
                    <option value="<?= h($c) ?>" <?= $s['color_rank'] === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-sm btn-primary">更新</button>
            </form>
            <?php if (!empty($s['color_certified_date'])): ?>
              <div class="small text-muted mt-1 text-md-end">現カラー認定日: <?= h($s['color_certified_date']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- 給与（時給：カラー×部門） -->
    <?php $rate = compute_class_rate($s); ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div class="small">
          授業時給 <span class="fw-bold">¥<?= number_format($rate['class_rate']) ?></span>
          <span class="text-muted ms-2">運営時給 ¥<?= number_format($rate['ops_rate']) ?></span>
          <span class="text-muted ms-2">（<?= h($s['color_rank']) ?> × <?= h($s['departments'] ?: '—') ?>）</span>
          <?php if (isset($cols['use_payroll'])): ?>
            <span class="badge <?= !empty($s['use_payroll']) ? 'bg-success' : 'bg-secondary' ?> ms-2"><?= !empty($s['use_payroll']) ? '給与対象' : '給与対象外' ?></span>
          <?php endif; ?>
        </div>
        <div>
          <a href="pay_rates.php" class="btn btn-sm btn-outline-secondary">時給表</a>
          <?php if (config_value('payroll_url', '') !== ''): ?>
            <a href="<?= h(config_value('payroll_url')) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success">給与システム ↗</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- プロフィール編集 -->
    <form method="post" class="card shadow-sm mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_profile">
      <div class="card-header">プロフィール</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label small mb-0">氏名</label>
            <input name="name" value="<?= h($val('name')) ?>" class="form-control form-control-sm" required>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">メール（ログインID）</label>
            <input name="email" value="<?= h($val('email')) ?>" class="form-control form-control-sm" <?= isset($cols['email']) ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">部門（複数はカンマ区切り）</label>
            <input name="departments" value="<?= h($val('departments')) ?>" class="form-control form-control-sm" placeholder="例: RED,ネクスタ">
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-0">校舎</label>
            <input name="school" value="<?= h($val('school')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">目標カラー</label>
            <select name="target_rank" class="form-select form-select-sm">
              <option value="">—</option>
              <?php foreach (color_ranks() as $c): ?>
                <option value="<?= h($c) ?>" <?= $val('target_rank') === $c ? 'selected' : '' ?>><?= h($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">目標期限</label>
            <input name="target_date" type="date" value="<?= h($val('target_date')) ?>" class="form-control form-control-sm" <?= isset($cols['target_date']) ? '' : 'disabled' ?>>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">メンター</label>
            <input name="mentor" value="<?= h($val('mentor')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">雇用形態</label>
            <select name="employment_type" class="form-select form-select-sm">
              <option value="">（未設定）</option>
              <?php foreach (candidate_employment_types() as $e): ?>
                <option value="<?= h($e) ?>" <?= $val('employment_type') === $e ? 'selected' : '' ?>><?= h($e) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">入社日</label>
            <input name="hire_date" type="date" value="<?= h($val('hire_date')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">求人媒体</label>
            <input name="recruiting_media" value="<?= h($val('recruiting_media')) ?>" class="form-control form-control-sm">
          </div>
          <div class="col-md-3">
            <label class="form-label small mb-0">紹介者</label>
            <input name="referrer" value="<?= h($val('referrer')) ?>" class="form-control form-control-sm">
          </div>
          <?php if (isset($cols['use_payroll'])): ?>
          <div class="col-md-3 d-flex align-items-end">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="use_payroll" id="up" <?= !empty($s['use_payroll']) ? 'checked' : '' ?>><label class="form-check-label small" for="up">給与システム対象</label></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-footer text-end">
        <button class="btn btn-primary">プロフィールを保存</button>
      </div>
    </form>

    <!-- 関連リンク・退職処理 -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div>
        <a href="training.php?staff_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">研修チェックリストを開く</a>
        <?php if (!empty($s['candidate_id'])): ?>
          <a href="candidate.php?id=<?= (int)$s['candidate_id'] ?>" class="btn btn-sm btn-outline-secondary">応募者情報</a>
        <?php endif; ?>
      </div>
      <div>
        <?php if ($s['is_active']): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('この講師を退職として登録します。よろしいですか？');">
            <?= csrf_field() ?><input type="hidden" name="action" value="resign">
            <button class="btn btn-sm btn-outline-danger">退職にする</button>
          </form>
        <?php else: ?>
          <form method="post" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="reactivate">
            <button class="btn btn-sm btn-outline-success">復職にする</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- 1on1 面談 -->
    <?php
      $meetings = [];
      try {
        $mq = db()->prepare("SELECT * FROM meetings WHERE staff_id = ? ORDER BY meeting_date DESC, id DESC");
        $mq->execute([$id]); $meetings = $mq->fetchAll();
        $hasMeetings = true;
      } catch (Throwable $e) { $hasMeetings = false; } // テーブル未作成（006未適用）でも他機能は動く
    ?>
    <?php if ($hasMeetings): ?>
    <div class="card shadow-sm my-3">
      <div class="card-header">1on1 面談</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_meeting">
          <div class="col-md-2">
            <label class="form-label small mb-0">面談日</label>
            <input name="meeting_date" type="date" class="form-control form-control-sm">
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">担当</label>
            <input name="mentor_name" class="form-control form-control-sm" value="<?= h($user['display_name'] ?: '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label small mb-0">内容</label>
            <input name="content" class="form-control form-control-sm">
          </div>
          <div class="col-md-2">
            <label class="form-label small mb-0">次回</label>
            <input name="next_date" type="date" class="form-control form-control-sm">
          </div>
          <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">記録追加</button></div>
        </form>
        <?php if (!$meetings): ?>
          <div class="text-muted small">まだ面談記録はありません。</div>
        <?php else: ?>
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th style="width:110px">面談日</th><th>内容</th><th style="width:90px">担当</th><th style="width:110px">次回</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($meetings as $m): ?>
                <tr>
                  <td class="small"><?= h($m['meeting_date'] ?: '—') ?></td>
                  <td class="small"><?= nl2br(h($m['content'])) ?></td>
                  <td class="small"><?= h($m['mentor_name']) ?></td>
                  <td class="small"><?= h($m['next_date'] ?: '') ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" onsubmit="return confirm('削除しますか？');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_meeting">
                      <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
                      <button class="btn btn-sm btn-link text-danger p-0">削除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- 質問への回答（参照） -->
    <?php
      $answers = [];
      try {
        $aq = db()->prepare("SELECT q.text, a.answer, a.answered_at
                               FROM answers a JOIN questions q ON q.id = a.question_id
                              WHERE a.staff_id = ? ORDER BY a.answered_at DESC");
        $aq->execute([$id]); $answers = $aq->fetchAll();
        $hasAnswers = true;
      } catch (Throwable $e) { $hasAnswers = false; }
    ?>
    <?php if ($hasAnswers && $answers): ?>
    <div class="card shadow-sm my-3">
      <div class="card-header">質問への回答</div>
      <div class="card-body">
        <?php foreach ($answers as $a): ?>
          <div class="mb-2"><div class="small text-muted"><?= h($a['text']) ?></div><div><?= nl2br(h($a['answer'])) ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
<?php render_footer(); ?>
