# 通信遮断の事前検証（仕様14-6）

結果と結論は、`docs/TECH_STACK.md` の7章にあります。

```bash
php tools/verify-network/run.php                 # 通信遮断（A1・A2・B・C と、対照X）。数分かかる
php tools/verify-network/profile_and_debug.php   # 使い捨てプロファイルと、デバッグ接続
python3 tools/verify-network/summarize.py tools/verify-network/result.json   # 結果の要約
```

Chromeは、`--chrome=/path/to/chrome` で指定できます（既定は、`/opt/pw-browsers/chromium-*/chrome-linux64/chrome`）。
偽のサーバーは、127.0.0.1 の 18091・18092 を使います。外部のサイトには、接続しません。
