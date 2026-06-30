<?php
// ヘルプ・使い方（給与・シフト）。ログイン中のロールに合わせて表示。
require __DIR__ . '/auth.php';
require __DIR__ . '/lib.php';
require_login();
$user = current_user();
$role = $user['role'] ?? '';
$colorhrm = config_value('colorhrm_url', '/colorhrm/');

render_header('ヘルプ・使い方', $user, 'help.php');
?>
  <div class="container py-4" style="max-width:900px">
    <h3 class="mb-1">ヘルプ・使い方</h3>
    <p class="text-muted">給与・シフト・打刻の使い方です。研修・育成の操作は
      <a href="<?= h($colorhrm) ?>help.php">ColorHRM のヘルプ</a>をご覧ください。</p>

    <div class="alert alert-light border small">
      時刻はすべて<strong>日本時間</strong>です。困ったときは管理者にお問い合わせください。
      この画面はいつでもメニューの「ヘルプ・使い方」から開けます。
    </div>

<?php if ($role === 'teacher'): ?>

    <!-- ============ 講師向け ============ -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">① 打刻（出勤・退勤）</div>
      <div class="card-body">
        <p class="mb-2">メニューの黄色い<span class="badge bg-warning text-dark">打刻</span>ボタンを開きます。</p>
        <ol class="mb-3">
          <li>「出勤」欄で<strong>出勤教室</strong>を選び <span class="badge bg-success">出勤打刻</span> を押します。</li>
          <li>退勤時は「退勤」欄で<strong>退勤教室</strong>を選び <span class="badge bg-danger">退勤打刻</span> を押します。</li>
        </ol>
        <p class="mb-2"><strong>シフトがない日でも打刻できます。</strong>その場合は「シフトなしで打刻しました」と表示され、
          一覧の判定は <span class="badge bg-secondary">シフトなし</span> になります。</p>
        <p class="mb-1 fw-semibold">判定の意味（当月の打刻状況）</p>
        <p class="mb-2">
          <span class="badge bg-success">OK</span> 問題なし／
          <span class="badge bg-danger">遅刻</span> シフト開始より遅い出勤／
          <span class="badge bg-danger">早退</span> シフト終了より早い退勤／
          <span class="badge bg-dark">欠勤</span> 打刻なし／
          <span class="badge bg-secondary">シフトなし</span> その日のシフトが無い
        </p>
        <p class="mb-0 small text-muted">
          ※ <strong>配属教室</strong>が未設定だと打刻できません。表示されない場合は管理者に配属教室の設定を依頼してください。
        </p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">② シフト可能登録</div>
      <div class="card-body">
        <p class="mb-2">メニューの<span class="badge bg-light text-dark border">シフト可能登録</span>から、勤務できる日に時間を入力して申請します。</p>
        <ul class="mb-2">
          <li><strong>入れる日だけ</strong>開始・終了の時間を入力します（空欄の日は申請しません）。</li>
          <li>「↑ 上をコピー」で前の行の時間をコピーできます。</li>
          <li><strong>マイ シフトテンプレート</strong>：よく使う時間帯を登録しておくと、各日の行の<strong>「テンプレ」から選んで入力</strong>できます（直接入力も可）。</li>
          <li>入力したら<span class="badge bg-primary">保存する</span>を押します。</li>
          <li>当月〜<strong>6か月先</strong>まで登録できます。</li>
        </ul>
        <p class="mb-0 small text-muted">
          スタッフが内容を<span class="badge bg-success">確定</span>すると、その行は編集できなくなります。
          確定したシフトは画面下部の一覧で確認できます。
        </p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">③ 給与明細</div>
      <div class="card-body">
        メニューの<span class="badge bg-light text-dark border">給与明細</span>から、発行済みの明細を確認できます。
        <span class="badge bg-primary">PDFを開く</span>でPDFを表示・保存できます。
        金額は<strong>発行時点の確定シフト</strong>に基づきます。
      </div>
    </div>

<?php else: ?>

    <!-- ============ スタッフ向け ============ -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">はじめに：メニュー構成</div>
      <div class="card-body small mb-0">
        左サイドメニューは <strong>ダッシュボード</strong>／<strong>シフト</strong>（シフト表・シフト申請・確定・打刻・確定シフト）／
        <strong>給与</strong>（給与計算）に分かれています。スマホでは左上の三本線から開けます。
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">① ダッシュボード</div>
      <div class="card-body">
        在籍講師数・給与計算対象数・<strong>当月の確定シフト</strong>（前月/翌月へ移動可）・講師別の時給を確認できます。
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">② シフト表</div>
      <div class="card-body">
        教室を選ぶと、その教室の講師×日付で
        <span class="badge bg-warning text-dark">申請中</span>と<span class="badge bg-success">確定</span>を一覧できます。
        確定したシフトは、確定した教室の列に表示されます。<strong>確定シフトには打刻時刻と判定</strong>
        （<span class="badge bg-success">OK</span>／<span class="badge bg-danger">遅刻</span>/<span class="badge bg-danger">早退</span>／<span class="badge bg-dark">欠勤</span>）も表示されます。
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">③ シフト申請・確定（確定の手順）</div>
      <div class="card-body">
        <p class="mb-2">講師の申請を確認して確定します。</p>
        <ol class="mb-2">
          <li><strong>申請中（受付待ち）</strong>の各行で、<strong>確定する時間</strong>・<strong>教室</strong>・<strong>授業（分）</strong>を入力し
            <span class="badge bg-success">確定</span> を押します（不要なら <span class="badge bg-secondary">却下</span>）。
            申請（可能時間）が <code>18:00〜21:30</code> でも、<code>19:00〜20:00</code> のように時間を変えて確定できます。</li>
          <li>その月の申請をまとめて確定する場合は <span class="badge bg-success">この月をまとめて確定</span>。</li>
          <li><strong>講師で絞り込み／日付で絞り込み</strong>で、1名分の確認や、ある日に申請がある講師の確認ができます。</li>
          <li><strong>確定用 時間テンプレート</strong>：よく使う確定時間を登録すると、各行の「テンプレ」から選んで時間を入力できます（直接入力も可）。</li>
        </ol>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">④ 打刻・確定シフト（確認・修正）</div>
      <div class="card-body">
        <p class="mb-2">確定済みシフトの一覧と<strong>打刻状況（出勤・退勤・判定）</strong>を確認し、必要に応じて修正します。</p>
        <ol class="mb-2">
          <li><strong>確定シフト</strong>の一覧で、教室・時間・授業分の修正（「保存」）や削除ができます。</li>
          <li>申請がないシフトは「確定シフトを直接追加」から登録できます。</li>
          <li>講師ごと・日付で絞り込めます。</li>
        </ol>
        <p class="mb-0 small text-muted">
          講師は1日に複数の教室で勤務することがあります。日ごとに<strong>確定した教室</strong>が、その教室のシフト表・講師のマイページに反映されます。
        </p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">⑤ 給与計算・明細発行</div>
      <div class="card-body">
        <p class="mb-2">「給与計算」で月を選ぶと、確定シフトと時給表から自動計算されます。</p>
        <ul class="mb-2">
          <li><strong>立替金</strong>は講師ごとに金額を入力し「保存」すると、合計に加算され明細にも反映されます（発行後に変更した場合は「再発行」で更新）。</li>
          <li><span class="badge bg-primary">発行</span>（または「全員に発行」）で給与明細を発行します。</li>
          <li><span class="badge bg-secondary">PDF</span>で明細PDFを確認、<span class="badge bg-primary">メール送信</span>で講師に送付できます（PDF出力とメール送信は分かれています）。</li>
          <li><span class="badge bg-outline-secondary border">CSVダウンロード</span>／<span class="badge bg-outline-secondary border">コピー</span>で表計算ソフトに貼り付けできます。</li>
        </ul>
        <p class="mb-1 fw-semibold">計算の考え方</p>
        <p class="mb-0 small text-muted">
          授業給与＝授業分÷60×授業時給、運営給与＝運営分÷60×運営時給。
          <strong>授業時給は勤務日ごとのColor（適用日）で判定</strong>し、明細にカラー別の内訳を表示します。
          <strong>交通費</strong>は講師の区分で算定（徒歩/定期＝0、公共交通＝1日額×対象日数で月8日以下は半額・9日以上は全額、車・バイク＝≤5日:日数×200・超過:切上げ(日数/5)×1000）。送迎等で交通費なしの日は「打刻・確定シフト」の<strong>送迎</strong>チェックで日別に除外できます。
          <strong>拘束時間が6時間を超える場合は45分の休憩を運営時間から自動控除</strong>します。
          金額のもとになる勤務時間は<strong>確定シフトの時間</strong>です（打刻の時刻は遅刻・早退・欠勤の判定にのみ使い、給与額には反映しません）。
        </p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">⑥ 退勤チェック／報告（日報）</div>
      <div class="card-body">
        <p class="mb-2"><strong>退勤チェック</strong>（点検）：「退勤チェック」で教室ごとの点検項目（1行＝1項目・エアコン/トイレ等）を編集できます（staffは担当教室のみ）。講師は<strong>退勤前に退勤教室の項目を全てチェック</strong>すると退勤打刻が押せます。記録は同画面で確認できます。</p>
        <p class="mb-0 small text-muted"><strong>報告（日報）</strong>：講師は打刻画面の「報告（日報）」から1日の業務報告（新規体験生・イレギュラー・保護者共有・休憩・シフト超過・業務内容 等）を提出します。admin/staffは<strong>「報告一覧」</strong>で確認できます。</p>
      </div>
    </div>

    <div class="card shadow-sm mb-3">
      <div class="card-header fw-bold">⑦ 担当教室について</div>
      <div class="card-body">
        スタッフ権限では、<strong>自分の担当教室に配属された講師</strong>がシフト表・シフト申請・確定・打刻・確定シフト・給与計算の対象になります。
        担当教室の設定は管理者が行います。
      </div>
    </div>

    <div class="card shadow-sm mb-3 border-info">
      <div class="card-header fw-bold bg-info-subtle">参考：講師の画面</div>
      <div class="card-body small mb-0">
        講師は<strong>打刻</strong>（出勤/退勤教室を選ぶ。シフトなしでも打刻可。<strong>退勤前に退勤チェックの項目を全てチェックすると退勤打刻が押せます</strong>）、<strong>報告（日報）</strong>（1日の業務報告を提出）、<strong>シフト可能登録</strong>（入れる日だけ時間入力）、
        <strong>給与明細</strong>（PDF閲覧）を使います。打刻には配属教室の設定が必要です。
        講師のホーム「当月の確定シフト」には<strong>自分の出勤・退勤・判定</strong>が表示され、打刻状況を本人が確認できます。
      </div>
    </div>

<?php endif; ?>

  </div>
<?php render_footer(); ?>
