{{--
  Spec 43 — Block-Renderer der öffentlichen Präsentation. Läuft die aufgelöste Layout-
  Definition (resolved_design.layout) durch und rendert je Block sein Partial. Der
  Block-Typ wird über eine WHITELIST-match auf einen festen Partial-Pfad gemappt
  (kein user-supplied View-Pfad → kein LFI).
--}}
@extends('foodalchemist::layouts.presentation', ['snapshot' => $snapshot])

@section('content')
    @php
        $layout = $snapshot['resolved_design']['layout'] ?? [];
        $content = $snapshot['content'] ?? [];
        $branding = $snapshot['branding'] ?? [];
        $meta = $snapshot['meta'] ?? [];
        $settings = $snapshot['settings'] ?? [];
        $tokens = $snapshot['resolved_design']['tokens'] ?? [];
    @endphp

    @php $istKonfigurator = ($snapshot['type'] ?? null) === 'konfigurator'; $konfigChrome = false; @endphp
    @if($istKonfigurator)<div class="pt-konfig-seite" data-konfig-seite>@endif
    @foreach($layout as $block)
        {{-- Spec 94 Stufe 5: vor dem ersten Konfigurator-Schritt Schritt-Leiste, Gäste und Preisleisten (gilt für jedes Design) --}}
        @if($istKonfigurator && ! $konfigChrome && str_starts_with($block['block_type'] ?? '', 'konfig_'))
            @include('foodalchemist::presentation.blocks._konfigurator_chrome', ['content' => $content])
            @php $konfigChrome = true; @endphp
        @endif
        @php
            $partial = match ($block['block_type'] ?? '') {
                'cover' => 'foodalchemist::presentation.blocks.cover',
                'chapter_loop' => 'foodalchemist::presentation.blocks.chapter_loop',
                'dish_list' => 'foodalchemist::presentation.blocks.dish_list',
                'price_summary' => 'foodalchemist::presentation.blocks.price_summary',
                'preis_aufschluesselung' => 'foodalchemist::presentation.blocks.preis_aufschluesselung',
                'legend' => 'foodalchemist::presentation.blocks.legend',
                'grid' => 'foodalchemist::presentation.blocks.grid',
                'text' => 'foodalchemist::presentation.blocks.text',
                'heading' => 'foodalchemist::presentation.blocks.heading',
                'image' => 'foodalchemist::presentation.blocks.image',
                'spacer' => 'foodalchemist::presentation.blocks.spacer',
                'cta' => 'foodalchemist::presentation.blocks.cta',
                // Spec 94: Konfigurator-Schritte (nur im Typ konfigurator, Layout erzwingt sie je einmal)
                'konfig_konzepte' => 'foodalchemist::presentation.blocks.konfig_konzepte',
                'konfig_tageszeit' => 'foodalchemist::presentation.blocks.konfig_tageszeit',
                'konfig_getraenke' => 'foodalchemist::presentation.blocks.konfig_getraenke',
                'konfig_addons' => 'foodalchemist::presentation.blocks.konfig_addons',
                'konfig_zusammenfassung' => 'foodalchemist::presentation.blocks.konfig_zusammenfassung',
                'konfig_anfrage' => 'foodalchemist::presentation.blocks.konfig_anfrage',
                default => null,
            };
        @endphp
        @if($partial)
            @include($partial, [
                'style' => $block['style'] ?? [],
                'snap' => $snapshot,
                'content' => $content,
                'branding' => $branding,
                'meta' => $meta,
                'settings' => $settings,
                'tokens' => $tokens,
            ])
        @endif
    @endforeach
    @if($istKonfigurator)</div>@endif
    @if(($snapshot['type'] ?? null) === 'konfigurator')
        @include('foodalchemist::presentation.blocks._konfigurator_script', ['content' => $content])
    @endif
@endsection
