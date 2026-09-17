{{-- Cover / Hero — Vollbild-Bild (Branding-Cover) mit Overlay, sonst elegantes typografisches Cover. --}}
@php
    $coverUrl = ($style['show_cover_image'] ?? true) ? ($branding['cover']['url'] ?? null) : null;
    $logoUrl = ($style['show_logo'] ?? true) ? ($branding['logo']['url'] ?? null) : null;
    $kicker = $meta['kicker'] ?? 'Kulinarisches Angebot';
    // Bild-Spielraum: Füll-Modus (füllen=cover / einpassen=contain, zeigt das ganze Bild) + Höhe.
    $fitContain = ($style['cover_fit'] ?? 'cover') === 'contain';
    // Höhe: frei einstellbar in % der Fensterhöhe (Bug-Runde 2026-09-17 — drei feste Stufen
    // reichten nicht). Bestandsdesigns und bereits veröffentlichte Snapshots tragen noch die
    // alten Stufen-Wörter; die behalten ihr bisheriges Rendering (Klasse inkl. px-Deckel),
    // damit kein Kundenlink sein Aussehen ändert.
    $rohHoehe = $style['cover_height'] ?? 'gross';
    $istStufe = in_array($rohHoehe, ['klein', 'mittel', 'gross'], true);
    $hoeheKlasse = $istStufe ? 'pt-hero--h-' . $rohHoehe : '';
    $hoeheStil = '';
    if (! $istStufe && is_numeric($rohHoehe)) {
        // Nur ganze Zahlen in einen inline-style lassen (öffentlicher Renderer → keine CSS-Injection).
        $vh = max(10, min(100, (int) $rohHoehe));
        $deckel = isset($style['cover_height_max_px']) && is_numeric($style['cover_height_max_px'])
            ? max(120, min(2000, (int) $style['cover_height_max_px']))
            : null;
        $hoeheStil = 'min-height: ' . ($deckel !== null ? 'min(' . $vh . 'vh, ' . $deckel . 'px)' : $vh . 'vh') . ';';
    } elseif (! $istStufe) {
        $hoeheKlasse = 'pt-hero--h-gross';
    }
@endphp
<header class="pt-hero {{ $hoeheKlasse }} {{ $fitContain ? 'pt-hero--fit-contain' : '' }} {{ $coverUrl ? 'has-media' : 'no-media' }}"@if($hoeheStil) style="{{ $hoeheStil }}"@endif>
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
