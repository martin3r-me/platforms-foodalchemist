{{-- Spec 94 · Speisen-Detail eines Konzepts oder Extras im Konfigurator-Link (großes Sheet). Gerichte über dieselbe
     Darstellung wie „Alle Kapitel“ im Foodbook-Link (presentation.blocks._block_body): Gang-Überschriften aus den
     Header-Slots, Wording als Zeile, Foto-Thumbnail und Allergen-/Zusatzstoff-Codes. Kopf mit Bild, Titel und Claim;
     Tag und Abend am Desktop nebeneinander, am Handy untereinander. Erwartet $kz (Konzept bzw. Extra mit detail),
     $url (Bild-Identifier → URL) und $art (konzept | addon). --}}
@php
    $d = $kz['detail'] ?? [];
    $zeitName = ['lunch' => 'Tag', 'dinner' => 'Abend'];
    $kopfBild = $url($d['bilder'][0] ?? null);
    $weitere = array_slice($d['bilder'] ?? [], 1);
    // Foodbook-Darstellung: Gericht-Fotos und Codes an, keine Preise (der Preis steht in der Leiste)
    $stil = ['show_price' => false, 'show_codes' => true, 'show_dish_photos' => true];
    $mehrereZeiten = count($d['zeiten'] ?? []) > 1;
@endphp
<dialog class="pt-konfig-detail" data-konfig-detail="{{ $kz['id'] }}" aria-label="{{ $kz['titel'] }}">
    <div class="pt-konfig-detail-kopf {{ $kopfBild ? 'pt-konfig-detail-kopf--bild' : '' }}">
        @if($kopfBild)<img src="{{ $kopfBild }}" alt="" class="pt-konfig-detail-titelbild">@endif
        <div class="pt-konfig-detail-titel">
            <p class="pt-kicker">{{ ['addon' => 'Extra', 'getraenk' => 'Getränke'][$art] ?? 'Konzept' }}</p>
            <h3>{{ $kz['titel'] }}</h3>
            @if(! empty($kz['claim']))<p class="pt-konfig-claim">{{ $kz['claim'] }}</p>@endif
        </div>
        <button type="button" class="pt-konfig-chip pt-konfig-detail-zu" data-konfig-detail-zu aria-label="Schließen">×</button>
    </div>

    @if(! empty($d['beschreibung']))<p class="pt-section-text">{{ $d['beschreibung'] }}</p>@endif
    @if($weitere !== [])
        <div class="pt-konfig-detail-bilder">
            @foreach($weitere as $b)@if($u = $url($b))<img src="{{ $u }}" alt="" loading="lazy" class="pt-zoomable">@endif @endforeach
        </div>
    @endif

    <div class="pt-konfig-detail-zeiten {{ $mehrereZeiten ? 'pt-konfig-detail-zeiten--zwei' : '' }}">
        @foreach($d['zeiten'] ?? [] as $t => $zeilen)
            @php
                $items = array_map(fn ($z) => [
                    'kind' => $z['kind'] === 'paket' ? 'header' : $z['kind'],
                    'label' => $z['label'],
                    'indent' => $z['indent'],
                    'codes' => $z['codes'] ?? [],
                    'image' => ! empty($z['image']) ? ['url' => $url($z['image'])] : null,
                ], $zeilen);
            @endphp
            <div class="pt-konfig-detail-zeit">
                @include('foodalchemist::presentation.blocks._block_body', [
                    'b' => ['label' => $mehrereZeiten ? ($zeitName[$t] ?? $t) : '', 'is_header' => $mehrereZeiten, 'items' => $items],
                    'style' => $stil,
                ])
            </div>
        @endforeach
    </div>

    @if(! empty($d['legende']))
        <p class="pt-konfig-legende">@foreach($d['legende'] as $l){{ $l['code'] }} {{ $l['label'] }}@if(! $loop->last) · @endif @endforeach · * Spuren</p>
    @endif
    <div class="pt-konfig-weiter">
        @if($art === 'addon')
            <button type="button" class="pt-cta-btn" data-konfig-addon-waehlen="{{ $kz['id'] }}">Dazunehmen</button>
        @elseif($art === 'getraenk')
            <button type="button" class="pt-cta-btn" data-konfig-getraenk-waehlen="{{ $kz['id'] }}">Dazunehmen</button>
        @else
            <button type="button" class="pt-cta-btn" data-konfig-waehlen="{{ $kz['id'] }}">Konzept wählen</button>
        @endif
    </div>
</dialog>
