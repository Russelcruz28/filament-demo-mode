<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo unavailable</title>
</head>
<body>
    <main style="max-width:640px;margin:10vh auto;padding:24px;font:16px/1.6 system-ui,sans-serif">
        <h1>Demo unavailable</h1>
        <p role="alert">{{ $message }}</p>
        <p>You have been signed out.</p>
        <p><a href="{{ url('/') }}">Return to the home page</a></p>
    </main>
</body>
</html>
