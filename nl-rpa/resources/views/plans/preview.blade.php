@extends('layout')
@section('title', 'プレビュー')
@section('body')
<p><a href="/plans/{{ $p['hash'] }}">← 実行計画</a></p>
<h1>実行前のプレビュー</h1>
<div class="card">
  <strong>業務キーの列：「{{ $keyColumn }}」</strong>（この列の値で、二重実行を防ぎます）<br>
  入力データ：番号{{ $set['id'] }}（<code>{{ $set['sha256'] }}</code>）<br>
  <strong>件数：{{ $sum['rows'] }}</strong>（上限 {{ $limits->max_rows_per_run ?? '—' }}）<br>
  <strong>数量の合計：{{ $sum['quantity'] }}</strong>（上限 {{ $limits->max_quantity_per_run ?? '—' }}）<br>
  <strong>金額の合計：{{ $sum['amount'] }}</strong>（上限 {{ $limits->max_amount_per_run ?? '—' }}）
</div>
<p>この内容でよければ、<strong>件数を数字で入力</strong>して、登録してください。上限を超える場合や、入力データに不正な行がある場合は、登録できません。登録しても、実行は始まりません。</p>
<form method="post" action="/plans/{{ $p['hash'] }}/register" class="card">
  @csrf
  <input type="hidden" name="input_set_id" value="{{ $set['id'] }}">
  <input type="hidden" name="dry_run_id" value="{{ $dryRunId }}">
  件数：<input type="text" name="count" size="8" autocomplete="off" required>
  お名前：<input type="text" name="name" maxlength="50" required>
  <button type="submit">実行を登録する</button>
</form>
<h2>入力データの先頭（最大10行）</h2>
<table>
@php($cols = array_keys($set['rows'][0] ?? []))
<tr>@foreach ($cols as $c)<th>{{ $c }}</th>@endforeach</tr>
@foreach (array_slice($set['rows'], 0, 10) as $r)<tr>@foreach ($cols as $c)<td>{{ $r[$c] ?? '' }}</td>@endforeach</tr>@endforeach
</table>
@endsection
