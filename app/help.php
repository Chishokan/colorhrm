<?php
// ヘルプ・使い方（ColorHRM）。ログイン中のロールに合わせて表示。
require __DIR__ . '/auth.php';
require __DIR__ . '/helpers.php';
require_login();
$user = current_user();
$role = $user['role'] ?? '';
$payroll = config_value('payroll_url', '/colorhrm-pay/');

render_header('ヘルプ・使い方', $user, 'help.php');
?>
  <div class="container py-4" style="max-width:900px">
    <h3 class="mb-1">ヘルプ・使い方</h3>
    <p class="text-muted">ColorHRM（採用・育成）の使い方です。給与・打刻・シフトの操作は
      <a href="<?= h($payroll) ?>help.php">「給与・シフト」アプリのヘルプ</a>をご覧ください。</p>

    <div class="alert alert-light border small">
      困ったときは管理者にお問い合わせください。この画面はいつでもメニューの「ヘルプ・使い方」から開けます。
    </div>

<?php if ($role === 'teacher'): ?>

    <!-- ============ 講師向け ============ -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">① マイページとは</div>
      <div class="card-body">
        <p class="mb-2">ログインすると最初に表示される自分専用の画面です。次のことができます。</p>
        <ul class="mb-0">
          <li>自分の<strong>現カラー／育成目標カラー</strong>と達成率の確認</li>
          <li><strong>研修進捗</strong>の確認と、テスト結果・OJT写真の提出、研修の「投稿済み」報告</li>
          <li><strong>顔写真</strong>の登録</li>
          <li>管理者からの<strong>質問への回答</strong></li>
        </ul>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">② 研修進捗の進め方</div>
      <div class="card-body">
        <p class="mb-2">研修項目には種別のタグが付いています。種別ごとに操作が違います。</p>
        <ul class="mb-3">
          <li><span class="badge bg-light text-dark border">テスト</span>／<span class="badge bg-light text-dark border">OJT</span>
            … テスト結果・OJT記録の<strong>写真</strong>を選んで
            <span class="badge bg-primary">写真提出</span>（OJTは<span class="badge bg-primary">OJT写真提出</span>）を押します。JPG/PNG・上限8MB。</li>
          <li><span class="badge bg-light text-dark border">研修</span>
            … LINE WORKS にリフレクションを投稿したら
            <span class="badge bg-primary">投稿済み</span>を押します（写真は不要）。</li>
        </ul>
        <p class="mb-1 fw-semibold">ステータスの流れ</p>
        <p class="mb-2">
          <span class="badge bg-secondary">未着手</span> →（提出/報告）→
          <span class="badge bg-warning text-dark">申告中</span>（＝承認待ち）→ 担当者が承認 →
          <span class="badge bg-success">合格</span>／<span class="badge bg-success">受講済</span>
        </p>
        <p class="mb-0 small text-muted">
          <span class="badge bg-danger">差戻し</span>・<span class="badge bg-danger">不合格</span>になった場合は、
          内容を直してもう一度提出（報告）してください。<span class="badge bg-dark">必須</span>の項目は昇格に必要です。
          <span class="badge bg-info text-dark">📺 教材</span>がある項目は、押すと動画・資料を確認できます。
        </p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">③ 顔写真の登録</div>
      <div class="card-body">
        マイページ左上の写真欄からファイルを選び「写真を登録」を押します（JPG/PNG・上限8MB）。
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">④ 管理者からの質問</div>
      <div class="card-body">
        質問が登録されると、マイページ下部に表示されます。回答を入力して「保存」を押してください。後から修正もできます。
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">⑤ 打刻・シフト・給与明細</div>
      <div class="card-body">
        出退勤の打刻、シフト可能登録、給与明細は
        <a href="<?= h($payroll) ?>" class="fw-semibold">「給与・シフト」アプリ</a>で行います。
        操作方法は<a href="<?= h($payroll) ?>help.php">そちらのヘルプ</a>をご覧ください。
      </div>
    </div>

<?php else: ?>

    <!-- ============ スタッフ向け ============ -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">① ダッシュボード</div>
      <div class="card-body">
        <ul class="mb-0">
          <li><strong>研修 承認待ち（教室別）</strong>… 講師から提出された申告を教室ごとに一覧。
            「承認へ」を押すとその講師の研修管理に移動して承認できます。</li>
          <li><strong>育成サマリー</strong>… 在籍講師のカラー別人数、目標期限超過や達成率の低い<strong>要注意リスト</strong>、教室×カラーの分布を確認できます。</li>
        </ul>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">② 講師一覧・講師の追加</div>
      <div class="card-body">
        <p class="mb-2">「講師一覧」で在籍講師を一覧できます。新しく講師を登録するには
          <span class="badge bg-success">＋ 講師を追加</span> から氏名などを入力し、続けて詳細画面で設定します。</p>
        <p class="mb-0 small text-muted">講師名（または「研修進捗」）を押すと、その講師の詳細・研修チェックリストへ移動します。</p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">③ 講師詳細でできること</div>
      <div class="card-body">
        <ul class="mb-0">
          <li><strong>プロフィール編集</strong>（氏名・メール＝ログインID・部門・校舎・メンター 等）→「プロフィールを保存」</li>
          <li><strong>配属教室</strong>の設定 … <span class="text-danger">打刻に必須</span>です。複数選択できます。</li>
          <li><strong>カラー昇格</strong> … カラーを選んで「更新」</li>
          <li><strong>退職／復職</strong>の切り替え</li>
          <li><strong>1on1 面談</strong>の記録追加</li>
        </ul>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">④ 研修管理（承認の手順）</div>
      <div class="card-body">
        <p class="mb-2">メニューの「研修管理」を開くと、<strong>承認待ち</strong>の申告が一覧されます。</p>
        <ol class="mb-2">
          <li>講師・研修項目・種別（研修／テスト／OJT）・メモ・写真を確認します（<span class="badge bg-secondary">写真</span>を押すと提出画像を表示）。</li>
          <li>結果（合格／受講済／不合格）を選び <span class="badge bg-success">承認</span> を押します。内容に不備があれば <span class="badge bg-outline-danger border text-danger">差戻し</span>。</li>
        </ol>
        <p class="mb-0 small text-muted">講師名を押すと進捗グリッドが開き、各項目のステータスを直接「更新」することもできます。</p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">⑤ 担当教室について</div>
      <div class="card-body">
        スタッフ権限では、<strong>自分の担当教室に配属された講師</strong>が表示・承認の対象になります。
        担当教室の設定は管理者が行います。表示されない講師がいる場合は、その講師の<strong>配属教室</strong>と
        あなたの<strong>担当教室</strong>が一致しているか管理者にご確認ください。
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">⑥ 打刻・シフト・給与</div>
      <div class="card-body">
        シフトの確定や給与計算は
        <a href="<?= h($payroll) ?>" class="fw-semibold">「給与・シフト」アプリ</a>で行います。
        操作方法は<a href="<?= h($payroll) ?>help.php">そちらのヘルプ</a>をご覧ください。
      </div>
    </div>

    <div class="card shadow-sm mb-3 border-info">
      <div class="card-header fw-bold bg-info-subtle">参考：講師のマイページ</div>
      <div class="card-body small">
        講師はログインすると<strong>マイページ</strong>が開き、研修項目を提出・報告します。
        <span class="badge bg-light text-dark border">テスト</span>・<span class="badge bg-light text-dark border">OJT</span>は写真提出、
        <span class="badge bg-light text-dark border">研修</span>はLINE WORKS投稿後に「投稿済み」を押す運用です。
        提出されると <span class="badge bg-warning text-dark">申告中</span> となり、ダッシュボード／研修管理の承認待ちに表示されます。
      </div>
    </div>

<?php endif; ?>

  </div>
<?php render_footer(); ?>
