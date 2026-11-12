@extends('layout')
@section('title', '計画の差分')
@section('body')
<p><a href="/plans/{{ $p['hash'] }}">← 実行計画</a></p>
<h1>計画の差分</h1>
<div class="card">
  <p>新しい計画：<code>{{ $p['hash'] }}</code>（{{ $p['created_at'] }}、作成者 {{ $p['created_by'] }}）</p>
  @if ($old === null)
    <p>比べる計画が、ありません。</p>
  @else
    <p>比べる計画：<code>{{ $old['hash'] }}</code>（{{ $old['created_at'] }}、作成者 {{ $old['created_by'] }}）
      @if ($oldApproved) <span class="badge good">最終承認済み</span> @else <span class="badge">最終承認なし</span> @endif</p>
  @endif
  <form method="get" action="/plans/{{ $p['hash'] }}/diff">
    比べる相手を変える：
    <select name="against">
      @foreach ($others as $o)<option value="{{ $o['hash'] }}" {{ $old !== null && $old['hash'] === $o['hash'] ? 'selected' : '' }}>{{ substr($o['hash'], 0, 23) }}…（{{ $o['created_at'] }}）</option>@endforeach
    </select>
    <button type="submit">比べる</button>
  </form>
</div>

@if ($old !== null)
  @if (count($changes) === 0)
    <p class="ok">差分は、ありません（中身が同じです）。</p>
  @else
    <p>変更：{{ count($changes) }} 件（うち<strong>重要</strong>：{{ count(array_filter($changes, fn ($c) => $c['important'])) }} 件）。
       <small>「重要」は、操作の種類・対象・接続先・確認条件・読み返し・入力データの列・許可リストなどの変更です。承認済みの版から、重要な変更があるときは、新しい版として、再承認とドライランのやり直しが要ります。</small></p>
    <table>
      <tr><th>種類</th><th>どこ</th><th>前</th><th>後</th><th></th></tr>
      @foreach ($changes as $c)
      <tr class="{{ $c['important'] ? 'irr' : '' }}">
        <td>{{ $c['kind'] }}</td><td>{{ $c['where'] }}</td><td><code>{{ $c['old'] }}</code></td><td><code>{{ $c['new'] }}</code></td>
        <td>@if ($c['important']) <span class="badge bad">重要</span> @endif</td>
      </tr>
      @endforeach
    </table>
  @endif
@endif
@endsection
