<?php

namespace Solarise\SmartTriage\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Solarise\SmartTriage\Batch;
use Solarise\SmartTriage\Questions\Choice;
use Solarise\SmartTriage\Questions\Noul;
use Solarise\SmartTriage\Questions\Score;
use Solarise\SmartTriage\Tests\Concerns\FakesJev;

class BatchTest extends TestCase
{
    use FakesJev;

    public function test_a_batch_question_is_scoped_to_its_own_record(): void
    {
        $this->fakeJev([
            'a::urgency' => $this->score(2.1),
            'b::urgency' => $this->score(0.2),
        ]);

        Batch::make()
            ->context(['clock' => 'Friday 20:12'])
            ->records(['a' => 'ERROR checkout gateway down', 'b' => 'INFO cron finished'])
            // Deliberately written with no :record placeholder, as a question
            // reused from a single-record model would be. Unscoped it asks
            // about every record at once and quietly returns near-identical
            // numbers for all of them.
            ->ask(['urgency' => Score::make('How urgent is this?')->levels(['low', 'high'])])
            ->run();

        Http::assertSent(function (Request $request) {
            $questions = $request->data()['questions'];

            $this->assertStringContainsString('`records.a`', $questions['a::urgency']['instructions']);
            $this->assertStringContainsString('`records.b`', $questions['b::urgency']['instructions']);
            $this->assertStringNotContainsString('`records.b`', $questions['a::urgency']['instructions']);

            return true;
        });
    }

    public function test_an_explicit_placeholder_is_used_where_it_is_written(): void
    {
        $this->fakeJev(['a::urgency' => $this->score(1.0)]);

        Batch::make()
            ->records(['a' => 'a line'])
            ->ask(['urgency' => Score::make('Rate the event at :record for urgency.')->levels(['low', 'high'])])
            ->run();

        Http::assertSent(function (Request $request) {
            $instructions = $request->data()['questions']['a::urgency']['instructions'];

            $this->assertSame('Rate the event at `records.a` for urgency.', $instructions);

            return true;
        });
    }

    public function test_a_batch_sends_the_shared_context_once(): void
    {
        $this->fakeJev([]);

        Batch::make()
            ->context(['clock' => 'Friday 20:12', 'open_incidents' => 1])
            ->records(array_combine(range('a', 'j'), array_fill(0, 10, 'ERROR something failed')))
            ->ask(['urgency' => Score::make('How urgent is :record?')->levels(['low', 'high'])])
            ->run();

        // Ten records, ten questions, one request, one copy of the context.
        Http::assertSentCount(1);

        Http::assertSent(function (Request $request) {
            $this->assertCount(10, $request->data()['questions']);
            $this->assertCount(10, $request->data()['state']['records']);
            $this->assertSame(1, $request->data()['state']['context']['open_incidents']);

            return true;
        });
    }

    public function test_a_batch_splits_when_it_passes_the_record_limit(): void
    {
        $this->fakeJev([]);

        Batch::make()
            ->chunk(4)
            ->records(array_combine(range(1, 10), array_fill(0, 10, 'a line')))
            ->ask(['urgency' => Score::make('How urgent is :record?')->levels(['low', 'high'])])
            ->run();

        Http::assertSentCount(3);
    }

    public function test_answers_come_back_keyed_by_record(): void
    {
        $this->fakeJev([
            'a::urgency' => $this->score(2.4),
            'a::noisy' => $this->noul(0.8),
            'b::urgency' => $this->score(0.1),
            'b::noisy' => $this->noul(0.1),
        ]);

        $results = Batch::make()
            ->records(['a' => 'gateway down', 'b' => 'cron finished'])
            ->ask([
                'urgency' => Score::make('How urgent is :record?')->levels(['low', 'high']),
                'noisy' => Noul::make('Is :record routine noise?'),
            ])
            ->run();

        $this->assertSame(2.4, $results['a']->get('urgency')->value);
        $this->assertSame(0.8, $results['a']->get('noisy')->value);
        $this->assertSame(0.1, $results['b']->get('urgency')->value);
    }

    public function test_an_empty_batch_asks_nothing(): void
    {
        Http::fake();

        $this->assertTrue(Batch::make()->records([])->run()->isEmpty());

        Http::assertNothingSent();
    }

    public function test_a_batch_needs_at_least_one_question(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Batch::make()->records(['a' => 'a line'])->run();
    }

    public function test_a_choice_refuses_fewer_than_two_options(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Choice::make('Which one?')->among(['only' => 'the only option'])->toPayload();
    }

    public function test_a_choice_builds_its_options_from_an_enum(): void
    {
        $payload = Choice::make('Which channel?')->among(Fixtures\Channel::class)->toPayload();

        $this->assertSame(['email', 'phone', 'portal'], array_keys($payload['criteria']));
        $this->assertSame('Written in, expects a written reply', $payload['criteria']['email']);
    }

    public function test_a_noul_carries_criteria_only_when_given(): void
    {
        $this->assertArrayNotHasKey('criteria', Noul::make('Is it raining?')->toPayload());

        $payload = Noul::make('Is it raining?')->criteria(true: 'Water falls', false: 'It does not')->toPayload();

        $this->assertSame('Water falls', $payload['criteria']['true']);
    }

    public function test_a_score_refuses_a_single_level(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Score::make('How bad?')->levels(['only one'])->toPayload();
    }
}
