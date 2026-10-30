<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Lieferanten-Portal</title>
    <link rel="stylesheet" href="{{ route('foodalchemist.presentation.lieferanten_portal_css', ['v' => $cssVersion ?? 1]) }}">
</head>
<body>
<main class="portal" data-lieferanten-portal>
    <h1>Lieferanten-Portal</h1>
    <p class="unter">Persönlicher Zugang für <strong>{{ $lieferant?->name }}</strong></p>
    <section class="karte" data-portal-zugang>
        <h2>Dein Zugang ist gültig</h2>
        <p class="hinweis">Gültig bis {{ $link->expires_at->timezone('Europe/Berlin')->format('d.m.Y') }}. Der Link gehört nur dir; gib ihn bitte nicht weiter.</p>
    </section>
    <section class="karte" data-portal-bereiche>
        <h2>Das kannst du hier tun</h2>
        <ul class="liste">@foreach($bereiche as $b)<li>{{ $b }}</li>@endforeach</ul>
        <p class="hinweis">Die Funktionen werden in Kürze freigeschaltet. Alles, was du einreichst, wird vom Team geprüft, bevor es übernommen wird.</p>
    </section>
</main>
</body>
</html>
