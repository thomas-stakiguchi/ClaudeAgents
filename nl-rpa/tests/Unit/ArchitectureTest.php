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
}
