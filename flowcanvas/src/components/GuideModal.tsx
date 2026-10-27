import { useState } from 'react';
import { Modal } from './Modal';

/* 操作ガイド。画面に出していた説明はすべてここにまとめる */

type Tab = { id: string; name: string; body: React.ReactNode };

const K = ({ children }: { children: React.ReactNode }) => <kbd>{children}</kbd>;

const TABS: Tab[] = [
  {
    id: 'start',
    name: 'はじめに',
    body: (
      <>
        <p className="lead">業務の流れ（実線）とデータの流れ（点線）を、1枚の図に描くための道具です。3つの手順で描きます。</p>
        <ol className="steps">
          <li>
            <b>枠を作る</b>
            <span>左の「1 枠を作る」で、作業をする人や部署（担当者）と、関係するシステムを追加します。担当者は上、システムは下に並びます。</span>
          </li>
          <li>
            <b>部品を置く</b>
            <span>「2 部品を置く」の部品を、キャンバスの置きたい所へドラッグします。クリックすると、選んでいる枠の右端に置かれます。</span>
          </li>
          <li>
            <b>線でつなぐ</b>
            <span>部品にマウスを乗せると出る丸をクリックし、相手の部品をクリックします。「文章でつなぐ」に書いてもつなげます。</span>
          </li>
        </ol>
        <p>図は自動で保存されます（上の「保存」や <K>Ctrl</K>+<K>S</K> でもすぐ保存できます）。</p>
      </>
    ),
  },
  {
    id: 'parts',
    name: '部品と枠',
    body: (
      <dl className="guide-list">
        <dt>名前を変える</dt>
        <dd>部品をダブルクリックして、そのまま打ちます。<K>Enter</K> で確定、<K>Shift</K>+<K>Enter</K> で改行、<K>Esc</K> で取り消し。部品を選んで <K>Enter</K> でも書けます。</dd>
        <dt>動かす</dt>
        <dd>ドラッグします。別の枠へも動かせます。10px ずつ吸い付きます。</dd>
        <dt>まとめて選ぶ</dt>
        <dd><K>Shift</K>+クリックで足す、<K>Shift</K>+背景をドラッグで囲む、<K>Ctrl</K>+<K>A</K> で全部。選んだ部品はまとめて動かせます。</dd>
        <dt>コピー</dt>
        <dd><K>Ctrl</K>+<K>C</K> → <K>Ctrl</K>+<K>V</K>。別の図にも貼れます（同じ名前の枠に入ります）。</dd>
        <dt>そろえる</dt>
        <dd>2つ以上選ぶと、右のパネルに「左端・中心・右端・枠の上下中央・横に等間隔」が出ます。</dd>
        <dt>消す</dt>
        <dd>選んで <K>Delete</K>。つながっている線も一緒に消えます。<K>Ctrl</K>+<K>Z</K> で戻せます。</dd>
        <dt>長い名前</dt>
        <dd>作業・判断・データ・付せんは、名前に合わせて広がります。</dd>
        <dt>枠の高さ</dt>
        <dd>枠の名前にマウスを乗せて「広く／狭く」、または枠の下の境目をドラッグします。</dd>
        <dt>枠の並べ替え</dt>
        <dd>左の一覧で ↑ ↓。枠や図を消すときは「本当に削除しますか？」と聞かれるので「はい」を押します。</dd>
      </dl>
    ),
  },
  {
    id: 'lines',
    name: '線',
    body: (
      <dl className="guide-list">
        <dt>つなぐ</dt>
        <dd>部品にマウスを乗せると出る丸をクリック → 相手の部品をクリック。丸からドラッグして相手の上で離してもつながります。「接続」を押してから 元 → 先 の順にクリックしても同じです。</dd>
        <dt>種類</dt>
        <dd>「3 線でつなぐ」で、これから引く線を「業務の流れ（実線）」か「データの流れ（点線）」、「曲線」か「直角」から選びます。引いた後は右のパネルで変えられます。</dd>
        <dt>色</dt>
        <dd>線をクリックして、右のパネルの「線の色」から選びます。</dd>
        <dt>重なりを直す</dt>
        <dd>同じ辺の線は自動でずれ、途中の部品は自動で回り込みます。それでも重なるときは、右のパネルの「出る辺・入る辺」を変えます。</dd>
        <dt>ラベル・向き</dt>
        <dd>線を選んで右のパネルで、ラベル（条件や受け渡す内容）を書く、向きを反転する。</dd>
      </dl>
    ),
  },
  {
    id: 'nl',
    name: '文章でつなぐ',
    body: (
      <>
        <p className="lead">左の「文章でつなぐ」に書いて <K>Enter</K>。決まった言葉を探して読み取ります（AI は使っていません）。</p>
        <table className="guide-table">
          <tbody>
            <tr><th>書いた文</th><th>結果</th></tr>
            <tr><td>作業とデータを点線で繋ぐ。線は赤。</td><td>作業 → データ に赤い点線</td></tr>
            <tr><td>売上仕訳から受注へ 青</td><td>「から」「へ」で向きを決める</td></tr>
            <tr><td>見積を作成すると商談を直角で。ラベルは「金額を登録」</td><td>直角の線にラベル</td></tr>
            <tr><td>商談と受注を点線で繋いでラベルに自動フローと書く</td><td>点線にラベル「自動フロー」</td></tr>
            <tr><td>赤にする（線を選んだ状態で）</td><td>選んだ線の色だけ変える</td></tr>
          </tbody>
        </table>
        <ul className="guide-notes">
          <li>部品の名前を2つ書きます。その種類の部品が1つだけなら「作業」「データ」「メール」なども使えます。</li>
          <li>線：点線／実線、形：直角／曲線、色：赤・青・緑・黄・オレンジ・紫・黒・灰色・水色・紺・ピンク・茶、または #FF0000</li>
          <li>ラベルの書き方：「ラベルは〇〇」「ラベルに〇〇と書く」「ラベルを〇〇にする」「〇〇」（かぎかっこで囲む）</li>
          <li>すでにつながっている2つを書くと、新しく作らずにその線を変えます。</li>
        </ul>
      </>
    ),
  },
  {
    id: 'io',
    name: '読み込み・書き出し',
    body: (
      <dl className="guide-list">
        <dt>Excel から図を作る</dt>
        <dd>上の「読み込み」→「Excel・CSV から作る」。1行に1つの作業を「誰が・何をする・誰（何）に対して・受け渡すもの・次のNo・条件」で書いた表から、新しい図を作ります。形はひな形（同じ画面からダウンロード）の「書き方」シートを見てください。</dd>
        <dt>JSON</dt>
        <dd>「書き出し」→「テキスト」で JSON を保存し、「読み込み」→「JSON」で戻せます。図の控えや受け渡しに使います。</dd>
        <dt>画像・PDF</dt>
        <dd>「書き出し」から PNG・JPG・PDF。タイトル・出力日・凡例を入れる、白い背景で出す、はメニューの下で切り替えます。PDF は大きな図だと複数ページに分かれます。</dd>
        <dt>Mermaid・Markdown</dt>
        <dd>「書き出し」→「テキスト」。Notion・GitHub・Backlog や仕様書に貼れます。</dd>
        <dt>Claude Code から</dt>
        <dd>ClaudeAgents フォルダで claude を起動し「この Excel を業務フローにして」と頼むと、形の違う Excel や議事録も表に整えて取り込みます。</dd>
      </dl>
    ),
  },
  {
    id: 'keys',
    name: 'キーボード',
    body: (
      <table className="guide-table keys">
        <tbody>
          <tr><th>キー</th><th>すること</th></tr>
          <tr><td><K>Ctrl</K>+<K>S</K></td><td>保存</td></tr>
          <tr><td><K>Ctrl</K>+<K>Z</K> ／ <K>Ctrl</K>+<K>Shift</K>+<K>Z</K></td><td>取り消し ／ やり直し</td></tr>
          <tr><td><K>Ctrl</K>+<K>C</K> ／ <K>Ctrl</K>+<K>V</K></td><td>部品のコピー ／ 貼り付け</td></tr>
          <tr><td><K>Ctrl</K>+<K>A</K></td><td>部品を全部選ぶ</td></tr>
          <tr><td><K>Delete</K></td><td>選んでいるものを消す</td></tr>
          <tr><td><K>Enter</K> ／ <K>F2</K></td><td>選んだ部品の名前を書く</td></tr>
          <tr><td><K>C</K></td><td>接続を始める（部品を選んでいればそこから）</td></tr>
          <tr><td><K>V</K></td><td>選択・移動に戻る</td></tr>
          <tr><td><K>Esc</K></td><td>接続・入力をやめる／選択を外す</td></tr>
          <tr><td>ホイール ／ <K>Ctrl</K>+ホイール</td><td>上下に動かす ／ 拡大・縮小</td></tr>
          <tr><td>背景をドラッグ</td><td>画面を動かす</td></tr>
        </tbody>
      </table>
    ),
  },
];

export function GuideModal({ onClose, start = 'start' }: { onClose: () => void; start?: string }) {
  const [tab, setTab] = useState(start);
  const cur = TABS.find((t) => t.id === tab) ?? TABS[0];
  return (
    <Modal title="操作ガイド" onClose={onClose} wide>
      <div className="tabs" role="tablist" aria-label="操作ガイドの項目">
        {TABS.map((t) => (
          <button key={t.id} role="tab" aria-selected={t.id === cur.id} className={`tab${t.id === cur.id ? ' on' : ''}`} onClick={() => setTab(t.id)}>
            {t.name}
          </button>
        ))}
      </div>
      <div className="guide-body" role="tabpanel" aria-label={cur.name}>
        {cur.body}
      </div>
    </Modal>
  );
}
