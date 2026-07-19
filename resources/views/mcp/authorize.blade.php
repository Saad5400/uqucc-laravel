<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>الإذن بالوصول — {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #1a1a1a;
            --card: #242424;
            --border: #3a3a3a;
            --text: #ededed;
            --muted: #a0a0a0;
            --brand: #16a34a;
            --brand-hover: #15803d;
            --danger: #b91c1c;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-block-size: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
            line-height: 1.7;
        }

        .card {
            inline-size: 100%;
            max-inline-size: 30rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 1rem;
            padding: 2rem;
        }

        h1 {
            margin: 0 0 0.5rem;
            font-size: 1.35rem;
        }

        p {
            margin: 0 0 1rem;
            color: var(--muted);
        }

        .client {
            font-weight: 700;
            color: var(--text);
        }

        ul {
            margin: 0 0 1.5rem;
            padding-inline-start: 1.25rem;
        }

        li {
            margin-block-end: 0.35rem;
        }

        .actions {
            display: flex;
            gap: 0.75rem;
            margin-block-start: 1.5rem;
        }

        form {
            flex: 1;
            margin: 0;
        }

        button {
            inline-size: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid transparent;
            border-radius: 0.6rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
        }

        .approve {
            background: var(--brand);
            color: #fff;
        }

        .approve:hover {
            background: var(--brand-hover);
        }

        .deny {
            background: transparent;
            color: var(--text);
            border-color: var(--border);
        }

        .deny:hover {
            border-color: var(--danger);
            color: #fca5a5;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>طلب إذن بالوصول</h1>

        <p>
            يطلب <span class="client">{{ $client->name }}</span> الإذن بالوصول إلى أدوات الإشراف
            في {{ config('app.name') }} نيابةً عنك (<span dir="ltr">{{ $user->email }}</span>).
        </p>

        @if (count($scopes) > 0)
            <p>سيتمكّن هذا التطبيق من:</p>
            <ul>
                @foreach ($scopes as $scope)
                    <li>{{ $scope->description }}</li>
                @endforeach
            </ul>
        @endif

        <div class="actions">
            <form method="post" action="{{ route('passport.authorizations.approve') }}">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="approve">السماح بالوصول</button>
            </form>

            <form method="post" action="{{ route('passport.authorizations.deny') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="deny">رفض</button>
            </form>
        </div>
    </main>
</body>
</html>
