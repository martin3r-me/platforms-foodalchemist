{{-- Spec 94 · Konfigurator-Schritt: Konzept wählen. Karten mit Bild (Rückfall ohne Bild: Fläche mit Akzentlinie), Claim,
     Preis pro Person aus der Preistabelle (live für Gästezahl und Tageszeit, bei „Tag und Abend“ der eigene Teil) und „Details“: Detailansicht mit Beschreibung, Bildern und den
     Gerichten je Tageszeit im Wording wie im Foodbook (Stufe 5b), dazu Allergen-Codes je Gericht und „Konzept wählen“. --}}
@php
    $media = app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class);
    $ansicht = ($style['ansicht'] ?? 'raster') === 'liste' ? 'pt-konfig-liste' : 'pt-konfig-raster';
    $mitBild = $style['show_bild'] ?? true;
    $mitAb = $style['show_ab_preis'] ?? true;
    $url = fn ($b) => ! empty($b) ? $media->url($b['context_file_id'] ?? null, $b['path'] ?? null) : null;
    $zeitName = ['lunch' => 'Tag', 'dinner' => 'Abend'];
@endphp
<section class="pt-wide pt-konfig-schritt" id="konfig-konzepte" data-konfig-schritt="konzepte" data-konfig-gruppe="konzepte">
    <p class="pt-kicker pt-konfig-nr"></p>
    <h2>{{ $style['titel'] ?? 'Welches Konzept passt zu eurem Event?' }}</h2>
    @if(! empty($content['intro']))<p class="pt-section-text">{{ $content['intro'] }}</p>@endif
    <p class="pt-section-text" data-konfig-kein-konzept hidden>Zu diesem Zeitfenster gibt es kein Konzept. Bitte ein anderes Zeitfenster wählen.</p>
    <div class="{{ $ansicht }}">
        @foreach($content['konzepte'] ?? [] as $kz)
            @php $bild = $mitBild ? $url($kz['bild'] ?? null) : null; $d = $kz['detail'] ?? []; @endphp
            <div class="pt-konfig-karte {{ $bild ? 'pt-konfig-karte--bild' : 'pt-konfig-karte--ohne-bild' }}" data-konfig-karte="{{ $kz['id'] }}"
                 data-lunch="{{ $kz['lunch'] ? 1 : 0 }}" data-dinner="{{ $kz['dinner'] ? 1 : 0 }}">
                <button type="button" class="pt-konfig-karte-knopf" data-konfig-konzept="{{ $kz['id'] }}" aria-pressed="false">
                    @if($bild)<img src="{{ $bild }}" alt="" class="pt-konfig-bild" loading="lazy">@endif
                    <span class="pt-konfig-karte-text">
                        <span class="pt-konfig-titel">{{ $kz['titel'] }}</span>
                        @if(! empty($kz['claim']))<span class="pt-konfig-claim">{{ $kz['claim'] }}</span>@endif
                        <span class="pt-konfig-zeiten">{{ collect($zeitName)->filter(fn ($l, $t) => $kz[$t])->implode(' · ') }}</span>
                        <span class="pt-konfig-indikativ" data-konfig-indikativ-badge hidden>Preis indikativ</span>
                        {{-- Ohne Skript „ab“ (günstigste Tageszeit, größtes Paket); mit Skript live für Gästezahl und Tageszeit (Dominique 2026-11-03). --}}
                        @if($mitAb)<span class="pt-konfig-ab" data-konfig-karte-preis-feld @if($kz['ab'] === null) hidden @endif><span data-konfig-karte-preis>{{ $kz['ab'] !== null ? 'ab ' . number_format($kz['ab'], 2, ',', '.') . ' €' : '' }}</span> <small>pro Person, all-in netto</small><small class="pt-konfig-kombi" data-konfig-karte-kombi hidden>Kombipreis siehe Leiste</small></span>@endif
                    </span>
                </button>
                @if(! empty($d['zeiten']))
                    <button type="button" class="pt-konfig-details" data-konfig-details="{{ $kz['id'] }}">Details</button>
                @endif
            </div>
        @endforeach
    </div>

    @foreach($content['konzepte'] ?? [] as $kz)
        @php $d = $kz['detail'] ?? []; @endphp
        @if(! empty($d['zeiten']))
            <dialog class="pt-konfig-detail" data-konfig-detail="{{ $kz['id'] }}" aria-label="{{ $kz['titel'] }}">
                <div class="pt-konfig-detail-kopf">
                    <h3 class="pt-konfig-titel">{{ $kz['titel'] }}</h3>
                    <button type="button" class="pt-konfig-chip" data-konfig-detail-zu aria-label="Schließen">×</button>
                </div>
                @if(! empty($d['bilder']))
                    <div class="pt-konfig-detail-bilder">
                        @foreach($d['bilder'] as $b)@if($u = $url($b))<img src="{{ $u }}" alt="" loading="lazy">@endif @endforeach
                    </div>
                @endif
                @if(! empty($kz['claim']))<p class="pt-konfig-claim">{{ $kz['claim'] }}</p>@endif
                @if(! empty($d['beschreibung']))<p class="pt-section-text">{{ $d['beschreibung'] }}</p>@endif
                @foreach($d['zeiten'] as $t => $zeilen)
                    @if(count($d['zeiten']) > 1)<h4 class="pt-konfig-abschnitt">{{ $zeitName[$t] ?? $t }}</h4>@endif
                    <ul class="pt-konfig-gerichte">
                        @foreach($zeilen as $z)
                            <li class="pt-konfig-gericht pt-konfig-gericht--{{ $z['kind'] }}" data-indent="{{ $z['indent'] }}">
                                @if(! empty($z['image']) && ($u = $url($z['image'])))<img src="{{ $u }}" alt="" loading="lazy">@endif
                                <span>{{ $z['label'] }}@if(! empty($z['codes']))<sup class="pt-konfig-codes">{{ implode(', ', $z['codes']) }}</sup>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                @endforeach
                @if(! empty($d['legende']))
                    <p class="pt-konfig-legende">@foreach($d['legende'] as $l){{ $l['code'] }} {{ $l['label'] }}@if(! $loop->last) · @endif @endforeach · * Spuren</p>
                @endif
                <div class="pt-konfig-weiter"><button type="button" class="pt-cta-btn" data-konfig-waehlen="{{ $kz['id'] }}">Konzept wählen</button></div>
            </dialog>
        @endif
    @endforeach
</section>
