<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log triage — Jev classifier</title>
<style>
  :root {
    --bg: #0e1013; --panel: #15181d; --line: #232830; --ink: #d7dce3;
    --dim: #767f8c; --hot: #e0533f; --warm: #d08b2c; --cool: #3f7f6e; --key: #7aa2d6;
    --mono: ui-monospace, "SF Mono", Menlo, monospace;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; background: var(--bg); color: var(--ink);
    font: 14px/1.5 var(--mono); padding: 40px 28px 80px;
  }
  .wrap { max-width: 1040px; margin: 0 auto; }
  h1 { font-size: 17px; font-weight: 600; letter-spacing: .01em; margin: 0 0 4px; }
  .sub { color: var(--dim); margin: 0 0 28px; max-width: 62ch; line-height: 1.6; }
  .bar {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
    background: var(--panel); border: 1px solid var(--line);
    padding: 14px 16px; margin-bottom: 8px;
  }
  button {
    font: inherit; background: #1f2530; color: var(--ink);
    border: 1px solid var(--line); padding: 7px 13px; cursor: pointer;
  }
  button[data-on="true"] { background: var(--key); border-color: var(--key); color: #0e1013; font-weight: 600; }
  button:hover { border-color: var(--key); }
  .meta { color: var(--dim); font-size: 12px; margin: 0 0 26px; }
  .meta b { color: var(--ink); font-weight: 600; }
  table { width: 100%; border-collapse: collapse; }
  th {
    text-align: left; font-size: 11px; text-transform: uppercase;
    letter-spacing: .09em; color: var(--dim); font-weight: 500;
    padding: 0 10px 9px; border-bottom: 1px solid var(--line);
  }
  td { padding: 11px 10px; border-bottom: 1px solid #1a1e24; vertical-align: top; }
  tr:hover td { background: #131720; }
  .score { font-variant-numeric: tabular-nums; font-weight: 600; width: 46px; }
  .meter { width: 74px; }
  .meter i { display: block; height: 3px; background: var(--cool); }
  .lvl { font-size: 11px; letter-spacing: .05em; color: var(--dim); width: 52px; }
  .svc { color: var(--dim); width: 78px; font-size: 12px; }
  .msg { color: var(--ink); }
  .msg small { display: block; color: var(--dim); margin-top: 3px; font-size: 11.5px; }
  .owner { width: 96px; font-size: 12px; }
  .owner em { font-style: normal; color: var(--key); }
  .owner span { display: block; color: var(--dim); font-size: 11px; }
  .flag { color: var(--warm); font-size: 11px; }
  .hot .score { color: var(--hot); } .hot .meter i { background: var(--hot); }
  .warm .score { color: var(--warm); } .warm .meter i { background: var(--warm); }
  .empty { color: var(--dim); padding: 40px 0; }
  .err { color: var(--hot); margin-bottom: 16px; }
  footer { margin-top: 34px; color: var(--dim); font-size: 12px; line-height: 1.8; }
  footer code { color: var(--ink); }
</style>
</head>
<body>
<div class="wrap">

  <h1>Log triage</h1>
  <p class="sub">
    Twenty log lines, unchanged between runs. What changes is the situation they landed in —
    and with it, which of them is worth a human. Level is a column; this is a judgement.
  </p>

  @error('jev')<p class="err">{{ $message }}</p>@enderror

  <form method="POST" action="{{ route('triage.classify') }}" class="bar">
    @csrf
    @foreach ($situations as $key => $config)
      <button name="situation" value="{{ $key }}" data-on="{{ $key === $situation ? 'true' : 'false' }}">
        {{ $config['label'] }}
      </button>
    @endforeach
  </form>

  @if ($lastRun)
    <p class="meta">
      <b>{{ $lastRun['records'] }}</b> records ·
      <b>{{ $lastRun['questions'] }}</b> judgements ·
      <b>{{ number_format($lastRun['tokens']) }}</b> input tokens ·
      <b>${{ number_format($lastRun['tokens'] / 1000000 * 0.042, 5) }}</b> ·
      <b>{{ $lastRun['ms'] }}ms</b> wall
    </p>
  @endif

  @php $ranked = $events->sortByDesc(fn ($e) => $e->judgement('urgency')?->number ?? -1); @endphp

  @if ($ranked->first()?->judgement('urgency') === null)
    <p class="empty">Nothing classified yet. Pick a situation above.</p>
  @else
  <table>
    <thead>
      <tr><th colspan="2">Urgency</th><th>Level</th><th>Service</th><th>Event</th><th>Owner</th></tr>
    </thead>
    <tbody>
      @foreach ($ranked as $event)
        @php
          $urgency = $event->judgement('urgency');
          $owner = $event->judgement('owner');
          $customer = $event->judgement('customer_facing');
          $score = $urgency?->number ?? 0;
          $band = $score >= 2.5 ? 'hot' : ($score >= 1.8 ? 'warm' : '');
        @endphp
        <tr class="{{ $band }}">
          <td class="score">{{ number_format($score, 2) }}</td>
          <td class="meter"><i style="width: {{ round($score / 3 * 100) }}%"></i></td>
          <td class="lvl">{{ $event->level }}</td>
          <td class="svc">{{ $event->service }}</td>
          <td class="msg">
            {{ $event->message }}
            <small>
              {{ $urgency?->value }}
              @if ($customer && $customer->number >= 0.7) · <span class="flag">customer would notice</span> @endif
            </small>
          </td>
          <td class="owner">
            <em>{{ $owner?->value }}</em>
            <span>
              @if ($owner && ! $owner->isConfident())
                unsure — {{ $owner->runnerUp()['option'] ?? '?' }} close behind
              @else
                confident
              @endif
            </span>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <footer>
    Every row above came from one request per chunk: the situation is sent once and each line gets
    its own question against it. Four judgements per line — urgency, owner, customer impact,
    whether it is a known repeat — because questions in one request are scored in parallel and cost
    no extra round trip.<br>
    <code>php artisan triage:run "App\Models\LogEvent" --dry</code> prints exactly what goes over the wire.
  </footer>

</div>
</body>
</html>
