<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset demo required</title>
</head>
<body>
    <main style="max-width:640px;margin:10vh auto;padding:24px;font:16px/1.6 system-ui,sans-serif">
        <h1>Your saved demo needs a reset</h1>
        <p>The application needs a table or column that is missing from this saved demo. This can happen after a database update.</p>
        <p>Reset the demo to rebuild it using the current database structure and selected starting data. Your demo changes will be replaced when the new demo is ready.</p>
        @if (app(\DemoMode\DemoManager::class)->provisioning() === null)
            <form method="POST" action="{{ route('demo-mode.reset') }}">
                @csrf
                <button type="submit">Reset demo</button>
            </form>
        @else
            <p role="status" id="progress">Preparing demo…</p>
            <button id="retry" type="button" hidden>Retry preparation</button>
            <script>
                const progress = document.getElementById('progress');
                const retry = document.getElementById('retry');
                async function step() {
                    retry.hidden = true;
                    try {
                        const response = await fetch(@json(route('demo-mode.provisioning.step')), {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) }
                        });
                        const body = await response.json();
                        if (!response.ok) throw new Error(body.message || 'Demo preparation failed.');
                        progress.textContent = body.message;
                        if (body.status === 'complete') { window.location.href = body.redirect; return; }
                        setTimeout(step, 100);
                    } catch (error) {
                        progress.textContent = error.message + ' You can retry or exit the demo and start again.';
                        retry.hidden = false;
                    }
                }
                retry.addEventListener('click', step);
                step();
            </script>
        @endif
        <form method="POST" action="{{ route('demo-mode.exit') }}" style="margin-top:16px">
            @csrf
            <button type="submit">Exit demo</button>
        </form>
    </main>
</body>
</html>
