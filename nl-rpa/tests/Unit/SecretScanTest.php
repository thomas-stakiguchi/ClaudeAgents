<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SecretScanTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2).'/tools/scan-secrets.php';
        $this->dir = sys_get_temp_dir().'/nlrpa_scan_'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        exec('git -C '.escapeshellarg($this->dir).' init -q');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function track(string $name, string $body): void
    {
        file_put_contents($this->dir.'/'.$name, $body);
        exec('git -C '.escapeshellarg($this->dir).' add -f '.escapeshellarg($name));
    }

    public function test_鍵やトークンらしいものを見つける(): void
    {
        $this->track('a.txt', str_repeat('-', 5).'BEGIN RSA PRIVATE KEY'.str_repeat('-', 5)."\n");
        $this->track('b.env', 'token = ghp_'.str_repeat('a', 30)."\n");
        $this->track('c.php', '$pass'.'word = '."'".'abcdefghijklmnop'."'".";\n");
        $hits = \scan($this->dir);
        $this->assertCount(3, $hits, implode(' / ', $hits));
    }

    public function test_普通のファイルは見つけない(): void
    {
        $this->track('ok.txt', "password = 'x'\n通常の文章です。\n");
        $this->assertSame([], \scan($this->dir));
    }

    public function test_このリポジトリには_秘密情報らしいものがない(): void
    {
        $hits = \scan(dirname(__DIR__, 2));
        $this->assertSame([], $hits, implode(' / ', $hits));
    }
}
