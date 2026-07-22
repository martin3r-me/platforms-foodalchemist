{{-- M5-07 / D-7: Pairing-Netz — Empfehler (2026-07-22 Redesign): »was passt zum Gericht,
     nach Typ«. Zentrum = Gericht, Innenring = Kern-Anker, aussen Kandidaten in Typ-Sektoren
     (erprobt/aroma/kontrast, per Chip filterbar), unten komplementäre Basisrezepte. Positionen
     fertig aus PairingService::pairingNetz — D3 (resources/js/pairing-netz) zeichnet nur. --}}
@php
    $zentrumNode = collect($netz['nodes'])->firstWhere('kind', 'zentrum');
    $counts = $netz['meta']['counts'] ?? ['erprobt' => 0, 'aroma' => 0, 'kontrast' => 0, 'basis' => 0];
    $typDefault = $netz['meta']['typ_default'] ?? ['erprobt' => true, 'aroma' => false, 'kontrast' => false];
@endphp
<x-foodalchemist::modal name="pairing-netz" title="Pairing-Netz: {{ $zentrumNode['label'] ?? '' }}" size="max-w-7xl">
    @if($zentrumNode === null)
        <p class="text-xs text-gray-500">Kein Rezept gewählt.</p>
    @else
        <div
            wire:ignore
            wire:key="pairing-netz-{{ $recipeId }}"
            x-data="pairingNetzGraph({
                nodes: @js($netz['nodes']),
                edges: @js($netz['edges']),
                mode: 'modal',
                canvasW: {{ (float) ($netz['meta']['canvas_w'] ?? 1000) }},
                canvasH: {{ (float) ($netz['meta']['canvas_h'] ?? 760) }},
                typDefault: @js($typDefault),
                onNodeClick: (id) => $wire.zeigeRezept(id),
            })"
        >
            {{-- Kopf: Typ-Filter-Chips (erprobt an, aroma/kontrast zuschaltbar) --}}
            <div class="flex flex-wrap items-center gap-2 mb-2 text-[11px]" data-netz-kopf>
                <span class="text-gray-500 mr-1">Was passt dazu:</span>
                @foreach(['erprobt' => '#d6409f', 'aroma' => '#f59e0b', 'kontrast' => '#06b6d4'] as $typ => $farbe)
                    <button type="button" @click="toggleTyp('{{ $typ }}')"
                            :class="typAktiv['{{ $typ }}'] ? 'ring-2 ring-offset-1' : 'opacity-45'"
                            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 transition"
                            style="border-color: {{ $farbe }}; --tw-ring-color: {{ $farbe }};"
                            data-netz-chip="{{ $typ }}">
                        <span class="w-2 h-2 rounded-full" style="background: {{ $farbe }}"></span>
                        {{ ucfirst($typ) }} ({{ $counts[$typ] ?? 0 }})
                    </button>
                @endforeach
                <span class="text-gray-400 ml-2">Basisrezepte: {{ $counts['basis'] ?? 0 }} · Klick auf Rezept = öffnen · Scroll/Ziehen = Zoom/Pan</span>
            </div>

            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet" class="w-full rounded-xl bg-black/[0.02]" style="height:76vh" data-fa-netz-mount></svg>

            {{-- Legende --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[10px] text-gray-600" data-netz-legende>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-orange-300 border border-orange-600"></span> Gericht</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-violet-200 border border-violet-600"></span> Kern-Anker (★)</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full bg-green-300 border border-green-600"></span> Basisrezept (komplementär)</span>
                <span class="text-gray-300">|</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#d6409f" stroke-width="2.2"/></svg> erprobt</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#f59e0b" stroke-width="2" stroke-dasharray="5 3"/></svg> aroma</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#06b6d4" stroke-width="2" stroke-dasharray="1 3"/></svg> kontrast</span>
            </div>
        </div>
    @endif
</x-foodalchemist::modal>
