<section class="fa-obs-briefing" data-observatorium-marktdaten style="margin-bottom:24px" aria-label="Amtliche Marktdaten">
    <h2>Markt in Zahlen <span class="fa-obs-pill">Destatis · Deutschland</span></h2>
    <p class="fa-obs-subtitle" style="margin-top:10px">{{ $marktdaten['hinweis'] }} Diese deutschlandweiten Reihen sind unabhängig von den Meldungsfiltern.</p>
    @unless($marktdaten['zugang_eingerichtet'])<p class="fa-obs-subtitle" role="status">Noch nicht verbunden: Der kostenlose GENESIS-Zugang muss eingerichtet werden.</p>@endunless
    <div class="fa-obs-wett-profile">
    @foreach($marktdaten['reihen'] as $reihe)
        <article>
            <h3>{{ $reihe['name'] }}</h3>
            @if($reihe['aktuell'])
                <p><strong>{{ $reihe['aktuell']['wert'] === null ? 'Wert nicht verfügbar' : number_format($reihe['aktuell']['wert'], 1, ',', '.') }}</strong> · {{ $reihe['aktuell']['einheit'] }}</p>
                <p class="fa-obs-subtitle">Bezugsmonat {{ $reihe['aktuell']['monat'] }} · {{ $reihe['veraltet'] ? 'Älterer Datenstand' : 'Letzter verfügbarer Wert' }}</p>
                @if($reihe['neuere_monate_ohne_wert'])<p class="fa-obs-subtitle">Für neuere Monate liegen noch keine Werte vor.</p>@endif
                <p>{{ $reihe['vorjahr_prozent'] === null ? 'Kein belastbarer Vorjahresvergleich' : sprintf('%s%s %% zum Vorjahresmonat', $reihe['vorjahr_prozent'] > 0 ? '+' : '', number_format($reihe['vorjahr_prozent'], 1, ',', '.')) }}</p>
                <p class="fa-obs-subtitle">Abgerufen {{ \Illuminate\Support\Carbon::parse($reihe['abgerufen_am'])->format('d.m.Y H:i') }}</p>
                <details><summary>Monatswerte &amp; Original-Kennzeichnungen</summary>
                    <div style="overflow-x:auto"><table><thead><tr><th>Monat</th><th>Index</th><th>Einheit / Basis</th></tr></thead><tbody>
                    @foreach(array_reverse($reihe['punkte']) as $punkt)<tr><td>{{ $punkt['monat'] }}</td><td>{{ $punkt['wert'] === null ? 'unbekannt' : number_format($punkt['wert'], 1, ',', '.') }}</td><td>{{ $punkt['einheit'] }}</td></tr>@endforeach
                    </tbody></table></div>
                    <p class="fa-obs-subtitle">Original der letzten Beobachtung, einschließlich möglicher Qualitätskennzeichen:</p>
                    <dl>@foreach($reihe['aktuell']['original'] as $feld => $wert)<dt>{{ $feld }}</dt><dd style="overflow-wrap:anywhere">{{ $wert ?: '—' }}</dd>@endforeach</dl>
                </details>
            @else<p class="fa-obs-subtitle">Noch keine geprüfte Datenübernahme.</p>@endif
            @if($reihe['fehler'])<p class="fa-obs-subtitle" role="status">Letzter Abruf: {{ $reihe['fehler'] }} Ein vorhandener Datenstand bleibt erhalten.</p>@endif
            <a class="fa-obs-link" href="{{ $reihe['quelle'] }}" target="_blank" rel="noopener noreferrer">Originaltabelle 61111-0004 ansehen @svg('heroicon-o-arrow-up-right')</a>
        </article>
    @endforeach
    </div>
    <p class="fa-obs-subtitle">Quelle: Statistisches Bundesamt (Destatis), GENESIS-Online · Datenlizenz Deutschland – Namensnennung – Version 2.0. Kein automatischer Trendbeleg.</p>
</section>
