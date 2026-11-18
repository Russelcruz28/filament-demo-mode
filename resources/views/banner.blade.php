@php
    $demoManager = app(\DemoMode\DemoManager::class);
    $inDemo = $demoManager->active();
    $visitor = $inDemo && $demoManager->isVisitor();
    // A one-time explanation of what just changed, shown only where it applies.
    $notice = session('demo_mode_notice');
    $notices = $inDemo ? [
        'entered' => $visitor
            ? 'Welcome! You are signed in to a demo. Explore freely — nothing you do here changes real records.'
            : 'You entered the demo. Changes stay in the demo until you exit.',
        'refreshed' => 'The demo was reset to fresh data. Earlier changes made in the demo were discarded.',
    ] : [
        'exited' => 'You left demo mode. You are now working with live data — changes affect real records.',
        'removed' => 'Demo access was removed from your account. You are now working with live data — changes affect real records.',
        'ended' => 'The demo was stopped. You are now working with live data — changes affect real records.',
    ];
    $message = config('demo-mode.notices', true) ? ($notices[$notice] ?? null) : null;
    if ($message !== null) {
        session()->forget('demo_mode_notice');
    }
@endphp
@if ($inDemo || $message)
    <style>
        .demo-mode-bar { display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:4px 12px;padding:8px 16px;font-size:14px;line-height:1.4;text-align:center; }
        .demo-mode-bar--demo { background:#fef3c7;color:#78350f;border-bottom:1px solid #fcd34d; }
        .demo-mode-bar--live { background:#dbeafe;color:#1e3a8a;border-bottom:1px solid #93c5fd; }
        .demo-mode-bar__label { display:inline-flex;align-items:center;gap:6px;font-weight:700;text-transform:uppercase;letter-spacing:.04em; }
        .demo-mode-bar__notice { flex-basis:100%;font-weight:600; }
        .demo-mode-bar a, .demo-mode-bar button { display:inline-flex;align-items:center;gap:6px;min-height:32px;padding:4px 0;border:0;background:transparent;color:inherit;font:inherit;line-height:1.2;text-decoration:underline;white-space:nowrap;cursor:pointer; }
        .demo-mode-bar a:focus-visible, .demo-mode-bar button:focus-visible { outline:2px solid currentColor;outline-offset:3px; }
        .demo-mode-bar form { margin:0; }
    </style>
@endif
@if ($inDemo)
    <div class="demo-mode-bar demo-mode-bar--demo" role="status" data-demo-mode-bar>
        @if ($message)
            <span class="demo-mode-bar__notice">{{ $message }}</span>
        @endif
        <span class="demo-mode-bar__label">
            <x-filament::icon icon="heroicon-o-beaker" style="width:16px;height:16px;" />
            Demo mode
        </span>
        <span>You are using a practice copy. Changes here do not affect real data.</span>
        @if ($visitor)
            <form method="POST" action="{{ route('demo-mode.exit') }}">
                @csrf
                <button type="submit">
                    <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" style="width:16px;height:16px;" />
                    Sign out
                </button>
            </form>
        @else
            <a href="{{ route('demo-mode.roles') }}">
                <x-filament::icon icon="heroicon-o-users" style="width:16px;height:16px;" />
                Switch role
            </a>
            <form method="POST" action="{{ route('demo-mode.reset') }}" onsubmit="return confirm('Reset all changes in this demo?')">
                @csrf
                <button type="submit">
                    <x-filament::icon icon="heroicon-o-arrow-path" style="width:16px;height:16px;" />
                    Reset demo
                </button>
            </form>
            <form method="POST" action="{{ route('demo-mode.exit') }}">
                @csrf
                <button type="submit">
                    <x-filament::icon icon="heroicon-o-arrow-right-start-on-rectangle" style="width:16px;height:16px;" />
                    Exit demo
                </button>
            </form>
        @endif
    </div>
    <script>
        // Mark the browser tab so the demo is recognisable even when the page is in the background.
        if (!document.title.startsWith('[Demo] ')) document.title = '[Demo] ' + document.title;
    </script>
@elseif ($message)
    <div class="demo-mode-bar demo-mode-bar--live" role="status" data-demo-mode-bar>
        <span class="demo-mode-bar__label">
            <x-filament::icon icon="heroicon-o-information-circle" style="width:16px;height:16px;" />
            Live data
        </span>
        <span>{{ $message }}</span>
        <button type="button" onclick="this.closest('[data-demo-mode-bar]').remove()" aria-label="Dismiss">Dismiss</button>
    </div>
@endif
