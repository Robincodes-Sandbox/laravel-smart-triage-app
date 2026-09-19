<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoLock
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.lock.enabled')) {
            return $next($request);
        }

        if ($request->session()->get('demo_unlocked') === true) {
            return $next($request);
        }

        if ($request->routeIs('demo.unlock*')) {
            return $next($request);
        }

        return redirect()->route('demo.unlock');
    }
}
