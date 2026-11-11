<?php

declare(strict_types=1);

namespace Src\Browser;

/**
 * Chromeの起動時の遮断の方針（仕様SEC-3、SEC-5、SEC-6。docs/TECH_STACK.md の7章の検証結果による）。
 *
 * 検証の結果、エンジンによる検査（CDPのFetch）だけでは、新しいウィンドウ・WebSocket・Service Worker を止められなかった。
 * 一方、Chromeの起動オプションによる遮断（名前解決の失敗＋使えないプロキシ）は、15種類の攻撃を、すべて遮断できた。
 * このクラスは、許可した接続先（スキーム・ホスト・ポートの完全一致）だけに通信できるよう、起動オプションを作る。
 * 呼び出し側は、オプションを選べない（許可する接続先だけを渡す）。
 * 注意：これは「Chrome自身の設定」。起動オプションだけでは、プロキシを通らないUDP（WebRTCなど）を、完全には止められない可能性がある（--force-webrtc-ip-handling-policy の効果は、テストで確認する）。ネットワーク側の制限（SEC-6：プロキシ、ファイアウォール）の代わりにはならない。
 */
final class ChromeLaunchPolicy
{
    /** @var list<array{scheme:string, host:string, port:int}> */
    private array $origins = [];

    /** @param list<string> $allowedOrigins 例：'http://localhost:8081'。ワイルドカードや、パス・問い合わせ文字列は、使えない */
    public function __construct(array $allowedOrigins)
    {
        if ($allowedOrigins === []) {
            throw new \InvalidArgumentException('許可する接続先が、1つもありません');
        }
        foreach ($allowedOrigins as $o) {
            $u = is_string($o) ? parse_url($o) : false;
            if ($u === false || ! in_array($u['scheme'] ?? '', ['http', 'https'], true) || ! isset($u['host'])
                || isset($u['path']) || isset($u['query']) || isset($u['fragment']) || isset($u['user']) || str_contains((string) $o, '*')
                || ! preg_match('/\A[A-Za-z0-9.\-]+\z/', $u['host'])) {
                throw new \InvalidArgumentException("許可する接続先は、スキーム・ホスト・ポートだけで指定してください: {$o}");
            }
            $port = $u['port'] ?? ($u['scheme'] === 'https' ? 443 : 80);
            $this->origins[] = ['scheme' => $u['scheme'], 'host' => strtolower($u['host']), 'port' => $port];
        }
    }

    /** スキーム・ホスト・ポートの完全一致（前方一致は使わない。SEC-5） */
    public function allows(string $url): bool
    {
        $u = parse_url($url);
        if ($u === false || ! isset($u['scheme'], $u['host'])) {
            return false;
        }
        $scheme = strtolower($u['scheme']);
        $port = $u['port'] ?? ($scheme === 'https' ? 443 : 80);
        foreach ($this->origins as $o) {
            if ($o['scheme'] === $scheme && $o['host'] === strtolower($u['host']) && $o['port'] === $port && ! isset($u['user'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> Chromeの起動オプション（chrome-php の customFlags に渡す） */
    public function flags(): array
    {
        $hosts = array_values(array_unique(array_map(static fn (array $o): string => $o['host'], $this->origins)));
        $bypass = array_map(static fn (array $o): string => $o['host'].':'.$o['port'], $this->origins);

        return [
            // 名前解決：許可したホスト以外は、解決に失敗させる（IPアドレスの直接指定も、TCPは遮断される。検証済み。UDPは別。下のWebRTCを参照）
            '--host-resolver-rules=MAP * ~NOTFOUND, '.implode(', ', array_map(static fn (string $h): string => 'EXCLUDE '.$h, $hosts)),
            // 通信：すべてを、使えないプロキシへ向け、許可した接続先（ホスト:ポート）だけを迂回する。<-loopback> で、ループバックの暗黙の迂回を外す
            '--proxy-server=http://127.0.0.1:1',
            '--proxy-bypass-list=<-loopback>;'.implode(';', $bypass),
            // WebRTC：プロキシを通らないUDP（STUN・TURNなど）を、禁止する。プロキシも名前解決の遮断も、UDPには効かない（検証で、外へ届いた）
            '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
            // デバッグ接続：既定の「どのOriginも許可（*）」を、上書きして、chrome-php自身のOriginだけにする（検証済み）
            '--remote-allow-origins=http://127.0.0.1',
            // 拡張機能・自動更新・テレメトリなどを、無効にする（SEC-3。効果の確認は、未検証）
            '--disable-extensions',
            '--disable-default-apps',
            '--disable-component-update',
            '--disable-breakpad',
            '--disable-domain-reliability',
            '--no-pings',
        ];
    }
}
