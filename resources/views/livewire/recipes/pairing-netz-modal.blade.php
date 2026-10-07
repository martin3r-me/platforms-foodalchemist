{{-- M5-07 / D-7: Pairing-Netz — Empfehler (Inspire-Umbau 2a): »was passt zum Gericht«.
     Zentrum = Gericht, Innenring = Kern-Anker, aussen die Kandidaten nach Stufe:
     ★★★ (Inspire L3, echtes Food Pairing) im Mittelkreis, aussen Kontrast-Lieferanten für
     offene Bedarfe und komplementäre Basisrezepte; Konflikte als rote Linie (Spec 60). Positionen fertig aus PairingService::pairingNetz —
     D3 (resources/js/pairing-netz) zeichnet nur. Schwarzer Editor-Grund (kein dark:). --}}

@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets

@php
    $zentrumNode = collect($netz['nodes'])->firstWhere('kind', 'zentrum');
    $counts = $netz['meta']['counts'] ?? ['stern3' => 0, 'kontrast' => 0, 'basis' => 0];
    $typDefault = $netz['meta']['typ_default'] ?? ['stern3' => true, 'kontrast' => true];
    // Spec 60: ★★★ = echtes Food Pairing; Kontrast = Lieferant für einen offenen Bedarf. 2★ ist Rauschen.
    $istGericht = ($netz['meta']['art'] ?? null) === 'gericht';
    $chips = $istGericht
        ? ['stern3' => ['#fcd34d', 'passt dazu'], 'kontrast' => ['#22d3ee', 'deckt offenen Bedarf']]
        : ['stern3' => ['#fcd34d', '★★★ harmoniert'], 'kontrast' => ['#22d3ee', 'Kontrast']];
@endphp
<x-foodalchemist::modal name="pairing-netz" title="Pairing-Netz" :title-name="$zentrumNode['label'] ?? null" size="max-w-7xl" dark-canvas>
    @if($zentrumNode === null)
        <x-fa::empty compact icon="heroicon-o-share" title="Kein Rezept gewählt" />
    @else
        <div
            wire:ignore
            wire:key="pairing-netz-{{ $recipeId }}-{{ $netz['meta']['sig'] ?? '' }}"
            class="flex flex-col gap-2"
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
            {{-- Kopf: Filter ★★★ (echtes Food Pairing) / Kontrast (Spec 60) — Optik aus fa-pass, Farbpunkt = Linienfarbe im Netz --}}
            <div class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-sm)]" data-netz-kopf>
                <span class="text-[var(--fa-ink-2)]">Was passt dazu</span>
                @foreach($chips as $typ => [$farbe, $label])
                    <button type="button" @click="toggleTyp('{{ $typ }}')"
                            :class="typAktiv['{{ $typ }}'] ? 'border-[var(--fa-accent)] bg-[var(--fa-hover)] text-[var(--fa-ink)]' : 'border-[var(--fa-line)] text-[var(--fa-ink-3)]'"
                            class="inline-flex items-center gap-1.5 h-7 px-2.5 rounded-full border transition"
                            data-netz-chip="{{ $typ }}">
                        <span class="w-2 h-2 rounded-full" style="background: {{ $farbe }}"></span>
                        {{ $label }} <span class="tabular-nums text-[var(--fa-ink-3)]">{{ $counts[$typ] ?? 0 }}</span>
                    </button>
                @endforeach
                <span class="ml-auto text-[var(--fa-ink-3)]">{{ $istGericht ? 'Bestandteile '.($counts['bestandteile'] ?? 0).' · Vorschläge '.($counts['basis'] ?? 0) : 'Basisrezepte '.($counts['basis'] ?? 0) }} · Klick auf ein Rezept öffnet es · Mausrad und Ziehen zoomen und verschieben</span>
            </div>

            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet" class="w-full h-[70vh] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]" data-fa-netz-mount></svg>

            {{-- Legende --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-netz-legende>
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full border-2 border-[var(--fa-accent)]"></span> Gericht</span>
                @if($istGericht)
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full border-2 border-[var(--fa-ok)] bg-[var(--fa-ok-soft)]"></span> Bestandteil (Basisrezept des Gerichts)</span>
                @else
                    <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[var(--fa-netz-anker)]"></span> Kern-Anker mit Anteil am Aromenprofil</span>
                @endif
                <span class="inline-flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-[var(--fa-ok)]"></span> Basisrezept</span>
                <span class="inline-flex items-center gap-1.5"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#fcd34d" stroke-width="2.4"/></svg> ★★★ harmoniert (gemessen)</span>
                <span class="inline-flex items-center gap-1.5"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#22d3ee" stroke-width="2" stroke-dasharray="1 4"/></svg> Kontrast: deckt einen offenen Bedarf</span>
                <span class="inline-flex items-center gap-1.5"><svg width="22" height="6"><line x1="0" y1="3" x2="22" y2="3" stroke="#f43f5e" stroke-width="2" stroke-dasharray="5 3"/></svg> Konflikt zwischen Kern-Ankern</span>
            </div>
        </div>
    @endif
</x-foodalchemist::modal>
