<?php

declare(strict_types=1);

namespace App\Support;

/**
 * アクセストークン（LOC-3）。起動ごとに発行し、コンソールに表示する。保存するのは、ハッシュだけ。
 * 再起動で、新しいトークンになり、古いものは無効になる。比較は hash_equals。
 */
final class AccessToken
{
    public function __construct(private readonly string $dataDir)
    {
    }

    private function path(): string
    {
        return $this->dataDir.'/access_token.sha256';
    }

    /** 新しいトークンを発行し、そのハッシュを保存する。トークン本体を返す（コンソールに表示するため） */
    public function issue(): string
    {
        $token = bin2hex(random_bytes(32));
        $tmp = $this->path().'.tmp';
        file_put_contents($tmp, hash('sha256', $token));
        @chmod($tmp, 0600);
        rename($tmp, $this->path());

        return $token;
    }

    /** 保存されているハッシュ。なければ null（まだ起動していない） */
    public function currentHash(): ?string
    {
        $h = @file_get_contents($this->path());

        return is_string($h) && preg_match('/\A[0-9a-f]{64}\z/', $h) ? $h : null;
    }

    public function verify(string $token): bool
    {
        $h = $this->currentHash();

        return $h !== null && hash_equals($h, hash('sha256', $token));
    }
}
