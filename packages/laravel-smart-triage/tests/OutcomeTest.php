<?php

namespace Solarise\SmartTriage\Tests;

use Solarise\SmartTriage\Band;
use Solarise\SmartTriage\Tests\Concerns\FakesJev;
use Solarise\SmartTriage\Tests\Fixtures\Team;
use Solarise\SmartTriage\Tests\Fixtures\Ticket;
use Solarise\SmartTriage\Triage;

class OutcomeTest extends TestCase
{
    use FakesJev;

    protected function setUp(): void
    {
        parent::setUp();

        Ticket::forgetTriageQuestions();
        Ticket::$context = [];

        Team::insert([
            ['id' => 1, 'name' => 'Billing', 'description' => 'Charges and refunds', 'active' => true],
            ['id' => 2, 'name' => 'Technical', 'description' => 'Errors and outages', 'active' => true],
        ]);
    }

    protected function outcomeFor(array $answers, ?string $receivedAt = null)
    {
        $this->fakeJev($answers);

        $ticket = Ticket::create([
            'subject' => 'Cannot log in',
            'body' => 'Every attempt returns an error.',
            'received_at' => $receivedAt ? now()->parse($receivedAt) : now(),
        ]);

        $ticket->triage();

        return $ticket->fresh()->outcome();
    }

    public function test_a_band_reads_hours_days_and_working_days(): void
    {
        $monday = now()->setDate(2026, 9, 21)->setTime(9, 0);

        $this->assertSame('2026-09-22 09:00', Band::make('a')->within('24 hours')->respondBy($monday)->format('Y-m-d H:i'));
        $this->assertSame('2026-09-24', Band::make('b')->within('3 working days')->respondBy($monday)->format('Y-m-d'));
        $this->assertSame('2026-09-30', Band::make('c')->within('7 working days')->respondBy($monday)->format('Y-m-d'));
        $this->assertSame('2026-10-19', Band::make('d')->within('28 days')->respondBy($monday)->format('Y-m-d'));
    }

    public function test_an_unreadable_deadline_says_so(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot read the deadline/');

        Band::make('x')->within('a fortnight')->respondBy(now());
    }

    public function test_a_triage_builds_one_question_set_from_its_declaration(): void
    {
        $questions = Ticket::triageQuestions();

        $this->assertSame(['urgency', 'team', 'refund_requested', 'threatens_legal'], array_keys($questions));

        // The urgency levels are the band descriptions, so policy wording and
        // prompt wording cannot drift apart.
        $levels = $questions['urgency']->toPayload()['criteria'];
        $this->assertCount(3, $levels);
        $this->assertStringContainsString('Nothing is broken', $levels[0]);
    }

    public function test_the_band_sets_the_deadline_from_when_it_arrived(): void
    {
        $outcome = $this->outcomeFor($this->ticketAnswers(urgency: 2.0), '-1 hour');

        $this->assertSame('High', $outcome->bandLabel());
        $this->assertSame('Billing', $outcome->owner());
        $this->assertEqualsWithDelta(3, $outcome->hoursRemaining(), 0.2);
        $this->assertFalse($outcome->overdue());
    }

    public function test_a_missed_deadline_is_reported_as_breached(): void
    {
        $outcome = $this->outcomeFor($this->ticketAnswers(urgency: 2.0), '-9 hours');

        $this->assertTrue($outcome->overdue());
        $this->assertLessThan(0, $outcome->hoursRemaining());
    }

    public function test_policy_escalation_is_kept_apart_from_model_uncertainty(): void
    {
        // Confident routing, but the legal flag fired — which matters however
        // sure the model was about anything else.
        $outcome = $this->outcomeFor(
            $this->ticketAnswers(urgency: 0.0, confidence: 0.98, flags: ['threatens_legal' => 0.93])
        );

        $this->assertSame(['threatens legal'], $outcome->escalations());
        $this->assertSame([], $outcome->uncertainties());
        $this->assertTrue($outcome->escalated());
        $this->assertFalse($outcome->uncertain());
        $this->assertTrue($outcome->needsHuman());
    }

    public function test_a_nearly_tied_route_is_held_back(): void
    {
        $outcome = $this->outcomeFor([
            'urgency' => $this->score(2.0),
            'team' => $this->choice('Billing', 0.47, ['Billing' => 0.47, 'Technical' => 0.45, 'other' => 0.08]),
            'refund_requested' => $this->noul(0.02),
            'threatens_legal' => $this->noul(0.02),
        ]);

        $this->assertTrue($outcome->uncertain());
        $this->assertFalse($outcome->escalated());
        $this->assertStringContainsString('routing confidence 0.47', $outcome->uncertainties()[0]);
        $this->assertStringContainsString('Billing and Technical nearly tied', $outcome->uncertainties()[1]);
    }

    public function test_a_score_sitting_between_bands_is_held_back(): void
    {
        // 1.48 and 1.52 are the same judgement but land in bands whose
        // deadlines are days apart.
        $outcome = $this->outcomeFor($this->ticketAnswers(urgency: 1.48));

        $this->assertTrue($outcome->uncertain());
        $this->assertStringContainsString('sits between bands', implode(' ', $outcome->uncertainties()));
    }

    public function test_a_confident_middling_score_passes_straight_through(): void
    {
        $outcome = $this->outcomeFor($this->ticketAnswers(urgency: 1.05, confidence: 0.97));

        $this->assertSame('Normal', $outcome->bandLabel());
        $this->assertFalse($outcome->needsHuman());
        $this->assertSame([], $outcome->reasons());
    }

    public function test_flags_only_fire_above_their_threshold(): void
    {
        $outcome = $this->outcomeFor(
            $this->ticketAnswers(flags: ['refund_requested' => 0.71, 'threatens_legal' => 0.69])
        );

        $this->assertArrayHasKey('refund_requested', $outcome->flags());
        $this->assertArrayNotHasKey('threatens_legal', $outcome->flags());
        $this->assertFalse($outcome->escalated());
    }

    public function test_a_triage_refuses_a_single_band(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Triage::make()->bands([Band::make('Only')->within('1 day')]);
    }

    public function test_a_triage_without_bands_cannot_ask_anything(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs bands/');

        Triage::make()->urgency('How urgent?')->questions();
    }
}
