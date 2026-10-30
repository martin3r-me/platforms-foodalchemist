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
        @if(!empty($blatt))
            @php
                $felder = \Platform\FoodAlchemist\Services\LieferantenStammdatenblattService::STAMM_FELDER;
                $rollen = \Platform\FoodAlchemist\Services\LieferantenStammdatenblattService::ROLLEN;
                $kanaele = \Platform\FoodAlchemist\Services\LieferantenStammdatenblattService::KANAELE;
                $gebiete = array_pad($blatt['gebiete'], count($blatt['gebiete']) + 2, []);
            @endphp
            <form class="upload blatt" method="POST" action="{{ route('foodalchemist.presentation.lieferanten_portal_stammdatenblatt', ['token' => $token]) }}" enctype="multipart/form-data" data-portal-upload="stammdatenblatt">
                @csrf
                <h3>Stammdatenblatt</h3>
                <p class="hinweis">Trage ein, was sich geändert hat oder noch fehlt. Leere Felder bleiben unverändert. Das Team prüft deine Angaben, bevor sie übernommen werden.</p>
                <fieldset><legend>Firma, Bestellung, Konditionen</legend>
                @foreach($felder as $feld => [$label, $art])
                    @if($art === 'kanaele')
                        <div class="gruppe">{{ $label }}:
                            @foreach($kanaele as $k => $kl)<label class="inline"><input type="checkbox" name="stamm[{{ $feld }}][]" value="{{ $k }}" @checked(in_array($k, (array) ($blatt['stamm'][$feld] ?? []), true))> {{ $kl }}</label>@endforeach
                        </div>
                    @else
                        <label>{{ $label }} <input type="text" name="stamm[{{ $feld }}]" value="{{ $blatt['stamm'][$feld] ?? '' }}" maxlength="1000"></label>
                    @endif
                @endforeach
                </fieldset>
                <fieldset><legend>Ansprechpartner</legend>
                @foreach($rollen as $rk => $rl)
                    <div class="gruppe"><strong>{{ $rl }}</strong>
                        @foreach(['name' => 'Name', 'phone' => 'Telefon', 'mobile' => 'Mobil', 'email' => 'E-Mail'] as $f => $fl)
                            <label>{{ $fl }} <input type="text" name="kontakt[{{ $rk }}][{{ $f }}]" value="{{ $blatt['kontakte'][$rk][$f] ?? '' }}" maxlength="255"></label>
                        @endforeach
                    </div>
                @endforeach
                </fieldset>
                <fieldset><legend>Liefergebiete</legend>
                @foreach($gebiete as $i => $g)
                    <div class="gruppe"><strong>Gebiet {{ $i + 1 }}</strong>
                        @foreach(['name' => 'Name', 'postal_codes' => 'PLZ-Bereiche', 'delivery_days' => 'Liefertage (1–7)', 'delivery_von' => 'Lieferung von (HH:MM)', 'delivery_bis' => 'bis (HH:MM)', 'order_cutoff_time' => 'Bestellschluss (HH:MM)', 'lead_days' => 'Vorlauf (Tage)', 'min_order_value' => 'Mindestbestellwert (€)', 'note' => 'Hinweis'] as $f => $fl)
                            <label>{{ $fl }} <input type="text" name="gebiet[{{ $i }}][{{ $f }}]" value="{{ $g[$f] ?? '' }}" maxlength="1000"></label>
                        @endforeach
                    </div>
                @endforeach
                </fieldset>
                <fieldset><legend>Zertifikat (eins je Einreichung)</legend>
                    <label>Art (z. B. IFS, BRC, Bio) <input type="text" name="zertifikat[cert_type]" maxlength="64"></label>
                    <label>Nummer <input type="text" name="zertifikat[cert_number]" maxlength="64"></label>
                    <label>Aussteller <input type="text" name="zertifikat[issuer]" maxlength="128"></label>
                    <label>Gültig bis (TT.MM.JJJJ) <input type="text" name="zertifikat[term_end]" maxlength="10"></label>
                    <input type="file" name="zertifikat_datei" accept=".pdf,.jpg,.jpeg,.png">
                </fieldset>
                <button type="submit">Einreichen</button>
            </form>
        @endif
        <ul class="liste">@foreach($bereiche as $b)<li>{{ $b }}</li>@endforeach</ul>
        <p class="hinweis">Alles, was du einreichst, wird vom Team geprüft, bevor es übernommen wird.</p>
    </section>
</main>
</body>
</html>
