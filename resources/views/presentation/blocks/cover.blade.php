{{-- Cover / Hero — Vollbild-Bild (Branding-Cover) mit Overlay, sonst elegantes typografisches Cover. --}}
@php
    $coverUrl = ($style['show_cover_image'] ?? true) ? ($branding['cover']['url'] ?? null) : null;
    $logoUrl = ($style['show_logo'] ?? true) ? ($branding['logo']['url'] ?? null) : null;
    $kicker = $meta['kicker'] ?? 'Kulinarisches Angebot';
@endphp
<header class="pt-hero {{ $coverUrl ? 'has-media' : 'no-media' }}">
    @if($coverUrl)
        <div class="pt-hero-media"><img src="{{ $coverUrl }}" alt=""></div>
    @endif
    <div class="pt-hero-inner">
        @if($logoUrl)<img class="pt-hero-logo" src="{{ $logoUrl }}" alt="">@endif
        <div class="pt-kicker">{{ $kicker }}</div>
        <h1 class="pt-hero-title">{{ $snap['title'] ?? '' }}</h1>
        @if(!empty($snap['subtitle']))<p class="pt-hero-sub">{{ $snap['subtitle'] }}</p>@endif
        <div class="pt-hero-meta">
            @if(!empty($meta['customer'])){{ $meta['customer'] }}@endif
            @if(!empty($meta['jahr'])) <span aria-hidden="true">·</span> {{ $meta['jahr'] }}@endif
        </div>
    </div>
</header>
