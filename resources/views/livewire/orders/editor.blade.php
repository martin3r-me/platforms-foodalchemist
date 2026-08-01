{{-- Bestellungen-Editor (Fullscreen-Dark, pro Schiene) — Positionen · Hinzufügen · Kopf/Status/Versand.
     Herausgezogen aus dem 3-Panel-Cockpit. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="orders-editor" fullscreen dark-canvas title="Bestellung bearbeiten"
    :title-name="$detail['supplier'] ?? null">
    <x-slot:actions>
        @if($detail && $erlaubteStatus)
            @foreach($erlaubteStatus as $z)
                <button type="button" wire:click="setStatus('{{ $z->value }}')"
                    class="{{ $z->value === 'sent' ? $btnPrimary : $btnGhostXs }}"
                    @if($z->value === 'cancelled') onclick="return confirm('Bestellung stornieren?')" @endif
                    data-status-{{ $z->value }}>{{ $z === \Platform\FoodAlchemist\Enums\OrderStatus::Sent ? 'Absenden' : $z->label() }}</button>
            @endforeach
        @endif
        @if($hinweis)<span class="text-[12px] text-emerald-600 ml-2 self-center" data-orders-hinweis>✓ {{ $hinweis }}</span>@endif
        @if($fehler)<span class="text-[12px] text-rose-600 ml-2 self-center" data-orders-fehler>{{ $fehler }}</span>@endif
    </x-slot:actions>

    @if($detail)
        <x-slot:kpiHeader>
            @php($moq = $detail['moq'])
            <x-foodalchemist::kpi-tiles marker="orders-kpis" :tiles="[
                ['kpi' => 'artikel', 'label' => 'Artikel', 'value' => (string) count($detail['zeilen'])],
                ['kpi' => 'netto', 'label' => 'Wareneinsatz netto', 'tone' => 'accent',
                 'value' => number_format((float) $detail['total_net'], 2, ',', '.') . ' €'],
                ['kpi' => 'moq', 'label' => 'Mindestbestellwert',
                 'tone' => $moq['unter_mindestbestellwert'] ? 'warn' : ($moq['min_order_value'] !== null ? 'good' : 'neutral'),
                 'value' => $moq['unter_mindestbestellwert'] ? '− ' . number_format((float) $moq['fehlt_bis_min'], 2, ',', '.') . ' €' : ($moq['min_order_value'] !== null ? 'erreicht' : '—')],
                ['kpi' => 'status', 'label' => 'Status', 'value' => $detail['status_label']],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($detail === null)
        <p class="pt-4 text-[12px] text-gray-500">Kein Beleg geladen.</p>
    @else
    <x-foodalchemist::editor-tabs marker="orders" wire-key="orders-tabs-{{ $detail['id'] }}" :init="'positionen'"
        :tabs="[
            'positionen' => 'Positionen',
            'hinzufuegen' => $detail['editierbar'] ? 'Hinzufügen' : null,
            'kopf' => 'Kopf, Status & Versand',
        ]">

        {{-- ═══ Tab: POSITIONEN ═══ --}}
        <div x-show="tab === 'positionen'" x-cloak class="pt-4">
        <x-foodalchemist::modal-section title="Positionen ({{ count($detail['zeilen']) }})">
            <div class="overflow-x-auto">
            <table class="{{ $table }}">
                <thead><tr>
                    <th class="{{ $th }} text-left">Artikel</th>
                    <th class="{{ $th }} text-right">Bedarf</th>
                    <th class="{{ $th }} text-right">Bestellen</th>
                    <th class="{{ $th }} text-right">Preis/Geb.</th>
                    <th class="{{ $th }} text-right">Summe</th>
                    <th class="{{ $th }}"></th>
                </tr></thead>
                <tbody>
                    @foreach($detail['zeilen'] as $z)
                        <tr class="border-t border-black/5 align-top" wire:key="line-{{ $z['id'] }}">
                            <td class="{{ $td }} text-gray-800">
                                {{ $z['designation'] ?: '—' }}
                                @if($z['article_number'])<br><span class="text-[10px] text-gray-400">Art. {{ $z['article_number'] }}@if($z['packaging_unit']) · {{ $z['packaging_unit'] }}@endif</span>@endif
                                @unless($z['bestellbar'])<br><span class="text-[10px] text-amber-600">nicht in Gebinde bestellbar (Preis/Gebinde fehlt)</span>@endunless
                                @if(!empty($z['herkunft']))
                                    <div class="flex flex-wrap gap-1 mt-1">
                                        @foreach($z['herkunft'] as $h)
                                            <span class="{{ $pill }} {{ $variantPill[$h['type'] === 'produktion' ? 'primary' : ($h['type'] === 'concept' ? 'info' : 'secondary')] }}" title="{{ $h['ref'] }}">{{ $h['label'] }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                @if($detail['editierbar'])
                                    <input type="text" value="{{ $z['note'] }}" placeholder="Notiz…"
                                        wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)"
                                        class="mt-1 {{ $input }} !py-1 !text-[11px]" />
                                @elseif($z['note'])
                                    <div class="text-[10px] text-gray-500 mt-1 italic">{{ $z['note'] }}</div>
                                @endif
                                @if($detail['editierbar'] && $z['gp_id'] !== null)
                                    <button type="button" wire:click="alternativenUmschalten({{ $z['id'] }})"
                                        class="mt-1 text-[10px] text-violet-600 hover:underline">
                                        {{ $altLineId === $z['id'] ? '▾ Ausweichquelle schließen' : '⇄ Ausweichquelle' }}
                                    </button>
                                    @if($altLineId === $z['id'])
                                        <div class="mt-1 rounded-md border border-violet-500/20 bg-violet-500/[0.06] p-1.5 space-y-0.5">
                                            @forelse($alternativen as $alt)
                                                <button type="button" wire:key="alt-{{ $z['id'] }}-{{ $alt['la_id'] }}"
                                                    wire:click="alternativeWaehlen({{ $z['id'] }}, {{ $alt['la_id'] }})"
                                                    @if($alt['schiene_wechsel']) onclick="return confirm('Anderer Lieferant ({{ $alt['supplier'] }}) — die Position wandert in dessen Bestellschiene. Fortfahren?')" @endif
                                                    @disabled($alt['gesperrt'])
                                                    class="block w-full text-left px-1.5 py-1 rounded hover:bg-black/[0.08] {{ $alt['gesperrt'] ? 'opacity-40 cursor-not-allowed' : '' }}">
                                                    <span class="text-[11px] text-gray-800">{{ $alt['designation'] ?: '—' }}</span>
                                                    <span class="text-[10px] text-gray-400 block">
                                                        {{ $alt['supplier'] ?? '—' }}@if($alt['schiene_wechsel']) · andere Schiene @endif
                                                        @if($alt['ist_stamm']) · Stamm @endif
                                                        @if($alt['vergleichspreis'] !== null) · {{ number_format($alt['vergleichspreis'], 2, ',', '.') }} {{ $alt['vergleichspreis_einheit'] ?? '' }} @endif
                                                    </span>
                                                </button>
                                            @empty
                                                <p class="text-[10px] text-gray-400 px-1">Keine Ausweichquelle für dieses Grundprodukt.</p>
                                            @endforelse
                                        </div>
                                    @endif
                                @endif
                            </td>
                            <td class="{{ $td }} text-right whitespace-nowrap text-gray-500">{{ rtrim(rtrim(number_format($z['needed_display'], 3, ',', '.'), '0'), ',') }} {{ $z['needed_unit'] }}</td>
                            <td class="{{ $td }} text-right whitespace-nowrap">
                                @if($detail['editierbar'])
                                    <input type="number" min="0" step="1" value="{{ (float) $z['qty_packs'] }}"
                                        wire:change="updateLineQty({{ $z['id'] }}, $event.target.value)"
                                        class="w-16 text-right {{ $input }} {{ $z['is_manual_qty'] ? '!border !border-amber-400' : '' }}" />
                                    @if($z['is_manual_qty'])<button type="button" wire:click="resetLineQty({{ $z['id'] }})" title="Auto-Menge" class="text-[10px] text-violet-600 ml-1">auto</button>@endif
                                @else
                                    {{ (float) $z['qty_packs'] }}
                                @endif
                                @if($z['packaging_unit'])<span class="text-[10px] text-gray-400"> {{ $z['packaging_unit'] }}</span>@endif
                            </td>
                            <td class="{{ $td }} text-right whitespace-nowrap text-gray-700">{{ $z['pack_price'] !== null ? number_format($z['pack_price'], 2, ',', '.') . ' €' : '—' }}</td>
                            <td class="{{ $td }} text-right whitespace-nowrap font-medium text-gray-900">{{ number_format($z['line_total'], 2, ',', '.') }} €</td>
                            <td class="{{ $td }} text-right">
                                @if($detail['editierbar'])<button type="button" wire:click="removeLine({{ $z['id'] }})" title="Entfernen" class="text-[11px] text-rose-500">✕</button>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-black/10">
                        <td class="{{ $td }} font-medium text-gray-900" colspan="4">Wareneinsatz gesamt (netto)</td>
                        <td class="{{ $td }} text-right font-semibold text-gray-900">{{ number_format($detail['total_net'], 2, ',', '.') }} €</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            @unless($detail['editierbar'])
                <p class="text-[11px] text-gray-400 mt-2">Versendeter Beleg — eingefroren, nicht mehr editierbar.</p>
            @endunless
        </x-foodalchemist::modal-section>
        </div>

        {{-- ═══ Tab: HINZUFÜGEN (Direktbestellung — nur Entwurf) ═══ --}}
        <div x-show="tab === 'hinzufuegen'" x-cloak class="pt-4 space-y-4">
            @if($detail['editierbar'])
                <x-foodalchemist::modal-section title="Artikel direkt bestellen">
                    <input type="search" wire:model.live.debounce.300ms="artikelSuche" placeholder="Artikel / Art-Nr… (aus der Liste mit Klick einfügen)" class="{{ $input }}" data-orders-artikel-suche />
                    @if($artikelTreffer->isNotEmpty())
                        <div class="mt-1 rounded-lg border border-white/10 divide-y divide-white/5 max-h-60 overflow-y-auto">
                            @foreach($artikelTreffer as $a)
                                <button type="button" wire:click="artikelHinzufuegen({{ $a['id'] }})" wire:key="art-{{ $a['id'] }}"
                                    class="block w-full text-left px-2.5 py-1.5 hover:bg-violet-500/10">
                                    <span class="text-[12px] text-gray-800">{{ $a['designation'] ?: '—' }}</span>
                                    <span class="text-[10px] text-gray-400 block">{{ $a['supplier'] }}@if($a['article_number']) · Art. {{ $a['article_number'] }}@endif</span>
                                </button>
                            @endforeach
                        </div>
                    @elseif(mb_strlen(trim($artikelSuche)) >= 2)
                        <p class="text-[11px] text-gray-400 mt-1">Kein Artikel gefunden.</p>
                    @endif
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Bedarf aus Gericht / Basisrezept">
                    <p class="text-[11px] text-gray-500 mb-2">Der Bedarf verteilt sich je Zutat auf die Lead-LA-Schienen — es können also mehrere Lieferanten-Belege entstehen/berührt werden.</p>
                    @if($bedarfRecipeId === null)
                        <input type="search" wire:model.live.debounce.300ms="bedarfSuche" placeholder="Gericht / Basisrezept…" class="{{ $input }}" data-orders-bedarf-suche />
                        @if($bedarfTreffer->isNotEmpty())
                            <div class="mt-1 rounded-lg border border-white/10 divide-y divide-white/5 max-h-60 overflow-y-auto">
                                @foreach($bedarfTreffer as $r)
                                    <button type="button" wire:click="bedarfRezeptWaehlen({{ $r['id'] }})" wire:key="brz-{{ $r['id'] }}"
                                        class="flex items-center gap-1.5 w-full text-left px-2.5 py-1.5 hover:bg-violet-500/10">
                                        <span class="text-[12px] text-gray-800 truncate">{{ $r['name'] }}</span>
                                        <span class="{{ $pill }} {{ $variantPill[$r['is_sales_recipe'] ? 'info' : 'secondary'] }} shrink-0">{{ $r['is_sales_recipe'] ? 'VK' : 'Basis' }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif(mb_strlen(trim($bedarfSuche)) >= 2)
                            <p class="text-[11px] text-gray-400 mt-1">Kein Rezept gefunden.</p>
                        @endif
                    @else
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between gap-1">
                                <span class="text-[12px] text-gray-800 truncate">{{ $bedarfRecipeName }}
                                    <span class="{{ $pill }} {{ $variantPill[$bedarfRecipeVk ? 'info' : 'secondary'] }}">{{ $bedarfRecipeVk ? 'VK' : 'Basis' }}</span>
                                </span>
                                <button type="button" wire:click="bedarfRezeptZuruecksetzen" class="text-[11px] text-gray-400 shrink-0">ändern</button>
                            </div>
                            <div class="flex gap-1 items-center">
                                <input type="number" min="0" step="0.1" wire:model="bedarfMenge" placeholder="Menge" class="{{ $input }} w-32" />
                                @if($bedarfRecipeVk)
                                    <span class="inline-flex items-center px-2 text-[11px] text-gray-500 whitespace-nowrap">Portionen</span>
                                @else
                                    <select wire:model="bedarfEinheit" class="{{ $input }} !w-auto">
                                        <option value="ansaetze">Ansätze</option>
                                        <option value="kg">kg</option>
                                    </select>
                                @endif
                                <button type="button" wire:click="bedarfUebernehmen" class="{{ $btnGhost }}" data-orders-bedarf-uebernehmen>Bedarf übernehmen</button>
                            </div>
                        </div>
                    @endif
                </x-foodalchemist::modal-section>
            @endif
        </div>

        {{-- ═══ Tab: KOPF, STATUS & VERSAND ═══ --}}
        <div x-show="tab === 'kopf'" x-cloak class="pt-4 space-y-4">
            {{-- MOQ-/Frei-Haus-Ampel --}}
            @php($moq = $detail['moq'])
            <div class="flex flex-wrap gap-2 text-[11px]">
                @if($moq['unter_mindestbestellwert'])
                    <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-700">Unter Mindestbestellwert — es fehlen {{ number_format($moq['fehlt_bis_min'], 2, ',', '.') }} €</span>
                @elseif($moq['min_order_value'] !== null)
                    <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-700">Mindestbestellwert erreicht</span>
                @endif
                @if($moq['frei_haus'])
                    <span class="px-2 py-0.5 rounded-md bg-emerald-500/10 text-emerald-700">frei Haus</span>
                @elseif($moq['free_shipping_threshold'] !== null)
                    <span class="px-2 py-0.5 rounded-md bg-black/10 text-gray-600">{{ number_format($moq['fehlt_bis_frei_haus'], 2, ',', '.') }} € bis frei Haus</span>
                @endif
            </div>

            @if($detail['editierbar'])
                <x-foodalchemist::modal-section title="Liefer-Logistik & Anlass">
                    <x-slot:actions>
                        <button type="button" wire:click="saveHeader" class="{{ $btnGhostXs }}" data-orders-kopf-speichern>Kopf speichern</button>
                    </x-slot:actions>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="text-[10px] text-gray-500">Anlass / Referenz</label>
                            <input type="text" wire:model="formReference" class="{{ $input }}" placeholder="z. B. Sommerfest" />
                        </div>
                        <div>
                            <label class="text-[10px] text-gray-500">Wunsch-Liefertermin</label>
                            <input type="date" wire:model="formDeliveryDate" class="{{ $input }}" />
                        </div>
                        <div class="md:col-span-3">
                            <label class="text-[10px] text-gray-500">Notiz</label>
                            <textarea wire:model="formNote" rows="2" class="{{ $input }}" placeholder="interne Notiz…"></textarea>
                        </div>
                    </div>
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Preisstrategie & Neu quellen">
                    <select wire:model="formStrategy" class="{{ $input }} max-w-sm">
                        <option value="">Haupteinstellung (Team)</option>
                        @foreach($strategieOptionen as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    @if($resourceVorschau === null)
                        <button type="button" wire:click="neuQuellenVorschau" class="{{ $btnGhost }} mt-2" data-neu-quellen-vorschau>Neu quellen (Vorschau)</button>
                    @else
                        <div class="rounded-md border border-violet-500/20 bg-violet-500/[0.06] p-2 space-y-1 mt-2">
                            @if(empty($resourceVorschau['wechsel']))
                                <p class="text-[11px] text-gray-500">Kein Wechsel unter dieser Strategie.</p>
                            @else
                                <p class="text-[11px] font-medium text-gray-700">{{ count($resourceVorschau['wechsel']) }} Position(en) wechseln:</p>
                                <ul class="space-y-0.5">
                                    @foreach($resourceVorschau['wechsel'] as $w)
                                        <li class="text-[10px] text-gray-600">
                                            {{ $w['gp'] }} → <span class="text-gray-800">{{ $w['nach_artikel'] ?: '—' }}</span>
                                            <span class="text-gray-400">({{ $w['nach_lieferant'] ?? '—' }}@if($w['schiene_wechsel']) · andere Schiene @endif)</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            <div class="flex gap-1.5 pt-1">
                                <button type="button" wire:click="neuQuellenAnwenden" class="{{ $btnPrimary }}" @disabled(empty($resourceVorschau['wechsel'])) data-neu-quellen-anwenden>Anwenden</button>
                                <button type="button" wire:click="neuQuellenAbbrechen" class="{{ $btnGhost }}">Abbrechen</button>
                            </div>
                        </div>
                    @endif
                </x-foodalchemist::modal-section>
            @else
                <x-foodalchemist::modal-section title="Kopf">
                    <div class="space-y-1 text-[11px] text-gray-600">
                        @if($detail['reference'])<div><span class="text-gray-400">Anlass:</span> {{ $detail['reference'] }}</div>@endif
                        @if($detail['desired_delivery_date'])<div><span class="text-gray-400">Liefertermin:</span> {{ $detail['desired_delivery_date'] }}</div>@endif
                        @if($detail['note'])<div><span class="text-gray-400">Notiz:</span> {{ $detail['note'] }}</div>@endif
                        @if($detail['sourcing_strategy'])<div><span class="text-gray-400">Preisstrategie:</span> {{ $detail['sourcing_strategy'] }}</div>@endif
                    </div>
                </x-foodalchemist::modal-section>
            @endif

            @if(!empty($detail['herkunft']))
                <x-foodalchemist::modal-section title="Herkunft">
                    <div class="flex flex-wrap gap-1">
                        @foreach($detail['herkunft'] as $h)
                            @if($h['production_order_id'] !== null)
                                <a href="{{ route('foodalchemist.produktion.index', ['auftrag' => $h['production_order_id']]) }}"
                                   class="{{ $pill }} {{ $variantPill['primary'] }} hover:underline" title="{{ $h['key'] }}">{{ $h['label'] }} ↗</a>
                            @else
                                <span class="{{ $pill }} {{ $variantPill[$h['type'] === 'concept' ? 'info' : 'secondary'] }}" title="{{ $h['key'] }}">{{ $h['label'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </x-foodalchemist::modal-section>
            @endif

            <x-foodalchemist::modal-section title="Export & Versand">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id']]) }}" target="_blank" class="{{ $btnGhostXs }}">@svg('heroicon-o-printer', 'w-3.5 h-3.5 inline-block align-middle') Dokument</a>
                    <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'pdf' => 1]) }}" class="{{ $btnGhostXs }}">PDF</a>
                    <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'csv' => 1]) }}" class="{{ $btnGhostXs }}">CSV</a>
                    @if($mailto)
                        <a href="{{ $mailto }}" class="{{ $btnGhostXs }}">@svg('heroicon-o-envelope', 'w-3.5 h-3.5 inline-block align-middle') E-Mail</a>
                    @else
                        <span class="text-[10px] text-gray-400">@svg('heroicon-o-envelope', 'w-3.5 h-3.5 inline-block align-middle') keine Bestell-Mail (Lieferant → email_order)</span>
                    @endif
                </div>
            </x-foodalchemist::modal-section>
        </div>
    </x-foodalchemist::editor-tabs>
    @endif
</x-foodalchemist::modal>
