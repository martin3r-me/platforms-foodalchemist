{{-- M5-07 / D-7: Pairing-Netz — Empfehler (Inspire-Umbau 2a): »was passt zum Gericht«.
     Zentrum = Gericht, Innenring = Kern-Anker, aussen die Kandidaten nach Stufe:
     ★★★ (Inspire L3, Best-Match) im Mittelkreis, ★★ (L2) + ★ (Basis) aussen,
     unten komplementäre Basisrezepte. Positionen fertig aus PairingService::pairingNetz —
     D3 (resources/js/pairing-netz) zeichnet nur. Schwarzer Editor-Grund (kein dark:). --}}

@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets

@php
    $zentrumNode = collect($netz['nodes'])->firstWhere('kind', 'zentrum');
    $counts = $netz['meta']['counts'] ?? ['stern3' => 0, 'stern2' => 0, 'stern1' => 0, 'basis' => 0];
    $typDefault = $netz['meta']['typ_default'] ?? ['stern3' => true, 'stern2' => true, 'stern1' => true];
    $chips = ['stern3' => ['#fcd34d', '★★★ Best'], 'stern2' => ['#f59e0b', '★★ Good'], 'stern1' => ['#94a3b8', '★ Basis']];
@endphp
<x-foodalchemist::modal name="pairing-netz" title="Pairing-Netz: {{ $zentrumNode['label'] ?? '' }}" size="max-w-7xl">
    @if($zentrumNode === null)
        <p class="text-xs text-gray-500">Kein Rezept gewählt.</p>
    @else
        <div
            wire:ignore
            wire:key="pairing-netz-{{ $recipeId }}"
            class="rounded-xl p-3"
            style="background:#0b1120"
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
            {{-- Kopf: Filter-Chips (Stern-Stufen ★★★ / ★★ / ★) --}}
            <div class="flex flex-wrap items-center gap-2 mb-2 text-[11px]" data-netz-kopf>
                <span class="text-slate-400 mr-1">Was passt dazu:</span>
                @foreach($chips as $typ => [$farbe, $label])
                    <button type="button" @click="toggleTyp('{{ $typ }}')"
                            :class="typAktiv['{{ $typ }}'] ? 'ring-2 ring-offset-1 ring-offset-slate-900' : 'opacity-45'"
                            class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 transition text-slate-200"
                            style="border-color: {{ $farbe }}; --tw-ring-color: {{ $farbe }};"
                            data-netz-chip="{{ $typ }}">
                        <span class="w-2 h-2 rounded-full" style="background: {{ $farbe }}"></span>
                        {{ $label }} ({{ $counts[$typ] ?? 0 }})
                    </button>
                @endforeach
                <span class="text-slate-500 ml-2">Basisrezepte: {{ $counts['basis'] ?? 0 }} · Klick auf Rezept = öffnen · Scroll/Ziehen = Zoom/Pan</span>
            </div>

            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet" class="w-full rounded-xl" style="height:76vh; background:#0b1120" data-fa-netz-mount></svg>

            {{-- Legende --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[10px] text-slate-400" data-netz-legende>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full" style="background:#fdba74"></span> Gericht</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full" style="background:#ddd6fe"></span> Kern-Anker (★)</span>
                <span class="inline-flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-full" style="background:#86efac"></span> Basisrezept</span>
                <span class="text-slate-600">|</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#fcd34d" stroke-width="2.4"/></svg> ★★★ Best-Match</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#f59e0b" stroke-width="2" stroke-dasharray="5 3"/></svg> ★★ Good-Match</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#94a3b8" stroke-width="1.6" stroke-dasharray="2 3"/></svg> ★ Basis</span>
            </div>
        </div>
    @endif
</x-foodalchemist::modal>
