{{-- 手順ごとの結果（確認条件の合否）。$rows：worker の結果の rows。$limit：表示する行数の上限 --}}
@php($shown = array_slice($rows, 0, $limit))
@foreach ($shown as $row)
  <h3>業務キー {{ $row['business_key'] }}（{{ $row['status'] }}）</h3>
  @if (empty($row['steps'])) <p><small>手順ごとの結果は、記録されていません。</small></p> @else
  <table>
    <tr><th>手順</th><th>操作</th><th>確認条件</th><th>合否</th><th>備考</th></tr>
    @foreach ($row['steps'] as $st)
    <tr>
      <td>{{ $st['id'] }}</td><td>{{ $st['action'] }}</td><td>{{ $st['verify'] }}</td>
      <td>@if ($st['ok']) <span class="badge good">合格</span> @else <span class="badge bad">不合格</span> @endif</td>
      <td>{{ $st['note'] }}</td>
    </tr>
    @endforeach
  </table>
  @endif
@endforeach
@if (count($rows) > $limit) <p><small>ほか {{ count($rows) - $limit }} 行は、省略しています。</small></p> @endif
