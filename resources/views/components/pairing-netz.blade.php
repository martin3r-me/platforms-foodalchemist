{{--
    FA Pairing-Netz — kompakter Inline-Hub fürs Detail-Panel: Gericht zentral,
    Kern-Anker im Innenkreis, die vertrauenswürdigen Pairing-Kandidaten aussen
    (★★★ = echtes Food Pairing; Kontrast und Konflikt im Overlay). Positionen/Buckets fertig aus
    PairingService::pairingNetz (deterministisch) — D3 zeichnet nur. Voller Filter
    (kontrast, Basisrezepte) im „Netz öffnen"-Overlay. Schwarzer Grund (kein dark:).
--}}
@props(['recipeId', 'netz' => ['nodes' => [], 'edges' => [], 'meta' => []]])
{{-- Bundle hier mitladen: die Vorschau steht auch in Editoren ohne Netz-Modal (Planung, Concepter). @assets lädt einmal. --}}
@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets
@php
    $zentrumNode = collect($netz['nodes'])->firstWhere('kind', 'zentrum');
    $ankerNodes = collect($netz['nodes'])->whereIn('kind', ['anker', 'bestandteil'])->values();
    $istGericht = ($netz['meta']['art'] ?? null) === 'gericht';   // Spec 60: Gericht = Basisrezepte, Anker im Hintergrund

    // Preview zeigt Gericht + Kern-Anker + die gemessenen Harmonie-Kandidaten (Spec 60: nur ★★★).
    $sichtbar = ['stern3'];
    // Vorschau knapp halten: höchstens 8 Vorschläge (das volle Netz zeigt das Modal).
    $previewNodes = collect($netz['nodes'])
        ->filter(fn ($n) => in_array($n['kind'], ['zentrum', 'anker', 'bestandteil'], true)
            || (in_array($n['kind'], ['kandidat', 'basisrezept'], true) && in_array($n['typ'] ?? null, $sichtbar, true) && ($n['kind'] === 'kandidat' || $istGericht)))
        ->values();
    $aussen = $previewNodes->filter(fn ($n) => in_array($n['kind'], ['kandidat', 'basisrezept'], true))->take(8)->pluck('id')->flip();
    $previewNodes = $previewNodes->filter(fn ($n) => ! in_array($n['kind'], ['kandidat', 'basisrezept'], true) || isset($aussen[$n['id']]))->values()->all();
    $previewIds = collect($previewNodes)->pluck('id')->flip();
    // anker_anker = innere Ebene (wie die Kern-Anker zusammenhängen) — immer mit.
    $previewEdges = collect($netz['edges'])
        ->filter(fn ($e) => isset($previewIds[$e['source']], $previewIds[$e['target']]))
        ->filter(fn ($e) => in_array($e['kind'], ['zentrum_anker', 'anker_anker', 'konflikt', 'teil_teil'], true)
            || ($istGericht && $e['kind'] === 'basis' && in_array($e['typ'] ?? null, $sichtbar, true)) || ($e['kind'] === 'kandidat' && in_array($e['typ'] ?? null, $sichtbar, true)))
        ->values()->all();
@endphp

@if($zentrumNode === null || $ankerNodes->count() < 1)
    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-3)]">{{ $istGericht ? 'Noch keine Bestandteile — das Netz zeigt die Basisrezepte des Gerichts, sobald welche eingesetzt sind.' : 'Noch kein Aromenprofil — das Netz erscheint, sobald eine Zutat einem Aroma zugeordnet ist.' }}</p>
@else
    <div
        wire:ignore
        wire:key="pairing-netz-preview-{{ $recipeId }}-{{ $netz['meta']['sig'] ?? '' }}"
        x-data="pairingNetzGraph({
            nodes: @js($previewNodes),
            edges: @js($previewEdges),
            mode: 'preview',
            canvasW: {{ (float) ($netz['meta']['canvas_w'] ?? 1000) }},
            canvasH: {{ (float) ($netz['meta']['canvas_h'] ?? 760) }},
            typDefault: { stern3: true, kontrast: false },
        })"
        class="w-full"
    >
        <svg viewBox="0 0 360 230" class="w-full rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]" data-fa-netz-mount></svg>
    </div>
@endif
