{{-- Bild-Band (Struktur-Builder) — nur wenn eine (frisch signierte) URL vorliegt. --}}
@if(!empty($style['url']))
    <div class="pt-image-band pt-reveal"><img src="{{ $style['url'] }}" alt=""></div>
@endif
