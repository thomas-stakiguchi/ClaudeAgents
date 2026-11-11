@extends('layout')
@section('title', '実行')
@section('body')
<p><a href="/plans/{{ $run['plan_hash'] }}">← 実行計画</a></p>
<h1>実行 {{ $run['run_id'] }}</h1>
<div class="card">
  登録：{{ $run['created_by'] }}／{{ $run['created_at'] }}／ドライラン：{{ $run['dry_run_id'] }}<br>
  接続先：{{ $rec->destination_name }}／件数：{{ count($rows) }}
</div>

<h2>行ごとの状態</h2>
<p><small>成功＝実行できたことを確認済み。要確認＝結果が分からない（人が、対象システムで確認して、判定します）。</small></p>
<table>
<tr><th>#</th><th>業務キー</th>@foreach ($irr as $sid)<th>{{ $sid }}</th>@endforeach</tr>
@foreach ($table as $r)
<tr><td>{{ $r['index'] + 1 }}</td><td>{{ $r['key'] }}</td>
@foreach ($irr as $sid)
 @php($st = $r['states'][$sid])
 <td>
  @if ($st === 'succeeded') <span class="badge good">成功</span>
  @elseif ($st === 'failed') <span class="badge bad">失敗（再実行の対象）</span>
  @elseif ($st === 'processing')
    <span class="badge wait">処理中</span>
    <small>ワーカーが動いている間かもしれません。止まっていることを確かめて、コンソールで <code>php artisan nlrpa:recover</code> を実行すると、「要確認」になります。</small>
  @elseif ($st === 'needs_review')
    <span class="badge wait">要確認</span>
    <form method="post" action="/runs/{{ $run['run_id'] }}/resolve">
      @csrf
      <input type="hidden" name="business_key" value="{{ $r['key'] }}"><input type="hidden" name="step_id" value="{{ $sid }}">
      <select name="verdict"><option value="executed">実行済み</option><option value="not_executed">未実行</option></select>
      <input type="text" name="name" maxlength="50" placeholder="お名前" required size="8"><button type="submit">判定する</button>
    </form>
  @else <span class="badge">未実行</span> @endif
 </td>
@endforeach
</tr>
@endforeach
</table>

<h2>実行</h2>
<div class="card">
  <p>初回は、<strong>1件だけ実行して止まります</strong>。結果を確認して、「初回の1件を確認した」を押すと、残りを実行できます。実行そのものは、コンソールのワーカーが行います（画面からは起動しません）。</p>
  <form method="post" action="/runs/{{ $run['run_id'] }}/start">
    @csrf
    お名前：<input type="text" name="name" maxlength="50" required> <button type="submit">実行の要求を作る</button>
  </form>
  @if (! $firstConfirmed)
  <form method="post" action="/runs/{{ $run['run_id'] }}/confirm-first">
    @csrf
    お名前：<input type="text" name="name" maxlength="50" required> <button type="submit">初回の1件を確認した</button>
  </form>
  @else <p><span class="badge good">初回の1件は確認済みです</span></p> @endif
</div>

@if (count($requests) > 0)
<h2>実行の結果</h2>
@php($lastRows = null)
@foreach ($requests as $q)@if ($lastRows === null && isset($q['result']['rows']) && count($q['result']['rows']) > 0)@php($lastRows = $q['result']['rows'])@endif @endforeach
@if ($lastRows !== null)
<table><tr><th>業務キー</th><th>状態</th><th>画面から読んだ値（発注番号など）</th></tr>
@foreach ($lastRows as $row)<tr><td>{{ $row['business_key'] }}</td><td>{{ $row['status'] }}</td><td>@foreach ($row['reads'] as $k => $v) {{ $k }}：{{ $v }} @endforeach</td></tr>@endforeach</table>
@endif
<h2>実行の記録</h2>
<table><tr><th>要求</th><th>状態</th><th>結果</th><th>理由</th></tr>
@foreach ($requests as $q)
<tr><td>{{ $q['id'] }}</td><td>{{ $q['status'] }}</td><td>{{ $q['result']['status'] ?? '' }}</td><td>{{ implode(' / ', (array) ($q['result']['reasons'] ?? [])) }}</td></tr>
@endforeach</table>
@endif
@endsection
