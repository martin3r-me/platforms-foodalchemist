{{-- Spec 94 · Druckfassung des Konfigurator-Links (Drucken / PDF): eigener Container, der beim Drucken den Assistenten ersetzt
     (Print-CSS im Presentation-Layout). Server rendert alle Bausteine verborgen, das Skript zeigt nur die aktuelle Auswahl und
     füllt Auswahl und Preis aus derselben Preistabelle wie der Link. Keine Bedienelemente, keine Leer-Hinweise. Reihenfolge:
     Kopf · Eure Auswahl · Speisen · Extras · Preis · Hinweise. Speisen über _block_body wie im Foodbook, ohne Gericht-Fotos (A4). --}}
@php
    $logo = $snapshot['branding']['logo']['url'] ?? null;
    $titelbild = $snapshot['branding']['cover']['url'] ?? null;
    $meta = $snapshot['meta'] ?? [];
    $zeitName = ['lunch' => 'Tag', 'dinner' => 'Abend'];
    $stil = ['show_price' => false, 'show_codes' => true, 'show_dish_photos' => false];
    $speisen = function (array $zeilen, string $label) {
        return ['label' => $label, 'is_header' => $label !== '', 'items' => array_map(fn ($z) => [
            'kind' => $z['kind'] === 'paket' ? 'header' : $z['kind'], 'label' => $z['label'], 'indent' => $z['indent'], 'codes' => $z['codes'] ?? [],
        ], $zeilen)];
    };
    $legende = fn (array $d) => collect($d['legende'] ?? [])->map(fn ($l) => $l['code'] . ' ' . $l['label'])->implode(' · ');
@endphp
<article class="pt-konfig-druck" data-konfig-druck aria-hidden="true">
    <header class="pt-druck-kopf" data-druck-abschnitt="kopf">
        <div class="pt-druck-marke">
            @if($logo)<img src="{{ $logo }}" alt="" class="pt-druck-logo">@elseif(! empty($meta['betrieb']))<p class="pt-druck-betrieb">{{ $meta['betrieb'] }}</p>@endif
            <p class="pt-kicker">{{ $meta['kicker'] ?? 'Konfigurator' }}</p>
            <h1>{{ $snapshot['title'] ?? '' }}</h1>
            @if(! empty($snapshot['subtitle']))<p class="pt-druck-claim">{{ $snapshot['subtitle'] }}</p>@endif
        </div>
        <div>
            @if($titelbild)<img src="{{ $titelbild }}" alt="" class="pt-druck-titelbild">@endif
            <p class="pt-druck-stand" data-druck-stand></p>
        </div>
    </header>

    <section class="pt-druck-abschnitt" data-druck-abschnitt="auswahl">
        <h2>Eure Auswahl</h2>
        <dl class="pt-druck-fakten">
            <div data-druck-feld="konzept" hidden><dt>Konzept</dt><dd data-druck-wert></dd></div>
            <div data-druck-feld="tageszeit" hidden><dt>Zeitfenster</dt><dd data-druck-wert></dd></div>
            <div data-druck-feld="pax"><dt>Gäste</dt><dd data-druck-wert></dd></div>
            <div data-druck-feld="stufe" hidden><dt>Preisstufe</dt><dd data-druck-wert></dd></div>
            <div data-druck-feld="getraenke" hidden><dt>Getränke</dt><dd data-druck-wert></dd></div>
            <div data-druck-feld="extras" hidden><dt>Extras</dt><dd data-druck-wert></dd></div>
        </dl>
    </section>

    <section class="pt-druck-abschnitt" data-druck-abschnitt="speisen" data-druck-speisen-block hidden>
        <h2>Speisen</h2>
        @foreach($content['konzepte'] ?? [] as $kz)
            @foreach($kz['detail']['zeiten'] ?? [] as $t => $zeilen)
                <div class="pt-druck-konzept" data-druck-speisen="{{ $kz['id'] }}:{{ $t }}" hidden>
                    <h3>{{ $kz['titel'] }} <span class="pt-druck-zeit" data-druck-zeit-label>· {{ $zeitName[$t] ?? $t }}</span></h3>
                    @include('foodalchemist::presentation.blocks._block_body', ['b' => $speisen($zeilen, ''), 'style' => $stil])
                    @if($legende($kz['detail']) !== '')<p class="pt-konfig-legende">{{ $legende($kz['detail']) }} · * Spuren</p>@endif
                </div>
            @endforeach
        @endforeach
    </section>

    @if(! empty($content['getraenke']))
        {{-- Spec 94c: gewählte Getränkepakete, je Zeitfenster das eigene Concept (Tag, Abend bzw. beide bei Tag und Abend) --}}
        <section class="pt-druck-abschnitt" data-druck-abschnitt="getraenke" data-druck-getraenke-block hidden>
            <h2>Getränke</h2>
            @foreach($content['getraenke'] as $g)
                <div class="pt-druck-konzept" data-druck-getraenk="{{ $g['id'] }}" hidden>
                    <h3>{{ $g['titel'] }}</h3>
                    @if(! empty($g['claim']))<p class="pt-druck-claim">{{ $g['claim'] }}</p>@endif
                    @foreach($g['detail']['zeiten'] ?? [] as $t => $zeilen)
                        <div data-druck-getraenk-zeit="{{ $t }}">
                            @include('foodalchemist::presentation.blocks._block_body', ['b' => $speisen($zeilen, $zeitName[$t] ?? $t), 'style' => $stil])
                        </div>
                    @endforeach
                    @if($legende($g['detail']) !== '')<p class="pt-konfig-legende">{{ $legende($g['detail']) }} · * Spuren</p>@endif
                </div>
            @endforeach
        </section>
    @endif

    @if(! empty($content['addons']))
        <section class="pt-druck-abschnitt" data-druck-abschnitt="extras" data-druck-extras-block hidden>
            <h2>Extras</h2>
            @foreach($content['addons'] as $a)
                <div class="pt-druck-konzept" data-druck-addon="{{ $a['id'] }}" hidden>
                    <h3>{{ $a['titel'] }}</h3>
                    @if(! empty($a['claim']))<p class="pt-druck-claim">{{ $a['claim'] }}</p>@endif
                    @foreach($a['detail']['zeiten'] ?? [] as $t => $zeilen)
                        @include('foodalchemist::presentation.blocks._block_body', ['b' => $speisen($zeilen, count($a['detail']['zeiten']) > 1 ? ($zeitName[$t] ?? $t) : ''), 'style' => $stil])
                    @endforeach
                    @if($legende($a['detail']) !== '')<p class="pt-konfig-legende">{{ $legende($a['detail']) }} · * Spuren</p>@endif
                </div>
            @endforeach
        </section>
    @endif

    <section class="pt-druck-abschnitt pt-druck-preis" data-druck-abschnitt="preis" data-druck-preis-block hidden>
        <h2>Preis</h2>
        <div class="pt-druck-preis-kasten">
        <ul class="pt-konfig-aufteilung" data-druck-aufteilung hidden aria-label="Aufteilung pro Person"></ul>
        <div class="pt-druck-summe">
            <p class="pt-druck-pp"><strong data-druck-pp></strong> <small>pro Person</small></p>
            <p class="pt-druck-gesamt">Gesamt <strong data-druck-gesamt></strong> netto, all-in</p>
        </div>
        <div class="pt-konfig-zzgl" data-druck-zzgl hidden>
            <p class="pt-kicker" data-konfig-zzgl-titel>zzgl. nach Aufwand</p>
            <ul data-druck-zzgl-liste></ul>
            <p class="pt-druck-gesamt">Voraussichtlich gesamt ca. <strong data-druck-gesamt-inkl></strong> netto</p>
            <p class="pt-konfig-legende">Geschätzt für diese Gästezahl, abgerechnet wird nach tatsächlichem Aufwand bzw. Verbrauch.</p>
        </div>
        </div>
    </section>

    <footer class="pt-druck-abschnitt pt-druck-fuss" data-druck-abschnitt="hinweise">
        <p>Unverbindliche Zusammenstellung, alle Preise netto. Der Preis ist indikativ und wird im Angebot bestätigt.</p>
        @if(! empty($meta['gueltig_bis']))<p>Preise gültig bis {{ $meta['gueltig_bis'] }}</p>@endif
        @if(! empty($meta['kontakt']['name']))<p>Ihr Kontakt: {{ $meta['kontakt']['name'] }}@if(! empty($meta['kontakt']['email'])) · {{ $meta['kontakt']['email'] }}@endif</p>@endif
    </footer>
</article>
