{{-- Spec 94 · Konfigurator-Schritt: Zeitfenster (nur wenn aktiv) als Karten mit Preis je Option für das gewählte Konzept;
     bei Tag und Abend optional ein anderes Konzept für den Abend. --}}
@if($content['tageszeit_aktiv'] ?? false)
    <section class="pt-wide pt-konfig-schritt" id="konfig-tageszeit" data-konfig-schritt="tageszeit" data-konfig-gruppe="tageszeit">
        <p class="pt-kicker pt-konfig-nr"></p>
        <h2>{{ $style['titel'] ?? 'Wann startet euer Event?' }}</h2>
        @if(($content['kombi_rabatt_pct'] ?? 0) > 0)<p class="pt-section-text">Tag, Abend oder beides. Zusammen gebucht kommen {{ number_format($content['kombi_rabatt_pct'], 0, ',', '.') }} % Kombi-Rabatt drauf.</p>@endif
        <div class="pt-konfig-raster">
            @foreach(['lunch' => ['Tag', 'Mittagessen und Pausen bei Tageslicht.'], 'dinner' => ['Abend', 'Dinner am Abend, mit dem vollen Programm.'], 'day_night' => ['Tag und Abend', 'Durchgehend von mittags bis in den Abend.']] as $t => [$titel, $text])
                <button type="button" class="pt-konfig-karte pt-konfig-karte--ohne-bild" data-konfig-tageszeit="{{ $t }}" aria-pressed="false">
                    <span class="pt-konfig-karte-text">
                        <span class="pt-konfig-titel">{{ $titel }}</span>
                        <span class="pt-konfig-claim">{{ $text }}</span>
                        <span class="pt-konfig-ab" data-konfig-tageszeit-preis="{{ $t }}"></span>
                    </span>
                </button>
            @endforeach
        </div>
        <label class="pt-konfig-abend" data-konfig-abend-feld hidden>
            <span>Konzept für den Abend</span>
            <select data-konfig-abend>
                @foreach($content['konzepte'] ?? [] as $kz)
                    @if($kz['dinner'])<option value="{{ $kz['id'] }}">{{ $kz['titel'] }}</option>@endif
                @endforeach
            </select>
        </label>
    </section>
@endif
