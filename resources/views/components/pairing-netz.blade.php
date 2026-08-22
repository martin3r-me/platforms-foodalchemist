{{--
    FA Pairing-Netz — kompakter Inline-Hub fürs Detail-Panel: Gericht zentral,
    Kern-Anker im Innenkreis, die vertrauenswürdigen Pairing-Kandidaten aussen
    (Stern-Stufen ★★★ / ★★ / ★). Positionen/Buckets fertig aus
    PairingService::pairingNetz (deterministisch) — D3 zeichnet nur. Voller Filter
    (kontrast, Basisrezepte) im „Netz öffnen"-Overlay. Schwarzer Grund (kein dark:).
--}}
@props(['recipeId', 'netz' => ['nodes' => [], 'edges' => [], 'meta' => []]])
@php
    $zentrumNode = collect($netz['nodes'])->firstWhere('kind', 'zentrum');
    $ankerNodes = collect($netz['nodes'])->where('kind', 'anker')->values();

    // Preview zeigt Gericht + Kern-Anker + die gemessenen Harmonie-Kandidaten (★★/★★★).
    $sichtbar = ['stern3', 'stern2'];
    $previewNodes = collect($netz['nodes'])
        ->filter(fn ($n) => in_array($n['kind'], ['zentrum', 'anker'], true) || ($n['kind'] === 'kandidat' && in_array($n['typ'] ?? null, $sichtbar, true)))
        ->values()->all();
    // anker_anker = innere Ebene (wie die Kern-Anker zusammenhängen) — immer mit.
    $previewEdges = collect($netz['edges'])
        ->filter(fn ($e) => in_array($e['kind'], ['zentrum_anker', 'anker_anker'], true) || ($e['kind'] === 'kandidat' && in_array($e['typ'] ?? null, $sichtbar, true)))
        ->values()->all();
@endphp

@if($zentrumNode === null || $ankerNodes->count() < 1)
    <p class="text-[13px] text-slate-400">Noch keine Kern-Anker verknüpft — Pairing-Netz sobald Anker gesetzt sind.</p>
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
            typDefault: { stern3: true, stern2: true, stern1: true },
        })"
        class="w-full"
    >
        <svg viewBox="0 0 360 230" class="w-full rounded-xl" style="background:#0b1120" data-fa-netz-mount></svg>
    </div>
@endif
