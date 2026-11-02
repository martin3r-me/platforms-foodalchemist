{{-- Spec 94 · Konfigurator-Schritt 2: Tageszeit (nur wenn aktiv), bei Day-into-Night optional ein zweites Konzept für den Abend. --}}
@if($content['tageszeit_aktiv'] ?? false)
    <section class="pt-measure pt-konfig-schritt" id="konfig-tageszeit" data-konfig-schritt="tageszeit">
        <p class="pt-kicker">Schritt 2</p>
        <h2>{{ $style['titel'] ?? 'Tageszeit' }}</h2>
        <div class="pt-konfig-wahl">
            <button type="button" class="pt-konfig-chip" data-konfig-tageszeit="lunch" aria-pressed="false">Lunch</button>
            <button type="button" class="pt-konfig-chip" data-konfig-tageszeit="dinner" aria-pressed="false">Dinner</button>
            <button type="button" class="pt-konfig-chip" data-konfig-tageszeit="day_night" aria-pressed="false">Day into Night</button>
        </div>
        <label class="pt-konfig-abend" data-konfig-abend-feld hidden>
            <span>Konzept für den Abend</span>
            <select data-konfig-abend>
                @foreach($content['konzepte'] ?? [] as $kz)
                    @if($kz['dinner'])<option value="{{ $kz['id'] }}">{{ $kz['titel'] }}</option>@endif
                @endforeach
            </select>
        </label>
        @if(($content['kombi_rabatt_pct'] ?? 0) > 0)<p class="pt-section-text">Day into Night: {{ number_format($content['kombi_rabatt_pct'], 0, ',', '.') }} % Kombi-Vorteil auf die Konzepte.</p>@endif
    </section>
@endif
