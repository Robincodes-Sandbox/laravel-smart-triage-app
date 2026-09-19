<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\RepairReport;
use App\Models\Trade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Solarise\SmartTriage\Band;
use Solarise\SmartTriage\Triage;
use Tests\TestCase;

class TriageOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RepairReport::forgetTriageQuestions();

        Trade::insert([
            ['id' => 1, 'name' => 'Plumbing', 'description' => 'Leaks and pipes', 'operatives' => 4],
            ['id' => 2, 'name' => 'Roofing', 'description' => 'Roofs and gutters', 'operatives' => 2],
            ['id' => 3, 'name' => 'Surveyor', 'description' => 'Needs inspecting first', 'operatives' => 1],
        ]);
    }

    protected function report(array $overrides = []): RepairReport
    {
        $property = Property::create([
            'uprn' => '906000000001', 'address' => '1/2 Test Street', 'postcode' => 'EH1 1AA',
            'block' => 'Testville', 'archetype' => 'Tenement flat', 'built' => 1908,
            'damp_history' => true, 'household_vulnerable' => false, 'floor' => 2,
        ]);

        return RepairReport::create(array_merge([
            'property_id' => $property->id,
            'channel' => 'phone',
            'room' => 'bathroom',
            'summary' => 'Water coming through the ceiling',
            'reported_at' => now(),
            'status' => 'open',
        ], $overrides));
    }

    protected function fake(float $urgency, string $trade, float $confidence, array $probabilities, array $flags = []): void
    {
        $answers = [
            'urgency' => ['type' => 'score', 'score' => $urgency, 'confidence' => 0.9, 'probabilities' => []],
            'trade' => ['type' => 'choice', 'choice' => $trade, 'confidence' => $confidence, 'probabilities' => $probabilities],
        ];

        foreach (['damp_mould', 'vulnerability_risk', 'repeat_visit', 'access_difficulty'] as $flag) {
            $answers[$flag] = ['type' => 'noul', 'noul' => $flags[$flag] ?? 0.02];
        }

        Http::fake(['api.typesafe.ai/*' => Http::response([
            'model' => 'jev-1.13.0', 'answers' => $answers,
            'usage' => ['input_tokens' => 600, 'output_tokens' => 90],
        ])]);
    }

    public function test_a_band_reads_hours_days_and_working_days(): void
    {
        $monday = now()->setDate(2026, 9, 21)->setTime(9, 0);

        $this->assertSame(
            '2026-09-22 09:00',
            Band::make('E')->within('24 hours')->respondBy($monday)->format('Y-m-d H:i'),
        );

        // Three working days from a Monday is Thursday, not Wednesday-plus-one.
        $this->assertSame(
            '2026-09-24',
            Band::make('U')->within('3 working days')->respondBy($monday)->format('Y-m-d'),
        );

        // Seven working days crosses a weekend and lands the following Wednesday.
        $this->assertSame(
            '2026-09-30',
            Band::make('Q')->within('7 working days')->respondBy($monday)->format('Y-m-d'),
        );

        $this->assertSame(
            '2026-10-19',
            Band::make('R')->within('28 days')->respondBy($monday)->format('Y-m-d'),
        );
    }

    public function test_a_triage_builds_one_question_set_from_its_declaration(): void
    {
        $questions = RepairReport::triageQuestions();

        $this->assertSame(
            ['urgency', 'trade', 'damp_mould', 'vulnerability_risk', 'repeat_visit', 'access_difficulty'],
            array_keys($questions),
        );

        // The score's levels are the band descriptions, so policy wording and
        // prompt wording cannot drift apart.
        $levels = $questions['urgency']->toPayload()['criteria'];
        $this->assertCount(4, $levels);
        $this->assertStringContainsString('Cosmetic or minor', $levels[0]);
        $this->assertStringContainsString('Immediate risk', $levels[3]);
    }

    public function test_the_band_sets_the_deadline_from_when_it_was_reported(): void
    {
        $this->fake(urgency: 3.0, trade: 'Plumbing', confidence: 0.99, probabilities: ['Plumbing' => 0.99, 'Roofing' => 0.01]);

        $report = $this->report(['reported_at' => now()->subHours(2)]);
        $report->triage();

        $outcome = $report->fresh()->outcome();

        $this->assertSame('Emergency', $outcome->bandLabel());
        $this->assertSame('Plumbing', $outcome->owner());
        $this->assertEqualsWithDelta(22, $outcome->hoursRemaining(), 0.2);
        $this->assertFalse($outcome->overdue());
    }

    public function test_a_missed_deadline_is_reported_as_breached(): void
    {
        $this->fake(urgency: 3.0, trade: 'Plumbing', confidence: 0.99, probabilities: ['Plumbing' => 0.99, 'Roofing' => 0.01]);

        $report = $this->report(['reported_at' => now()->subHours(40)]);
        $report->triage();

        $this->assertTrue($report->fresh()->outcome()->overdue());
    }

    public function test_policy_escalation_is_kept_apart_from_model_uncertainty(): void
    {
        // Confident routing, but damp and mould fired — which carries a
        // statutory clock regardless of how sure the model was.
        $this->fake(
            urgency: 1.0, trade: 'Plumbing', confidence: 0.99,
            probabilities: ['Plumbing' => 0.99, 'Roofing' => 0.01],
            flags: ['damp_mould' => 0.95],
        );

        $outcome = tap($this->report())->triage()->fresh()->outcome();

        $this->assertSame(['damp mould'], $outcome->escalations());
        $this->assertSame([], $outcome->uncertainties());
        $this->assertTrue($outcome->escalated());
        $this->assertFalse($outcome->uncertain());
        $this->assertTrue($outcome->needsHuman());
    }

    public function test_a_nearly_tied_route_is_held_back(): void
    {
        $this->fake(
            urgency: 3.0, trade: 'Roofing', confidence: 0.42,
            probabilities: ['Roofing' => 0.46, 'Surveyor' => 0.44, 'Plumbing' => 0.10],
        );

        $outcome = tap($this->report())->triage()->fresh()->outcome();

        $this->assertTrue($outcome->uncertain());
        $this->assertFalse($outcome->escalated());
        $this->assertStringContainsString('routing confidence 0.42', $outcome->uncertainties()[0]);
        $this->assertStringContainsString('Roofing and Surveyor nearly tied', $outcome->uncertainties()[1]);
    }

    public function test_a_score_sitting_between_bands_is_held_back(): void
    {
        // 1.48 and 1.52 are the same judgement but land in bands whose
        // deadlines are three weeks apart.
        $this->fake(urgency: 1.48, trade: 'Plumbing', confidence: 0.99, probabilities: ['Plumbing' => 0.99, 'Roofing' => 0.01]);

        $outcome = tap($this->report())->triage()->fresh()->outcome();

        $this->assertTrue($outcome->uncertain());
        $this->assertStringContainsString('sits between bands', implode(' ', $outcome->uncertainties()));
    }

    public function test_a_confident_middling_score_passes_straight_through(): void
    {
        $this->fake(urgency: 2.05, trade: 'Plumbing', confidence: 0.97, probabilities: ['Plumbing' => 0.97, 'Roofing' => 0.03]);

        $outcome = tap($this->report())->triage()->fresh()->outcome();

        $this->assertSame('Urgent', $outcome->bandLabel());
        $this->assertFalse($outcome->needsHuman());
        $this->assertSame([], $outcome->reasons());
    }

    public function test_flags_only_fire_above_their_threshold(): void
    {
        $this->fake(
            urgency: 1.0, trade: 'Plumbing', confidence: 0.99,
            probabilities: ['Plumbing' => 0.99, 'Roofing' => 0.01],
            flags: ['repeat_visit' => 0.71, 'access_difficulty' => 0.69],
        );

        $flags = tap($this->report())->triage()->fresh()->outcome()->flags();

        $this->assertArrayHasKey('repeat_visit', $flags);
        $this->assertArrayNotHasKey('access_difficulty', $flags);
    }

    public function test_a_triage_refuses_a_single_band(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Triage::make()->bands([Band::make('Only')->within('1 day')]);
    }
}
