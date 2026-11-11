@extends('layout')
@section('title', '実行計画')
@section('body')
<p><a href="/">← ホーム</a></p>
<h1>実行計画</h1>
<div class="card">
  計画ハッシュ：<code>{{ $p['hash'] }}</code><br>
  作成者：{{ $p['created_by'] }}（OS：{{ $p['os_user'] }}）／{{ $p['created_at'] }}<br>
  AI：{{ $p['plan']->created_by_ai->model }}（プロンプト版 {{ $p['plan']->created_by_ai->prompt_version }}）<br>
  状態：
  @if ($finalApproved) <span class="badge good">最終承認済み</span>
  @elseif ($planApproved) <span class="badge wait">計画を承認済み（ドライラン待ち）</span>
  @else <span class="badge">未承認</span> @endif
  @if (isset($p['plan']->unresolved) && count($p['plan']->unresolved) > 0)
    <p class="err">「未確定」が残っています。実行できません：@foreach ($p['plan']->unresolved as $u) 「{{ $u }}」 @endforeach</p>
  @endif
</div>

<h2>シナリオ（元の文）</h2>
<div class="card"><pre>{{ $p['scenario'] }}</pre></div>

<h2>手順（実行エンジンが、計画から機械的に作った説明）</h2>
<p><small>赤い行は、<strong>取り消せない操作</strong>です（実行エンジンが計算します。印は、外せません）。「AIが補った」は、シナリオに根拠がない手順です。AIが書いた説明は、参考として右端に出しています。</small></p>
<table>
<tr><th>ID</th><th>操作</th><th>接続先</th><th>対象</th><th>値</th><th>確認条件</th><th>読み返し照合</th><th>取り消し</th><th>根拠のシナリオ</th><th>AIの説明（参考）</th></tr>
@foreach ($steps as $s)
<tr class="{{ $s['reversible'] === false ? 'irr' : '' }}">
  <td>{{ $s['id'] }}</td>
  <td><span class="indent{{ min($s['depth'], 4) }}">{{ $s['action'] }}</span></td>
  <td>{{ $s['destination'] }}</td><td>{{ $s['target'] }}</td><td>{{ $s['value'] }}</td><td>{{ $s['verify'] }}</td><td>{{ $s['readback'] }}</td>
  <td class="rev">@if ($s['reversible'] === null) — @elseif ($s['reversible']) 可 @else 不可 @endif</td>
  <td>@if ($s['ai_supplied']) <span class="ai">AIが補った</span> @elseif ($s['source']) {{ $s['source'] }} @endif</td>
  <td><small>{{ $s['ai_label'] }}</small></td>
</tr>
@endforeach
</table>

<h2>2. 計画の承認</h2>
<div class="card">
@if ($planApproved) <p>承認済みです。</p> @else
  <form method="post" action="/plans/{{ $p['hash'] }}/approve-plan">
    @csrf
    @if (count($aiSupplied) > 0)
      <p class="warn">次の取り消せない手順は、シナリオに根拠がなく、AIが補ったものです。1つずつ、内容を確かめてチェックしてください。</p>
      @foreach ($aiSupplied as $id) <label><input type="checkbox" name="ack[]" value="{{ $id }}"> {{ $id }} を確認した</label><br> @endforeach
    @endif
    <p>お名前：<input type="text" name="name" maxlength="50" required> <button type="submit">計画を承認する</button></p>
  </form>
@endif
</div>

<h2>3. ドライラン</h2>
<div class="card">
  <p>取り消せない操作の手前まで実行します。実行そのものは、コンソールのワーカーが行います（画面からは起動しません）。</p>
  @if ($planApproved)
  <form method="post" action="/plans/{{ $p['hash'] }}/dry-run">
    @csrf
    入力データ：<select name="input_set_id"><option value="">（ダミーのデータを使う）</option>@foreach ($inputSets as $s)<option value="{{ $s['id'] }}">番号{{ $s['id'] }}（{{ $s['count'] }}行）</option>@endforeach</select>
    お名前：<input type="text" name="name" maxlength="50" required> <button type="submit">ドライランの要求を作る</button>
  </form>
  @else <p>先に、計画を承認してください。</p> @endif
  @if (count($dryRuns) > 0)
  <table><tr><th>ドライランID</th><th>結果</th><th>環境</th><th>理由</th></tr>
  @foreach ($dryRuns as $d)<tr><td>{{ $d['dry_run_id'] }}</td><td>@if ($d['result'] === 'passed') <span class="badge good">合格</span> @else <span class="badge bad">不合格</span> @endif</td><td>{{ $d['environment'] ?? '' }}</td><td>{{ implode(' / ', (array) ($d['reasons'] ?? [])) }}</td></tr>@endforeach</table>
  @endif
  @if (count($requests) > 0)
  <table><tr><th>要求</th><th>種類</th><th>状態</th><th>結果</th></tr>
  @foreach ($requests as $q)<tr><td>{{ $q['id'] }}</td><td>{{ $q['kind'] }}</td><td>{{ $q['status'] }}</td><td>@if ($q['result_json']) {{ json_decode($q['result_json'], true)['status'] ?? '' }} @endif</td></tr>@endforeach</table>
  @endif
</div>

<h2>4. 最終承認</h2>
<div class="card">
@if ($finalApproved) <p>最終承認済みです。</p> @elseif (count($passedDryRuns) === 0) <p>合格したドライランがありません。</p> @else
  <p>ドライランの結果を見たうえで、承認します。第1段階はテスト環境のみのため、作成者が承認してもかまいません（記録に残ります）。本番の接続先では、別の人の承認が必須になります。</p>
  <form method="post" action="/plans/{{ $p['hash'] }}/approve-final">
    @csrf
    ドライラン：<select name="dry_run_id">@foreach ($passedDryRuns as $d)<option value="{{ $d['dry_run_id'] }}">{{ $d['dry_run_id'] }}</option>@endforeach</select>
    お名前：<input type="text" name="name" maxlength="50" required> <button type="submit">最終承認する</button>
  </form>
@endif
</div>

<h2>5. 実行の登録（プレビュー）</h2>
<div class="card">
@if ($finalApproved && count($passedDryRuns) > 0 && count($inputSets) > 0)
  <form method="get" action="/plans/{{ $p['hash'] }}/preview">
    入力データ：<select name="input_set_id">@foreach ($inputSets as $s)<option value="{{ $s['id'] }}">番号{{ $s['id'] }}（{{ $s['count'] }}行）</option>@endforeach</select>
    ドライラン：<select name="dry_run_id">@foreach ($passedDryRuns as $d)<option value="{{ $d['dry_run_id'] }}">{{ $d['dry_run_id'] }}</option>@endforeach</select>
    <button type="submit">プレビューを見る</button>
  </form>
@else <p>最終承認と、入力データが必要です。</p> @endif
  @if (count($runs) > 0)
  <table><tr><th>実行ID</th><th>登録日時</th></tr>@foreach ($runs as $r)<tr><td><a href="/runs/{{ $r['run_id'] }}">{{ $r['run_id'] }}</a></td><td>{{ $r['created_at'] }}</td></tr>@endforeach</table>
  @endif
</div>
@endsection
