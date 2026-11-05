{{-- Spec 94c · Konfigurator-Schritt: Getränke (nur wenn es Getränkepakete gibt). Karten wie bei den Extras: Bild, Claim, Preis je Person
     für die gewählte Tageszeit (Pauschale, bei „je Stunde“ mit Einsatzzeit), „Details“ öffnet das Detail mit den Getränken. Mehrere Pakete
     kombinierbar (Haken); „ohne Getränke“, wenn der Konfigurator sie nicht verlangt. Bindung an ein Hauptkonzept filtert das Skript. --}}
@if(! empty($content['getraenke']))
    @php
        $media = app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class);
        $url = fn ($b) => ! empty($b) ? $media->url($b['context_file_id'] ?? null, $b['path'] ?? null) : null;
        $mitBild = $style['show_bild'] ?? true;
    @endphp
    <section class="pt-wide pt-konfig-schritt" id="konfig-getraenke" data-konfig-schritt="getraenke" data-konfig-gruppe="getraenke">
        <p class="pt-kicker pt-konfig-nr"></p>
        <h2>{{ $style['titel'] ?? 'Was darf es zu trinken sein?' }}</h2>
        <p class="pt-section-text">{{ ($content['getraenke_pflicht'] ?? false) ? 'Bitte mindestens ein Getränkepaket wählen, mehrere lassen sich kombinieren.' : 'Ein oder mehrere Getränkepakete dazunehmen, oder ohne Getränke weiter.' }}</p>
        <div class="pt-konfig-raster">
            @foreach($content['getraenke'] as $g)
                @php $bild = $mitBild ? $url($g['bild'] ?? null) : null; @endphp
                <div class="pt-konfig-karte pt-konfig-addon {{ $bild ? 'pt-konfig-karte--bild' : 'pt-konfig-karte--ohne-bild' }}" data-konfig-getraenk-zeile="{{ $g['id'] }}" data-gebunden="{{ $g['gebunden_an'] ?? '' }}">
                    <label class="pt-konfig-karte-knopf">
                        @if($bild)<img src="{{ $bild }}" alt="" class="pt-konfig-bild" loading="lazy">@endif
                        <span class="pt-konfig-karte-text">
                            <span class="pt-konfig-addon-kopf"><input type="checkbox" value="{{ $g['id'] }}" data-konfig-getraenk class="pt-konfig-haken"> <span class="pt-konfig-titel">{{ $g['titel'] }}</span></span>
                            @if(! empty($g['claim']))<span class="pt-konfig-claim">{{ $g['claim'] }}</span>@endif
                            <span class="pt-konfig-ab" data-konfig-getraenk-preis="{{ $g['id'] }}"></span>
                        </span>
                    </label>
                    @if(! empty($g['detail']['zeiten']))
                        <button type="button" class="pt-konfig-details" data-konfig-details="{{ $g['id'] }}">Details</button>
                    @endif
                </div>
            @endforeach
        </div>

        @foreach($content['getraenke'] as $g)
            @if(! empty($g['detail']['zeiten']))
                @include('foodalchemist::presentation.blocks._konfigurator_detail', ['kz' => $g, 'url' => $url, 'art' => 'getraenk'])
            @endif
        @endforeach
    </section>
@endif
