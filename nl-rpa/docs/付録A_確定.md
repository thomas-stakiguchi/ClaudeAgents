# 付録A 実行計画の形式（1a確定版）

仕様書の付録Aの骨子を、1aの実装用に確定したもの。検査は `src/Plan/PlanValidator.php`。

## 読み込み
- JSONは `Src\Plan\StrictJson` で読む（重複キー、2^53超の整数、不正なUTF-8を拒否）
- 知らない項目は、すべて拒否する

## 全体
| 項目 | 内容 |
|---|---|
| plan_id | `PLN-数字` |
| version | 1以上の整数 |
| plan_hash | 省略可。**信用しない**。実行エンジンが計算する値で上書きする |
| scenario_id | `SCN-数字` |
| created_by_ai | `{model, prompt_version}`（文字列） |
| allowlist_versions | `{operations, destinations}`（1以上の整数） |
| screen_record_version | 1以上の整数 |
| input.columns | 列定義の配列。**業務キー列（role=business_key）がちょうど1つ**（R8） |
| limits | `max_rows_per_run`（1以上）。`max_quantity_per_run` は任意。取り消せない手順があれば、`max_rows_per_run` は必須（R9） |
| unresolved | 「未確定」の一覧（文字列の配列、省略可）。1つでもあれば、実行できない（9-2） |
| steps | 手順の配列（1つ以上） |

## 列定義
`name`（重複不可）、`type`（text／number／date）、`required`（真偽）、`max_bytes`（任意）、`format`（任意）、`role`（任意。business_key／quantity／amount／personal_info）

## 手順
共通：`id`（`S-数字`。計画の中で重複不可）、`action`、`source`（シナリオの文。なければ null＝「AIが補った」）、`label`（表示用、任意）

- 操作（A-2）：`destination`（`{kind, name}`。**必須**。1aの kind は browser のみ、name は英数字・`_`・`.`・`-`）。open_url、read_text、type_text、clear_text、click、press_key、login、confirm_dialog、wait_for、write_result
  - `target`：画面の記録の要素名（open_url、read_text、type_text、clear_text、click、login で必須）
  - `value`（固定値）か `column`（入力データの列名）：type_text と write_result で、どちらか一方だけ。`column` は、列定義にある名前
  - `verify`：確認条件（**必須**）。`type` は text_visible／text_equals／element_exists／url_matches／value_matches。`value_matches` は `rule`（照合ルール）が必須
  - `timeout_sec`：wait_for で必須（上限は300）
  - `readback`：読み返し照合の項目の配列（任意。ただし、取り消せない手順に指定がなければ、12-1の関数が拒否する）。各項目は `target`（読み返す要素名）と、`column`（入力データの列）か `value`（固定値）のどちらか一方、`rule`（任意）
  - `reversible`：**信用しない**。AIが書いていても無視し、取り消し可否は `OperationPolicy` が計算する（R4）
- 構文（A-3）
  - `for_each_row`：`steps` を持つ。入れ子にできない
  - `if`：`condition`（確認条件）、`then`（手順の配列）、`else`（任意）
  - `on_dialog`：`dialog_text`、`response`（ok／stop）

## 照合ルール（rule）
`width`（full／half）、`date_format`（文字列）、`thousand_separator`（真偽）、`trim`（真偽）、`max_length`（整数）。すべて任意。

## AI出力の門（`AiPlanGate`）の決め事
- AIが書いた `plan_hash`、`reversible` は捨てる
- 必須の項目は、`null` を認めない（`source` だけ null 可。null は「AIが補った」の意味）
- 文字列は、制御文字（改行・タブを含む）、NUL、向きを変える文字（U+202Eなど）を拒否する
- 入力データの列（`column`）は、for_each_row の中でだけ使える
- 件数の上限は、読み取り・待ち・結果の記録以外が1つでもあれば必須（取り消し判定より広く取る）
- OKする確認ダイアログ（on_dialog の response=ok）は、取り消せない。同じ並びの click も、取り消せない扱い（7-1）
- 呼び出し側が渡せば、次も照合する：シナリオ（根拠の文）、人が定義した列、画面の記録の要素名、組織の件数の最大値

## 未確定（次に決める）
- `verify.value` の意味（列名か固定値か）。今のスタブは列名を書いている
- press_key の「押すキー」の欄
- `created_by_ai`、`allowlist_versions`、`screen_record_version`、`plan_id` などの自己申告は、計画を作る処理（app側）が実行エンジンの値で上書きする。ゲートはまだ上書きしない
- `unresolved`（未確定）をAIが書かずに隠すことは、決定的な検査では防げない。承認画面で補う（方法は未定）

## 12-1の関数（`IrreversibleGuard`）で決めたこと
- 取り消せる手順は、この関数を通さない（取り消し可否は `OperationPolicy` が決める）
- `readback` がない取り消せない手順は、拒否する（安全側。必要なら、のちに緩める）
- ドライランの記録は `{dry_run_id, result, environment, destination_name}`。1aは environment=stub だけを有効とする
- 承認の記録は `{stage:'final', approver, os_user}`。計画ハッシュに結び付ける。**承認の取り消し（revoke）は未実装**
- 拒否は、理由つきで `ledger`（kind=refusal）に記録する
- 「処理中」の保存が最後。保存に失敗したら実行しない。実行後の異常・確認条件の不成立は、すべて「要確認」
- 初回1件の確認は、`first_row_confirmed`（subject=run_id）の記録で判定する

## SQLiteについて（仕様14-3の「要確認」の結果）
- PHP 8.3.6 の pdo_sqlite では、`exec('BEGIN IMMEDIATE')` で始めたトランザクションを、`PDO::inTransaction()` は認識しない（`false` を返す）。そのため、`Db::immediate` は `inTransaction()` に頼らず、自分で管理している深さで COMMIT / ROLLBACK する
- PHP 8.5.12 で同じ挙動かは、未確認（8.5.12 が用意できたら確認する）
