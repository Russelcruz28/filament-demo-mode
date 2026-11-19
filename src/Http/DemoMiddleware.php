<?php

namespace DemoMode\Http;

use Closure;
use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\DemoManager;
use DemoMode\SandboxRuntime;
use Filament\Facades\Filament;
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
        if ($manager->active() || ! $request->hasSession()) {
            return $next($request);
        }
        $state = $request->session()->get('demo_mode');
        $user = Auth::user();
        $inScope = app(DemoScope::class)->covers($request);
        if ($user !== null && $manager->isDemoUser($user)) {
            // Demo users never reach live data: outside the demo panels they are sent back to the demo.
            return $inScope ? $this->demoUser($request, $next, $manager, $user) : $this->toDemoPanel($request);
        }
        // Other panels and routes are not part of demo mode and keep serving live data.
        if (! $inScope) {
            return $next($request);
        }
        if ($state['visitor'] ?? false) {
            // No longer tagged: the user is back on production from this request on.
            $request->session()->forget('demo_mode');
            $manager->notice('removed');

            return $next($request);
        }
        if (! $state) {
            return $next($request);
        }
        $adapter = app(ApplicationAdapter::class);
        if (! config('demo-mode.enabled') || ! $adapter->canManage($user)
            || (string) $user?->getAuthIdentifier() !== (string) ($state['owner'] ?? '')
            || ! Str::isUuid($state['token'] ?? '')
            || ! $manager->exists($state['token'])) {
            $manager->stop(preserve: false);
            $manager->notice('ended');

            return redirect($adapter->destination());
        }
        $runtime = app(SandboxRuntime::class);
        if ($request->routeIs('demo-mode.exit', 'demo-mode.reset', 'demo-mode.recovery', 'demo-mode.provisioning', 'demo-mode.provisioning.*')) {
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

    /**
     * Tagged users always run in the saved demo, keep their own copied roles, and are signed out
     * rather than ever being served from production.
     */
    private function demoUser(Request $request, Closure $next, DemoManager $manager, $user): Response
    {
        $token = $manager->currentToken();
        if ($token === null || ! $manager->exists($token)) {
            return $this->signOut($request, 'There is no demo ready for your account yet. Please try again later.');
        }
        $manager->joinAsVisitor($user, $token);
        if ($request->routeIs('demo-mode.exit', 'demo-mode.recovery', 'demo-mode.unavailable')) {
            return $next($request);
        }
        $runtime = app(SandboxRuntime::class);
        try {
            $runtime->enter($token);
            Auth::forgetGuards();
            // The account exists in production but was created after the demo was copied.
            if (Auth::check()) {
                return $next($request);
            }
        } finally {
            $runtime->leave();
            Auth::forgetGuards();
        }

        return $this->signOut($request, 'Your account was added after this demo was prepared. Ask an administrator to reset the demo.');
    }

    private function signOut(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('demo_mode_unavailable', $message);

        return $this->redirectTo($request, route('demo-mode.unavailable'), $message);
    }

    private function toDemoPanel(Request $request): Response
    {
        $panel = Filament::getPanels()[config('demo-mode.panel')] ?? null;
        abort_if($panel === null, 403, 'Demo users can only use the demo.');

        return $this->redirectTo($request, $panel->getUrl() ?? url($panel->getPath()), 'Demo users can only use the demo.');
    }

    private function redirectTo(Request $request, string $url, string $message): Response
    {
        if ($request->hasHeader('X-Livewire')) {
            return response()->json(['components' => array_map(
                fn ($component) => ['snapshot' => $component['snapshot'] ?? null, 'effects' => ['redirect' => $url]],
                (array) $request->input('components', []),
            )]);
        }

        return $request->expectsJson() ? response()->json(['message' => $message, 'redirect' => $url], 409) : redirect($url);
    }
}
