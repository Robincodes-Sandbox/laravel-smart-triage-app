<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\RepairReport;
use App\Support\OperatingContext;
use Illuminate\Http\Request;
use Solarise\SmartTriage\Exceptions\TriageException;
use Solarise\SmartTriage\Models\Judgement;

class RepairsController extends Controller
{
    public function index(Request $request)
    {
        $reports = RepairReport::with(['property', 'judgements'])
            ->where('status', 'open')
            ->get()
            // Worked in deadline order, not score order. A routine repair
            // reported 27 days ago is more pressing than an emergency raised
            // five minutes ago, and a queue sorted by urgency hides that.
            ->sortBy(fn (RepairReport $r) => $r->outcome()->hoursRemaining() ?? PHP_INT_MAX)
            ->values();

        $triaged = $reports->filter(fn ($r) => $r->judgements->isNotEmpty());
        $filter = $request->query('view', 'all');

        $visible = $triaged->filter(fn (RepairReport $r) => match ($filter) {
            'escalated' => $r->outcome()->escalated(),
            'uncertain' => $r->outcome()->uncertain(),
            'breached' => $r->outcome()->overdue(),
            'all' => true,
            default => $r->outcome()->bandLabel() === $filter,
        });

        return view('repairs', [
            'reports' => $visible->take(50),
            'showing' => $visible->count(),
            'filter' => $filter,
            'stats' => $this->stats($reports, $triaged),
            'conditions' => session('conditions', 'mild'),
            'conditionSet' => OperatingContext::CONDITIONS,
            'lastRun' => session('repairs_run'),
        ]);
    }

    public function triage(Request $request)
    {
        session(['conditions' => $request->input('conditions', 'mild')]);

        $startedAt = microtime(true);
        $done = 0;
        $failed = 0;

        try {
            RepairReport::with('property')
                ->where('status', 'open')
                ->chunkById(100, function ($chunk) use (&$done, &$failed) {
                    foreach (RepairReport::triageMany($chunk) as $result) {
                        $result instanceof TriageException ? $failed++ : $done++;
                    }
                });
        } catch (TriageException $e) {
            return back()->withErrors(['jev' => $e->getMessage()]);
        }

        $questions = count(RepairReport::triageQuestions());

        session(['repairs_run' => [
            'seconds' => round(microtime(true) - $startedAt, 1),
            'reports' => $done,
            'failed' => $failed,
            'judgements' => $done * $questions,
            'tokens' => $this->tokensUsed(),
        ]]);

        return redirect()->route('repairs');
    }

    /**
     * Input tokens spent on repair reports alone.
     *
     * Judgements from every Triageable model share one table, so an unscoped
     * sum would quietly bill the logs demo to the repairs service. Divided by
     * the question count because each row carries its whole request's total.
     */
    protected function tokensUsed(): int
    {
        return (int) (Judgement::where('triageable_type', RepairReport::class)->sum('input_tokens')
            / max(count(RepairReport::triageQuestions()), 1));
    }

    protected function stats($reports, $triaged): array
    {
        $outcomes = $triaged->map->outcome();
        $tokens = $this->tokensUsed();
        $perReport = $triaged->count() > 0 ? $tokens / $triaged->count() : 0;

        return [
            'properties' => Property::count(),
            'open' => $reports->count(),
            'triaged' => $triaged->count(),
            'emergency' => $outcomes->filter(fn ($o) => $o->bandLabel() === 'Emergency')->count(),
            'breached' => $outcomes->filter(fn ($o) => $o->overdue())->count(),
            // Kept apart deliberately: one is policy firing as designed, the
            // other is the model admitting it is not sure. Same queue in most
            // dashboards, and that is why they all look unreliable.
            'escalated' => $outcomes->filter(fn ($o) => $o->escalated())->count(),
            'uncertain' => $outcomes->filter(fn ($o) => $o->uncertain())->count(),
            'clean' => $outcomes->filter(fn ($o) => ! $o->needsHuman())->count(),
            'bands' => $outcomes->groupBy(fn ($o) => $o->bandLabel())->map->count(),
            'perReportTokens' => round($perReport),
            // The number a housing association actually asks for: what does it
            // cost to run this over a year of real volume.
            'dailyCost' => $perReport * 10_000 / 1_000_000 * 0.042,
            'annualCost' => $perReport * 10_000 * 365 / 1_000_000 * 0.042,
        ];
    }
}
