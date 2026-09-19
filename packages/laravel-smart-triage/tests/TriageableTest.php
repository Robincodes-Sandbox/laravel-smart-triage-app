<?php

namespace Solarise\SmartTriage\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Solarise\SmartTriage\Exceptions\TriageException;
use Solarise\SmartTriage\Models\Judgement;
use Solarise\SmartTriage\Tests\Concerns\FakesJev;
use Solarise\SmartTriage\Tests\Fixtures\PlainTicket;
use Solarise\SmartTriage\Tests\Fixtures\Team;
use Solarise\SmartTriage\Tests\Fixtures\Ticket;

class TriageableTest extends TestCase
{
    use FakesJev;

    protected function setUp(): void
    {
        parent::setUp();

        Ticket::forgetTriageQuestions();
        Ticket::$context = ['office_hours' => 'yes'];

        Team::insert([
            ['id' => 1, 'name' => 'Billing', 'description' => 'Charges and refunds', 'active' => true],
            ['id' => 2, 'name' => 'Technical', 'description' => 'Errors and outages', 'active' => true],
        ]);
    }

    protected function ticket(array $overrides = []): Ticket
    {
        return Ticket::create(array_merge([
            'subject' => 'Charged twice for order 4471',
            'body' => 'You took the payment twice and I need the second one back.',
            'received_at' => now(),
        ], $overrides));
    }

    public function test_one_triage_sends_one_request_carrying_every_question(): void
    {
        $this->fakeJev($this->ticketAnswers());

        $this->ticket()->triage();

        Http::assertSentCount(1);

        Http::assertSent(function (Request $request) {
            $this->assertEqualsCanonicalizing(
                ['urgency', 'team', 'refund_requested', 'threatens_legal'],
                array_keys($request->data()['questions']),
            );

            return true;
        });

        $this->assertSame(4, Judgement::count());
    }

    public function test_only_whitelisted_attributes_reach_jev(): void
    {
        $this->fakeJev($this->ticketAnswers());

        $this->ticket()->triage();

        Http::assertSent(function (Request $request) {
            $record = $request->data()['state']['record'];

            $this->assertEqualsCanonicalizing(['subject', 'body'], array_keys($record));
            $this->assertArrayNotHasKey('id', $record);
            $this->assertArrayNotHasKey('created_at', $record);
            $this->assertArrayNotHasKey('channel', $record);

            return true;
        });
    }

    public function test_context_travels_beside_the_record_not_inside_it(): void
    {
        $this->fakeJev($this->ticketAnswers());

        $this->ticket()->triage();

        Http::assertSent(function (Request $request) {
            $state = $request->data()['state'];

            $this->assertSame('yes', $state['context']['office_hours']);
            $this->assertArrayNotHasKey('office_hours', $state['record']);

            return true;
        });
    }

    public function test_the_whole_distribution_is_stored(): void
    {
        $this->fakeJev($this->ticketAnswers(team: 'Billing', confidence: 0.94));

        $ticket = $this->ticket();
        $ticket->triage();

        $team = $ticket->fresh()->judgement('team');

        $this->assertSame('Billing', $team->value);
        $this->assertSame(0.94, $team->confidence);
        $this->assertSame('Technical', $team->runnerUp()['option']);
        $this->assertSame('jev-1.13.0', $team->model);
        $this->assertTrue($team->isConfident(0.9));
        $this->assertFalse($team->isConfident(0.99));
    }

    public function test_a_score_keeps_its_number_and_gains_a_label_from_the_bands(): void
    {
        $this->fakeJev($this->ticketAnswers(urgency: 2.4));

        $ticket = $this->ticket();
        $ticket->triage();

        $urgency = $ticket->fresh()->judgement('urgency');

        $this->assertSame(2.4, $urgency->number);
        // No legend came back, so the label falls back to the level we sent.
        $this->assertStringContainsString('cannot do what they came to do', $urgency->value);
    }

    public function test_editing_a_whitelisted_attribute_marks_answers_stale(): void
    {
        $this->fakeJev($this->ticketAnswers());

        $ticket = $this->ticket();
        $ticket->triage();

        $this->assertFalse($ticket->fresh()->needsTriage());

        $ticket->update(['body' => 'Something else entirely happened']);

        $this->assertTrue($ticket->fresh()->judgements->every->stale);
        $this->assertTrue($ticket->fresh()->needsTriage());
    }

    public function test_touching_an_unwhitelisted_attribute_does_not(): void
    {
        $this->fakeJev($this->ticketAnswers());

        $ticket = $this->ticket();
        $ticket->triage();

        $ticket->update(['channel' => 'phone']);

        $this->assertFalse($ticket->fresh()->judgements->contains('stale', true));
    }

    public function test_changing_the_taxonomy_invalidates_the_answers_it_produced(): void
    {
        $this->fakeJev($this->ticketAnswers());

        $ticket = $this->ticket();
        $ticket->triage();

        $this->assertFalse($ticket->fresh()->needsTriage());

        // A new team is a new question. No ticket changed, but every stored
        // routing answer was chosen from a list that no longer exists.
        Team::insert([['id' => 3, 'name' => 'Onboarding', 'description' => 'New accounts', 'active' => true]]);
        Ticket::forgetTriageQuestions();

        $this->assertTrue($ticket->fresh()->needsTriage());
    }

    public function test_a_plain_question_set_still_works_without_a_triage(): void
    {
        $this->fakeJev([
            'severity' => $this->score(1.0),
            'angry' => $this->noul(0.88),
        ]);

        $ticket = PlainTicket::create([
            'subject' => 'This is unacceptable',
            'body' => 'Third time I have had to write in.',
        ]);

        $ticket->triage();

        $this->assertSame(0.88, $ticket->fresh()->judgement('angry')->number);
    }

    public function test_a_plain_question_set_has_no_outcome_to_resolve(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/no bands or thresholds/');

        PlainTicket::create(['subject' => 'x', 'body' => 'y'])->outcome();
    }

    public function test_one_failed_record_does_not_take_down_the_run(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['error' => ['message' => 'malformed']], 422)
                : Http::response(['model' => 'jev-1.13.0', 'answers' => $this->ticketAnswers(), 'usage' => ['input_tokens' => 400, 'output_tokens' => 20]]);
        });

        $results = Ticket::triageMany([$this->ticket(), $this->ticket(['subject' => 'Another'])]);

        $this->assertCount(2, $results);
        $this->assertSame(1, $results->filter(fn ($r) => $r instanceof TriageException)->count());
    }

    public function test_an_unauthorised_key_is_not_retried(): void
    {
        Http::fake(['api.typesafe.ai/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        try {
            $this->ticket()->triage();
            $this->fail('Expected a TriageException.');
        } catch (TriageException $e) {
            $this->assertSame(401, $e->status);
            $this->assertFalse($e->isRetryable());
        }

        // 401 is our bug, not a blip. Retrying it just burns the rate limit.
        Http::assertSentCount(1);
    }
}
