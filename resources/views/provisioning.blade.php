<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Preparing demo - {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8faf9; color: #202724; font: 15px/1.5 system-ui, sans-serif; letter-spacing: 0; }
        header { display: flex; align-items: center; gap: 10px; padding: 18px max(20px, calc((100% - 800px)/2)); border-bottom: 1px solid #dde3e0; background: #fff; }
        header svg { width: 24px; height: 24px; color: #8d1436; flex: none; }
        header strong { font-size: 18px; overflow-wrap: anywhere; }
        main { max-width: 800px; margin: 0 auto; padding: 32px 20px 48px; }
        h1 { margin: 0 0 8px; font-size: 28px; line-height: 1.2; }
        .hint { margin: 0 0 24px; color: #535e58; }
        .track { height: 12px; overflow: hidden; background: #e7ece9; border-radius: 6px; }
        .bar { width: 0; height: 100%; background: #8d1436; transition: width .3s ease; }
        .status { display: flex; justify-content: space-between; gap: 16px; margin: 10px 0 24px; overflow-wrap: anywhere; }
        .error { margin: 0 0 16px; color: #9a1834; }
        .actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 20px; border: 1px solid #d6deda; border-radius: 6px; color: #202724; background: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .button:hover { border-color: #8d1436; }
        :focus-visible { outline: 2px solid #00563f; outline-offset: 3px; }
        [hidden] { display: none !important; }
        @media (max-width: 540px) { main { padding-top: 24px; } .button { width: 100%; } }
    </style>
</head>
<body>
    <header><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 0-6.23-.693L5 14.5m14.8.8 1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0 1 12 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5" /></svg><strong>{{ config('app.name') }} - Demo Mode</strong></header>
    <main>
        <h1>Preparing demo</h1>
        <p class="hint">Copying data into a private sandbox. Keep this page open.</p>
        <div class="track" role="progressbar" aria-label="Demo preparation" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}">
            <div class="bar" style="width: {{ $progress['percent'] }}%"></div>
        </div>
        <div class="status" aria-live="polite">
            <span data-message>{{ $progress['message'] }}</span>
            <span data-percent>{{ $progress['percent'] }}%</span>
        </div>
        <p class="error" role="alert" data-error hidden></p>
        <div class="actions">
            <form method="POST" action="{{ route('demo-mode.provisioning.cancel') }}" data-cancel>
                @csrf
                <button class="button" type="submit">Cancel</button>
            </form>
            <a class="button" href="{{ $back }}" data-back hidden>Back</a>
        </div>
    </main>
    <script>
        (() => {
            const STEP_URL = @json(route('demo-mode.provisioning.step'));
            const MAX_RETRIES = 3;
            const token = document.querySelector('meta[name="csrf-token"]').content;
            const bar = document.querySelector('[role="progressbar"]');
            const message = document.querySelector('[data-message]');
            const percent = document.querySelector('[data-percent]');
            const error = document.querySelector('[data-error]');
            let cancelled = false;
            let retries = 0;

            document.querySelector('[data-cancel]').addEventListener('submit', () => { cancelled = true; });

            const show = (progress) => {
                bar.setAttribute('aria-valuenow', progress.percent);
                bar.firstElementChild.style.width = progress.percent + '%';
                message.textContent = progress.message;
                percent.textContent = progress.percent + '%';
            };

            const fail = (text) => {
                error.textContent = text;
                error.hidden = false;
                document.querySelector('[data-cancel]').hidden = true;
                document.querySelector('[data-back]').hidden = false;
            };

            const step = async () => {
                if (cancelled) return;
                let response;
                try {
                    response = await fetch(STEP_URL, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
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
                    if (retries++ >= MAX_RETRIES) return fail('The server stopped responding. Start the demo again.');
                    return setTimeout(step, 1000 * retries);
                }
                retries = 0;
                show(body);
                if (body.status === 'complete') {
                    window.location.href = body.redirect;
                    return;
                }
                step();
            };

            step();
        })();
    </script>
</body>
</html>
