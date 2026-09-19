<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Laravel Jev Triage — demo</title>
<style>
  :root { --paper:#f7f6f3; --ink:#1c1b19; --mute:#6f6a62; --rule:#e2dfd8; --accent:#1a4f7a; --err:#b3261e; }
  *{box-sizing:border-box}
  body{margin:0;background:var(--paper);color:var(--ink);font:15px/1.6 ui-sans-serif,-apple-system,"Segoe UI",Helvetica,sans-serif;
       display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
  .card{background:#fff;border:1px solid var(--rule);padding:30px;max-width:460px;width:100%}
  h1{font-size:18px;margin:0 0 6px}
  p{color:var(--mute);margin:0 0 20px}
  label{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.07em;color:var(--mute);margin-bottom:6px}
  input{width:100%;font:inherit;padding:9px 11px;border:1px solid var(--rule);border-radius:2px;background:#fdfdfc}
  input:focus{outline:2px solid var(--accent);outline-offset:1px}
  button{margin-top:14px;font:inherit;background:var(--accent);color:#fff;border:0;padding:9px 18px;border-radius:2px;cursor:pointer}
  .err{color:var(--err);font-size:13.5px;margin-top:10px}
  .foot{margin-top:22px;padding-top:16px;border-top:1px solid var(--rule);font-size:13px;color:var(--mute)}
  a{color:var(--accent)}
</style>
</head>
<body>
  <div class="card">
    <h1>Laravel Jev Triage</h1>
    <p>A demo repairs service for 50,000 homes. It calls a paid API, so it is kept behind a password.</p>

    @if ($configured)
      <form method="POST" action="{{ route('demo.unlock.submit') }}">
        @csrf
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>
        <button type="submit">Open the demo</button>
        @error('password')<p class="err">{{ $message }}</p>@enderror
      </form>
    @else
      <p class="err">No demo password is configured on this server, so the demo cannot be opened.</p>
    @endif

    <p class="foot">
      The package itself is open:
      <a href="https://github.com/Robincodes-Sandbox/laravel-smart-triage">github.com/Robincodes-Sandbox/laravel-smart-triage</a>
    </p>
  </div>
</body>
</html>
