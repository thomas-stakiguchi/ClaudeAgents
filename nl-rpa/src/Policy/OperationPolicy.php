<?php

declare(strict_types=1);

namespace Src\Policy;

/**
 * 取り消せない操作の判定（R4、9-1、7-1、付録A-2）。実行エンジンが計算する。AIの出力（reversible）は使わない。
 *
 * 既定は「取り消せない」。取り消せるのは、次だけ。
 *  - 画面の読み取り、入力欄の入力・クリア（自動保存なしと登録された要素のみ）、待ち、結果の記録
 *  - open_url：登録されたURLパターンの画面のみ
 *  - click：個別登録されたボタンのみ
 *  - login：登録されたパスワード欄のみ
 * 1a（スタブ）では、登録の中身は、呼び出し側が渡す。
 */
final class OperationPolicy
{
    /**
     * @param array<string,true> $registeredButtons    個別登録されたボタンの要素名
     * @param array<string,true> $registeredAutosaveFreeInputs 「自動保存なし」と登録された入力欄の要素名
     * @param array<string,true> $registeredUrls       登録されたURLパターンの対象名（画面の記録の名前）
     * @param array<string,true> $registeredPasswordFields 登録されたパスワード欄
     */
    public function __construct(
        private readonly array $registeredButtons = [],
        private readonly array $registeredAutosaveFreeInputs = [],
        private readonly array $registeredUrls = [],
        private readonly array $registeredPasswordFields = [],
    ) {
    }

    public function isReversible(\stdClass $step): bool
    {
        $action = $step->action ?? null;
        $target = $step->target ?? null;
        if (! is_string($action)) {
            return false;
        }

        return match ($action) {
            'read_text', 'wait_for', 'write_result' => true,
            'open_url' => is_string($target) && isset($this->registeredUrls[$target]),
            'type_text', 'clear_text' => is_string($target) && isset($this->registeredAutosaveFreeInputs[$target]),
            'click' => is_string($target) && isset($this->registeredButtons[$target]),
            'login' => is_string($target) && isset($this->registeredPasswordFields[$target]),
            default => false, // press_key、confirm_dialog、未知の操作は、すべて取り消せない
        };
    }

    /**
     * 計画の中の、取り消せない手順のIDの一覧（構文の中も含む）。
     *
     * @return list<string>
     */
    public function irreversibleStepIds(\stdClass $plan): array
    {
        $ids = [];
        $this->walk($plan->steps ?? [], $ids);

        return $ids;
    }

    /** @param array<mixed> $steps @param list<string> $ids */
    private function walk(array $steps, array &$ids): void
    {
        foreach ($steps as $s) {
            if (! $s instanceof \stdClass) {
                continue;
            }
            $a = $s->action ?? null;
            if (in_array($a, ['for_each_row', 'if', 'on_dialog'], true)) {
                foreach (['steps', 'then', 'else'] as $k) {
                    isset($s->{$k}) && is_array($s->{$k}) && $this->walk($s->{$k}, $ids);
                }
                continue;
            }
            if (! $this->isReversible($s) && isset($s->id) && is_string($s->id)) {
                $ids[] = $s->id;
            }
        }
    }
}
