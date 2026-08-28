{{-- Freies Bild (Struktur-Builder). URL wird zur Render-Zeit frisch signiert
     (hydrateImages füllt style.url aus style.context_file_id/path). --}}
@if(!empty($style['url']))
    <img class="pt-image" src="{{ $style['url'] }}" alt="{{ $style['alt'] ?? '' }}">
@endif
