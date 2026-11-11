<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 仕様14-3：src/ はフレームワークに依存しない素のPHPで書く。
 * Chromeの部品（HeadlessChromium）を使うのは src/Browser の中だけ。
 */
class ArchitectureTest extends TestCase
{
    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        if (! is_dir($dir)) {
            return $files;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }

    public function test_src_にIlluminateが出てこない(): void
    {
        foreach ($this->phpFiles(dirname(__DIR__, 2).'/src') as $file) {
            $this->assertStringNotContainsString('Illuminate', file_get_contents($file), $file);
        }
    }

    public function test_HeadlessChromiumはsrc_Browserの中だけ(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (array_merge($this->phpFiles($root.'/src'), $this->phpFiles($root.'/app')) as $file) {
            if (str_contains($file, '/src/Browser/')) {
                continue;
            }
            $this->assertStringNotContainsString('HeadlessChromium', file_get_contents($file), $file);
        }
    }

    public function test_取り消せない操作の実装はGuardedActionの継承に限る(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (array_merge($this->phpFiles($root.'/src'), $this->phpFiles($root.'/app')) as $file) {
            $code = file_get_contents($file);
            if (str_ends_with($file, '/Guard/IrreversibleAction.php') || str_ends_with($file, '/Guard/GuardedAction.php')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/implements\s+[^{]*IrreversibleAction/', $code, $file);
        }
    }

    public function test_executeはGuardedActionの中でだけ呼ぶ(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (array_merge($this->phpFiles($root.'/src'), $this->phpFiles($root.'/app')) as $file) {
            if (str_ends_with($file, '/Guard/GuardedAction.php')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/->execute\s*\(\s*\$(proof|permit|redeemed|forged)/i', file_get_contents($file), $file);
        }
    }

    public function test_srcでリフレクションによる生成をしない(): void
    {
        foreach ($this->phpFiles(dirname(__DIR__, 2).'/src') as $file) {
            $code = file_get_contents($file);
            $this->assertStringNotContainsString('newInstanceWithoutConstructor', $code, $file);
            $this->assertStringNotContainsString('ReflectionClass', $code, $file);
            $this->assertStringNotContainsString('Closure::bind', $code, $file);
        }
    }

    public function test_画面からシェルやプロセスを起動しない(): void
    {
        // 14-3の3：画面から、シェルのコマンドを組み立てて起動しない。ワーカーは、担当者がコンソールで起動する
        $root = dirname(__DIR__, 2);
        foreach ($this->phpFiles($root.'/app/Http') as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\b(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\(/', $code, $file);
            $this->assertStringNotContainsString('Artisan::call', $code, $file);
            $this->assertStringNotContainsString('Symfony\\Component\\Process', $code, $file);
            $this->assertStringNotContainsString('Process::', $code, $file);
        }
    }

    public function test_画面にインラインのスクリプトやスタイルや外部の読み込みがない(): void
    {
        // CSPで禁止している（LOC-2）。外部のJavaScriptライブラリは、必要になるまで入れない
        foreach ($this->phpFiles(dirname(__DIR__, 2).'/resources/views') as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/<script|style=|onclick=|onload=|javascript:|https?:\/\//i', $code, $file);
        }
        foreach (glob(dirname(__DIR__, 2).'/resources/views/**/*.blade.php') ?: [] as $f) {
            $this->assertDoesNotMatchRegularExpression('/<script|style=|https?:\/\//i', file_get_contents($f), $f);
        }
    }

    public function test_画面で値をエスケープなしで出さない(): void
    {
        foreach ($this->phpFiles(dirname(__DIR__, 2).'/resources/views') as $file) {
            $this->assertStringNotContainsString('{!!', file_get_contents($file), $file);
        }
    }
}
