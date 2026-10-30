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
    @if(!empty($meldung))<section class="karte" data-portal-meldung role="status"><p>{{ $meldung }}</p></section>@endif
    @if(!empty($fehler))<section class="karte" data-portal-fehler role="alert"><p>{{ $fehler }}</p></section>@endif
    <section class="karte" data-portal-bereiche>
        <h2>Das kannst du hier tun</h2>
        @foreach(['preisliste' => ['Preisliste hochladen', 'Artikelnummer oder EAN und Preis netto je Zeile.'], 'stammdaten' => ['Stammdaten-Datei hochladen', 'Artikeldaten, Allergene, Nährwerte laut Vorlage.']] as $key => [$titel, $text])
            @if(in_array($key, $link->bereiche ?? [], true))
                <form class="upload" method="POST" action="{{ route('foodalchemist.presentation.lieferanten_portal_upload', ['token' => $token]) }}" enctype="multipart/form-data" data-portal-upload="{{ $key }}">
                    @csrf
                    <input type="hidden" name="bereich" value="{{ $key }}">
                    <h3>{{ $titel }}</h3>
                    <p class="hinweis">{{ $text }} Erlaubt: .csv, .xlsx, .ods (höchstens 10 MB, keine Makros).</p>
                    <input type="file" name="datei" accept=".csv,.tsv,.txt,.xlsx,.ods" required>
                    <button type="submit">Einreichen</button>
                </form>
            @endif
        @endforeach
        @if(in_array('dokumente', $link->bereiche ?? [], true))
            <form class="upload" method="POST" action="{{ route('foodalchemist.presentation.lieferanten_portal_dokument', ['token' => $token]) }}" enctype="multipart/form-data" data-portal-upload="dokumente">
                @csrf
                <h3>Datenblatt oder Produktpass zu einem Artikel</h3>
                <p class="hinweis">Ein Dokument je Artikel. Erlaubt: .pdf, .jpg, .png (höchstens 15 MB).</p>
                <label>Artikelnummer <input type="text" name="artikelnummer" maxlength="64" required></label>
                <input type="file" name="datei" accept=".pdf,.jpg,.jpeg,.png" required>
                <button type="submit">Einreichen</button>
            </form>
        @endif
        <ul class="liste">@foreach($bereiche as $b)<li>{{ $b }}</li>@endforeach</ul>
        <p class="hinweis">Alles, was du einreichst, wird vom Team geprüft, bevor es übernommen wird.</p>
    </section>
</main>
</body>
</html>
