@if (app(\DemoMode\DemoManager::class)->active())
    <style>
        .demo-mode-banner { display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:4px 12px;padding:8px 16px;background:#fef3c7;color:#78350f;font-size:14px; }
        .demo-mode-banner a, .demo-mode-banner button { display:inline-flex;align-items:center;gap:6px;min-height:32px;padding:4px 0;border:0;background:transparent;color:inherit;font:inherit;line-height:1.2;text-decoration:underline;white-space:nowrap;cursor:pointer; }
        .demo-mode-banner a:focus-visible, .demo-mode-banner button:focus-visible { outline:2px solid #78350f;outline-offset:3px; }
        .demo-mode-banner form { margin:0; }
    </style>
    <div class="demo-mode-banner" role="status">
        <strong>Demo mode</strong>
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
    </div>
@endif
