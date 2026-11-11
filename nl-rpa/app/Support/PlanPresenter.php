<?php

declare(strict_types=1);

namespace App\Support;

use Src\Policy\OperationPolicy;

/**
 * 承認画面に出す説明を、計画から機械的に作る（R3）。AIが書いた日本語の説明（label）は、参考として併記するだけ。
 * 取り消し可否は、実行エンジンが計算した結果（OperationPolicy）。
 */
final class PlanPresenter
{
    /**
     * @param array<string,mixed> $hashInputs
     * @return list<array<string,mixed>>
     */
    public static function steps(\stdClass $plan, array $hashInputs): array
    {
        $policy = OperationPolicy::fromRegistrations($hashInputs['registrations']);
        $irreversible = array_flip($policy->irreversibleStepIds($plan));
        $out = [];
        self::walk($plan->steps, 0, $irreversible, $out);

        return $out;
    }

    /** @return list<string> 取り消せない手順のID */
    public static function irreversibleIds(\stdClass $plan, array $hashInputs): array
    {
        return OperationPolicy::fromRegistrations($hashInputs['registrations'])->irreversibleStepIds($plan);
    }

    /** @return list<string> 取り消せない手順のうち、根拠がなく「AIが補った」もの（5章の5：1つずつ確認しないと承認できない） */
    public static function aiSuppliedIrreversible(\stdClass $plan, array $hashInputs): array
    {
        $ids = array_flip(self::irreversibleIds($plan, $hashInputs));
        $out = [];
        foreach (self::steps($plan, $hashInputs) as $s) {
            if (isset($ids[$s['id']]) && $s['ai_supplied']) {
                $out[] = $s['id'];
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $steps
     * @param array<string,int> $irreversible
     * @param list<array<string,mixed>> $out
     */
    private static function walk(array $steps, int $depth, array $irreversible, array &$out): void
    {
        foreach ($steps as $s) {
            $action = (string) $s->action;
            $isSyntax = in_array($action, ['for_each_row', 'if', 'on_dialog'], true);
            $out[] = [
                'id' => (string) $s->id,
                'depth' => $depth,
                'action' => $action,
                'destination' => isset($s->destination) ? $s->destination->kind.' / '.$s->destination->name : '',
                'target' => (string) ($s->target ?? ''),
                'value' => self::value($s),
                'verify' => isset($s->verify) ? self::verify($s->verify) : (isset($s->condition) ? self::verify($s->condition) : ''),
                'readback' => isset($s->readback) ? implode('、', array_map(fn ($r) => $r->target.' ← '.(isset($r->column) ? '列「'.$r->column.'」' : '固定値「'.$r->value.'」'), $s->readback)) : '',
                'reversible' => $isSyntax ? null : ! isset($irreversible[(string) $s->id]),
                'source' => $s->source ?? null,
                'ai_supplied' => ! $isSyntax && ($s->source ?? null) === null,
                'ai_label' => (string) ($s->label ?? ''),
            ];
            foreach (['steps', 'then', 'else'] as $k) {
                isset($s->{$k}) && self::walk($s->{$k}, $depth + 1, $irreversible, $out);
            }
        }
    }

    private static function value(\stdClass $s): string
    {
        if (isset($s->column)) {
            return '入力データの列「'.$s->column.'」';
        }
        if (property_exists($s, 'value')) {
            return '固定値「'.$s->value.'」';
        }
        if (($s->action ?? null) === 'on_dialog') {
            return '「'.$s->dialog_text.'」→ '.($s->response === 'ok' ? 'OKする' : '停止');
        }

        return '';
    }

    private static function verify(\stdClass $v): string
    {
        $names = ['text_visible' => '画面に文字がある', 'text_equals' => '値が等しい', 'element_exists' => '要素がある', 'url_matches' => '画面が一致', 'value_matches' => '入力データと一致'];

        return ($names[$v->type] ?? $v->type).'：'.($v->value ?? '');
    }
}
