<?php

declare(strict_types=1);

/**
 * 秘密情報の簡易検査（SEC-11、14-6）。gitで管理しているファイルに、鍵・トークンらしい文字列が入っていないかを調べる。
 * 暫定の道具です。専用のツール（gitleaks など）を選ぶまでの間、これを使う（選定は、docs/付録A_確定.md の「決めること」）。
 * 使い方：php tools/scan-secrets.php   （見つかれば、終了コード 1）
 * 試験（tests/Unit/SecretScanTest.php）からも、実行する。
 */
const PATTERNS = [
    '秘密鍵' => '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----/',
    'AWSのアクセスキー' => '/\bAKIA[0-9A-Z]{16}\b/',
    'GitHubのトークン' => '/\b(?:ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{20,}\b/',
    'AnthropicなどのAPIキー' => '/\bsk-(?:ant-)?[A-Za-z0-9_\-]{24,}\b/',
    'Slackのトークン' => '/\bxox[abprs]-[A-Za-z0-9\-]{10,}\b/',
    '値つきの秘密の代入' => '/(?i)\b(?:password|passwd|secret|api[_-]?key|access[_-]?token)\b\s*[=:]\s*[\'"][^\'"\s]{12,}[\'"]/',
];

/** @return list<string> 見つかった場所（ファイル:行 種類） */
function scan(string $root): array
{
    $files = [];
    exec('git -C '.escapeshellarg($root).' ls-files -z', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException('git ls-files に失敗しました');
    }
    foreach (explode("\0", implode("\n", $out)) as $f) {
        $f = trim($f, "\n");
        $f !== '' && is_file($root.'/'.$f) && $files[] = $f;
    }
    $hits = [];
    foreach ($files as $f) {
        if (preg_match('#(^|/)(vendor|node_modules)/|\.(png|jpg|gif|ico|woff2?|lock)$#', $f) || filesize($root.'/'.$f) > 2_000_000) {
            continue;
        }
        $lines = @file($root.'/'.$f, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $n => $line) {
            foreach (PATTERNS as $name => $re) {
                if (preg_match($re, $line) === 1) {
                    $hits[] = "{$f}:".($n + 1)." {$name}";
                }
            }
        }
    }

    return $hits;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $hits = scan($root);
    foreach ($hits as $h) {
        fwrite(STDERR, $h."\n");
    }
    echo $hits === [] ? "見つかりませんでした\n" : count($hits)." 件、見つかりました\n";
    exit($hits === [] ? 0 : 1);
}
