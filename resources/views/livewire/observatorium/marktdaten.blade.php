<section class="fa-obs-briefing fa-obs-marktdaten" data-observatorium-marktdaten aria-label="Amtliche Marktdaten">
    <h2>Markt in Zahlen <span class="fa-obs-pill">Destatis · Deutschland</span></h2>
    @if($marktdaten['aussagen'])
        <ul class="fa-obs-marktdaten-aussagen" data-marktdaten-aussagen>
        @foreach($marktdaten['aussagen'] as $aussage)
            <li><p class="fa-obs-marktdaten-aussage" data-marktdaten-aussage="{{ $aussage['schluessel'] }}">{{ $aussage['text'] }}</p>
                <p class="fa-obs-subtitle">Bezugsmonat {{ $aussage['monat'] }} · Destatis-Tabelle {{ implode(', ', $aussage['quellen']) }}</p></li>
        @endforeach
        </ul>
    @endif
    <p class="fa-obs-subtitle fa-obs-marktdaten-hinweis">{{ $marktdaten['hinweis'] }} Diese deutschlandweiten Reihen sind unabhängig von den Meldungsfiltern.</p>
    @unless($marktdaten['zugang_eingerichtet'])<p class="fa-obs-subtitle" role="status">Noch nicht verbunden: Der kostenlose GENESIS-Zugang muss eingerichtet werden.</p>@endunless
    @if($marktdaten['reihen'] === [] && $marktdaten['zugang_eingerichtet'])<p class="fa-obs-subtitle" role="status">Noch keine Marktdaten abgerufen.</p>@endif
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
    <p class="fa-obs-subtitle">Quelle: Statistisches Bundesamt (Destatis), GENESIS-Online · Datenlizenz Deutschland – Namensnennung – Version 2.0. Kein automatischer Trendbeleg.</p>
</section>
