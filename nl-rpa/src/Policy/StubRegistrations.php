<?php

declare(strict_types=1);

namespace Src\Policy;

/**
 * 1a（スタブ）用の、固定の許可リスト・個別登録・画面の記録。計画ハッシュの入力になる。
 * 本来は、ルール管理者が管理し、承認と記録を伴う（R22、7-1-1）。1aには、その管理画面がないため、
 * スタブの発注画面に合わせた固定の内容をここに置く。変更はGitの差分で確認する（LOC-8）。
 *
 * 登録：入力欄 item_code・quantity を「自動保存なし」、login_page・order_page をURLとして登録する。
 *      submit_button は登録しない（＝取り消せない）。
 */
final class StubRegistrations
{
    /** @return array<string,mixed> PlanHasher::REQUIRED_INPUTS をすべて含む */
    public static function hashInputs(): array
    {
        return [
            'operations' => (object) ['version' => 1, 'reversible' => ['read_text', 'wait_for', 'write_result', 'open_url(登録)', 'type_text(登録)', 'clear_text(登録)', 'click(登録)', 'login(登録)']],
            'destinations' => (object) ['version' => 1, 'names' => DestinationLimits::NAMES],
            'registrations' => (object) [
                'autosave_free_inputs' => [
                    (object) ['destination' => 'stub', 'target' => 'item_code'],
                    (object) ['destination' => 'stub', 'target' => 'quantity'],
                ],
                'urls' => [
                    (object) ['destination' => 'stub', 'target' => 'login_page'],
                    (object) ['destination' => 'stub', 'target' => 'order_page'],
                ],
            ],
            'screen_record' => (object) ['version' => 1, 'elements' => (object) ['login_page' => 'page', 'order_page' => 'page', 'done_page' => 'page', 'item_code' => 'input', 'quantity' => 'input', 'submit_button' => 'button', 'order_number' => 'text']],
        ];
    }

    /** ドライランで入力データを選ばなかったときに使う、ダミーの行（機密を含めない） */
    public static function dummyRows(): array
    {
        return [
            ['商品コード' => 'TEST-A', '数量' => '10', '伝票番号' => 'DRY-001'],
            ['商品コード' => 'TEST-B', '数量' => '2', '伝票番号' => 'DRY-002'],
        ];
    }
}
