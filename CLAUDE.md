# laravel-smart-triage

A Laravel package that gives any Eloquent model semantic triage via TypeSafe's Jev, plus a demo
housing repairs service that exercises it. Public repo — meant to be read by strangers, so the
README is the product and the code carries its reasoning in comments.

## Two repos, one working folder

Develop here; the package is split out to its own repo so people can install it.

| Remote | Repo | What it holds |
|---|---|---|
| `origin` | `laravel-smart-triage-app` | this whole folder — the demo app, deployable |
| `package` | `laravel-smart-triage` | `packages/laravel-smart-triage/` only, Packagist-shaped |

Push the package with a subtree split, never by copying files:

```
git subtree push --prefix=packages/laravel-smart-triage package main
```

The package has its own `composer.json`, LICENSE, phpunit.xml, CI workflow and a self-contained
Testbench suite using fixture models — it must not depend on anything in `app/`.

Everything in `app/`, `routes/` and `resources/views/` is demo, not deliverable.

## Why the name has no vendor in it

Robin expects to swap the model later, so nothing outward-facing names Jev. The package is
`solarise/laravel-smart-triage`, the namespace is `Solarise\SmartTriage`, and everything resolves
`Contracts\Judge` rather than a concrete client. Jev survives as `Drivers/JevClient`, selected by
`config('smart-triage.driver')`. Keep it that way: a vendor name in a class, config key, command
or doc comment outside the driver is a bug.

## The shape

`Judge` is the contract a model driver implements. `Triage` is the declaration (bands, route, flags, review thresholds). `Band` holds a level's
wording *and* its SLA deadline in one object. `Outcome` resolves a record's stored judgements
into a decision without another API call. `Triageable` is the trait. Under all of it sit the
three Jev primitives — `Choice`, `Noul`, `Score` — which are still usable directly.

Two bulk modes: `triageMany()` pools one request per record; `Batch` puts many small records in
one request with a shared context.

## Notes

- **Jev has no PHP SDK.** `src/Jev/JevClient.php` is the whole client. That absence is a real
  part of why this is worth publishing.
- `~/Projects/.container/docs/JEV.md` is the house method; the live docs win where they differ.
- Key read from `~/.config/typesafe/env` into the gitignored `.env` as `JEV_API_KEY`.
- Demo pages: `/` is repairs triage, `/logs` is the shared-state batch example.
- The deployed copy is gated by `App\Http\Middleware\DemoLock`, which fails closed: lock on and
  no password set means nothing gets through. `DEMO_LOCK=false` locally.
- `deploy.json` is `live: false` until the server has a shared `.env`. Deploying without one
  would serve errors, not a locked demo.
- Two suites: `php artisan test` for the app, `vendor/bin/phpunit` inside the package.
- Verified against the live API: 400 reports × 6 judgements = 25.4s, $0.0144. 50,000 properties
  seed in 753ms via chunked `DB::table()->insert()`, not model saves.

## Learnings

- **The condenser idea was correctly killed.** An early plan put an LLM summariser in front of
  Jev for long text and images. Robin cut it: a Haiku pass at $1/M in front of a $0.042/M call
  costs 24x the thing it feeds. If someone wants an LLM-written description they populate that
  column themselves; the package reads attributes and doesn't care where the text came from.
- **Urgency is mostly deterministic; context is not.** Robin's question — "would we
  deterministically already be determining urgency?" — reshaped the design. What justifies a
  call is the same words meaning different things in different situations, which is why
  `triageContext()` exists and why a batch shares one context.
- **Separate policy escalation from model uncertainty.** 167 of 400 reports came back "needs a
  person", which reads like an unreliable system. 94 of them were the damp/mould statutory
  escalation firing exactly as designed. `escalations()` and `uncertainties()` are now separate,
  and the honest headline is 233 of 400 routed cleanly.
- **Band wording is the prompt.** "EMERGENCY!!! tap will not stop dripping" scored Emergency
  2.60 because the band read "water entering and not stopping" — which a dripping tap matches.
  Adding "a fixture that merely drips or runs is not this, however the report is worded" moved
  it a full band to Urgent 1.85. The model was right about the words; the words were wrong.
- **Calibrate the band-edge threshold or everything looks uncertain.** At 0.22 it fired on most
  scores. It means "within N of the .5 line between two bands", so 0.10 is the sensible default.
- **A batch question without `:record` silently asks about everything.** Found against the live
  API, not by reading code: every line scored ~2.9, including a DEBUG line about a rate limiter.
  `Batch::renderQuestion()` now scopes any unpointed question. Plausible-looking numbers are the
  failure mode that survives review.
- **Renaming a trait breaks its boot hook silently.** `bootClassifiable` doesn't match
  `\bClassifiable\b` — no word boundary inside the identifier — so it survived the rename and
  Laravel stopped calling it. Staleness quietly stopped working; only a test caught it. Check
  `boot{TraitName}` and `initialize{TraitName}` by hand after any trait rename.
- **Sort a triage queue by deadline, not by score.** The first dashboard sorted on urgency, so
  the top 50 rows were all Emergency and the range never showed. A routine repair reported 27
  days ago is more pressing than an emergency raised five minutes ago; deadline order is what an
  ops team actually works and it makes the demo legible at the same time.
- **Demo data needs variety or it reads as broken.** 400 reports cycling 42 templates gave ten
  byte-identical rows at the top. The judgements were right; the screenshot looked like
  duplicate data. Now 40 reports, each a template plus a different caller's phrasing.
- `array_chunk`'s third parameter is `preserve_keys`, not `preserveKeys` — named args fail.
- `--limit` on a command that uses `chunkById` is silently ignored: chunkById sets its own limit
  on the builder. Enforce the cap inside the chunk callback instead.
- Judgements from every Triageable model share one table. Any `sum()` or `count()` for one
  model's stats must be scoped by `triageable_type`, or one demo bills another.
- Jev sometimes returns a score `probabilities` as a list rather than a map, and may omit
  `legend`. `JudgementWriter` falls back to the levels we sent.
