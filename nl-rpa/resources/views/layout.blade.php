<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', '自然言語RPA')（ローカルのテスト用）</title>
<link rel="stylesheet" href="/nlrpa.css">
</head>
<body>
<header>
  <a href="/">自然言語RPA（ローカルのテスト用）</a>
  @if (session('nlrpa_token_hash'))
  <form method="post" action="/logout">@csrf <button type="submit">ログアウト</button></form>
  @endif
</header>
<main>
  <div class="warn">
    この画面は、あなたのPCの中だけで動く、テスト用です。接続できるのは、スタブ（偽の画面）だけです。ローカルでは、次の安全の条件を満たせません（LOC-8）。詳しくは README を見てください。
    <ul>
      <li><b>記録の保護（SEC-2）</b>：記録（承認、状態、ログ）は、実行者が書き換えられる場所にあります。チェーンの検証で見つかるのは、途中の改ざんだけです。末尾の記録を消されても、見つけられません。</li>
      <li><b>ネットワーク側の通信制限（SEC-6）</b>：未実施です。通信の制限は、Chrome自身の設定だけで行っています。</li>
      <li><b>専用のOSアカウント（SEC-10）</b>：使っていません。この画面とワーカーは、あなたのOSアカウントで動きます。</li>
    </ul>
  </div>
  @if (session('ok')) <p class="ok">{{ session('ok') }}</p> @endif
  @if ($errors->any())
    <div class="err">@foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach</div>
  @endif
  @yield('body')
</main>
</body>
</html>
