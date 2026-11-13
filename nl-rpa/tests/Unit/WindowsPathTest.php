<?php

namespace Tests\Unit;

use App\Support\Engine;
use PHPUnit\Framework\TestCase;

/** Windows形式のパスの判定。文字列だけで決まる部分を、Linuxでも確かめる（実在しないパスで試す） */
class WindowsPathTest extends TestCase
{
    public function test_Windowsの絶対パスを整理する(): void
    {
        $this->assertSame('C:/nlrpa/data', Engine::resolvePath('C:\\nlrpa\\data'));
        $this->assertSame('C:/nlrpa/data', Engine::resolvePath('c:/nlrpa//data/./'));
        $this->assertSame('D:/a', Engine::resolvePath('d:\\x\\..\\a'));
    }

    public function test_相対パスとUNCは受け付けない(): void
    {
        foreach (['data', '.\\data', '..\\data', 'C:data', '\\\\server\\share\\x', ''] as $bad) {
            $this->assertNull(Engine::resolvePath($bad), $bad);
        }
    }

    public function test_リポジトリの中かの判定(): void
    {
        $repo = 'C:\\Users\\me\\ClaudeAgents\\nl-rpa';
        $this->assertTrue(Engine::isInside('C:\\Users\\me\\ClaudeAgents\\nl-rpa\\data', $repo));
        $this->assertTrue(Engine::isInside('c:/users/ME/claudeagents/NL-RPA', $repo), '大文字小文字は区別しない');
        $this->assertTrue(Engine::isInside('C:\\Users\\me\\ClaudeAgents\\nl-rpa\\..\\nl-rpa\\x', $repo), '..を整理してから判定する');
        $this->assertFalse(Engine::isInside('C:\\Users\\me\\nlrpa-data', $repo));
        $this->assertFalse(Engine::isInside('C:\\Users\\me\\ClaudeAgents\\nl-rpa2', $repo), '前方一致だけでは、中とみなさない');
        $this->assertFalse(Engine::isInside('D:\\Users\\me\\ClaudeAgents\\nl-rpa', $repo), 'ドライブが違えば、外');
    }

    public function test_データの置き場は_Windowsの相対パスを拒否する(): void
    {
        $this->expectException(\RuntimeException::class);
        Engine::checkedDataDir('data\\x', sys_get_temp_dir());
    }
}
