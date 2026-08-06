{{-- M5-07 / D-7: Pairing-Netz — Empfehler (Inspire-Umbau 2a): »was passt zum Gericht«.
     Zentrum = Gericht, Innenring = Kern-Anker, aussen die Kandidaten nach Stufe:
     best (Inspire L3, ★★★) im Mittelkreis, harmonie (★★/★) + kontrast (⇄) aussen,
     unten komplementäre Basisrezepte. Positionen fertig aus PairingService::pairingNetz —
     D3 (resources/js/pairing-netz) zeichnet nur. Schwarzer Editor-Grund (kein dark:). --}}

@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets

@php
    $zentrumNode = collect($netz['nodes'])->firstWhere('kind', 'zentrum');
    $counts = $netz['meta']['counts'] ?? ['best' => 0, 'harmonie' => 0, 'kontrast' => 0, 'basis' => 0];
    $typDefault = $netz['meta']['typ_default'] ?? ['best' => true, 'harmonie' => true, 'kontrast' => false];
    $chips = ['best' => ['#f472b6', 'Best ★★★'], 'harmonie' => ['#fbbf24', 'Harmonie ★★/★'], 'kontrast' => ['#22d3ee', 'Kontrast ⇄']];
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
            {{-- Kopf: Filter-Chips (best + harmonie an, kontrast zuschaltbar) --}}
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
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#f472b6" stroke-width="2.2"/></svg> best ★★★</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#fbbf24" stroke-width="2" stroke-dasharray="5 3"/></svg> harmonie ★★/★</span>
                <span class="inline-flex items-center gap-1"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#22d3ee" stroke-width="2" stroke-dasharray="1 3"/></svg> kontrast ⇄</span>
            </div>
        </div>
    @endif
</x-foodalchemist::modal>
