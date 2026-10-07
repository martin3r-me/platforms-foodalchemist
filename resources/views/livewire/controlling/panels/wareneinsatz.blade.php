{{-- Einkauf E4 — Wareneinsatz-Optimierung: Ist vs. Optimal (Listenpreis / inkl. Rückvergütung).
     Spec 32: von der eigenen Seite `/einkauf/optimierung` zum Panel im Controlling-Tab
     „Wareneinsatz"; Seiten-Hülle entfällt, Titel trägt jetzt der Tab.

     fa-pass 2026-10-05: Tokens + Bausteine. Ist und zwei Optima als Kennzahl-Leiste (Hauptzahl:
     Ist), Lieferanten-Ausklammern als Chips, Umstellen zweistufig (Vorschau → eine Hauptaktion),
     Tabelle als fa-table. wire:-Bindungen, data-Marker und Reihenfolge unverändert. --}}
@php
    $eur = fn ($v) => number_format((float) $v, 2, ',', '.') . ' €';
    $nUmstellbar = collect($r['top'])->where('lead_ist_optimal', false)->count();
    $nAuswahl = count($auswahl);
@endphp

<div class="flex flex-col gap-4" data-ctrl-wareneinsatz>
    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
        Was tatsächlich bezahlt wurde, gegen den günstigsten Lieferanten je Grundprodukt, mit und ohne Rückvergütung.
        Markierte Positionen lassen sich dauerhaft auf den günstigsten Bezug umstellen.
    </p>

    @if($r['n_articles'] === 0)
        <div class="rounded-[var(--fa-radius-surface)] border border-dashed border-[var(--fa-line-strong)]">
            <x-fa::empty icon="heroicon-o-chart-bar" title="Noch keine Einkäufe im Journal">
                Sobald Bestellungen geliefert oder Einkäufe importiert sind, steht hier der tatsächliche Wareneinsatz
                gegen den günstigsten Bezug, mit und ohne Rückvergütung.
            </x-fa::empty>
        </div>
    @else
        {{-- Kennzahlen: Ist gegen zwei Optima --}}
        <x-fa::kpis :items="[
            ['label' => 'Tatsächlich bezahlt', 'value' => $eur($r['ist_total']), 'primary' => true,
             'title' => $r['n_articles'] . ' Artikel im Einkaufsjournal'],
            ['label' => 'Beim günstigsten Lieferanten', 'value' => $eur($r['optimal_list_total']),
             'title' => 'Listenpreis des günstigsten Lieferanten je Grundprodukt'],
            ['label' => 'Ersparnis Listenpreis', 'tone' => 'ok',
             'value' => '−' . $eur($r['saving_list']) . ($r['saving_list_pct'] !== null ? ' (' . number_format($r['saving_list_pct'], 1, ',', '.') . ' %)' : '')],
            ['label' => 'Mit Rückvergütung', 'value' => $eur($r['optimal_rebate_total']),
             'title' => 'Effektiver Nettopreis nach Rückvergütung'],
            ['label' => 'Ersparnis mit Rückvergütung', 'tone' => 'ok',
             'value' => '−' . $eur($r['saving_rebate']) . ($r['saving_rebate_pct'] !== null ? ' (' . number_format($r['saving_rebate_pct'], 1, ',', '.') . ' %)' : '')],
        ]" />
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
            Grundlage: {{ number_format($r['n_articles'], 0, ',', '.') }} Artikel im Einkaufsjournal.
        </p>

        @if($r['n_skipped'] > 0)
            <x-fa::signal tone="warn">
                {{ $r['n_skipped'] }} {{ $r['n_skipped'] === 1 ? 'Position hat' : 'Positionen haben' }} keinen vergleichbaren Alternativpreis und {{ $r['n_skipped'] === 1 ? 'zählt' : 'zählen' }} nicht mit.
            </x-fa::signal>
        @endif

        {{-- Was-wäre-wenn: Lieferant ausklammern --}}
        @if(count($lieferanten))
            <x-fa::section variant="plain" title="Lieferanten ausklammern" icon="heroicon-o-no-symbol"
                           description="Was wäre, wenn bei diesen Lieferanten nicht mehr bestellt wird? Die Rechnung oben und die Liste unten passen sich an.">
                <div class="flex flex-wrap gap-1.5">
                    @foreach($lieferanten as $l)
                        <label wire:key="we-excl-{{ $l->id }}" class="fa-chip" title="{{ $l->name }}">
                            <input type="checkbox" wire:model.live="excludeSupplierIds" value="{{ $l->id }}" class="sr-only peer" />
                            <span class="max-w-[16rem] truncate">{{ $l->name }}</span>
                        </label>
                    @endforeach
                </div>
            </x-fa::section>
        @endif

        {{-- Spec 32 — die Maßnahme zur Analyse: markierte Positionen dauerhaft auf den
             günstigsten Lieferanten umstellen. Bewusst zweistufig (erst Vorschau, dann
             ausführen): eine Umstellung verschiebt den EK jedes Rezepts, in dem das
             Grundprodukt steckt; das darf niemand aus Versehen auslösen. --}}
        <x-fa::section variant="plain" title="Bezugsquellen umstellen" icon="heroicon-o-arrows-right-left"
                       :description="$nAuswahl . ' von ' . $nUmstellbar . ' umstellbaren Positionen markiert. Bereits günstig bezogene Zeilen lassen sich nicht markieren.'"
                       data-ctrl-batch>
            <x-slot:actions>
                <x-fa::button variant="ghost" size="sm" wire:click="alleWaehlen" :disabled="$nUmstellbar === 0">Alle umstellbaren wählen</x-fa::button>
                <x-fa::button variant="ghost" size="sm" wire:click="auswahlLeeren" :disabled="$nAuswahl === 0">Auswahl leeren</x-fa::button>
                <x-fa::button size="sm" icon="heroicon-o-eye" wire:click="vorschau"
                              data-ctrl-batch-vorschau :disabled="$nAuswahl === 0">Auswirkung zeigen</x-fa::button>
            </x-slot:actions>

            @if($hinweis)<x-fa::signal tone="ok" data-ctrl-batch-hinweis>{{ $hinweis }}</x-fa::signal>@endif
            @if($fehler)<x-fa::signal tone="crit" data-ctrl-batch-fehler>{{ $fehler }}</x-fa::signal>@endif

            @if($vorschau)
                <div class="flex flex-col gap-2 rounded-[var(--fa-radius-surface)] border border-[var(--fa-accent-line)] bg-[var(--fa-accent-soft)] px-4 py-3" data-ctrl-batch-vorschau-box>
                    <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                        <strong class="font-semibold">{{ $vorschau['n_gps'] }} {{ $vorschau['n_gps'] === 1 ? 'Bezugsquelle' : 'Bezugsquellen' }}</strong> umstellen.
                        Betrifft <strong class="font-semibold">{{ number_format($vorschau['n_rezepte'], 0, ',', '.') }} {{ $vorschau['n_rezepte'] === 1 ? 'Rezept' : 'Rezepte' }}</strong>,
                        davon <strong class="font-semibold">{{ number_format($vorschau['n_gerichte'], 0, ',', '.') }} {{ $vorschau['n_gerichte'] === 1 ? 'Verkaufsgericht' : 'Verkaufsgerichte' }}</strong>.
                        Rechnerische Ersparnis auf die eingekaufte Menge: <strong class="font-semibold">{{ $eur($vorschau['ersparnis']) }}</strong>.
                    </p>
                    <ul class="flex flex-col gap-0.5">
                        @foreach($vorschau['gps'] as $g)
                            <li class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                                {{ $g['name'] }} <span class="text-[var(--fa-ink-3)]">zu</span> {{ $g['nach'] }}
                                <span class="ml-1 tabular-nums text-[var(--fa-ok)]">{{ $eur($g['ersparnis']) }}</span>
                            </li>
                        @endforeach
                        @if($vorschau['gekuerzt'] > 0)
                            <li class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">und {{ $vorschau['gekuerzt'] }} weitere</li>
                        @endif
                    </ul>
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[60ch]">
                            Die Ersparnis ist eine Rückrechnung auf die bereits eingekaufte Menge. Sie sagt, was der Bezug
                            gekostet hätte, nicht was künftig sicher gespart wird.
                        </p>
                        <x-fa::button variant="primary" icon="heroicon-o-arrows-right-left" wire:click="umstellen" wire:loading.attr="disabled"
                                      wire:confirm="{{ $vorschau['n_gps'] }} {{ $vorschau['n_gps'] === 1 ? 'Bezugsquelle' : 'Bezugsquellen' }} umstellen? Der Einkaufspreis wird für {{ $vorschau['n_rezepte'] }} {{ $vorschau['n_rezepte'] === 1 ? 'Rezept' : 'Rezepte' }} neu gerechnet."
                                      data-ctrl-batch-umstellen>
                            {{ $vorschau['n_gps'] }} {{ $vorschau['n_gps'] === 1 ? 'Bezugsquelle' : 'Bezugsquellen' }} umstellen
                        </x-fa::button>
                    </div>
                </div>
            @endif

            {{-- Top-Einsparpotenziale --}}
            <div class="overflow-x-auto">
                <table class="fa-table" data-optimierung-top>
                    <thead>
                        <tr>
                            <th class="w-8"><span class="sr-only">Auswahl</span></th>
                            <th>Grundprodukt</th>
                            <th class="num">Menge</th>
                            <th class="num">Bezahlt</th>
                            <th class="num">Günstigster Bezug</th>
                            <th class="num">Ersparnis</th>
                            <th>Günstigster Lieferant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($r['top'] as $z)
                            <tr wire:key="opt-{{ $z['gp_id'] }}" @if(in_array($z['gp_id'], $auswahl)) aria-selected="true" @endif>
                                <td>
                                    {{-- Bereits optimal bezogene Zeilen tragen keinen Haken: sie würden
                                         beim Umstellen nichts ändern und den Batch nur aufblähen. --}}
                                    @if($z['lead_ist_optimal'])
                                        <span class="inline-flex text-[var(--fa-ok)]" title="Wird bereits am günstigsten bezogen">@svg('heroicon-m-check-circle', 'w-4 h-4')</span>
                                    @else
                                        <input type="checkbox" wire:model.live="auswahl" value="{{ $z['gp_id'] }}"
                                               aria-label="{{ $z['name'] }} auswählen"
                                               class="w-4 h-4 accent-[var(--fa-accent)]"
                                               data-ctrl-batch-pick="{{ $z['gp_id'] }}" />
                                    @endif
                                </td>
                                <td>{{ $z['name'] }}</td>
                                <td class="num text-[var(--fa-ink-2)]">{{ rtrim(rtrim(number_format($z['qty'], 2, ',', '.'), '0'), ',') }} {{ $z['unit'] }}</td>
                                <td class="num">{{ $eur($z['ist']) }}</td>
                                <td class="num">{{ $eur($z['optimal_rebate']) }}</td>
                                <td class="num font-semibold {{ $z['saving_rebate'] > 0 ? 'text-[var(--fa-ok)]' : 'text-[var(--fa-ink-3)]' }}">{{ $eur($z['saving_rebate']) }}</td>
                                <td>
                                    @if($z['lead_ist_optimal'])
                                        <x-fa::badge tone="ok">{{ $z['cheapest_rebate_supplier'] }}</x-fa::badge>
                                    @else
                                        <x-fa::badge>{{ $z['cheapest_rebate_supplier'] }}</x-fa::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Günstigster Bezug inklusive Rückvergütung.</p>
        </x-fa::section>
    @endif
</div>
