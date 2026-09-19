# Laravel Smart Triage

Sort a queue of Eloquent records by how urgently someone needs to deal with them, who should
pick each one up, and which ones a person should look at first.

It runs on a model that answers typed questions and returns calibrated probabilities instead of
text, so there is nothing to parse and nothing to hallucinate. One request answers every question
you ask about a record, in about 200 to 700 milliseconds, for a few thousandths of a penny.

[Jev](https://docs.typesafe.ai) is the driver that ships today. The package is written against a
`Judge` contract rather than one vendor, so a different model can be added without touching a
single triage declaration.

![The repairs dashboard, showing 40 open repairs in deadline order with bands, trades and review flags](https://raw.githubusercontent.com/Robincodes-Sandbox/laravel-smart-triage-app/main/docs/dashboard.png)

## Install

The package is not on Packagist yet, so point Composer at the repository:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/Robincodes-Sandbox/laravel-smart-triage" }
]
```

```bash
composer require solarise/laravel-smart-triage:^0.1
php artisan vendor:publish --tag=smart-triage-migrations
php artisan migrate
```

Once it is registered on Packagist, drop the `repositories` block and the plain
`composer require solarise/laravel-smart-triage` works.

Add your key to `.env`. Keep it server-side and never put it in a browser bundle:

```dotenv
JEV_API_KEY=
```

## Swapping the model

`config/smart-triage.php` picks a driver. Jev is the only one that ships:

```php
'driver' => env('SMART_TRIAGE_DRIVER', 'jev'),

'drivers' => [
    'jev' => [
        'client' => JevClient::class,
        'api_key' => env('JEV_API_KEY'),
        'base_url' => env('JEV_BASE_URL', 'https://api.typesafe.ai/v1'),
        'model' => env('JEV_MODEL', 'jev-latest'),
    ],
],
```

To add another, implement `Solarise\SmartTriage\Contracts\Judge` and register it here. A driver
has to return typed answers with calibrated probabilities. Anything that only returns prose does
not belong behind this contract, because the package reads distributions, not sentences.

## Quick start

Add the trait, name the attributes the model may read, and declare the triage:

```php
use Solarise\SmartTriage\Band;
use Solarise\SmartTriage\Concerns\Triageable;
use Solarise\SmartTriage\Triage;

class SupportTicket extends Model
{
    use Triageable;

    protected array $triageState = ['subject', 'body'];

    protected function triageRules(): Triage
    {
        return Triage::make()
            ->bands([
                Band::make('Low')->meaning('A question. Nothing is broken.')->within('5 days'),
                Band::make('Normal')->meaning('Something works badly but there is a way round it.')->within('2 days'),
                Band::make('High')->meaning('The customer cannot do what they came to do.')->within('4 hours'),
            ])
            ->urgency('How quickly does this ticket need an answer?')
            ->route('team', 'Which team should answer it?', [
                'billing' => 'Charges, invoices, refunds',
                'technical' => 'Errors, outages, integrations',
                'other' => null,
            ], noneOf: 'other');
    }
}
```

Run it:

```php
$ticket->triage();
```

That sends one request and stores one row per question.

## Read the result

```php
$outcome = $ticket->outcome();

$outcome->bandLabel();    // 'High'
$outcome->urgency();      // 2.41
$outcome->owner();        // 'technical'
$outcome->respondBy();    // Carbon: 4 hours after the ticket arrived
$outcome->overdue();      // false
$outcome->needsHuman();   // true
$outcome->reasons();      // ['billing and technical nearly tied']
```

Nothing above costs another request. It all reads answers you already hold, so you can change a
threshold for free.

## Add conditions you want recorded

A flag is a yes or no question asked in the same request. Several can be true at once:

```php
->flag('refund_requested', 'Is the customer asking for money back?')
->flag('threatens_legal', 'Does the message threaten legal action?', escalates: true)
```

Set `escalates: true` when a flag alone means a person must look, whatever the model thought.

## Decide when a person should look

Two different things send a record to a person, and the package keeps them apart:

- an escalating flag fired, which is policy working as intended
- the model was not sure enough, which is the model telling you it does not know

```php
$outcome->escalations();     // ['threatens legal']
$outcome->uncertainties();   // ['routing confidence 0.48']
```

Counting those together makes a confident system look unreliable. Set the thresholds yourself:

```php
->reviewWhen(confidence: 0.80, margin: 0.20, bandEdge: 0.10)
```

- `confidence` — how concentrated the routing distribution is
- `margin` — how far the winning team beat the runner-up
- `bandEdge` — how close the urgency score sits to the line between two bands

The last one matters more than it looks. A score of 1.48 and a score of 1.52 are the same
judgement, but they land in bands whose deadlines are days apart.

## Give it what the record does not say

Most of what you would triage on is already in a column, and a model that re-derives it is
wasted money. What no column holds is that the same words mean different things at different
times.

```php
protected function triageContext(): array
{
    return [
        'time' => 'Friday 17:40, support closes at 18:00',
        'customer_tier' => $this->customer->tier,
        'open_tickets' => $this->customer->tickets()->open()->count(),
        'known_outage' => Outage::current()?->summary,
    ];
}
```

Work out anything numeric in your own code. Jev cannot count and does not order dates reliably.

## Triage a lot of records

Use `triageMany()` when each record has its own substantial text. It sends one request per
record, several at a time, paced under the rate limit:

```php
SupportTicket::triageMany($tickets);
```

```bash
php artisan triage:run "App\Models\SupportTicket"
php artisan triage:run "App\Models\SupportTicket" --limit=50
php artisan triage:run "App\Models\SupportTicket" --dry    # prints the payload, sends nothing
```

Use `Batch` when records are short and share a situation. It sends the shared context once and
asks one question per record, so 20 log lines become one request instead of 20:

```php
use Solarise\SmartTriage\Batch;
use Solarise\SmartTriage\Questions\Score;

Batch::make()
    ->context(['deploy' => 'finished 4 minutes ago', 'open_incidents' => 1])
    ->records($logLines)
    ->ask(['urgency' => Score::make('How urgent is :record?')->levels([...])])
    ->run();
```

On 20 log lines that was about 1,000 input tokens, against about 13,700 for the same lines sent
one at a time.

## Choose where the options come from

`route()` takes a plain array, a PHP enum, an Eloquent model, or a scoped query:

```php
->route('team', 'Which team?', ['billing' => 'Charges and refunds', 'other' => null])
->route('team', 'Which team?', TeamEnum::class)
->route('trade', 'Which trade?', Trade::class, noneOf: 'surveyor')
->route('trade', 'Which trade?', EloquentTaxonomy::make(Trade::class)->where(fn ($q) => $q->active()))
```

Always name a `noneOf` option. Without one the model cannot say that nothing fits, so it returns
the least bad answer with a distribution that looks confident.

## Keep the whole distribution

Every row stores the probabilities, the confidence and the model version that answered:

```php
$judgement = $ticket->judgement('team');

$judgement->probabilities;   // ['technical' => 0.48, 'billing' => 0.46, 'other' => 0.06]
$judgement->runnerUp();      // ['option' => 'billing', 'probability' => 0.46]
$judgement->margin();        // 0.02
```

Storing only the winning label throws away the most useful part. A choice that won on 0.48
against 0.46 is a different fact from one that won on 0.98.

Confidence measures how concentrated the distribution is. It is not the chance the answer is
right. Set your threshold against your own data and the cost of being wrong.

## Know when an answer has gone stale

Answers stop being valid for 2 reasons, and the package tracks both:

- the record changed, which a `saved` hook spots by checking whether a named attribute actually moved
- the question changed, which a fingerprint of the question set spots even though no record moved

Reword a band or add a team and every answer that question produced is out of date. The hook
marks rows stale but does not call the API, so a bulk import will not turn into thousands of
requests.

```php
SupportTicket::query()->needingTriage()->count();
```

## What it costs

Measured against the live API on the demo below, 6 judgements per record:

| Measure | Result |
| --- | --- |
| 40 repair reports | 2.4 seconds, $0.0017 |
| One record | 200 to 700 milliseconds, about 1,000 input tokens |
| 10,000 records a day | $0.42 a day, about $154 a year |

Input costs $0.042 per million tokens and output is free, so cost is rarely the limit. The limit
is 1,200 requests a minute, which works out at roughly 1.7 million records a day.

## The demo

The screenshot above comes from a working demo: a repairs service with 50,000 homes and 40 open
reports. The source is in
[the demo repository](https://github.com/Robincodes-Sandbox/laravel-smart-triage-app).

```bash
git clone https://github.com/Robincodes-Sandbox/laravel-smart-triage-app
composer install
php artisan migrate --seed
php artisan triage:run "App\Models\RepairReport"
php artisan serve
```

Switch the operating conditions between a mild September week and a February cold snap, and the
whole queue is re-run against a different situation. The reports themselves do not change.

The bands follow published social housing practice.
[Awaab's Law](https://england.shelter.org.uk/professional_resources/news_and_updates/how_awaabs_law_changes_the_rules_on_hazards_in_social_housing)
gives landlords 24 hours to investigate and make safe an emergency hazard.
[Scotland's Right to Repair](https://www.gov.scot/publications/right-repair/) runs qualifying
repairs at 1, 3 or 7 working days. The demo combines the two as an illustration. It is not
legal advice.

The demo has a second page at `/logs` showing the shared-context batch on log lines.

## What the model cannot do

These are Jev's limits. Read them before you design around it, and expect any driver to have its
own list:

- it cannot count, and the error grows with the size of the thing counted
- it reads numbers written as text badly, such as hex values or "are these 2 figures close"
- it treats dates as text, not as ordered quantities
- it loses accuracy with every step of indirection
- it loses accuracy when you send it large amounts of irrelevant state, which is why `$triageState` is an explicit list with no default
- it can be steered by text inside the state, so user text must reach it as data and must never decide an action on its own

## Licence

MIT.
