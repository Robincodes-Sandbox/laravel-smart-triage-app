<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class DemoLockController extends Controller
{
    public function show()
    {
        if (! config('demo.lock.enabled')) {
            return redirect()->route('repairs');
        }

        return response()->view('lock', [
            // No password configured means the lock cannot be opened, which is
            // the right answer when an env var has gone missing on a server.
            'configured' => filled(config('demo.lock.password')),
        ], 401);
    }

    public function unlock(Request $request)
    {
        $key = 'demo-unlock:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            return back()->withErrors([
                'password' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $expected = config('demo.lock.password');

        if (filled($expected) && hash_equals((string) $expected, (string) $request->input('password'))) {
            RateLimiter::clear($key);
            $request->session()->regenerate();
            $request->session()->put('demo_unlocked', true);

            return redirect()->route('repairs');
        }

        RateLimiter::hit($key, 900);

        return back()->withErrors(['password' => 'That is not the password.']);
    }
}
