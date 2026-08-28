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

    @foreach($layout as $block)
        @php
            $partial = match ($block['block_type'] ?? '') {
                'cover' => 'foodalchemist::presentation.blocks.cover',
                'chapter_loop' => 'foodalchemist::presentation.blocks.chapter_loop',
                'dish_list' => 'foodalchemist::presentation.blocks.dish_list',
                'price_summary' => 'foodalchemist::presentation.blocks.price_summary',
                'legend' => 'foodalchemist::presentation.blocks.legend',
                'grid' => 'foodalchemist::presentation.blocks.grid',
                'text' => 'foodalchemist::presentation.blocks.text',
                'heading' => 'foodalchemist::presentation.blocks.heading',
                'image' => 'foodalchemist::presentation.blocks.image',
                'spacer' => 'foodalchemist::presentation.blocks.spacer',
                'cta' => 'foodalchemist::presentation.blocks.cta',
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
@endsection
