@if (app(\DemoMode\DemoManager::class)->active())
    {{-- Stays visible in the sticky top bar while the page scrolls. --}}
    <span title="You are in demo mode. Changes here do not affect real data."
        style="display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:9999px;background:#fef3c7;color:#78350f;border:1px solid #fcd34d;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap;">
        <x-filament::icon icon="heroicon-o-beaker" style="width:14px;height:14px;" />
        Demo
    </span>
@endif
