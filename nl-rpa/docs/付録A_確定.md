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

## 実行の登録（`RunRegistrar`）と、12-1の関数（`IrreversibleGuard`）で決めたこと
- 実行の前に、`RunRegistrar::register` で実行を登録する。ここで、最終承認、ドライラン、接続先、入力データ（R8）、件数・数量・金額の上限（R9）を検査し、「実行ID → 計画ハッシュ・ドライランID・接続先・各行の業務キーと値のハッシュ」を記録（ledger）に固定する
- 12-1の関数は、呼び出し側の自己申告を信じない。版・許可リスト・行の順番・件数・ドライランは、登録の記録から読む。行の値は、登録時のハッシュと照合する
- 計画は、関数の最初に深いコピーを取り、そのコピーだけを使う。許可オブジェクト（`Permit`）には、検査した時点の手順のコピーが入る
- 取り消せる手順は、この関数を通さない（取り消し可否は `OperationPolicy` が決める）
- `readback` がない取り消せない手順は、拒否する（安全側。必要なら、のちに緩める）
- 承認の記録は `{stage:'final', approver, os_user}`、ドライランの記録は `{dry_run_id, result, environment, destination_name}`。形が欠けた記録は無効。1aは environment=stub だけを有効とする。**承認の取り消し（revoke）は未実装**
- 拒否は、理由つきで ledger（kind=refusal）に記録する
- 「処理中」の保存が最後。「成功済みでない」「同じ業務キーに未解消がない」「停止の旗」の確認は、この保存と同じトランザクションの中で行う（同時実行の隙を作らない）。保存に失敗したら実行しない。実行後の異常・確認条件の不成立は、すべて「要確認」。実行後の状態の保存に失敗したら、「処理中」のまま残し（次の起動時に「要確認」になる）、呼び出し側には `executedButUnsaved` を返す
- 2行目以降は、`first_row_confirmed`（subject=実行ID）の記録があり、かつ1行目の同じ手順が「成功」のときだけ進める
- 許可オブジェクトの偽造対策（多重の防御）
  - `Permit` は複製・直列化を禁止し、Guardだけが知る使い捨ての合言葉（nonce）を持つ
  - 操作の部品は `GuardedAction` を継承する。`perform`（final）が `Guard::redeem` で、合言葉と保存先の状態（処理中、実行ID・計画ハッシュの一致）を照合し、使い捨ての証明（`Redeemed`）を返す
  - 本当に取り消せない操作をする瞬間は、`effect()` を通す。`effect()` は `Guard::assertRedeemed` で証明を照合し直してから、検査した時点の手順のコピーを渡す。継承して `execute` を直接呼ぶ裏口を作っても、`effect` を通らなければ操作できない（ブラウザの部品は、操作の瞬間に必ず `effect` を使うこと）
  - リフレクションでコンストラクタを直接呼んで偽造しても、合言葉・token を知らないので、止まる（テストで確認）
  - アーキテクチャのテストで、`IrreversibleAction` の実装は `GuardedAction` の継承に限ること、`src/` でリフレクションによる生成をしないことを確かめる
- 取り消し可否は、承認済みの個別登録（計画ハッシュに含まれる `hashInputs['registrations']`）から、Guardの中で計算する。形：`{buttons, autosave_free_inputs, urls, password_fields}`。それぞれ `[{destination, target}]`
- 業務キー：NFKC正規化で変わらない、空白・制御・書式文字を含まない、200バイト以内。大文字小文字は**区別する**（`K-001` と `k-001` は別。利用者に確認済み）。保存・比較の入り口は `BusinessKey::fold`（今は、そのまま返す）
- 入力データの検査（R8）：型（number／date。date の format は YYYY-MM-DD 形式）、必須、max_bytes、制御文字、業務キーの重複。数量・金額は、負の値・不正な形を拒否し、小数4桁の固定小数で合計する（浮動小数点の丸めで上限を超えさせない）
- 「初回1件」の確認の記録には、確認者（`by`、`os_user`）が必要。その1件の「成功」より後に記録されたものだけが有効
- 実行後に状態を保存できなかったときは、kind=`post_exec_failure` で記録する（拒否と区別する）
- 状態の表（step_states）が、記録（ledger）の最新と食い違っていれば、拒否する。表と旗の削除は、トリガーで拒否する。旗は、表が0でも、記録の最新が「立っている」なら、立っているものとして扱う

## 既知の限界・未実装（Opusレビューで挙がったもの）
- ledger は、末尾の記録をまとめて消されても、`verify()` では見つからない（先頭のハッシュの外部への退避がないため）。鍵もないため、全体を計算し直されれば、改ざんは見つからない。「部分的な改ざんのみ検知できる（未検証）」以上のことは言わない（LOC-8）
- `ledger->append()` は、同じプロセスから誰でも呼べる。承認・ドライラン・実行の登録（run）・初回確認・旗（flag）の記録を、偽って追記できる。とくに、`run` を偽れば、数量・金額の上限を迂回できる（Guardは件数の上限だけ、計画と照合し直す）。承認画面・ワーカーの分離と、別のOSアカウントは、1bの条件
- 同じ業務キーの別の手順の「未解消」の確認（`unresolvedFor`）は、状態の表だけを見ている。ledger との突き合わせは、実行する手順だけ
- 実行の登録に、SEC-13の項目（実行者、実行PC、入力ファイルのハッシュ）が、まだない
- `IrreversibleGuard` は、組織全体の件数の最大値（SEC-14）を見ない。登録時（`RunRegistrar` の `orgMaxRows`）だけ
- `recoverAfterCrash` は、実行中のワーカーがいても呼べてしまう。14-3の6のファイルロックは、Runner で実装する
- 接続先は、名前でしか照合していない。スキーム・ホスト・ポートと種類の照合は、ブラウザの部品（1b）で行う
- 計画ハッシュの照合は、この関数では「直前」の1回だけ。ドライランの開始時・実行の開始時の2回は、Runner で行う
- 「初回」は、今は実行（run_id）ごと。版ごとの最初の1回にするかは、仕様から判断できないので、要確認

## SQLiteについて（仕様14-3の「要確認」の結果）
- PHP 8.3.6 の pdo_sqlite では、`exec('BEGIN IMMEDIATE')` で始めたトランザクションを、`PDO::inTransaction()` は認識しない（`false` を返す）。そのため、`Db::immediate` は `inTransaction()` に頼らず、自分で管理している深さで COMMIT / ROLLBACK する
- PHP 8.5.12 で同じ挙動かは、未確認（8.5.12 が用意できたら確認する）

## 実行PCで必要なPHP拡張（実装で判明）
- `intl`（業務キーのNFKC正規化）、`mbstring`、`pdo_sqlite`。TECH_STACK.md の更新対象
