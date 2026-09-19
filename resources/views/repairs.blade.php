<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Repairs triage — 50,000 homes</title>
<style>
  :root {
    --paper:#f7f6f3; --card:#fff; --rule:#e2dfd8; --ink:#1c1b19; --mute:#6f6a62;
    --emergency:#b3261e; --urgent:#b4690e; --qualifying:#2d6a4f; --routine:#5b6470;
    --accent:#1a4f7a; --warn:#fdf4e3;
    --sans:ui-sans-serif,-apple-system,"Segoe UI",Helvetica,sans-serif;
    --mono:ui-monospace,"SF Mono",Menlo,monospace;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.5 var(--sans);padding:36px 24px 80px}
  .wrap{max-width:1180px;margin:0 auto}
  header{display:flex;justify-content:space-between;align-items:baseline;gap:20px;flex-wrap:wrap;margin-bottom:6px}
  h1{font-size:20px;margin:0;letter-spacing:-.01em}
  .lede{color:var(--mute);margin:0 0 24px;max-width:66ch}
  .strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(122px,1fr));gap:1px;background:var(--rule);
         border:1px solid var(--rule);margin-bottom:22px}
  .cell{background:var(--card);padding:13px 15px}
  .cell b{display:block;font-size:21px;font-variant-numeric:tabular-nums;letter-spacing:-.02em}
  .cell span{font-size:11px;text-transform:uppercase;letter-spacing:.07em;color:var(--mute)}
  .cell.alert b{color:var(--emergency)}
  .cell.money b{color:var(--qualifying)}
  form.bar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;background:var(--card);
           border:1px solid var(--rule);padding:13px 15px;margin-bottom:10px}
  form.bar > em{font-style:normal;font-size:12px;color:var(--mute);margin-right:4px}
  button{font:inherit;font-size:13px;background:var(--card);border:1px solid var(--rule);
         padding:7px 13px;cursor:pointer;border-radius:2px}
  button[data-on="true"]{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
  button:hover{border-color:var(--accent)}
  .runline{font-size:12px;color:var(--mute);margin:0 0 22px;font-family:var(--mono)}
  table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--rule)}
  th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.08em;color:var(--mute);
     font-weight:600;padding:10px;border-bottom:1px solid var(--rule);background:#fbfaf8}
  td{padding:11px 10px;border-bottom:1px solid #efede8;vertical-align:top;font-size:13.5px}
  tr:last-child td{border-bottom:none}
  tr.human{background:var(--warn)}
  .band{font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
  .band.Emergency{color:var(--emergency)} .band.Urgent{color:var(--urgent)}
  .band.Qualifying{color:var(--qualifying)} .band.Routine{color:var(--routine)}
  .num{font-family:var(--mono);font-size:12px;color:var(--mute);font-variant-numeric:tabular-nums}
  .sla{font-family:var(--mono);font-size:12px;white-space:nowrap;font-variant-numeric:tabular-nums}
  .sla.breach{color:var(--emergency);font-weight:600}
  .what{max-width:46ch}
  .what small{display:block;color:var(--mute);font-size:11.5px;margin-top:3px}
  .addr{color:var(--mute);font-size:11.5px;white-space:nowrap}
  .tags span{display:inline-block;font-size:10.5px;padding:1px 6px;border:1px solid var(--rule);
             border-radius:2px;color:var(--mute);margin:1px 2px 1px 0;white-space:nowrap}
  .tags span.esc{border-color:#d9b38c;background:#fdf1e0;color:#8a5115}
  .why{font-size:11.5px;color:#8a5115}
  .empty{padding:48px;text-align:center;color:var(--mute);background:var(--card);border:1px solid var(--rule)}
  .err{color:var(--emergency);margin-bottom:14px}
  footer{margin-top:30px;font-size:12.5px;color:var(--mute);line-height:1.75;max-width:78ch}
  footer code{font-family:var(--mono);font-size:11.5px;color:var(--ink);background:#eeece6;padding:1px 4px}
  a{color:var(--accent)}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <h1>Repairs triage</h1>
    <span class="num">{{ number_format($stats['properties']) }} homes · <a href="{{ route('triage') }}">log triage demo →</a></span>
  </header>
  <p class="lede">
    Every open repair, in deadline order. The band sets the deadline, the trade comes from the
    same single call, and anything the model was not sure enough about is held back for a person
    instead of being routed quietly.
  </p>

  @error('jev')<p class="err">{{ $message }}</p>@enderror

  <div class="strip">
    <div class="cell"><b>{{ number_format($stats['open']) }}</b><span>Open</span></div>
    <div class="cell alert"><b>{{ $stats['emergency'] }}</b><span>Emergency</span></div>
    <div class="cell alert"><b>{{ $stats['breached'] }}</b><span>Past deadline</span></div>
    <div class="cell"><b>{{ $stats['escalated'] }}</b><span>Statutory escalation</span></div>
    <div class="cell"><b>{{ $stats['uncertain'] }}</b><span>Model unsure</span></div>
    <div class="cell"><b>{{ $stats['clean'] }}</b><span>Routed cleanly</span></div>
    <div class="cell money"><b>${{ number_format($stats['dailyCost'], 2) }}</b><span>Per 10k/day</span></div>
    <div class="cell money"><b>${{ number_format($stats['annualCost'], 0) }}</b><span>Per year</span></div>
  </div>

  <form method="POST" action="{{ route('repairs.triage') }}" class="bar">
    @csrf
    <em>Conditions the service is working under</em>
    @foreach ($conditionSet as $key => $set)
      <button name="conditions" value="{{ $key }}" data-on="{{ $key === $conditions ? 'true' : 'false' }}">
        {{ $set['label'] }}
      </button>
    @endforeach
  </form>

  <form class="bar" method="GET" action="{{ route('repairs') }}">
    <em>Queue</em>
    @foreach (['all' => 'All', 'Emergency' => 'Emergency', 'Urgent' => 'Urgent', 'Qualifying' => 'Qualifying', 'Routine' => 'Routine', 'escalated' => 'Statutory', 'uncertain' => 'Model unsure', 'breached' => 'Past deadline'] as $key => $label)
      <button name="view" value="{{ $key }}" data-on="{{ $key === $filter ? 'true' : 'false' }}">
        {{ $label }}@if (isset($stats['bands'][$key])) <span style="opacity:.6">{{ $stats['bands'][$key] }}</span>@endif
      </button>
    @endforeach
  </form>

  @if ($lastRun)
    <p class="runline">
      {{ number_format($lastRun['reports']) }} reports ·
      {{ number_format($lastRun['judgements']) }} judgements ·
      {{ number_format($lastRun['tokens']) }} tokens ·
      ${{ number_format($lastRun['tokens'] / 1000000 * 0.042, 4) }} ·
      {{ $lastRun['seconds'] }}s
      @if ($lastRun['failed']) · <b>{{ $lastRun['failed'] }} failed</b> @endif
    </p>
  @endif

  <p class="runline" style="margin-bottom:10px">Showing {{ min($showing, 50) }} of {{ number_format($showing) }} in this queue.</p>

  @if ($stats['triaged'] === 0)
    <p class="empty">Nothing triaged yet. Pick the operating conditions above to run it.</p>
  @else
  <table>
    <thead>
      <tr>
        <th>Band</th><th>Score</th><th>Respond by</th><th>Report</th>
        <th>Property</th><th>Trade</th><th>Notes</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($reports as $report)
        @php $o = $report->outcome(); @endphp
        @continue($o->urgency() === null)
        <tr class="{{ $o->needsHuman() ? 'human' : '' }}">
          <td class="band {{ $o->bandLabel() }}">{{ $o->bandLabel() }}</td>
          <td class="num">{{ number_format($o->urgency(), 2) }}</td>
          <td class="sla {{ $o->overdue() ? 'breach' : '' }}">
            {{ $o->respondBy()?->format('D d M H:i') }}<br>
            <span style="opacity:.7">{{ $o->overdue() ? 'breached' : $o->hoursRemaining().'h left' }}</span>
          </td>
          <td class="what">
            {{ $report->summary }}
            <small>{{ ucfirst($report->room) }} · via {{ $report->channel }} · {{ $report->reported_at->diffForHumans() }}</small>
          </td>
          <td class="addr">
            {{ $report->property->address }}<br>
            <span style="opacity:.75">{{ $report->property->block }} · {{ $report->property->built }}</span>
          </td>
          <td>{{ $o->owner() }}</td>
          <td class="tags">
            @foreach ($o->flags() as $flag => $probability)
              <span class="{{ in_array($flag, ['damp_mould','vulnerability_risk']) ? 'esc' : '' }}">
                {{ str_replace('_', ' ', $flag) }}
              </span>
            @endforeach
            @if ($o->escalated())
              <div class="why"><b>policy:</b> {{ implode('; ', $o->escalations()) }}</div>
            @endif
            @if ($o->uncertain())
              <div class="why" style="color:#6f6a62"><b>unsure:</b> {{ implode('; ', $o->uncertainties()) }}</div>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @endif

  <footer>
    Bands follow published social housing practice — Awaab's Law gives 24 hours to investigate
    and make safe an emergency hazard, and Scotland's Right to Repair runs qualifying repairs at
    1, 3 or 7 working days. The wording of each band is what the model reads and what the
    deadline is derived from, so policy and prompt cannot drift apart.<br><br>
    Seven judgements per report — urgency, trade, and four conditions — all from one request,
    because questions in a request are scored in parallel and a fifth costs no extra round trip.
    Switching the operating conditions re-runs the lot against a different situation; the reports
    themselves never change.<br><br>
    <code>php artisan triage:run "App\Models\RepairReport" --dry</code> prints the exact payload.
  </footer>

</div>
</body>
</html>
