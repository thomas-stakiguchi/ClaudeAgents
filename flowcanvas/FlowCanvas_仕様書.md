# FlowCanvas 実装仕様書（Claude Code 引き渡し用）

## 1. ゴール
- ローカルPCで `npm run dev` すると起動し、ブラウザで使える業務フロー兼データフローのエディタ
- 全社展開は対象外。1人（または少人数）がローカルで使う前提
- 同梱の `flowcanvas_mock.html` が見た目と操作の正解。これを再現しつつ、下記の追加要件を満たす

## 2. 技術構成（指定）
| 項目 | 内容 |
|---|---|
| フロント | Vite + React + TypeScript |
| 描画 | SVG（矢印・レーン）＋ HTML（部品）。外部の図形ライブラリは使わない |
| サーバー | Node.js + Express。静的配信と保存APIのみ |
| 保存 | `./data/*.json` にフローを1ファイル1図で保存 |
| 画像出力 | `html-to-image` でPNG/JPG、`jspdf` で画像を貼ったPDF |
| 起動 | `npm install` → `npm run dev` で http://localhost:5173 |

## 3. データ構造
```ts
type Lane = { id: string; kind: 'actor' | 'system'; name: string; desc?: string };
type Node = {
  id: string;
  type: 'start' | 'end' | 'task' | 'decision' | 'data';
  lane: string;      // Lane.id
  x: number;         // レーン内の横位置（px）
  ly: number;        // レーン上端からの縦位置（px）
  label: string;
  note?: string;
};
type Edge = { id: string; from: string; to: string; kind: 'flow' | 'data'; label?: string };
type Flow = { id: string; title: string; lanes: Lane[]; nodes: Node[]; edges: Edge[]; updatedAt: string };
```
- モックの「書き出し → JSON」と互換にする（`id` と `updatedAt` を足すだけ）

## 4. 画面構成
3カラム固定。モック通り。
- 左：Actor一覧／関連システム一覧／部品パレット／操作ヒント
- 中：スイムレーンのキャンバス（パン・ズーム）
- 右：選択中のプロパティ
- 上：ツールバー（図の切替、ズーム、書き出し、保存状態）

## 5. 機能要件

### 5.1 レーン（枠の自動生成）
- Actor と 関連システム をGUIで追加・改名・並び替え・削除
- Actor レーンは上段、システムレーンは下段。間に「DATA LAYER」の点線区切り
- レーンは横向き（時間は左→右）。高さ150px固定
- 部品が残っているレーンの削除は、2回押しで確定（モックと同じ。`confirm()` は使わない）

### 5.2 部品
- 5種類：開始／終了／作業／判断／データ
- パレットをクリックで、選択中レーンに配置（未選択なら最初のレーン。データは最初のシステムレーン）
- ドラッグで移動。別レーンへ移動可。10pxグリッドにスナップ
- ダブルクリックで名前編集（右パネルにフォーカス）
- Delete / Backspace で削除。繋がっている矢印も一緒に消す

### 5.3 接続（クリックで繋ぐ）★
- 部品にホバーすると四辺中央に小さな丸（ポート）を表示
- ポートをクリック → 線が追従 → 相手の部品（どこでも可）をクリックで確定
- Esc でキャンセル。同じ部品同士は繋がない
- 確定直後に右パネルのラベル欄へフォーカス
- 矢印の種類は上部プルダウンの現在値（業務＝実線／データ＝点線）。後から右パネルで変更可
- 補助として「元の部品で C キー → 先の部品をクリック」も残す
- 矢印の経路：左右が主なら左右ポート、上下が主なら上下ポートからのベジェ曲線（モックの `edgePath` と同じ）

### 5.4 プロパティ編集
- 部品：名前、種類、レーン、メモ、削除
- 矢印：種類、ラベル、向き反転、削除
- レーン：名前、区分（Actor⇔システム）、説明、削除
- 未選択時：図のタイトル、集計（Actor数・システム数・部品数・矢印数）

### 5.5 保存・複数図
- 編集は自動保存（500ms デバウンスで `PUT /api/flows/:id`）
- 上部に「図の切替」プルダウン。新規作成・複製・削除
- API：
  - `GET /api/flows` 一覧（id, title, updatedAt）
  - `GET /api/flows/:id`
  - `PUT /api/flows/:id`（本文はFlow JSON）
  - `POST /api/flows`（新規。本文省略時はサンプルを複製）
  - `DELETE /api/flows/:id`
- 初回起動時、`data/` が空ならモックのサンプル（受注〜請求）を1件作る

### 5.6 書き出し ★
- PNG / JPG / PDF をダウンロード
- 対象は図の全体（画面に映っている範囲ではない）。余白20px。レーン見出し込み
- 選択状態の枠線やポートは消して出力
- PDF は横向き A3 相当。図が収まらない場合は縮小
- JSON（互換形式）、Mermaid、Markdown表（モックと同じ3種）も残す。こちらはテキストをコピー＋ファイルダウンロード
- ファイル名：`{タイトル}_{YYYYMMDD}.{拡張子}`

### 5.7 画面操作
- 背景ドラッグでパン、ホイールで上下、Ctrl+ホイールでズーム（25%〜250%）
- 「全体表示」ボタン
- ショートカット：V 選択、C 接続、Delete 削除、Esc キャンセル、Ctrl+Z / Ctrl+Shift+Z 取り消し・やり直し（履歴は50件）

## 6. 非機能
- ライト／ダーク両対応（OS設定に追従）。モックのCSS変数をそのまま使う
- フォント：Zen Kaku Gothic New（本文）、IBM Plex Mono（データ部品・数値）。Google Fonts が使えない環境は system-ui にフォールバック
- 1,000部品・2,000矢印で操作がもたつかないこと（描画は差分更新。毎回 innerHTML を作り直さない）
- 認証なし。localhost のみで待ち受け

## 7. やらないこと
- 複数人の同時編集、クラウド保存、ログイン
- 自動レイアウト
- Salesforce等の外部システム連携

## 8. ディレクトリ構成（目安）
```
flowcanvas/
  package.json          # dev: concurrently で vite と server を同時起動
  server/index.ts       # Express。/api と dist の静的配信
  data/                 # 保存先（.gitignore）
  src/
    model/types.ts
    model/sample.ts     # モックのサンプルを移植
    store/flowStore.ts  # 状態・履歴（undo/redo）・自動保存
    components/
      Toolbar.tsx  SidebarLeft.tsx  Canvas.tsx  Lane.tsx  NodeView.tsx  EdgeLayer.tsx  SidebarRight.tsx
    export/image.ts     # html-to-image → png/jpg/pdf
    export/text.ts      # json / mermaid / markdown
    styles/tokens.css   # モックの :root をそのまま
  mock/flowcanvas_mock.html   # 参照用（ビルド対象外）
```

## 9. 受け入れ確認
1. `npm run dev` 一発で起動し、サンプルの図が表示される
2. Actorを追加するとレーンが増え、システムを追加すると下段に増える
3. 部品のポートをクリック→別の部品をクリックで矢印ができ、ラベルが入力できる
4. ブラウザを閉じて開き直しても図が残っている
5. PNG / JPG / PDF をダウンロードでき、図全体が収まっている
6. ダークモードで文字が読める
7. Ctrl+Z で直前の操作が戻る

## 10. Claude Code への最初の指示（コピー用）
```
mock/flowcanvas_mock.html と FlowCanvas_仕様書.md を読んで、仕様書の構成どおりに実装してください。
まず package.json・server・src の骨組みを作って npm run dev で空のキャンバスが出るところまで進め、
次にレーン→部品→接続→保存→書き出しの順に機能を足してください。
各機能が動いたら、仕様書「9. 受け入れ確認」の該当項目を自分で確認してから次に進んでください。
```
