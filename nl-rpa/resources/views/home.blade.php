@extends('layout')
@section('title', 'ホーム')
@section('body')
<h1>ホーム</h1>

@if ($kill) <p class="err">キルスイッチが発動しています。すべての実行が止まっています（解除は、コンソールで行います）。</p> @endif
<div class="card">
  <strong>停止の旗：</strong>
  @if ($stopFlag) <span class="badge bad">立っています</span> @else <span class="badge good">下りています</span> @endif
  <small>（実行中の手順が終わった時点で止まります。すぐに止めたいときは、コンソールで <code>php artisan nlrpa:emergency-stop</code>）</small>
  <form method="post" action="{{ $stopFlag ? '/stop/clear' : '/stop' }}">
    @csrf
    お名前：<input type="text" name="name" maxlength="50" required>
    <button type="submit" class="{{ $stopFlag ? '' : 'danger' }}">{{ $stopFlag ? '停止の旗を下ろす' : '停止する' }}</button>
  </form>
</div>

<h2>1. 実行計画を作る</h2>
<div class="card">
  <p>手順を日本語で書いて、「実行計画を作る」を押します。1a（スタブ）のAIは、固定の計画を返します。<strong>顧客名や単価などの機密は、書かないでください。</strong></p>
  <form method="post" action="/plans">
    @csrf
    <textarea name="scenario" maxlength="4000" required>{{ old('scenario', $defaultScenario) }}</textarea>
    <p>お名前：<input type="text" name="name" maxlength="50" required> <button type="submit">実行計画を作る</button></p>
  </form>
</div>

<h2>実行計画の一覧</h2>
@if (count($plans) === 0) <p>まだありません。</p> @else
<table><tr><th>計画ハッシュ</th><th>作成者</th><th>作成日時</th><th>承認</th></tr>
@foreach ($plans as $p)
<tr><td><a href="/plans/{{ $p['hash'] }}"><code>{{ substr($p['hash'], 0, 23) }}…</code></a></td><td>{{ $p['created_by'] }}</td><td>{{ $p['created_at'] }}</td>
<td>@if ($p['final_approved']) <span class="badge good">最終承認済み</span> @elseif ($p['plan_approved']) <span class="badge wait">計画を承認済み</span> @else <span class="badge">未承認</span> @endif</td></tr>
@endforeach</table>
@endif

<h2>入力データ（CSV）</h2>
<div class="card">
  <p>1行目は列名。UTF-8で、1MBまで。読み込んだ時点の内容を固定します。</p>
  <form method="post" action="/inputs" enctype="multipart/form-data">
    @csrf
    <input type="file" name="csv" accept=".csv,text/csv" required>
    お名前：<input type="text" name="name" maxlength="50" required>
    <button type="submit">取り込む</button>
  </form>
</div>
@if (count($inputSets) > 0)
<table><tr><th>番号</th><th>行数</th><th>ハッシュ</th><th>取り込んだ人</th><th>日時</th></tr>
@foreach ($inputSets as $s)
<tr><td>{{ $s['id'] }}</td><td>{{ $s['count'] }}</td><td><code>{{ substr($s['sha256'], 0, 23) }}…</code></td><td>{{ $s['created_by'] }}</td><td>{{ $s['created_at'] }}</td></tr>
@endforeach</table>
@endif
@endsection
