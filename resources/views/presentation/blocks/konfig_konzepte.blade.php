{{-- Spec 94 · Konfigurator-Schritt: Konzept wählen. Karten mit Bild (Rückfall ohne Bild: Fläche mit Akzentlinie), Claim und
     „ab … pro Person, all-in netto“ aus der Preistabelle des Snapshots. --}}
@php
    $media = app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class);
    $ansicht = ($style['ansicht'] ?? 'raster') === 'liste' ? 'pt-konfig-liste' : 'pt-konfig-raster';
    $mitBild = $style['show_bild'] ?? true;
    $mitAb = $style['show_ab_preis'] ?? true;
@endphp
<section class="pt-wide pt-konfig-schritt" id="konfig-konzepte" data-konfig-schritt="konzepte" data-konfig-gruppe="konzepte">
    <p class="pt-kicker pt-konfig-nr"></p>
    <h2>{{ $style['titel'] ?? 'Welches Konzept passt zu eurem Event?' }}</h2>
    @if(! empty($content['intro']))<p class="pt-section-text">{{ $content['intro'] }}</p>@endif
    <div class="{{ $ansicht }}">
        @foreach($content['konzepte'] ?? [] as $kz)
            @php $bild = $mitBild && ! empty($kz['bild']) ? $media->url($kz['bild']['context_file_id'] ?? null, $kz['bild']['path'] ?? null) : null; @endphp
            <button type="button" class="pt-konfig-karte {{ $bild ? 'pt-konfig-karte--bild' : 'pt-konfig-karte--ohne-bild' }}" data-konfig-konzept="{{ $kz['id'] }}" aria-pressed="false">
                @if($bild)<img src="{{ $bild }}" alt="" class="pt-konfig-bild" loading="lazy">@endif
                <span class="pt-konfig-karte-text">
                    <span class="pt-konfig-titel">{{ $kz['titel'] }}</span>
                    @if(! empty($kz['claim']))<span class="pt-konfig-claim">{{ $kz['claim'] }}</span>@endif
                    <span class="pt-konfig-zeiten">{{ collect(['lunch' => 'Tag', 'dinner' => 'Abend'])->filter(fn ($l, $t) => $kz[$t])->implode(' · ') }}</span>
                    @if($mitAb && $kz['ab'] !== null)<span class="pt-konfig-ab">ab {{ number_format($kz['ab'], 2, ',', '.') }} € <small>pro Person, all-in netto</small></span>@endif
                </span>
            </button>
        @endforeach
    </div>
</section>
