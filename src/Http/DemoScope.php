<?php

namespace DemoMode\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Decides whether a request belongs to demo mode. Demo mode runs only on panels that register
 * DemoModePlugin and on routes that opt in with the `demo-mode` middleware; every other page in
 * the application keeps serving live data as if the package were not installed.
 */
class DemoScope
{
    /** Endpoints shared by every panel: they follow the session of the page that called them. */
    private const SHARED = ['demo-mode.*', 'livewire.upload-file', 'livewire.preview-file', 'filament.exports.*', 'filament.imports.*'];

    public function covers(Request $request): bool
    {
        $route = $request->route();
        if (! $route instanceof Route) {
            return false;
        }
        // Livewire updates arrive on one endpoint for every panel; use the page that rendered the component.
        if (Str::endsWith((string) $route->getName(), 'livewire.update')) {
            return $this->coversLivewireUpdate($request);
        }

        return $this->routeCovered($route);
    }

    private function coversLivewireUpdate(Request $request): bool
    {
        foreach ((array) $request->input('components', []) as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $path = $snapshot['memo']['path'] ?? null;
            if (! is_string($path)) {
                continue;
            }
            // The snapshot checksum is verified by Livewire before any component code runs.
            $original = $this->match($request, $path, (string) ($snapshot['memo']['method'] ?? 'GET'));

            return $original !== null && $this->routeCovered($original);
        }

        return false;
    }

    private function routeCovered(Route $route): bool
    {
        if ($route->named(self::SHARED)) {
            return true;
        }
        $aliases = app('router')->getMiddleware();
        // Only middleware attached to the route itself counts: the `web` group always carries
        // DemoMiddleware so that it can act on the shared endpoints above.
        foreach ($route->middleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            $name = Str::before($middleware, ':');
            if (($aliases[$name] ?? $name) === DemoMiddleware::class) {
                return true;
            }
        }

        return false;
    }

    private function match(Request $request, string $path, string $method): ?Route
    {
        $fake = Request::create($request->getSchemeAndHttpHost().'/'.ltrim($path, '/'), strtoupper($method));
        try {
            return app('router')->getRoutes()->match($fake);
        } catch (HttpExceptionInterface) {
            return null;
        }
    }
}
