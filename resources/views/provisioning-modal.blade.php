@php($progress = app(\DemoMode\DemoManager::class)->provisioning())
@if ($progress !== null)
    <div x-data x-init="$nextTick(() => $dispatch('open-modal', { id: 'demo-mode-provisioning' }))">
        <x-filament::modal
            id="demo-mode-provisioning"
            heading="Preparing demo"
            description="Copying data into a private sandbox for demo users. Keep this page open."
            width="lg"
            :close-button="false"
            :close-by-clicking-away="false"
            :close-by-escaping="false"
        >
            <div data-demo-provisioning>
                <div role="progressbar" aria-label="Demo preparation" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}"
                    style="height:10px;overflow:hidden;border-radius:9999px;background:var(--gray-200);">
                    <div data-bar style="width:{{ $progress['percent'] }}%;height:100%;background:var(--primary-600);transition:width .3s ease;"></div>
                </div>
                <div aria-live="polite" style="display:flex;justify-content:space-between;gap:16px;margin-top:10px;font-size:14px;overflow-wrap:anywhere;">
                    <span data-message>{{ $progress['message'] }}</span>
                    <span data-percent>{{ $progress['percent'] }}%</span>
                </div>
                <ul data-warnings @if (empty($progress['warnings'])) hidden @endif style="margin-top:12px;padding-left:18px;list-style:disc;font-size:13px;color:var(--warning-700);overflow-wrap:anywhere;">
                    @foreach ($progress['warnings'] ?? [] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
                <p role="alert" data-error hidden style="margin-top:12px;font-size:14px;color:var(--danger-600);"></p>
            </div>

            <x-slot name="footerActions">
                <form method="POST" action="{{ route('demo-mode.provisioning.cancel') }}" data-demo-provisioning-cancel>
                    @csrf
                    <x-filament::button type="submit" color="gray" data-cancel-label>Cancel</x-filament::button>
                </form>
            </x-slot>
        </x-filament::modal>
    </div>

    <script>
        (() => {
            const STEP_URL = @json(route('demo-mode.provisioning.step'));
            const CSRF_TOKEN = @json(csrf_token());
            // Progress is saved after every batch, so retries resume where the last step stopped.
            const MAX_RETRIES = 8;
            const MAX_DELAY_MS = 15000;
            const root = document.querySelector('[data-demo-provisioning]');
            const cancelForm = document.querySelector('[data-demo-provisioning-cancel]');
            const bar = root.querySelector('[role="progressbar"]');
            const message = root.querySelector('[data-message]');
            const percent = root.querySelector('[data-percent]');
            const error = root.querySelector('[data-error]');
            const warnings = root.querySelector('[data-warnings]');
            let cancelled = false;
            let retries = 0;

            cancelForm.addEventListener('submit', () => { cancelled = true; });

            const show = (progress) => {
                bar.setAttribute('aria-valuenow', progress.percent);
                root.querySelector('[data-bar]').style.width = progress.percent + '%';
                message.textContent = progress.message;
                percent.textContent = progress.percent + '%';
                const items = progress.warnings || [];
                warnings.replaceChildren(...items.map((text) => Object.assign(document.createElement('li'), { textContent: text })));
                warnings.hidden = items.length === 0;
            };

            // Cancelling also clears any half-built sandbox, so it doubles as "Close" after a failure.
            const fail = (text) => {
                error.textContent = text;
                error.hidden = false;
                cancelForm.querySelector('[data-cancel-label]').textContent = 'Close';
            };

            const step = async () => {
                if (cancelled) return;
                let response;
                try {
                    response = await fetch(STEP_URL, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                } catch (exception) {
                    response = null;
                }
                if (cancelled) return;
                if (response && [401, 403, 404, 419].includes(response.status)) {
                    return fail('This demo preparation expired or is no longer available. Start the demo again.');
                }
                const body = response ? await response.json().catch(() => null) : null;
                if (body && body.status === 'failed') return fail(body.message);
                if (!response || !response.ok || !body) {
                    const reason = response ? 'HTTP ' + response.status : 'network error';
                    if (retries++ >= MAX_RETRIES) {
                        return fail('The server stopped responding (' + reason + '). Reload this page to resume, or check the server logs.');
                    }
                    message.textContent = 'Connection problem (' + reason + '). Retrying…';
                    return setTimeout(step, Math.min(MAX_DELAY_MS, 1000 * 2 ** retries));
                }
                retries = 0;
                show(body);
                if (body.status === 'complete') {
                    // Leave warnings (e.g. columns that were not copied) on screen for a moment.
                    // A background start stays on this page; reloading closes the modal and shows the notification.
                    setTimeout(() => body.redirect ? (window.location.href = body.redirect) : window.location.reload(),
                        (body.warnings || []).length ? 4000 : 0);
                    return;
                }
                step();
            };

            step();
        })();
    </script>
@endif
