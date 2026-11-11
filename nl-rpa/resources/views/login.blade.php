@extends('layout')
@section('title', 'ログイン')
@section('body')
<h1>アクセストークンを入力</h1>
<div class="card">
  <p>起動したときに、コンソールに表示されたアクセストークンを入力してください。再起動すると、トークンは変わります。</p>
  <form method="post" action="/login">
    @csrf
    <input type="password" name="token" size="70" autocomplete="off" autofocus>
    <button type="submit">入る</button>
  </form>
</div>
@endsection
