<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo Mode - {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8faf9; color: #202724; font: 15px/1.5 system-ui, sans-serif; letter-spacing: 0; }
        header { display: flex; align-items: center; gap: 10px; padding: 18px max(20px, calc((100% - 800px)/2)); border-bottom: 1px solid #dde3e0; background: #fff; }
        header svg { width: 24px; height: 24px; color: #8d1436; flex: none; }
        header strong { font-size: 18px; overflow-wrap: anywhere; }
        main { max-width: 800px; margin: 0 auto; padding: 32px 20px 48px; }
        h1 { margin: 0 0 24px; font-size: 28px; line-height: 1.2; }
        fieldset { padding: 0; margin: 0 0 24px; border: 0; min-width: 0; }
        legend { margin-bottom: 10px; font-size: 16px; font-weight: 600; }
        .roles { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .role { display: flex; align-items: center; gap: 12px; min-height: 58px; padding: 12px 16px; background: #fff; border: 1px solid #d6deda; border-radius: 6px; cursor: pointer; overflow-wrap: anywhere; }
        .role:hover { border-color: #8d1436; }
        .role:has(input:checked) { background: #fcf2f5; border-color: #8d1436; }
        input { margin: 0; accent-color: #8d1436; width: 18px; height: 18px; flex: none; }
        .continue { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 20px; border: 0; border-radius: 6px; color: #fff; background: #8d1436; font: inherit; font-weight: 600; cursor: pointer; }
        .continue:hover { background: #6b0028; }
        .continue svg { width: 20px; height: 20px; }
        :focus-visible { outline: 2px solid #00563f; outline-offset: 3px; }
        .error { margin: 0 0 16px; color: #9a1834; }
        .empty { color: #535e58; }
        @media (max-width: 540px) { .roles { grid-template-columns: minmax(0, 1fr); } main { padding-top: 24px; } .continue { width: 100%; } }
    </style>
</head>
<body>
    @include('demo-mode::banner')
    <header><x-filament::icon icon="heroicon-o-beaker" /><strong>{{ config('app.name') }} - Demo Mode</strong></header>
    <main>
        <h1>Switch Role</h1>
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        @if ($roles !== [])
            <form method="POST" action="{{ route('demo-mode.roles.store') }}">
                @csrf
                @foreach (collect($roles)->groupBy('group') as $group => $options)
                    <fieldset>
                        <legend>{{ $group }}</legend>
                        <div class="roles">
                            @foreach ($options as $role)
                                <label class="role">
                                    <input type="radio" name="role" value="{{ $role['id'] }}" @checked(old('role', collect($roles)->firstWhere('selected', true)['id'] ?? null) === $role['id']) required>
                                    <span>{{ $role['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
                <button class="continue" type="submit">Continue<x-filament::icon icon="heroicon-o-arrow-right" /></button>
            </form>
        @else
            <p class="empty">No demo roles are available.</p>
        @endif
    </main>
</body>
</html>
