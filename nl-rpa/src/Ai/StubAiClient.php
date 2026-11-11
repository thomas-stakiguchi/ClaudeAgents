<?php

declare(strict_types=1);

namespace Src\Ai;

/** 1a用のAIスタブ。常に、固定の実行計画のJSONを返す（仕様6-1）。 */
final class StubAiClient implements AiClient
{
    public function __construct(private readonly ?string $fixedJson = null)
    {
    }

    public function createPlan(string $scenario, array $columnNames, array $elements): string
    {
        return $this->fixedJson ?? self::defaultPlanJson();
    }

    public static function defaultPlanJson(): string
    {
        return <<<'JSON'
{
  "plan_id": "PLN-0001",
  "version": 1,
  "scenario_id": "SCN-0001",
  "created_by_ai": { "model": "stub", "prompt_version": "stub-1" },
  "allowlist_versions": { "operations": 1, "destinations": 1 },
  "screen_record_version": 1,
  "input": {
    "columns": [
      { "name": "商品コード", "type": "text", "required": true, "max_bytes": 12 },
      { "name": "数量", "type": "number", "required": true, "role": "quantity" },
      { "name": "伝票番号", "type": "text", "required": true, "role": "business_key" }
    ]
  },
  "limits": { "max_rows_per_run": 100, "max_quantity_per_run": 5000 },
  "steps": [
    { "id": "S-001", "action": "open_url", "target": "login_page",
      "verify": { "type": "text_visible", "value": "ログイン" },
      "source": "発注管理システムにログインする。", "label": "ログイン画面を開く" },
    { "id": "S-002", "action": "for_each_row", "source": "入力データの各行について",
      "steps": [
        { "id": "S-003", "action": "type_text", "target": "item_code", "column": "商品コード",
          "verify": { "type": "value_matches", "value": "商品コード", "rule": { "trim": true } },
          "source": "商品コード・数量・納品日を入力する。" },
        { "id": "S-004", "action": "type_text", "target": "quantity", "column": "数量",
          "verify": { "type": "value_matches", "value": "数量", "rule": { "trim": true, "thousand_separator": true } },
          "source": "商品コード・数量・納品日を入力する。" },
        { "id": "S-005", "action": "click", "target": "submit_button",
          "verify": { "type": "text_visible", "value": "発注番号" },
          "source": "登録ボタンを押して、表示された発注番号を結果に記録する。" },
        { "id": "S-006", "action": "read_text", "target": "order_number",
          "verify": { "type": "element_exists", "value": "order_number" },
          "source": null }
      ] }
  ]
}
JSON;
    }
}
