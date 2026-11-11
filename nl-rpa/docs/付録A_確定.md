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

- 操作（A-2）：open_url、read_text、type_text、clear_text、click、press_key、login、confirm_dialog、wait_for、write_result
  - `target`：画面の記録の要素名（open_url、read_text、type_text、clear_text、click、login で必須）
  - `value`（固定値）か `column`（入力データの列名）：type_text と write_result で、どちらか一方だけ。`column` は、列定義にある名前
  - `verify`：確認条件（**必須**）。`type` は text_visible／text_equals／element_exists／url_matches／value_matches。`value_matches` は `rule`（照合ルール）が必須
  - `timeout_sec`：wait_for で必須（上限は300）
  - `reversible`：**信用しない**。AIが書いていても無視し、取り消し可否は `OperationPolicy` が計算する（R4）
- 構文（A-3）
  - `for_each_row`：`steps` を持つ。入れ子にできない
  - `if`：`condition`（確認条件）、`then`（手順の配列）、`else`（任意）
  - `on_dialog`：`dialog_text`、`response`（ok／stop）

## 照合ルール（rule）
`width`（full／half）、`date_format`（文字列）、`thousand_separator`（真偽）、`trim`（真偽）、`max_length`（整数）。すべて任意。
