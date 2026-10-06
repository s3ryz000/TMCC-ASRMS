<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TMCC ASRMS server</title>
    {{-- Self-contained: no web fonts or CDN assets, so it loads on the campus LAN (#22). --}}
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f6f7f8; color: #1f2937; }
        main { max-width: 32rem; padding: 2rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; }
        p { margin: .5rem 0 0; line-height: 1.5; color: #4b5563; }
    </style>
</head>
<body>
    <main>
        <h1>TMCC ASRMS: application server</h1>
        <p>This address serves the system's data. Open the ASRMS application at the address your registrar's office uses.</p>
    </main>
</body>
</html>
