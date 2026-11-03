{{-- Spec 94 · Konfigurator-Schritt: Extras nach Abschnitten; Bindung an ein Konzept und Tageszeit filtert das Skript.
     Karten wie bei den Konzepten: Bild (Konfigurator-Bild, sonst Concept-Titelbild), Claim, Preis, „Details“ öffnet das
     Speisen-Detail des Add-on-Concepts (presentation.blocks._konfigurator_detail). --}}
@if(! empty($content['addons']))
    @php
        $media = app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class);
        $url = fn ($b) => ! empty($b) ? $media->url($b['context_file_id'] ?? null, $b['path'] ?? null) : null;
        $mitBild = $style['show_bild'] ?? true;
    @endphp
    <section class="pt-wide pt-konfig-schritt" id="konfig-addons" data-konfig-schritt="addons" data-konfig-gruppe="addons">
        <p class="pt-kicker pt-konfig-nr"></p>
        <h2>{{ $style['titel'] ?? 'Was darf dazukommen?' }}</h2>
        @foreach(collect($content['addons'])->groupBy('abschnitt') as $abschnitt => $liste)
            <h3 class="pt-konfig-abschnitt">{{ $abschnitt }}</h3>
            <div class="pt-konfig-raster">
                @foreach($liste as $a)
                    @php $bild = $mitBild ? $url($a['bild'] ?? null) : null; @endphp
                    <div class="pt-konfig-karte pt-konfig-addon {{ $bild ? 'pt-konfig-karte--bild' : 'pt-konfig-karte--ohne-bild' }}" data-konfig-addon-zeile="{{ $a['id'] }}" data-tageszeit="{{ $a['tageszeit'] }}" data-gebunden="{{ $a['gebunden_an'] ?? '' }}">
                        <label class="pt-konfig-karte-knopf">
                            @if($bild)<img src="{{ $bild }}" alt="" class="pt-konfig-bild" loading="lazy">@endif
                            <span class="pt-konfig-karte-text">
                                <span class="pt-konfig-addon-kopf"><input type="checkbox" value="{{ $a['id'] }}" data-konfig-addon class="pt-konfig-haken"> <span class="pt-konfig-titel">{{ $a['titel'] }}</span></span>
                                @if(! empty($a['claim']))<span class="pt-konfig-claim">{{ $a['claim'] }}</span>@endif
                                <span class="pt-konfig-ab" data-konfig-addon-preis="{{ $a['id'] }}"></span>
                            </span>
                        </label>
                        @if(! empty($a['detail']['zeiten']))
                            <button type="button" class="pt-konfig-details" data-konfig-details="{{ $a['id'] }}">Details</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        @foreach($content['addons'] as $a)
            @if(! empty($a['detail']['zeiten']))
                @include('foodalchemist::presentation.blocks._konfigurator_detail', ['kz' => $a, 'url' => $url, 'art' => 'addon'])
            @endif
        @endforeach
    </section>
@endif
