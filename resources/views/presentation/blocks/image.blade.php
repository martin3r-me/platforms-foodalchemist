{{-- Freier Bild-Block (Struktur-Builder) — nur wenn eine (frisch signierte) URL vorliegt.
     Darstellung/Höhe/Breite über den Stil steuerbar; design-eigen, inhalts-unabhängig. --}}
@if(!empty($style['url']))
    @php
        $imgFit = in_array($style['img_fit'] ?? 'cover', ['cover', 'contain', 'auto'], true) ? ($style['img_fit'] ?? 'cover') : 'cover';
        $imgH = in_array($style['img_height'] ?? 'mittel', ['klein', 'mittel', 'gross'], true) ? ($style['img_height'] ?? 'mittel') : 'mittel';
        $imgW = in_array($style['img_width'] ?? 'voll', ['voll', 'schmal', 'bleed'], true) ? ($style['img_width'] ?? 'voll') : 'voll';
        $wrap = $imgW === 'schmal' ? 'pt-measure' : ($imgW === 'voll' ? 'pt-wide' : '');
    @endphp
    <div class="{{ $wrap }}">
        <figure class="pt-image-band pt-image-band--fit-{{ $imgFit }} pt-image-band--h-{{ $imgH }} pt-reveal">
            <img class="pt-zoomable" src="{{ $style['url'] }}" alt="">
        </figure>
    </div>
@endif
