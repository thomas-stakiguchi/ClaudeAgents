<?php

declare(strict_types=1);

namespace Src\Ai;

use Src\Plan\PlanValidator;
use Src\Plan\StrictJson;

/**
 * AIの出力を、信頼しない入力として受け入れる門（仕様3-2、SEC-7）。
 * 厳密に読み、形式を検査し、AIが決めてはいけない値を捨てる／照合する。通らないものは受け入れない。
 *
 *  - plan_hash、reversible は捨てる（実行エンジンが計算する。R4）
 *  - $scenario を渡すと、根拠（source）がシナリオの文に含まれないものは null（＝「AIが補った」）にする（R2、5章）
 *  - $expectedColumns を渡すと、列定義が人の定義と完全に一致することを求める（3-3、R8、R9）
 *  - $elementNames を渡すと、target が画面の記録の要素名であることを求める（3-3、R21）
 */
final class AiPlanGate
{
    public const MAX_BYTES = 1_048_576;

    /**
     * @param array<string,array{type:string,role?:string|null,required?:bool}>|null $expectedColumns 列名 => 定義
     * @param list<string>|null $elementNames
     * @param array{model:string,prompt_version:string,operations:int,destinations:int,screen_record_version:int}|null $engineFacts
     *        渡すと、AIの自己申告（モデル名、プロンプトの版、許可リストと画面の記録の版）を、これで上書きする（監査の記録を、事実に合わせる）
     * @return array{plan: \stdClass|null, errors: list<string>}
     */
    public static function accept(
        string $aiOutput,
        ?string $scenario = null,
        ?array $expectedColumns = null,
        ?array $elementNames = null,
        ?int $orgMaxRows = null,
        ?array $engineFacts = null,
    ): array {
        try {
            $plan = StrictJson::decode($aiOutput, 64, self::MAX_BYTES);
            $errors = PlanValidator::validate($plan, $orgMaxRows);
            if ($errors !== []) {
                return ['plan' => null, 'errors' => $errors];
            }
            /** @var \stdClass $plan */
            unset($plan->plan_hash);
            self::strip($plan->steps);
            if ($engineFacts !== null) {
                $plan->created_by_ai = (object) ['model' => $engineFacts['model'], 'prompt_version' => $engineFacts['prompt_version']];
                $plan->allowlist_versions = (object) ['operations' => $engineFacts['operations'], 'destinations' => $engineFacts['destinations']];
                $plan->screen_record_version = $engineFacts['screen_record_version'];
            }
            if ($scenario !== null) {
                self::checkSources($plan->steps, $scenario);
            }
            $errors = [];
            $expectedColumns !== null && self::checkColumns($plan, $expectedColumns, $errors);
            $elementNames !== null && self::checkTargets($plan->steps, $elementNames, $errors);
            if ($errors !== []) {
                return ['plan' => null, 'errors' => $errors];
            }

            return ['plan' => $plan, 'errors' => []];
        } catch (\Throwable $e) {
            // どんな例外も、門の外へ出さず、拒否として扱う
            return ['plan' => null, 'errors' => ['受け入れ中にエラー: '.get_debug_type($e).': '.$e->getMessage()]];
        }
    }

    /** @param array<mixed> $steps */
    private static function strip(array $steps): void
    {
        foreach ($steps as $s) {
            unset($s->reversible);
            foreach (['steps', 'then', 'else'] as $k) {
                isset($s->{$k}) && self::strip($s->{$k});
            }
        }
    }

    /** @param array<mixed> $steps */
    private static function checkSources(array $steps, string $scenario): void
    {
        // シナリオを、句点・改行で文に分け、source が、いずれかの文と（前後の空白を除いて）完全に一致するときだけ認める。
        // 部分一致だと、「。」1文字でも「根拠あり」になり、「AIが補った」の印を回避できる（R2、SEC-7）
        $sentences = self::sentences($scenario);
        foreach ($steps as $s) {
            if (is_string($s->source ?? null) && ! in_array(trim($s->source), $sentences, true)) {
                $s->source = null; // シナリオの文と一致しないものは、根拠として認めない
            }
            foreach (['steps', 'then', 'else'] as $k) {
                isset($s->{$k}) && self::checkSources($s->{$k}, $scenario);
            }
        }
    }

    /** @return list<string> シナリオの文と節（句点「。」「！」「？」と改行で文に、読点で節に区切る。空は除く） */
    private static function sentences(string $scenario): array
    {
        $parts = preg_split('/(?<=[。！？!?])|[\r\n]+/u', $scenario) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $t = trim($p);
            if ($t === '') {
                continue;
            }
            $out[] = $t;
            // 読点で区切った節も、1つの根拠として認める（例：「発注画面を開き」）。完全一致のみ。部分一致は認めない
            foreach (preg_split('/[、,]/u', $t) ?: [] as $c) {
                $c = trim($c);
                $c !== '' && $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * @param array<string,array{type:string,role?:string|null,required?:bool}> $expected
     * @param list<string> $errors
     */
    private static function checkColumns(\stdClass $plan, array $expected, array &$errors): void
    {
        $actual = [];
        foreach ($plan->input->columns as $c) {
            $actual[$c->name] = ['type' => $c->type, 'role' => $c->role ?? null, 'required' => $c->required];
        }
        $names = array_map('strval', array_keys($expected));
        $got = array_map('strval', array_keys($actual));
        sort($names);
        sort($got);
        if ($names !== $got) {
            $errors[] = '列の一覧が、人が定義した入力データの列と一致しません';

            return;
        }
        foreach ($expected as $name => $def) {
            $a = $actual[$name];
            if ($a['type'] !== $def['type'] || $a['role'] !== ($def['role'] ?? null) || (isset($def['required']) && $a['required'] !== $def['required'])) {
                $errors[] = "列「{$name}」の定義が、人の定義と一致しません";
            }
        }
    }

    /**
     * @param array<mixed> $steps
     * @param list<string> $elements
     * @param list<string> $errors
     */
    private static function checkTargets(array $steps, array $elements, array &$errors): void
    {
        foreach ($steps as $s) {
            if (isset($s->target) && ! in_array($s->target, $elements, true)) {
                $errors[] = "画面の記録にない要素名です: {$s->target}";
            }
            foreach ($s->readback ?? [] as $item) {
                if (is_object($item) && isset($item->target) && ! in_array($item->target, $elements, true)) {
                    $errors[] = "画面の記録にない要素名です（読み返しの対象）: {$item->target}";
                }
            }
            if (isset($s->destination->name) && ! \Src\Policy\DestinationLimits::isAllowed((string) $s->destination->name)) {
                $errors[] = "許可されていない接続先です: {$s->destination->name}";
            }
            foreach (['steps', 'then', 'else'] as $k) {
                isset($s->{$k}) && self::checkTargets($s->{$k}, $elements, $errors);
            }
        }
    }
}
