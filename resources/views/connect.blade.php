{{-- The pairing approval page. Standalone, so it renders the same in any store's
     app; styles are inline to avoid depending on the store's asset pipeline. --}}
@php($rtl = in_array(app()->getLocale(), ['ar', 'fa', 'he', 'ur'], true))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('agentstore::messages.title') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f7fb; color: #23263a; font-family: system-ui, 'Segoe UI', Tahoma, sans-serif; }
        main { width: min(30rem, 92vw); background: #fff; border: 1px solid #e3e5ee; border-radius: .75rem; padding: 2rem; box-shadow: 0 1px 2px #0000000d; }
        h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
        p { color: #5d6177; line-height: 1.6; margin: .5rem 0; }
        dl { margin: 1.25rem 0; display: grid; grid-template-columns: max-content 1fr; gap: .5rem 1rem; font-size: .9rem; }
        dt { color: #8a8ea3; }
        dd { margin: 0; font-weight: 600; word-break: break-word; }
        ul { margin: .25rem 0 0; padding-inline-start: 1.2rem; }
        .warning { background: #fff8e6; border: 1px solid #f3dd9c; border-radius: .5rem; padding: .75rem 1rem; font-size: .9rem; color: #6b5310; }
        .error { background: #fdeeee; border: 1px solid #f3c4c4; border-radius: .5rem; padding: .75rem 1rem; color: #8a1f1f; }
        .actions { display: flex; gap: .75rem; margin-top: 1.5rem; }
        button, a.button { flex: 1; padding: .65rem 1rem; border-radius: .5rem; font-size: .95rem; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; }
        .approve { background: #4338ca; color: #fff; border: 0; }
        .deny { background: #fff; color: #23263a; border: 1px solid #d5d8e3; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('agentstore::messages.title') }}</h1>

    @if (! empty($error))
        <p class="error" role="alert">{{ $error }}</p>
    @else
        <p>{{ __('agentstore::messages.intro', ['tenant' => $tenant]) }}</p>

        <dl>
            <dt>{{ __('agentstore::messages.platform') }}</dt>
            <dd dir="ltr">{{ $saasHost }}</dd>
            <dt>{{ __('agentstore::messages.tenant') }}</dt>
            <dd dir="auto">{{ $tenant }}</dd>
            <dt>{{ __('agentstore::messages.access') }}</dt>
            <dd>
                @if (empty($capabilities))
                    {{ __('agentstore::messages.no_capabilities') }}
                @else
                    <ul>
                        @foreach ($capabilities as $capability)
                            <li>{{ __('agentstore::messages.capabilities')[$capability] ?? $capability }}</li>
                        @endforeach
                    </ul>
                @endif
            </dd>
            <dt>{{ __('agentstore::messages.expires') }}</dt>
            <dd>{{ $expires->diffForHumans() }}</dd>
        </dl>

        <p class="warning">{{ __('agentstore::messages.warning') }}</p>

        <form method="POST" action="{{ route('agentstore.connect.approve') }}">
            @csrf
            <input type="hidden" name="pairing" value="{{ $pairingId }}">
            <div class="actions">
                <button type="submit" class="approve">{{ __('agentstore::messages.approve') }}</button>
                <a class="button deny" href="{{ url('/') }}">{{ __('agentstore::messages.deny') }}</a>
            </div>
        </form>
    @endif
</main>
</body>
</html>
