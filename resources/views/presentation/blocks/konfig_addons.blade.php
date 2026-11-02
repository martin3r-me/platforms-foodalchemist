{{-- Spec 94 · Konfigurator-Schritt: Extras nach Abschnitten; Bindung an ein Konzept und Tageszeit filtert das Skript. --}}
@if(! empty($content['addons']))
    <section class="pt-wide pt-konfig-schritt" id="konfig-addons" data-konfig-schritt="addons" data-konfig-gruppe="addons">
        <p class="pt-kicker pt-konfig-nr"></p>
        <h2>{{ $style['titel'] ?? 'Was darf dazukommen?' }}</h2>
        @foreach(collect($content['addons'])->groupBy('abschnitt') as $abschnitt => $liste)
            <h3 class="pt-konfig-abschnitt">{{ $abschnitt }}</h3>
            <div class="pt-konfig-raster">
                @foreach($liste as $a)
                    <label class="pt-konfig-karte pt-konfig-karte--ohne-bild pt-konfig-addon" data-konfig-addon-zeile="{{ $a['id'] }}" data-tageszeit="{{ $a['tageszeit'] }}" data-gebunden="{{ $a['gebunden_an'] ?? '' }}">
                        <input type="checkbox" value="{{ $a['id'] }}" data-konfig-addon class="pt-konfig-haken">
                        <span class="pt-konfig-karte-text">
                            <span class="pt-konfig-titel">{{ $a['titel'] }}</span>
                            @if(! empty($a['claim']))<span class="pt-konfig-claim">{{ $a['claim'] }}</span>@endif
                            <span class="pt-konfig-ab" data-konfig-addon-preis="{{ $a['id'] }}"></span>
                        </span>
                    </label>
                @endforeach
            </div>
        @endforeach
    </section>
@endif
