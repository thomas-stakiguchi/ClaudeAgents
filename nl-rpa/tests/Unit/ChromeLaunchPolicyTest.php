<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Src\Browser\ChromeLaunchPolicy;

class ChromeLaunchPolicyTest extends TestCase
{
    public function test_完全一致だけを許可する(): void
    {
        $p = new ChromeLaunchPolicy(['http://localhost:8081', 'https://app.example.com']);
        $this->assertTrue($p->allows('http://localhost:8081/x?y=1'));
        $this->assertTrue($p->allows('https://app.example.com/a'));
        $this->assertTrue($p->allows('https://APP.example.com:443/a'));
        foreach ([
            'http://localhost:8082/', 'http://localhost/', 'https://localhost:8081/', 'http://localhost:8081.evil.example/',
            'http://evil.example/?http://localhost:8081', 'http://user@localhost:8081/', 'http://localhost:8081@evil.example/',
            'http://app.example.com/', 'https://app.example.com.evil.example/', 'ftp://localhost:8081/', 'data:text/html,x', 'about:blank', '',
        ] as $bad) {
            $this->assertFalse($p->allows($bad), $bad);
        }
    }

    public function test_許可する接続先の書き方が不正なら拒否(): void
    {
        foreach ([[], ['*'], ['http://*.example.com'], ['http://a.example/path'], ['http://a.example/?q=1'], ['localhost:8081'], ['ftp://a.example'], ['http://u:p@a.example'], ['http://a b.example'], [123]] as $bad) {
            $thrown = false;
            try {
                new ChromeLaunchPolicy($bad);
            } catch (\InvalidArgumentException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, json_encode($bad));
        }
    }

    public function test_起動オプション(): void
    {
        $f = (new ChromeLaunchPolicy(['http://localhost:8081', 'https://app.example.com']))->flags();
        $this->assertContains('--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE app.example.com', $f);
        $this->assertContains('--proxy-server=http://127.0.0.1:1', $f);
        $this->assertContains('--proxy-bypass-list=<-loopback>;localhost:8081;app.example.com:443', $f);
        $this->assertContains('--remote-allow-origins=http://127.0.0.1', $f);
        $this->assertNotContains('--remote-allow-origins=*', $f);
        $this->assertContains('--disable-extensions', $f);
    }
}
