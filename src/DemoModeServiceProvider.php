<?php

namespace DemoMode;

use DemoMode\Commands\DoctorDemoMode;
use DemoMode\Commands\InstallDemoMode;
use DemoMode\Commands\PruneDemos;
use DemoMode\Contracts\ApplicationAdapter;
use DemoMode\Contracts\LockProvider;
use DemoMode\Contracts\MetadataStore;
use DemoMode\Contracts\RoleSwitcher;
use DemoMode\Http\DemoMiddleware;
use DemoMode\Locks\CacheLock;
use DemoMode\Locks\DatabaseLock;
use DemoMode\Locks\FileLock;
use DemoMode\Metadata\DatabaseMetadataStore;
use DemoMode\Metadata\FileMetadataStore;
use DemoMode\Sandbox\ConnectionPolicy;
use DemoMode\Sandbox\SandboxDrivers;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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
        $this->app->singleton(SandboxRuntime::class);
        $this->app->singleton(SandboxDrivers::class);
        $this->app->bind(MetadataStore::class, fn () => app(match (config('demo-mode.metadata', 'file')) {
            'database' => DatabaseMetadataStore::class,
            'file' => FileMetadataStore::class,
            default => config('demo-mode.metadata'),
        }));
        $this->app->bind(LockProvider::class, fn () => app(match (config('demo-mode.lock', 'file')) {
            'cache' => CacheLock::class,
            'database' => DatabaseLock::class,
            'file' => FileLock::class,
            default => config('demo-mode.lock'),
        }));
        $this->app->bind(ApplicationAdapter::class, fn () => app(config('demo-mode.adapter')));
        // Database sessions must keep their original connection when the default changes.
        if (! config('session.connection')) {
            config(['session.connection' => config('database.default')]);
        }
    }

    public function boot(Kernel $kernel): void
    {
        $this->app->make(ExceptionHandler::class)->renderable(function (QueryException $exception, Request $request) {
            // Missing table or column: SQLite messages, PostgreSQL 42P01/42703, MySQL 42S02/42S22.
            $missing = in_array($exception->errorInfo[0] ?? null, ['42P01', '42703', '42S02', '42S22'], true)
                || preg_match('/no such (table|column):|has no column named/i', $exception->getPrevious()?->getMessage() ?? '');
            if ($exception->getConnectionName() !== 'demo' || ! $request->hasSession()
                || ! $request->session()->has('demo_mode') || ! $missing) {
                return null;
            }
            $url = route('demo-mode.recovery');
            if ($request->hasHeader('X-Livewire')) {
                return response()->json(['components' => array_map(
                    fn ($component) => ['snapshot' => $component['snapshot'] ?? null, 'effects' => ['redirect' => $url]],
                    $request->input('components', []),
                )]);
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => 'The saved demo is out of date. Reset the demo to use the current database structure.', 'recovery_url' => $url], 409);
            }

            return redirect($url);
        });
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

        // Sandbox connections carry the active token. Anything else must be explicitly allowed; this also
        // blocks stale production connections whose name is aliased to the sandbox.
        $guardConnection = function ($connection): void {
            $connection->beforeExecuting(function ($query, $bindings, $connection): void {
                if (! app(DemoManager::class)->active()) {
                    return;
                }
                $runtime = app(SandboxRuntime::class);
                if ($connection->getConfig('demo_mode') !== $runtime->token()
                    && $runtime->policy($connection->getName()) !== ConnectionPolicy::ALLOW) {
                    throw new \LogicException('Demo mode blocked access to a non-demo database connection ['.$connection->getName().'].');
                }
            });
        };
        Event::listen(ConnectionEstablished::class, fn ($event) => $guardConnection($event->connection));
        foreach (DB::getConnections() as $connection) {
            $guardConnection($connection);
        }
        Blade::if('demomode', fn (): bool => app(DemoManager::class)->active());
        Event::listen(NotificationSending::class, function ($event) {
            if (app(DemoManager::class)->active() && $event->channel !== 'database') {
                return false;
            }
        });

        Route::middleware('web')->group(function (): void {
            Route::get('/demo-mode/recovery', function () {
                $manager = app(DemoManager::class);
                abort_unless(session()->has('demo_mode') && ($manager->canManage() || $manager->isVisitor()), 403);

                return response()->view('demo-mode::recovery', ['visitor' => $manager->isVisitor()]);
            })->name('demo-mode.recovery');
            Route::get('/demo-mode/unavailable', fn () => response()->view('demo-mode::unavailable', [
                'message' => session('demo_mode_unavailable', 'The demo is not available right now.'),
            ], 503))->name('demo-mode.unavailable');
            Route::get('/demo-mode/roles', function () {
                // Demo users keep the roles their account has; only presenters switch.
                abort_unless(app(DemoManager::class)->active() && ! app(DemoManager::class)->isVisitor(), 403);
                $adapter = app(ApplicationAdapter::class);
                abort_unless($adapter instanceof RoleSwitcher, 501, 'Configure the demo role switcher adapter.');

                return view('demo-mode::roles', ['roles' => $adapter->roles(auth()->user())]);
            })->name('demo-mode.roles');
            Route::post('/demo-mode/roles', function (Request $request) {
                abort_unless(app(DemoManager::class)->active() && ! app(DemoManager::class)->isVisitor(), 403);
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
            Route::post('/demo-mode/exit', function (Request $request) {
                abort_unless(session()->has('demo_mode'), 403);
                // Demo users have no production session to return to.
                if (app(DemoManager::class)->isVisitor()) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect(app(ApplicationAdapter::class)->destination());
                }
                app(DemoManager::class)->stop();

                return redirect(app(ApplicationAdapter::class)->destination());
            })->name('demo-mode.exit');
            Route::post('/demo-mode/reset', function () {
                $state = session('demo_mode');
                abort_unless($state && app(DemoManager::class)->canManage()
                    && (string) auth()->id() === (string) $state['owner'], 403);
                app(DemoManager::class)->begin();

                // The provisioning modal on the previous page picks up the new demo.
                return redirect()->back();
            })->name('demo-mode.reset');
            // Progress is shown in a modal on panel pages; this URL remains for existing links.
            Route::get('/demo-mode/provisioning', fn () => redirect(app(ApplicationAdapter::class)->destination()))
                ->name('demo-mode.provisioning');
            Route::post('/demo-mode/provisioning', function () {
                try {
                    $progress = app(DemoManager::class)->advance();
                    if ($progress['status'] === 'complete' && $progress['redirect'] === null) {
                        $users = count(app(DemoManager::class)->demoUserIds());
                        Notification::make()->title('Demo is live')->success()
                            ->body($users === 1 ? '1 demo user now signs in to this demo.' : "{$users} demo users now sign in to this demo.")
                            ->send();
                    }

                    return response()->json($progress);
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
        FilamentView::registerRenderHook(
            PanelsRenderHook::USER_MENU_BEFORE,
            fn () => view('demo-mode::topbar-badge'),
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            fn () => view('demo-mode::provisioning-modal'),
        );
    }
}
