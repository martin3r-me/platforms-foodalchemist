<section class="fa-obs-briefing fa-obs-marktdaten" data-observatorium-marktdaten aria-label="Amtliche Marktdaten">
    <h2>Markt in Zahlen <span class="fa-obs-pill">Destatis · Deutschland</span></h2>
    @php
        $pctFmt = fn ($v) => ($v > 0 ? '+' : '').number_format($v, 1, ',', '.').' %';
        $ansicht = $marktdaten['ansicht'] ?? ['kacheln' => [], 'balken' => null, 'schere' => null];
        $einordnung = $marktdaten['einordnung'] ?? null;
    @endphp
    @if(($einordnung['saetze'] ?? []) !== [] || $marktdaten['aussagen'])
    <div class="fa-obs-markt-box" data-marktdaten-box>
        <h3>Was heißt das für dich?</h3>
        @if(($einordnung['saetze'] ?? []) !== [])
            <ul class="fa-obs-markt-ki" data-marktdaten-ki>@foreach($einordnung['saetze'] as $satz)<li>{{ $satz }}</li>@endforeach</ul>
            <p class="fa-obs-subtitle">KI-Einordnung · Stand {{ $einordnung['stand_monat'] }}@if($einordnung['erstellt_am']) · erstellt {{ \Illuminate\Support\Carbon::parse($einordnung['erstellt_am'])->format('d.m.Y') }}@endif · Zahlen gegen die abgerufenen Werte geprüft, keine Prognose.</p>
        @endif
        @if($marktdaten['aussagen'])
            @if(($einordnung['saetze'] ?? []) !== [])<p class="fa-obs-subtitle fa-obs-markt-beleg-titel">Aus den Werten berechnet:</p>@endif
            <ul class="fa-obs-marktdaten-aussagen {{ ($einordnung['saetze'] ?? []) !== [] ? 'fa-obs-markt-beleg' : '' }}" data-marktdaten-aussagen>
            @foreach($marktdaten['aussagen'] as $aussage)
                <li><p class="fa-obs-marktdaten-aussage" data-marktdaten-aussage="{{ $aussage['schluessel'] }}">{{ $aussage['text'] }}</p>
                    <p class="fa-obs-subtitle">Bezugsmonat {{ $aussage['monat'] }} · Destatis-Tabelle {{ implode(', ', $aussage['quellen']) }}</p></li>
            @endforeach
            </ul>
        @endif
    </div>
    @endif
    @if($ansicht['kacheln'] !== [])
    <div class="fa-obs-markt-kacheln" data-marktdaten-kacheln>
    @foreach($ansicht['kacheln'] as $k)
        <article class="fa-obs-markt-kachel is-{{ $k['farbe'] }}" data-marktdaten-kachel="{{ $k['serie'] }}">
            <h3>{{ $k['titel'] }}</h3>
            <p class="fa-obs-markt-zahl">{{ $pctFmt($k['vorjahr_prozent']) }}</p>
            <p class="fa-obs-subtitle">zum Vorjahresmonat · {{ $k['monat'] }}</p>
            <svg class="fa-obs-markt-spark" viewBox="0 0 {{ $k['sparkline']['breite'] }} {{ $k['sparkline']['hoehe'] }}" role="img" aria-label="Verlauf {{ $k['titel'] }} {{ $k['von'] }} bis {{ $k['bis'] }}"><polyline points="{{ $k['sparkline']['punkte'] }}" fill="none"/><circle cx="{{ $k['sparkline']['ende'][0] }}" cy="{{ $k['sparkline']['ende'][1] }}" r="2.5"/></svg>
            <p class="fa-obs-subtitle">Index {{ $k['von'] }} bis {{ $k['bis'] }} · {{ $k['basis'] }} · Destatis {{ $k['tabelle'] }}</p>
        </article>
    @endforeach
    </div>
    @endif
    @if($ansicht['balken'])
    @php $b = $ansicht['balken']; $zeilenH = 26; $svgH = count($b['zeilen']) * $zeilenH + 8; @endphp
    <div class="fa-obs-markt-block" data-marktdaten-balken>
        <h3>Warengruppen im Großhandel</h3>
        <p class="fa-obs-subtitle">Veränderung zum Vorjahresmonat {{ $b['monat'] }} · rot = Einkauf teurer, grün = günstiger · Destatis {{ $b['tabelle'] }}</p>
        <svg class="fa-obs-markt-balken" viewBox="0 0 640 {{ $svgH }}" role="img" aria-label="Warengruppen im Großhandel, sortiert nach Veränderung">
            <line class="fa-obs-markt-null" x1="420" y1="0" x2="420" y2="{{ $svgH }}"/>
            @foreach($b['zeilen'] as $i => $z)
                @php $y = 4 + $i * $zeilenH; @endphp
                <text class="fa-obs-markt-label" x="0" y="{{ $y + 16 }}">{{ $z['name'] }}</text>
                <rect class="is-{{ $z['farbe'] }}" x="{{ round(300 + $z['links'] * 2.4, 1) }}" y="{{ $y + 4 }}" width="{{ max(1, round($z['breite'] * 2.4, 1)) }}" height="16" rx="2"/>
                <text class="fa-obs-markt-wert" x="{{ $z['pct'] >= 0 ? round(300 + ($z['links'] + $z['breite']) * 2.4 + 6, 1) : round(300 + $z['links'] * 2.4 - 6, 1) }}" y="{{ $y + 16 }}" text-anchor="{{ $z['pct'] >= 0 ? 'start' : 'end' }}">{{ $pctFmt($z['pct']) }}</text>
            @endforeach
        </svg>
    </div>
    @endif
    @if($ansicht['schere'])
    @php $sc = $ansicht['schere']; @endphp
    <div class="fa-obs-markt-block" data-marktdaten-schere>
        <h3>Die Schere: Einkauf, Restaurant, Kantine</h3>
        <p class="fa-obs-subtitle">Veränderung zum Vorjahresmonat in %, {{ $sc['von'] }} bis {{ $sc['bis'] }} · Destatis 61281-0004, 61111-0004</p>
        <svg class="fa-obs-markt-schere" viewBox="0 0 {{ $sc['breite'] }} {{ $sc['hoehe'] }}" role="img" aria-label="Vorjahresveränderung Einkauf, Restaurantpreise und Kantinen über zwölf Monate">
            @foreach($sc['ticks'] as $t)<line class="fa-obs-markt-gitter" x1="{{ $sc['links'] }}" y1="{{ $t['y'] }}" x2="{{ $sc['breite'] - 10 }}" y2="{{ $t['y'] }}"/><text class="fa-obs-markt-achse" x="{{ $sc['links'] - 6 }}" y="{{ $t['y'] + 4 }}" text-anchor="end">{{ $t['wert'] }} %</text>@endforeach
            <line class="fa-obs-markt-null" x1="{{ $sc['links'] }}" y1="{{ $sc['null_y'] }}" x2="{{ $sc['breite'] - 10 }}" y2="{{ $sc['null_y'] }}"/>
            @foreach($sc['linien'] as $l)<polyline class="fa-obs-markt-linie is-{{ $l['strich'] }}" points="{{ $l['punkte'] }}" fill="none"/>@endforeach
            @foreach($sc['x_labels'] as $x)<text class="fa-obs-markt-achse" x="{{ $x['x'] }}" y="{{ $sc['hoehe'] - 8 }}" text-anchor="{{ $x['anker'] }}">{{ $x['text'] }}</text>@endforeach
        </svg>
        <ul class="fa-obs-markt-legende">@foreach($sc['linien'] as $l)<li><svg viewBox="0 0 28 8" aria-hidden="true"><line class="fa-obs-markt-linie is-{{ $l['strich'] }}" x1="0" y1="4" x2="28" y2="4"/></svg>{{ $l['titel'] }} <strong>{{ $pctFmt($l['letzter']) }}</strong></li>@endforeach</ul>
    </div>
    @endif
    <p class="fa-obs-subtitle fa-obs-marktdaten-hinweis">{{ $marktdaten['hinweis'] }} Diese deutschlandweiten Reihen sind unabhängig von den Meldungsfiltern.</p>
    @unless($marktdaten['zugang_eingerichtet'])<p class="fa-obs-subtitle" role="status">Noch nicht verbunden: Der kostenlose GENESIS-Zugang muss eingerichtet werden.</p>@endunless
    @if($marktdaten['reihen'] === [] && $marktdaten['zugang_eingerichtet'])<p class="fa-obs-subtitle" role="status">Noch keine Marktdaten abgerufen.</p>@endif
    @if($marktdaten['reihen'] !== [])
    <details class="fa-obs-markt-alle" data-marktdaten-alle>
    <summary>Alle Reihen, Indexwerte und Tabellen ({{ count($marktdaten['reihen']) }})</summary>
    <div class="fa-obs-wett-profile">
    @foreach($marktdaten['reihen'] as $reihe)
        <article>
            <h3>{{ $reihe['name'] }}</h3>
            <p class="fa-obs-subtitle">{{ $reihe['lang'] }}</p>
            @if($reihe['aktuell'])
                <p><strong>{{ $reihe['vorjahr_prozent'] === null ? 'Kein belastbarer Vorjahresvergleich' : sprintf('%s%s %% zum Vorjahresmonat', $reihe['vorjahr_prozent'] > 0 ? '+' : '', number_format($reihe['vorjahr_prozent'], 1, ',', '.')) }}</strong></p>
                <p class="fa-obs-subtitle">Stand {{ $reihe['aktuell']['wert'] === null ? 'nicht verfügbar' : number_format($reihe['aktuell']['wert'], 1, ',', '.') }} ({{ $reihe['aktuell']['einheit'] }}) · Bezugsmonat {{ $reihe['aktuell']['monat'] }} · {{ $reihe['veraltet'] ? 'Älterer Datenstand' : 'Letzter verfügbarer Wert' }}</p>
                @if($reihe['unterreihen'])
                    @php
                        $pct = fn ($v) => $v === null ? 'kein Vergleich' : sprintf('%s%s %%', $v > 0 ? '+' : '', number_format($v, 1, ',', '.'));
                        $abschnitte = collect($reihe['unterreihen'])->groupBy(fn ($u) => $u['abschnitt'] ?? 'gleich');
                        $st = $reihe['staerkste_unterreihe'];
                    @endphp
                    <details class="fa-obs-marktdaten-details" data-marktdaten-unterreihen>
                        <summary>{{ count($reihe['unterreihen']) }} Detailzeilen @if($st)· stärkste Bewegung: {{ $st['name'] }} {{ $pct($st['vorjahr_prozent']) }}@endif</summary>
                        @foreach($abschnitte as $abschnitt => $zeilen)
                            @if($abschnitt === 'verbraucher')<p class="fa-obs-subtitle fa-obs-marktdaten-abschnitt">Verbraucherpreise im Laden (zum Vergleich)</p>@endif
                            <ul class="fa-obs-marktdaten-unterreihen">
                            @foreach($zeilen as $unter)<li><span>{{ $unter['name'] }}</span> <strong>{{ $pct($unter['vorjahr_prozent']) }}</strong></li>@endforeach
                            </ul>
                        @endforeach
                    </details>
                @endif
                @if($reihe['neuere_monate_ohne_wert'])<p class="fa-obs-subtitle">Für neuere Monate liegen noch keine Werte vor.</p>@endif
                <p class="fa-obs-subtitle">Abgerufen {{ \Illuminate\Support\Carbon::parse($reihe['abgerufen_am'])->format('d.m.Y H:i') }}</p>
                <details><summary>Monatswerte &amp; Original-Kennzeichnungen</summary>
                    <div class="fa-obs-scroll-x"><table class="fa-obs-marktdaten-tabelle"><thead><tr><th>Monat</th><th>Wert</th><th>Einheit / Basis</th></tr></thead><tbody>
                    @foreach($reihe['tabelle_werte'] as $punkt)<tr><td>{{ $punkt['monat'] }}</td><td>{{ number_format($punkt['wert'], 1, ',', '.') }}</td><td>{{ $punkt['einheit'] }}</td></tr>@endforeach
                    </tbody></table></div>
                    <p class="fa-obs-subtitle">Original der letzten Beobachtung, einschließlich möglicher Qualitätskennzeichen:</p>
                    <dl>@foreach($reihe['aktuell']['original'] as $feld => $wert)<dt>{{ $feld }}</dt><dd class="fa-obs-umbruch">{{ $wert ?: '—' }}</dd>@endforeach</dl>
                </details>
            @else<p class="fa-obs-subtitle">Noch keine geprüfte Datenübernahme.</p>@endif
            @if($reihe['fehler'])<p class="fa-obs-subtitle" role="status">Letzter Abruf: {{ $reihe['fehler'] }} Ein vorhandener Datenstand bleibt erhalten.</p>@endif
            <a class="fa-obs-link" href="{{ $reihe['quelle'] }}" target="_blank" rel="noopener noreferrer">Originaltabelle {{ $reihe['tabelle'] }} ansehen @svg('heroicon-o-arrow-up-right')</a>
        </article>
    @endforeach
    </div>
    </details>
    @endif
    <p class="fa-obs-subtitle">Quelle: Statistisches Bundesamt (Destatis), GENESIS-Online · Datenlizenz Deutschland – Namensnennung – Version 2.0. Kein automatischer Trendbeleg.</p>
</section>
