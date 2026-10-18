<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AlOtrojah API docs — sign in</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center;
               background: #0f172a; font-family: system-ui, sans-serif; }
        form { background: #fff; border-radius: 10px; padding: 2rem 2.25rem;
               width: 320px; box-shadow: 0 10px 30px rgba(0,0,0,.35); }
        h1 { margin: 0 0 1.25rem; font-size: 1.1rem; color: #0f172a; }
        label { display: block; font-size: .85rem; color: #475569; margin-bottom: .25rem; }
        input { width: 100%; box-sizing: border-box; padding: .55rem .6rem; margin-bottom: 1rem;
                border: 1px solid #cbd5e1; border-radius: 6px; font-size: .95rem; }
        input:focus { outline: 2px solid #6366f1; border-color: transparent; }
        .error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca;
                 border-radius: 6px; padding: .5rem .65rem; font-size: .85rem; margin-bottom: 1rem; }
        button { width: 100%; padding: .6rem; border: 0; border-radius: 6px; cursor: pointer;
                 background: #4f46e5; color: #fff; font-size: .95rem; }
        button:hover { background: #4338ca; }
    </style>
</head>
<body>
    <form method="post" action="{{ url('/docs/login') }}">
        @csrf
        <h1>AlOtrojah API docs</h1>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <label for="email">Admin email</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

        <label for="password">Password</label>
        <input id="password" type="password" name="password" required>

        <button type="submit">Sign in</button>
    </form>
</body>
</html>
