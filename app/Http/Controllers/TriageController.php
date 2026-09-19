<?php

namespace App\Http\Controllers;

use App\Models\LogEvent;
use App\Support\SystemContext;
use Illuminate\Http\Request;
use Solarise\SmartTriage\Batch;
use Solarise\SmartTriage\Exceptions\TriageException;

class TriageController extends Controller
{
    public function index()
    {
        return view('triage', [
            'events' => LogEvent::with('judgements')->get(),
            'situation' => session('situation', 'quiet'),
            'situations' => SystemContext::SITUATIONS,
            'lastRun' => session('last_run'),
        ]);
    }

    public function triage(Request $request)
    {
        $situation = $request->input('situation', 'quiet');
        session(['situation' => $situation]);

        $events = LogEvent::all();
        $startedAt = microtime(true);

        try {
            // One shared context, one question set, every line in as few
            // requests as the state cap allows. The lines share a minute, so
            // they share a situation — sending it once is the honest shape as
            // well as the cheap one.
            $results = Batch::make()
                ->context(SystemContext::for($situation))
                ->ask(LogEvent::triageQuestions())
                ->records($events, as: fn (LogEvent $e) => sprintf(
                    '%s %s (x%d) %s', $e->level, $e->service, $e->occurrences, $e->message
                ))
                ->store()
                ->run();
        } catch (TriageException $e) {
            return back()->withErrors(['jev' => $e->getMessage()]);
        }

        session(['last_run' => [
            'ms' => round((microtime(true) - $startedAt) * 1000),
            'records' => $results->count(),
            'questions' => $results->count() * count(LogEvent::triageQuestions()),
            'tokens' => $results->first()?->batchInputTokens() ?? 0,
            'situation' => $situation,
        ]]);

        return redirect()->route('triage');
    }
}
