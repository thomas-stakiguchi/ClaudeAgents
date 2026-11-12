<?php

declare(strict_types=1);

namespace Src\Plan;

/**
 * 実行計画の形式検査（仕様3-2、付録A、docs/付録A_確定.md）。
 * 形式に合わないものは受け入れない。戻り値は、エラーの一覧（空なら合格）。
 */
final class PlanValidator
{
    public const ACTIONS = ['open_url', 'read_text', 'type_text', 'clear_text', 'click', 'press_key', 'login', 'confirm_dialog', 'wait_for', 'write_result'];
    public const SYNTAX = ['for_each_row', 'if', 'on_dialog'];
    public const VERIFY_TYPES = ['text_visible', 'text_equals', 'element_exists', 'url_matches', 'value_matches'];
    /** 1aで使える接続先の種類（仕様3-4。第1段階はブラウザのみ） */
    public const DESTINATION_KINDS = ['browser'];
    private const NEEDS_TARGET = ['open_url', 'read_text', 'type_text', 'clear_text', 'click', 'login'];
    private const COLUMN_TYPES = ['text', 'number', 'date'];
    private const ROLES = ['business_key', 'quantity', 'amount', 'personal_info'];
    private const RULE_KEYS = ['width', 'date_format', 'thousand_separator', 'trim', 'max_length'];

    /** @var list<string> */
    private array $errors = [];
    /** @var array<string,true> */
    private array $stepIds = [];
    /** @var array<string,true> */
    private array $columns = [];

    /** @var int|null 組織全体の件数の最大値（SEC-14）。指定があれば、超える上限は拒否 */
    private ?int $orgMaxRows = null;

    /** @return list<string> */
    public static function validate(mixed $plan, ?int $orgMaxRows = null): array
    {
        $v = new self();
        $v->orgMaxRows = $orgMaxRows;
        $v->plan($plan);

        return $v->errors;
    }

    /** 「未確定」が残っていないか（9-2、12-1）。形式が正しい計画に対して使う */
    public static function hasUnresolved(\stdClass $plan): bool
    {
        return ($plan->unresolved ?? []) !== [];
    }

    private function e(string $path, string $msg): void
    {
        $this->errors[] = "{$path}: {$msg}";
    }

    /** @param list<string> $required @param list<string> $optional */
    private function obj(mixed $v, string $path, array $required, array $optional = []): ?\stdClass
    {
        if (! $v instanceof \stdClass) {
            $this->e($path, 'オブジェクトではありません');

            return null;
        }
        $keys = array_keys(get_object_vars($v));
        foreach ($required as $r) {
            if (! in_array($r, $keys, true)) {
                $this->e($path, "必須の項目がありません: {$r}");
            } elseif ($v->{$r} === null && $r !== 'source') {
                $this->e($path, "必須の項目に null は使えません: {$r}");
            }
        }
        foreach ($keys as $k) {
            if (! in_array($k, [...$required, ...$optional], true)) {
                $this->e($path, "知らない項目です: {$k}");
            }
        }

        return $v;
    }

    private function str(mixed $v, string $path, ?string $pattern = null, bool $nonEmpty = true): bool
    {
        if (! is_string($v) || ($nonEmpty && $v === '') || ($pattern !== null && ! preg_match($pattern, $v))
            || preg_match('/[\x00-\x1f\x7f\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $v)) {
            $this->e($path, '文字列の形式が正しくありません（空、制御文字、改行・タブ、向きを変える文字は使えません）');

            return false;
        }

        return true;
    }

    private function posInt(mixed $v, string $path): bool
    {
        if (! is_int($v) || $v < 1) {
            $this->e($path, '1以上の整数にしてください');

            return false;
        }

        return true;
    }

    private function plan(mixed $p): void
    {
        $o = $this->obj($p, '$', ['plan_id', 'version', 'scenario_id', 'created_by_ai', 'allowlist_versions', 'screen_record_version', 'input', 'limits', 'steps'], ['plan_hash', 'unresolved']);
        if ($o === null) {
            return;
        }
        isset($o->plan_id) && $this->str($o->plan_id, '$.plan_id', '/\APLN-[0-9]+\z/');
        isset($o->version) && $this->posInt($o->version, '$.version');
        isset($o->scenario_id) && $this->str($o->scenario_id, '$.scenario_id', '/\ASCN-[0-9]+\z/');
        if (isset($o->created_by_ai) && ($a = $this->obj($o->created_by_ai, '$.created_by_ai', ['model', 'prompt_version']))) {
            isset($a->model) && $this->str($a->model, '$.created_by_ai.model');
            isset($a->prompt_version) && $this->str($a->prompt_version, '$.created_by_ai.prompt_version');
        }
        if (isset($o->allowlist_versions) && ($a = $this->obj($o->allowlist_versions, '$.allowlist_versions', ['operations', 'destinations']))) {
            isset($a->operations) && $this->posInt($a->operations, '$.allowlist_versions.operations');
            isset($a->destinations) && $this->posInt($a->destinations, '$.allowlist_versions.destinations');
        }
        isset($o->screen_record_version) && $this->posInt($o->screen_record_version, '$.screen_record_version');
        if (property_exists($o, 'unresolved')) {
            if (! is_array($o->unresolved)) {
                $this->e('$.unresolved', '配列ではありません');
            } else {
                foreach ($o->unresolved as $i => $u) {
                    $this->str($u, "\$.unresolved[{$i}]");
                }
            }
        }
        isset($o->input) && $this->input($o->input);
        $steps = $o->steps ?? null;
        if (isset($o->steps)) {
            if (! is_array($steps) || $steps === []) {
                $this->e('$.steps', '1つ以上の手順の配列にしてください');
            } else {
                $this->steps($steps, '$.steps', false);
            }
        }
        isset($o->limits) && $this->limits($o->limits, is_array($steps) && $this->hasIrreversibleCandidate($steps));
    }

    private function input(mixed $in): void
    {
        $o = $this->obj($in, '$.input', ['columns']);
        if ($o === null || ! isset($o->columns)) {
            return;
        }
        if (! is_array($o->columns) || $o->columns === []) {
            $this->e('$.input.columns', '1つ以上の列定義の配列にしてください');

            return;
        }
        $keyCount = 0;
        foreach ($o->columns as $i => $c) {
            $path = "\$.input.columns[{$i}]";
            $c = $this->obj($c, $path, ['name', 'type', 'required'], ['max_bytes', 'format', 'role']);
            if ($c === null) {
                continue;
            }
            if (isset($c->name) && $this->str($c->name, "{$path}.name")) {
                isset($this->columns[$c->name]) && $this->e("{$path}.name", '列名が重複しています');
                $this->columns[$c->name] = true;
            }
            isset($c->type) && ! in_array($c->type, self::COLUMN_TYPES, true) && $this->e("{$path}.type", 'text／number／date のどれかにしてください');
            isset($c->required) && ! is_bool($c->required) && $this->e("{$path}.required", '真偽にしてください');
            property_exists($c, 'max_bytes') && $this->posInt($c->max_bytes, "{$path}.max_bytes");
            property_exists($c, 'format') && $this->str($c->format, "{$path}.format");
            if (property_exists($c, 'role')) {
                if (! in_array($c->role, self::ROLES, true)) {
                    $this->e("{$path}.role", '知らない役割です');
                } elseif ($c->role === 'business_key') {
                    $keyCount++;
                    ($c->required ?? null) !== true && $this->e("{$path}.required", '業務キー列は required:true にしてください（R8）');
                }
            }
        }
        $keyCount !== 1 && $this->e('$.input.columns', "業務キー列（role=business_key）は、ちょうど1つ必要です（現在{$keyCount}）");
    }

    private function limits(mixed $l, bool $needRows): void
    {
        $o = $this->obj($l, '$.limits', [], ['max_rows_per_run', 'max_quantity_per_run', 'max_amount_per_run']);
        if ($o === null) {
            return;
        }
        if (property_exists($o, 'max_rows_per_run') && $this->posInt($o->max_rows_per_run, '$.limits.max_rows_per_run')
            && $this->orgMaxRows !== null && $o->max_rows_per_run > $this->orgMaxRows) {
            $this->e('$.limits.max_rows_per_run', "組織全体の最大値（{$this->orgMaxRows}）を超えています（SEC-14）");
        }
        property_exists($o, 'max_quantity_per_run') && $this->posInt($o->max_quantity_per_run, '$.limits.max_quantity_per_run');
        property_exists($o, 'max_amount_per_run') && $this->posInt($o->max_amount_per_run, '$.limits.max_amount_per_run');
        if ($needRows && ! property_exists($o, 'max_rows_per_run')) {
            $this->e('$.limits.max_rows_per_run', '取り消せない操作がありうる計画では、件数の上限が必須です（R9）');
        }
    }

    /**
     * 件数の上限が要るかの、安全側の判定。
     * 画面の読み取り・待ち・結果の記録以外は、登録の有無にかかわらず、すべて対象にする
     * （実際の取り消し可否は OperationPolicy が決める。ここは、それより広く取る）。
     */
    private function hasIrreversibleCandidate(array $steps): bool
    {
        foreach ($steps as $s) {
            if (! $s instanceof \stdClass) {
                continue;
            }
            $a = $s->action ?? null;
            if (is_string($a) && ! in_array($a, ['read_text', 'wait_for', 'write_result', 'for_each_row', 'if'], true)) {
                return true;
            }
            if ($a !== null && ! is_string($a)) {
                return true;
            }
            foreach (['steps', 'then', 'else'] as $k) {
                if (isset($s->{$k}) && is_array($s->{$k}) && $this->hasIrreversibleCandidate($s->{$k})) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<mixed> $steps */
    private function steps(array $steps, string $path, bool $inLoop): void
    {
        if (! array_is_list($steps)) {
            $this->e($path, '配列ではありません');

            return;
        }
        foreach ($steps as $i => $s) {
            $this->step($s, "{$path}[{$i}]", $inLoop);
        }
    }

    private function step(mixed $s, string $path, bool $inLoop): void
    {
        if (! $s instanceof \stdClass) {
            $this->e($path, 'オブジェクトではありません');

            return;
        }
        $action = $s->action ?? null;
        if (! is_string($action) || ! in_array($action, [...self::ACTIONS, ...self::SYNTAX], true)) {
            $this->e("{$path}.action", '知らない操作の種類です');

            return;
        }
        $common = ['id', 'action', 'source'];
        $opt = ['label'];
        $req = $common;
        switch ($action) {
            case 'for_each_row':
                $o = $this->obj($s, $path, [...$req, 'steps'], $opt);
                break;
            case 'if':
                $o = $this->obj($s, $path, [...$req, 'condition', 'then'], [...$opt, 'else']);
                break;
            case 'on_dialog':
                $o = $this->obj($s, $path, [...$req, 'dialog_text', 'response'], $opt);
                break;
            default:
                $required = [...$req, 'destination', 'verify'];
                in_array($action, self::NEEDS_TARGET, true) && $required[] = 'target';
                $action === 'wait_for' && $required[] = 'timeout_sec';
                $optional = [...$opt, 'reversible', 'readback'];
                in_array($action, ['type_text', 'write_result'], true) && array_push($optional, 'value', 'column');
                $o = $this->obj($s, $path, $required, $optional);
        }
        if ($o === null) {
            return;
        }
        if (isset($o->id) && $this->str($o->id, "{$path}.id", '/\AS-[0-9]+\z/')) {
            isset($this->stepIds[$o->id]) && $this->e("{$path}.id", "手順IDが重複しています: {$o->id}");
            $this->stepIds[$o->id] = true;
        }
        if (property_exists($o, 'source') && $o->source !== null) {
            $this->str($o->source, "{$path}.source");
        }
        property_exists($o, 'label') && $this->str($o->label, "{$path}.label");
        property_exists($o, 'reversible') && ! is_bool($o->reversible) && $this->e("{$path}.reversible", '真偽にしてください（ただし、この値は使われません）');

        switch ($action) {
            case 'for_each_row':
                $inLoop && $this->e($path, 'for_each_row は入れ子にできません');
                if (isset($o->steps)) {
                    is_array($o->steps) && $o->steps !== [] ? $this->steps($o->steps, "{$path}.steps", true) : $this->e("{$path}.steps", '1つ以上の手順の配列にしてください');
                }
                break;
            case 'if':
                isset($o->condition) && $this->verify($o->condition, "{$path}.condition");
                foreach (['then', 'else'] as $k) {
                    if (property_exists($o, $k)) {
                        is_array($o->{$k}) && $o->{$k} !== [] ? $this->steps($o->{$k}, "{$path}.{$k}", $inLoop) : $this->e("{$path}.{$k}", '1つ以上の手順の配列にしてください');
                    }
                }
                break;
            case 'on_dialog':
                isset($o->dialog_text) && $this->str($o->dialog_text, "{$path}.dialog_text");
                isset($o->response) && ! in_array($o->response, ['ok', 'stop'], true) && $this->e("{$path}.response", 'ok か stop にしてください');
                break;
            default:
                $this->action($o, $action, $path, $inLoop);
        }
    }

    private function action(\stdClass $o, string $action, string $path, bool $inLoop): void
    {
        property_exists($o, 'target') && $this->str($o->target, "{$path}.target");
        if (property_exists($o, 'destination') && ($d = $this->obj($o->destination, "{$path}.destination", ['kind', 'name']))) {
            isset($d->kind) && ! in_array($d->kind, self::DESTINATION_KINDS, true) && $this->e("{$path}.destination.kind", '1aで使える接続先の種類は browser だけです');
            isset($d->name) && $this->str($d->name, "{$path}.destination.name", '/\A[A-Za-z0-9_.-]+\z/');
        }
        $hasValue = property_exists($o, 'value');
        $hasColumn = property_exists($o, 'column');
        if (in_array($action, ['type_text', 'write_result'], true)) {
            if ($hasValue === $hasColumn) {
                $this->e($path, 'value（固定値）か column（入力データの列）の、どちらか一方だけを指定してください');
            }
            $hasValue && $this->str($o->value, "{$path}.value", null, false);
            if ($hasColumn && $this->str($o->column, "{$path}.column")) {
                ! isset($this->columns[$o->column]) && $this->e("{$path}.column", "列定義にない列です: {$o->column}");
                ! $inLoop && $this->e("{$path}.column", '入力データの列は、for_each_row の中でだけ使えます');
            }
        }
        if ($action === 'wait_for') {
            if (! is_int($o->timeout_sec ?? null) || $o->timeout_sec < 1 || $o->timeout_sec > 300) {
                $this->e("{$path}.timeout_sec", '1〜300の整数にしてください');
            }
        }
        isset($o->verify) && $this->verify($o->verify, "{$path}.verify");
        property_exists($o, 'readback') && $this->readback($o->readback, "{$path}.readback", $inLoop);
    }

    /** 読み返し照合の項目（9-3）。取り消せない操作の直前に、画面から読み返して、入力データの値と比べる */
    private function readback(mixed $r, string $path, bool $inLoop): void
    {
        if (! is_array($r) || $r === [] || ! array_is_list($r)) {
            $this->e($path, '1つ以上の項目の配列にしてください');

            return;
        }
        foreach ($r as $i => $item) {
            $p = "{$path}[{$i}]";
            $o = $this->obj($item, $p, ['target'], ['column', 'value', 'rule']);
            if ($o === null) {
                continue;
            }
            isset($o->target) && $this->str($o->target, "{$p}.target");
            $hasC = property_exists($o, 'column');
            $hasV = property_exists($o, 'value');
            if ($hasC === $hasV) {
                $this->e($p, 'column（入力データの列）か value（固定値）の、どちらか一方だけを指定してください');
            }
            $hasV && $this->str($o->value, "{$p}.value", null, false);
            if ($hasC && $this->str($o->column, "{$p}.column")) {
                ! isset($this->columns[$o->column]) && $this->e("{$p}.column", "列定義にない列です: {$o->column}");
                ! $inLoop && $this->e("{$p}.column", '入力データの列は、for_each_row の中でだけ使えます');
            }
            property_exists($o, 'rule') && $this->rule($o->rule, "{$p}.rule");
        }
    }

    private function verify(mixed $v, string $path): void
    {
        $o = $this->obj($v, $path, ['type'], ['value', 'rule']);
        if ($o === null) {
            return;
        }
        if (! isset($o->type) || ! in_array($o->type, self::VERIFY_TYPES, true)) {
            $this->e("{$path}.type", '知らない確認条件の種類です');

            return;
        }
        if (! property_exists($o, 'value') || ! is_string($o->value) || $o->value === '') {
            $this->e("{$path}.value", '確認する値（空でない文字列）が必要です');
        }
        if ($o->type === 'value_matches') {
            if (! property_exists($o, 'rule')) {
                $this->e("{$path}.rule", 'value_matches には照合ルールが必須です');
            } else {
                $this->rule($o->rule, "{$path}.rule");
            }
        } elseif (property_exists($o, 'rule')) {
            $this->e("{$path}.rule", 'rule は value_matches だけで使えます');
        }
    }

    private function rule(mixed $r, string $path): void
    {
        $o = $this->obj($r, $path, [], self::RULE_KEYS);
        if ($o === null) {
            return;
        }
        property_exists($o, 'width') && ! in_array($o->width, ['full', 'half'], true) && $this->e("{$path}.width", 'full か half にしてください');
        if (property_exists($o, 'date_format') && $this->str($o->date_format, "{$path}.date_format") && \Src\Guard\ReadbackComparator::phpDateFormat($o->date_format) === null) {
            $this->e("{$path}.date_format", '対応していない書式です（YYYY・MM・DD・HH・mm・ss と、区切りの - / : . 空白だけ）');
        }
        foreach (['thousand_separator', 'trim'] as $k) {
            property_exists($o, $k) && ! is_bool($o->{$k}) && $this->e("{$path}.{$k}", '真偽にしてください');
        }
        property_exists($o, 'max_length') && $this->posInt($o->max_length, "{$path}.max_length");
    }
}
