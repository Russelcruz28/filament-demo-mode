<?php

namespace DemoMode;

use DemoMode\Commands\DoctorDemoMode;
use DemoMode\Commands\InstallDemoMode;
use DemoMode\Commands\PruneDemos;
use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\Contracts\RoleSwitcher;
use DemoMode\Http\DemoMiddleware;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DemoModeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/demo-mode.php', 'demo-mode');
        $this->app->singleton(DemoManager::class);
        $this->app->singleton(DemoModeOptions::class);
        $this->app->bind(ApplicationAdapter::class, fn () => app(config('demo-mode.adapter')));
        // Database sessions must keep their original connection when the default changes.
        if (! config('session.connection')) {
            config(['session.connection' => config('database.default')]);
        }
    }

    public function boot(Kernel $kernel): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'demo-mode');
        if ($this->app->runningInConsole()) {
            $this->commands([PruneDemos::class, InstallDemoMode::class, DoctorDemoMode::class]);
        }
        $this->publishes([__DIR__.'/../config/demo-mode.php' => config_path('demo-mode.php')], 'demo-mode-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/demo-mode')], 'demo-mode-views');
        $kernel->appendMiddlewareToGroup('web', DemoMiddleware::class);
        $kernel->addToMiddlewarePriorityAfter(StartSession::class, DemoMiddleware::class);
        Livewire::addPersistentMiddleware([DemoMiddleware::class]);
        Livewire::listen('dehydrate', function ($component, $context): void {
            $context->addMemo('demo_mode_token', session('demo_mode.token'));
        });
        Livewire::listen('hydrate', function ($component, $memo): void {
            abort_unless(($memo['demo_mode_token'] ?? null) === session('demo_mode.token'), 409,
                'Demo mode changed. Reload this page before continuing.');
        });

        $guardConnection = function ($connection): void {
            $connection->beforeExecuting(function ($query, $bindings, $connection): void {
                if (app(DemoManager::class)->active() && $connection->getName() !== 'demo') {
                    throw new \LogicException('Demo mode blocked access to a non-demo database connection.');
                }
            });
        };
        Event::listen(ConnectionEstablished::class, fn ($event) => $guardConnection($event->connection));
        foreach (DB::getConnections() as $connection) {
            $guardConnection($connection);
        }
        Event::listen(NotificationSending::class, function ($event) {
            if (app(DemoManager::class)->active() && $event->channel !== 'database') {
                return false;
            }
        });

        Route::middleware('web')->group(function (): void {
            Route::get('/demo-mode/roles', function () {
                abort_unless(app(DemoManager::class)->active(), 403);
                $adapter = app(ApplicationAdapter::class);
                abort_unless($adapter instanceof RoleSwitcher, 501, 'Configure the demo role switcher adapter.');

                return view('demo-mode::roles', ['roles' => $adapter->roles(auth()->user())]);
            })->name('demo-mode.roles');
            Route::post('/demo-mode/roles', function (Request $request) {
                abort_unless(app(DemoManager::class)->active(), 403);
                $adapter = app(ApplicationAdapter::class);
                abort_unless($adapter instanceof RoleSwitcher, 501);
                $data = $request->validate(['role' => ['required', 'string', 'max:255']]);

                return redirect($adapter->switchRole(auth()->user(), $data['role']));
            })->name('demo-mode.roles.store');
            Route::get('/demo-mode/files/{disk}/{path}', function (string $disk, string $path) {
                abort_unless(app(DemoManager::class)->active(), 403);
                abort_unless(array_key_exists($disk, config('filesystems.disks')), 404);
                abort_unless(Storage::disk($disk)->exists($path), 404);

                return Storage::disk($disk)->response($path);
            })->where('path', '.*')->name('demo-mode.files');
            Route::post('/demo-mode/exit', function () {
                abort_unless(session()->has('demo_mode'), 403);
                app(DemoManager::class)->stop();

                return redirect(app(ApplicationAdapter::class)->destination());
            })->name('demo-mode.exit');
            Route::post('/demo-mode/reset', function () {
                $state = session('demo_mode');
                abort_unless($state && app(DemoManager::class)->canManage()
                    && (string) auth()->id() === (string) $state['owner'], 403);
                app(DemoManager::class)->begin();

                return redirect()->route('demo-mode.provisioning');
            })->name('demo-mode.reset');
            Route::get('/demo-mode/provisioning', function () {
                $progress = app(DemoManager::class)->provisioning();
                $destination = app(ApplicationAdapter::class)->destination();
                if ($progress === null) {
                    return redirect($destination);
                }

                return view('demo-mode::provisioning', ['progress' => $progress, 'back' => $destination]);
            })->name('demo-mode.provisioning');
            Route::post('/demo-mode/provisioning', function () {
                try {
                    return response()->json(app(DemoManager::class)->advance());
                } catch (HttpExceptionInterface $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    report($exception);

                    return response()->json(['status' => 'failed', 'message' => $exception instanceof ProvisioningFailed
                        ? $exception->getMessage()
                        : 'The demo could not be prepared. Check the selected models and server logs, then try again.',
                    ], 422);
                }
            })->name('demo-mode.provisioning.step');
            Route::post('/demo-mode/provisioning/cancel', function () {
                app(DemoManager::class)->cancel();

                return redirect(app(ApplicationAdapter::class)->destination());
            })->name('demo-mode.provisioning.cancel');
        });

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            fn () => view('demo-mode::banner'),
        );
    }
}
