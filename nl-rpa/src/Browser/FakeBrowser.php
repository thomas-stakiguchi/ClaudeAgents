<?php

declare(strict_types=1);

namespace Src\Browser;

use Src\Guard\IrreversibleGuard;
use Src\Guard\Redeemed;
use Src\Plan\StrictJson;

/**
 * 偽のブラウザ部品（1a）。Chromeを使わず、stubs/ の定義（JSON）を直接読んで、画面の動きをまねる。
 * 副作用（発注の登録）は、orders() に記録する。試験用の故障の仕掛けも持つ。
 */
final class FakeBrowser implements BrowserPort
{
    use RegistrationRestriction;

    private string $page;
    /** @var array<string,string> 入力欄・文字の現在の値（要素名 => 値） */
    private array $values = [];
    /** @var list<string> */
    private array $orders = [];

    /** 試験用：入力した値を書き換える（画面側の不具合のまね） */
    public ?\Closure $typedValueTransform = null;
    /** 試験用：クリックで発注を登録したあと、例外を投げる（通信が切れたまね） */
    public bool $failAfterClick = false;
    /** 試験用：クリックしても画面が変わらない（確認条件を満たさないまね） */
    public bool $clickDoesNothing = false;

    /** @var \stdClass */
    private \stdClass $def;

    /** @var (\Closure(string,bool):bool)|null 試験用：画面に文字があるかの判定を、書き換える（文字, 本当の結果 → 返す結果） */
    public ?\Closure $textVisibleOverride = null;
    /** 試験用：画面を初期状態へ戻すのに失敗する */
    public bool $failReset = false;

    public function __construct(string $stubFile, private readonly IrreversibleGuard $guard, private readonly string $destination = 'stub')
    {
        $json = file_get_contents($stubFile);
        if ($json === false) {
            throw new \RuntimeException("スタブを読めません: {$stubFile}");
        }
        $def = StrictJson::decode($json);
        if (! $def instanceof \stdClass) {
            throw new \RuntimeException('スタブの形式が不正です');
        }
        $this->def = $def;
        $this->page = (string) $def->start;
    }

    public function destinationName(): string
    {
        return $this->destination;
    }

    public function environmentName(): string
    {
        return 'stub';
    }

    /** @return list<string> 登録された発注番号（副作用の記録） */
    public function orders(): array
    {
        return $this->orders;
    }

    private function page(string $name): \stdClass
    {
        $p = $this->def->pages->{$name} ?? null;
        if (! $p instanceof \stdClass) {
            throw new \RuntimeException("画面がありません: {$name}");
        }

        return $p;
    }

    private function element(string $name): \stdClass
    {
        $e = $this->page($this->page)->elements->{$name} ?? null;
        if (! $e instanceof \stdClass) {
            throw new \RuntimeException("この画面に要素がありません: {$name}");
        }

        return $e;
    }

    public function openUrl(string $page): void
    {
        $this->assertRegistered('open_url', $page);
        $this->rawOpenUrl($page);
    }

    private function rawOpenUrl(string $page): void
    {
        $this->page($page);
        $this->page = $page;
        $this->values = [];
    }

    public function currentPage(): string
    {
        return $this->page;
    }

    public function readValue(string $element): string
    {
        $e = $this->element($element);

        return $this->values[$element] ?? (string) ($e->value ?? '');
    }

    /** 試験用：要素がなくても、例外にせず、入力済みの値（なければ空）を返す */
    public function readValueOrEmpty(string $element): string
    {
        return $this->values[$element] ?? '';
    }

    public function typeText(string $element, string $value): void
    {
        $this->assertRegistered('type_text', $element);
        $this->rawTypeText($element, $value);
    }

    private function rawTypeText(string $element, string $value): void
    {
        $e = $this->element($element);
        if (($e->kind ?? null) !== 'input') {
            throw new \RuntimeException("入力欄ではありません: {$element}");
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new \RuntimeException('改行・タブ・制御文字は入力しません（A-2）');
        }
        $this->values[$element] = $this->typedValueTransform ? ($this->typedValueTransform)($value) : $value;
    }

    public function clearText(string $element): void
    {
        $this->assertRegistered('clear_text', $element);
        $this->element($element);
        $this->values[$element] = '';
    }

    public function textVisible(string $text): bool
    {
        $real = $this->textVisibleReal($text);

        return $this->textVisibleOverride ? ($this->textVisibleOverride)($text, $real) : $real;
    }

    private function textVisibleReal(string $text): bool
    {
        $p = $this->page($this->page);
        foreach ($p->text as $t) {
            if (str_contains((string) $t, $text)) {
                return true;
            }
        }
        foreach (get_object_vars($p->elements) as $name => $e) {
            if (($e->kind ?? null) === 'text' && str_contains($this->values[$name] ?? (string) ($e->value ?? ''), $text)) {
                return true;
            }
        }

        return false;
    }

    public function elementExists(string $element): bool
    {
        return isset($this->page($this->page)->elements->{$element});
    }

    public function pendingDialog(): ?string
    {
        return null;
    }

    public function resetToStart(): void
    {
        $this->failReset && throw new \RuntimeException('画面を初期状態に戻せません');
        $this->page = (string) $this->def->start;
        $this->values = [];
    }

    public function performIrreversible(Redeemed $proof, array $rowValues): void
    {
        $this->guard->assertRedeemed($proof); // 操作の瞬間に、証明を照合し直す（使い捨て）
        $step = $proof->permit->step(); // 検査した時点の手順。呼び出し側は、対象を指定できない
        $target = (string) ($step->target ?? '');
        switch ($step->action ?? null) {
            case 'click':
                $e = $this->element($target);
                if (($e->kind ?? null) !== 'button') {
                    throw new \RuntimeException("ボタンではありません: {$target}");
                }
                if ($this->clickDoesNothing) {
                    return;
                }
                if (($e->creates_order ?? false) === true) {
                    $n = sprintf('ORD-%04d', count($this->orders) + 1);
                    $this->orders[] = $n;
                    $to = (string) $e->goto;
                    $this->page = $to;
                    $this->values = [];
                    foreach (get_object_vars($this->page($to)->elements) as $name => $el) {
                        ($el->kind ?? null) === 'text' && $name === 'order_number' && $this->values[$name] = $n;
                    }
                } else {
                    $this->page = (string) ($e->goto ?? $this->page);
                    $this->values = [];
                }
                if ($this->failAfterClick) {
                    throw new \RuntimeException('通信が切れました（クリック後）');
                }

                return;
            case 'type_text':
                $value = isset($step->column) ? ($rowValues[$step->column] ?? null) : ($step->value ?? null);
                if (! is_string($value)) {
                    throw new \RuntimeException('入力する値がありません');
                }
                $this->rawTypeText($target, $value);

                return;
            case 'open_url':
                $this->rawOpenUrl($target);

                return;
        }
        throw new \RuntimeException('この操作は、偽の部品では未対応です: '.($step->action ?? '?'));
    }

    public function close(): void
    {
    }
}
