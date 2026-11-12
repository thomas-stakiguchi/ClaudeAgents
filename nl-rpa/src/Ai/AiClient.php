<?php

declare(strict_types=1);

namespace Src\Ai;

/**
 * AIクライアントのインターフェース（仕様3-3、14-3）。
 * 渡してよいのは、シナリオの文章、入力データの列名、画面の記録の要素名と種類だけ。
 * 入力データの値、認証情報、画面の中身は、渡さない。
 */
interface AiClient
{
    /**
     * @param list<string>          $columnNames 入力データの列名
     * @param array<string,string>  $elements    画面の記録の「要素名 => 種類」
     * @return string 実行計画のJSON文字列。信用しない入力として扱い、AiPlanGate を通して受け入れる
     */
    public function createPlan(string $scenario, array $columnNames, array $elements): string;

    /**
     * 実際に呼んだAIの、モデル名と、プロンプトの版。監査の記録には、AIの自己申告ではなく、この値を使う。
     *
     * @return array{model:string,prompt_version:string}
     */
    public function identity(): array;
}
