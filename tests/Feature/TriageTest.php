<?php

namespace Tests\Feature;

use App\Models\LogEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Solarise\SmartTriage\Batch;
use Solarise\SmartTriage\Exceptions\TriageException;
use Solarise\SmartTriage\Models\Judgement;
use Solarise\SmartTriage\Questions\Choice;
use Solarise\SmartTriage\Questions\Noul;
use Solarise\SmartTriage\Questions\Score;
use Tests\TestCase;

class TriageTest extends TestCase
{
    use RefreshDatabase;

    protected function event(array $overrides = []): LogEvent
    {
        return LogEvent::create(array_merge([
            'level' => 'ERROR',
            'service' => 'checkout',
            'message' => 'Stripe webhook signature verification failed',
            'occurrences' => 3,
            'occurred_at' => now(),
        ], $overrides));
    }

    protected function fakeAnswers(array $keys = ['urgency', 'owner', 'customer_facing', 'recurring']): array
    {
        $answers = [];

        foreach ($keys as $key) {
            $answers[$key] = match ($key) {
                'owner' => ['type' => 'choice', 'choice' => 'payments', 'confidence' => 0.94,
                    'probabilities' => ['payments' => 0.94, 'platform' => 0.04, 'product' => 0.01, 'security' => 0.005, 'nobody' => 0.005]],
                'urgency' => ['type' => 'score', 'score' => 2.6, 'confidence' => 0.88,
                    'probabilities' => ['0' => 0.0, '1' => 0.1, '2' => 0.3, '3' => 0.6],
                    'legend' => ['0' => 'Routine.', '1' => 'Tomorrow.', '2' => 'Today.', '3' => 'Now.']],
                default => ['type' => 'noul', 'noul' => 0.91],
            };
        }

        return ['model' => 'jev-1.13.0', 'answers' => $answers, 'usage' => ['input_tokens' => 450, 'output_tokens' => 60]];
    }

    public function test_one_triage_sends_one_request_with_every_question(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fakeAnswers())]);

        $this->event()->triage();

        Http::assertSentCount(1);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            // Every declared judgement rides in the one request — that is the
            // whole economic argument for the design.
            $this->assertEqualsCanonicalizing(
                ['urgency', 'owner', 'customer_facing', 'recurring'],
                array_keys($body['questions']),
            );
            $this->assertSame('jev-latest', $body['model']);

            return true;
        });

        $this->assertSame(4, Judgement::count());
    }

    public function test_only_whitelisted_attributes_reach_jev(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fakeAnswers())]);

        $this->event()->triage();

        Http::assertSent(function (Request $request) {
            $state = $request->data()['state'];

            $this->assertEqualsCanonicalizing(
                ['level', 'service', 'message', 'occurrences'],
                array_keys($state['record']),
            );
            // Timestamps and primary keys are noise that costs accuracy, and
            // must never be swept in by a toArray().
            $this->assertArrayNotHasKey('id', $state['record']);
            $this->assertArrayNotHasKey('created_at', $state['record']);

            return true;
        });
    }

    public function test_context_travels_separately_from_the_record(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fakeAnswers())]);

        $this->event()->triage();

        Http::assertSent(function (Request $request) {
            $state = $request->data()['state'];

            $this->assertArrayHasKey('context', $state);
            $this->assertArrayHasKey('open_incidents', $state['context']);

            return true;
        });
    }

    public function test_the_distribution_is_stored_not_just_the_winner(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fakeAnswers())]);

        $event = $this->event();
        $event->triage();

        $owner = $event->fresh()->judgement('owner');

        $this->assertSame('payments', $owner->value);
        $this->assertSame(0.94, $owner->confidence);
        $this->assertSame(0.04, $owner->probabilities['platform']);
        $this->assertSame('platform', $owner->runnerUp()['option']);
        $this->assertEqualsWithDelta(0.90, $owner->margin(), 0.001);
        $this->assertTrue($owner->isConfident(0.9));
        $this->assertFalse($owner->isConfident(0.99));
    }

    public function test_a_score_keeps_its_number_and_gains_a_label(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fakeAnswers())]);

        $event = $this->event();
        $event->triage();

        $urgency = $event->fresh()->judgement('urgency');

        $this->assertSame(2.6, $urgency->number);
        $this->assertSame('Now.', $urgency->value);
    }

    public function test_editing_a_whitelisted_attribute_marks_answers_stale(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fakeAnswers())]);

        $event = $this->event();
        $event->triage();

        $this->assertFalse($event->fresh()->needsTriage());

        $event->update(['message' => 'Something else entirely happened']);

        $this->assertTrue($event->fresh()->judgements->every->stale);
        $this->assertTrue($event->fresh()->needsTriage());
    }

    public function test_touching_an_unwhitelisted_attribute_does_not(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response($this->fakeAnswers())]);

        $event = $this->event();
        $event->triage();

        $event->update(['occurred_at' => now()->subDay()]);

        $this->assertFalse($event->fresh()->judgements->contains('stale', true));
    }

    public function test_changing_a_question_invalidates_the_answers_it_produced(): void
    {
        $before = LogEvent::triageQuestionsFingerprint();

        $rewritten = hash('sha256', (string) json_encode([
            'urgency' => Score::make('A different question entirely')->levels(['low', 'high'])->fingerprint(),
        ]));

        // No record changed, but an answer to a different question is not an
        // answer to this one.
        $this->assertNotSame($before, $rewritten);
    }

    public function test_a_batch_question_is_scoped_to_its_own_record(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0',
            'answers' => [
                'a::urgency' => ['type' => 'score', 'score' => 2.1, 'confidence' => 0.7, 'probabilities' => []],
                'b::urgency' => ['type' => 'score', 'score' => 0.2, 'confidence' => 0.8, 'probabilities' => []],
            ],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 20],
        ])]);

        Batch::make()
            ->context(['clock' => 'Friday 20:12'])
            ->records(['a' => 'ERROR checkout gateway down', 'b' => 'INFO cron finished'])
            // Deliberately written without a :record placeholder — a question
            // reused from a single-record model. Unscoped it would ask about
            // all records at once and quietly return near-identical numbers.
            ->ask(['urgency' => Score::make('How urgent is this?')->levels(['low', 'high'])])
            ->run();

        Http::assertSent(function (Request $request) {
            $questions = $request->data()['questions'];

            $this->assertArrayHasKey('a::urgency', $questions);
            $this->assertStringContainsString('`records.a`', $questions['a::urgency']['instructions']);
            $this->assertStringContainsString('`records.b`', $questions['b::urgency']['instructions']);
            $this->assertStringNotContainsString('`records.b`', $questions['a::urgency']['instructions']);

            return true;
        });
    }

    public function test_a_batch_sends_the_shared_context_once(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0', 'answers' => [], 'usage' => ['input_tokens' => 100, 'output_tokens' => 0],
        ])]);

        Batch::make()
            ->context(['clock' => 'Friday 20:12', 'open_incidents' => 1])
            ->records(array_combine(range('a', 'j'), array_fill(0, 10, 'ERROR something failed')))
            ->ask(['urgency' => Score::make('How urgent is :record?')->levels(['low', 'high'])])
            ->run();

        // Ten records, ten questions, one request — one copy of the context.
        Http::assertSentCount(1);

        Http::assertSent(function (Request $request) {
            $this->assertCount(10, $request->data()['questions']);
            $this->assertCount(10, $request->data()['state']['records']);
            $this->assertSame(1, $request->data()['state']['context']['open_incidents']);

            return true;
        });
    }

    public function test_a_choice_refuses_to_send_fewer_than_two_options(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Choice::make('Which one?')->among(['only' => 'the only option'])->toPayload();
    }

    public function test_a_noul_carries_criteria_only_when_given(): void
    {
        $this->assertArrayNotHasKey('criteria', Noul::make('Is it raining?')->toPayload());

        $payload = Noul::make('Is it raining?')->criteria(true: 'Water falls', false: 'It does not')->toPayload();

        $this->assertSame('Water falls', $payload['criteria']['true']);
    }

    public function test_a_failed_record_does_not_take_down_the_rest_of_a_run(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['error' => ['message' => 'nope']], 422)
                : Http::response($this->fakeAnswers());
        });

        $results = LogEvent::triageMany([$this->event(), $this->event(['service' => 'auth'])]);

        $this->assertCount(2, $results);
        $this->assertSame(1, $results->filter(fn ($r) => $r instanceof TriageException)->count());
        $this->assertSame(1, $results->reject(fn ($r) => $r instanceof TriageException)->count());
    }
}
