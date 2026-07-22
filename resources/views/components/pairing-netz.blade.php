{{--
    FA Pairing-Netz — kompakter Inline-Hub fürs Detail-Panel: Gericht zentral,
    Kern-Anker im Innenkreis, die erprobten Pairing-Kandidaten aussen (2026-07-22
    Empfehler-Redesign). Positionen/Typen fertig aus PairingService::pairingNetz
    (deterministisch) — D3 (resources/js/pairing-netz) zeichnet nur. Voller Filter
    (aroma/kontrast, Basisrezepte) im „Netz öffnen"-Overlay.
--}}
@props(['recipeId'])
@php
    $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation;
    $netz = ($team !== null && $recipeId !== null)
        ? app(\Platform\FoodAlchemist\Services\PairingService::class)->pairingNetz($team, $recipeId)
        : ['nodes' => [], 'edges' => [], 'meta' => []];

    $zentrumNode = collect($netz['nodes'])->firstWhere('kind', 'zentrum');
    $ankerNodes = collect($netz['nodes'])->where('kind', 'anker')->values();

    // Preview zeigt nur Gericht + Kern-Anker + erprobte Kandidaten (kompakter Hub).
    $previewNodes = collect($netz['nodes'])
        ->filter(fn ($n) => in_array($n['kind'], ['zentrum', 'anker'], true) || ($n['kind'] === 'kandidat' && ($n['typ'] ?? null) === 'erprobt'))
        ->values()->all();
    $previewEdges = collect($netz['edges'])
        ->filter(fn ($e) => $e['kind'] === 'zentrum_anker' || ($e['kind'] === 'kandidat' && ($e['typ'] ?? null) === 'erprobt'))
        ->values()->all();
@endphp

@if($zentrumNode === null || $ankerNodes->count() < 1)
    <p class="text-[13px] text-gray-500">Noch keine Kern-Anker verknüpft — Pairing-Netz sobald Anker gesetzt sind.</p>
@else
    <div
        wire:ignore
        wire:key="pairing-netz-preview-{{ $recipeId }}"
        x-data="pairingNetzGraph({
            nodes: @js($previewNodes),
            edges: @js($previewEdges),
            mode: 'preview',
            canvasW: {{ (float) ($netz['meta']['canvas_w'] ?? 1000) }},
            canvasH: {{ (float) ($netz['meta']['canvas_h'] ?? 760) }},
            typDefault: { erprobt: true, aroma: false, kontrast: false },
        })"
        class="w-full"
    >
        <svg viewBox="0 0 360 230" class="w-full rounded-xl bg-black/[0.02]" data-fa-netz-mount></svg>
    </div>
@endif
