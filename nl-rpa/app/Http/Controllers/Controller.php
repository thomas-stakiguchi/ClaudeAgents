<?php

namespace App\Http\Controllers;

use App\Support\Engine;
use Illuminate\Http\Request;

abstract class Controller
{
    public function __construct(protected Engine $engine)
    {
    }

    /** 操作者の名前（承認者・確認者・判定者）。画面で入力する（自己申告。LOC-3）。OSのユーザー名とは別に記録する */
    protected function operator(Request $request): string
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/\A[^\p{C}\p{Z}][^\p{C}]*\z/u'],
        ], [], ['name' => 'お名前'])['name'];
    }

    protected function osUser(): string
    {
        return Engine::osUser();
    }

    /** 文字列（と整数）の入力だけを受け取る。配列などで送られたら、空として扱う（500にしない）。 */
    protected function str(Request $request, string $key): string
    {
        $v = $request->input($key, '');

        return is_string($v) ? $v : (is_int($v) ? (string) $v : '');
    }

    /**
     * 追記のみの記録（SEC-13）。いつ・誰が・どのOSユーザー・どのPCで、を必ず入れる。
     *
     * @param array<string,mixed> $payload
     */
    protected function audit(string $kind, string $subject, string $by, array $payload = []): void
    {
        $this->engine->ledger->append($kind, $subject, (object) ($payload + ['by' => $by, 'os_user' => $this->osUser(), 'host' => gethostname() ?: 'unknown']));
    }
}
