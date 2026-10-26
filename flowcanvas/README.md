# FlowCanvas

業務フローとデータフローを1枚に描けるエディタです。ローカルPCで動かします。

## 起動のしかた

Node.js 18 以上が必要です。

```bash
cd flowcanvas
npm install
npm run dev
```

ブラウザで http://localhost:5173 を開きます。初回はサンプル（受注〜請求）が1件入っています。

- 図は `flowcanvas/data/` に1図1ファイル（JSON）で自動保存されます
- 止めるときはターミナルで Ctrl+C

ビルドして1プロセスで動かす場合：

```bash
npm run build
npm start   # http://localhost:3001
```

## 使い方

| やりたいこと | 操作 |
|---|---|
| 担当・システムの枠を増やす | 左の「＋ 追加」 |
| 部品を置く | 左の「部品を置く」をクリック（選択中のレーンに置かれる） |
| 矢印でつなぐ | 部品にマウスを乗せて出る丸をクリック → 相手の部品をクリック |
| 名前を変える | 部品をダブルクリック → 右のパネルで入力 |
| 消す | 選んで Delete |
| 取り消し / やり直し | Ctrl+Z / Ctrl+Shift+Z |
| 画像にする | 上の PNG / JPG / PDF |
| 文章にする | 「テキスト書き出し」（JSON / Mermaid / Markdown表） |
| 明るさを変える | 右上の「表示」で 自動 / ライト / ダーク（自動はPCの設定に合わせる） |

## 構成

| 場所 | 中身 |
|---|---|
| `server/index.ts` | Express。保存API（`/api/flows`）と、ビルド後の画面の配信 |
| `src/model/` | データの型、サンプル、座標計算 |
| `src/store/` | 画面の状態、取り消し履歴（50件）、自動保存 |
| `src/components/` | ツールバー、左右のパネル、キャンバス |
| `src/export/` | 画像（PNG/JPG/PDF）とテキストの書き出し |
| `mock/flowcanvas_mock.html` | 元になったモック（参照用） |
| `FlowCanvas_仕様書.md` | 仕様書 |
