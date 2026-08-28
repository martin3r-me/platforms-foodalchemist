{{-- Cover: Cover-Bild (frisch signiert), Logo, Titel/Untertitel, Kunde/Jahr. --}}
<header class="pt-cover">
    @if(($style['show_cover_image'] ?? false) && !empty($branding['cover']['url']))
        <img class="pt-cover-img" src="{{ $branding['cover']['url'] }}" alt="">
    @endif
    @if(($style['show_logo'] ?? false) && !empty($branding['logo']['url']))
        <img class="pt-logo" src="{{ $branding['logo']['url'] }}" alt="">
    @endif
    <h1 class="pt-title">{{ $snap['title'] ?? '' }}</h1>
    @if(!empty($snap['subtitle']))
        <p class="pt-subtitle">{{ $snap['subtitle'] }}</p>
    @endif
    @if(!empty($meta['customer']) || !empty($meta['jahr']))
        <p class="pt-meta">
            @if(!empty($meta['customer']))<span>{{ $meta['customer'] }}</span>@endif
            @if(!empty($meta['jahr']))<span>· {{ $meta['jahr'] }}</span>@endif
        </p>
    @endif
</header>
