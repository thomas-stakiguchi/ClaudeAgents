<?php

declare(strict_types=1);

namespace Src\Browser;

use Src\Guard\IrreversibleGuard;
use Src\Guard\Redeemed;

/**
 * ブラウザ操作のインターフェース（仕様14-3）。1aは、Chromeを使わない偽の部品（FakeBrowser）、1bは chrome-php 用。
 *
 * 取り消せる操作（読み取りなど）は、普通のメソッド。
 * 取り消せない操作は、performIrreversible だけ。許可の証明（Redeemed）を必ず受け取り、操作の瞬間に Guard で照合し直す。
 * 対象の要素は、呼び出し側が渡さない。検査した時点の手順（証明の中のコピー）から取る。
 */
interface BrowserPort
{
    public function destinationName(): string;

    /** 実行環境の名前（ドライランの記録に使う。1aの偽の部品は stub）。1bで部品を差し替えたら、別の名前になる */
    public function environmentName(): string;

    /** 登録された画面（名前）を開く */
    public function openUrl(string $page): void;

    public function currentPage(): string;

    public function readValue(string $element): string;

    public function typeText(string $element, string $value): void;

    public function clearText(string $element): void;

    public function textVisible(string $text): bool;

    public function elementExists(string $element): bool;

    /** 開いている確認ダイアログの文言（なければ null）。想定外のダイアログは、呼び出し側が停止する */
    public function pendingDialog(): ?string;

    /** 入力をクリアし、開始画面へ戻る（7-1） */
    public function resetToStart(): void;

    /**
     * 取り消せない操作（click、取り消せない扱いの type_text など）。
     *
     * 照合に使う Guard は、部品が自分で持つ（コンストラクタで結び付ける）。引数で受け取った Guard は信用しない。
     *
     * @param array<string,string> $rowValues この行の入力データ（type_text の値の解決に使う）
     */
    public function performIrreversible(Redeemed $proof, array $rowValues): void;

    /**
     * 取り消せる操作（openUrl、typeText、clearText）を、承認済みの個別登録に限る（多重の防御。12-1）。
     * 登録にない対象への操作は、例外にする。実行エンジンは、実行のたびに、承認済みの登録から作った OperationPolicy を渡す。
     */
    public function restrictTo(\Src\Policy\OperationPolicy $policy): void;

    public function close(): void;
}
