# scratchpad

## Where it stands

Package works, verified against the live API, 24 tests green. Two demos:
`https://laravel-smart-triage.projects.localhost/` (repairs, 50k homes) and `/logs` (batch mode).
Public repo at `Robincodes-Sandbox/laravel-smart-triage`. Nothing committed yet.

## Measured

| | |
|---|---|
| 1 record, 3 questions | 669ms, 450 tokens, $0.000019 |
| 40 repair reports × 6 judgements, pooled | 2.4s wall, ~40k tokens, $0.0017 |
| 20 log lines, one shared-state batch | ~650ms, ~1,030 tokens, $0.000043 |
| 20 log lines sent individually | ~13,700 tokens |
| 50,000 properties seeded | 753ms |

Extrapolated: 10,000 repair reports/day is $0.42/day, ~$153/year. The rate limit (1,200/min,
~1.7M/day) binds long before the money does.

## Findings worth keeping

- Judgement beats the severity column. On the log demo three `WARN`s outrank five `ERROR`s.
- Tenants' own framing is noise and Jev ignores it: "URGENT the wardrobe handle has come off"
  → Routine 0.33; "bit of a drip through the ceiling" → Urgent 2.38.
- "part of the ceiling has come down in the night" → Emergency, **Surveyor**, with Roofing at
  0.44 against Surveyor 0.46 → held for a person. Exactly the right call, and it came from the
  margin, not from a rule anyone wrote.
- Median trade routing confidence across 400 reports is 0.97 with a real tail at 0.18. The
  system is confident where it should be and says so where it isn't.
- Of 40: 16 routed cleanly, 14 escalated by policy, 18 flagged uncertain (overlapping).
- Whole session came to roughly 1,200 requests for about 10 cents.

## POTENTIAL

- `// POTENTIAL:` confidence-gated hierarchy — the SEC cookbook's parent-category fallback
  (48/60 vs 39/60 useful) maps onto `EloquentTaxonomy` with a `parent_id`. Below threshold,
  report the parent. No extra call.
- `// POTENTIAL:` reranking. `Batch` already has the shape: query as context, candidates as
  records, one choice over candidate ids. Docs measure 5% → 18% top-1. Makes it a Scout
  companion as well as a triage package.
- `// POTENTIAL:` `jev:watch` — batch on a window (N records or N ms) rather than sweeping a
  table. The actual firehose shape, and the thing a 10-updates-per-second table wants.
- `// POTENTIAL:` public holidays in `Band::respondBy()`. Currently weekdays only; real housing
  policy excludes bank holidays, and the calendar belongs to the application.
- Nothing handles a taxonomy past 255 options. Hierarchical beam search is the documented answer
  and is a bigger feature than it looks.

## Next commercial step

Housing associations are the target — volume, statutory triage, context sitting unused in their
database, and they buy from small suppliers. The demo is deliberately Edinburgh stock.
