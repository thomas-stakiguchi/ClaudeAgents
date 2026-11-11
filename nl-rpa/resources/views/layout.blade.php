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
  <div class="warn">この画面は、あなたのPCの中だけで動く、テスト用です。接続できるのは、スタブ（偽の画面）だけです。ローカルでは満たせない安全の条件（記録の保護、専用のOSアカウントなど）があります。詳しくは README を見てください。</div>
  @if (session('ok')) <p class="ok">{{ session('ok') }}</p> @endif
  @if ($errors->any())
    <div class="err">@foreach ($errors->all() as $e) <div>{{ $e }}</div> @endforeach</div>
  @endif
  @yield('body')
</main>
</body>
</html>
