<?php

namespace DemoMode\Http;

use Closure;
use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\DemoManager;
use DemoMode\SandboxRuntime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DemoMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $manager = app(DemoManager::class);
        // Livewire may also run persistent middleware in the same request.
        if ($manager->active() || ! $request->hasSession() || ! ($state = $request->session()->get('demo_mode'))) {
            return $next($request);
        }
        $adapter = app(ApplicationAdapter::class);
        $user = Auth::user();
        if (! config('demo-mode.enabled') || ! $adapter->canManage($user)
            || (string) $user?->getAuthIdentifier() !== (string) ($state['owner'] ?? '')
            || ! Str::isUuid($state['token'] ?? '')
            || ! is_file($manager->directory($state['token']).'/database.sqlite')) {
            $manager->stop(preserve: false);

            return redirect($adapter->destination());
        }
        $runtime = app(SandboxRuntime::class);
        if ($request->routeIs('demo-mode.exit', 'demo-mode.reset', 'demo-mode.provisioning', 'demo-mode.provisioning.*')) {
            return $next($request);
        }
        try {
            $runtime->enter($state['token']);
            Auth::forgetGuards();
            abort_unless(Auth::check(), 403);
            $adapter->synchronizeRole(Auth::user());

            return $next($request);
        } finally {
            $runtime->leave();
            Auth::forgetGuards();
        }
    }
}
