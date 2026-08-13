{{-- Bestellungen-Editor (Fullscreen-Dark, pro Schiene) — Positionen · Hinzufügen · Kopf/Status/Versand.
     Herausgezogen aus dem 3-Panel-Cockpit. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="orders-editor" fullscreen dark-canvas title="Bestellung bearbeiten"
    :title-name="$detail['supplier'] ?? null">
    <x-slot:actions>
        @php($sendBlockers = $detail['send_blockers'] ?? [])
        @if($detail && $erlaubteStatus)
            @foreach($erlaubteStatus as $z)
                <button type="button" wire:click="setStatus('{{ $z->value }}')"
                    class="{{ $z->value === 'sent' ? $btnPrimary : $btnGhostXs }}"
                    @if($z->value === 'cancelled') onclick="return confirm('Bestellung stornieren?')" @endif
                    @disabled($z->value === 'sent' && !empty($sendBlockers))
                    @if($z->value === 'sent' && !empty($sendBlockers)) title="Versand gesperrt: {{ implode(', ', $sendBlockers) }}" @endif
                    data-status-{{ $z->value }}>{{ $z === \Platform\FoodAlchemist\Enums\OrderStatus::Sent ? 'Absenden' : $z->label() }}</button>
            @endforeach
        @endif
        @if($hinweis)<span class="text-[12px] text-emerald-600 ml-2 self-center" data-orders-hinweis>✓ {{ $hinweis }}</span>@endif
        @if($fehler)<span class="text-[12px] text-rose-600 ml-2 self-center" data-orders-fehler>{{ $fehler }}</span>@endif
    </x-slot:actions>

    @if($detail)
        <x-slot:kpiHeader>
            @php($moq = $detail['moq'])
            @php($warnings = $detail['warnings'] ?? [])
            @php($sendBlockers = $detail['send_blockers'] ?? [])
            <x-foodalchemist::kpi-tiles marker="orders-kpis" :tiles="[
                ['kpi' => 'artikel', 'label' => 'Artikel', 'value' => (string) count($detail['zeilen'])],
                ['kpi' => 'netto', 'label' => 'Wareneinsatz netto', 'tone' => 'accent',
                 'value' => number_format((float) $detail['total_net'], 2, ',', '.') . ' €'],
                ['kpi' => 'moq', 'label' => 'Mindestbestellwert',
                 'tone' => $moq['unter_mindestbestellwert'] ? 'warn' : ($moq['min_order_value'] !== null ? 'good' : 'neutral'),
                 'value' => $moq['unter_mindestbestellwert'] ? '− ' . number_format((float) $moq['fehlt_bis_min'], 2, ',', '.') . ' €' : ($moq['min_order_value'] !== null ? 'erreicht' : '—')],
                ['kpi' => 'strategie', 'label' => 'Strategie',
                 'value' => $detail['sourcing_strategy'] ? (\Platform\FoodAlchemist\Enums\LeadLaStrategie::tryFrom($detail['sourcing_strategy'])?->label() ?? $detail['sourcing_strategy']) : 'Team-Standard'],
                ['kpi' => 'hinweise', 'label' => 'Hinweise',
                 'tone' => !empty($sendBlockers) ? 'bad' : (!empty($warnings) ? 'warn' : 'good'),
                 'value' => count($warnings) > 0 ? (string) count($warnings) : 'ok'],
                ['kpi' => 'wareneingang', 'label' => 'Wareneingang',
                 'tone' => ($detail['receipt']['differences'] ?? 0) > 0 ? 'warn' : (($detail['receipt']['missing'] ?? 0) > 0 ? 'neutral' : 'good'),
                 'value' => ($detail['receipt']['booked'] ?? 0) . '/' . ($detail['receipt']['lines'] ?? 0)],
                ['kpi' => 'rechnung', 'label' => 'Rechnung',
                 'tone' => ($detail['invoice']['differences'] ?? 0) > 0 ? 'warn' : (($detail['invoice']['missing'] ?? 0) > 0 ? 'neutral' : 'good'),
                 'value' => ($detail['invoice']['checked'] ?? 0) . '/' . ($detail['invoice']['lines'] ?? 0)],
                ['kpi' => 'lager', 'label' => 'Lager',
                 'tone' => ($detail['inventory']['shortage'] ?? 0) > 0 ? 'warn' : (($detail['inventory']['tracked'] ?? 0) > 0 ? 'good' : 'neutral'),
                 'value' => ($detail['inventory']['tracked'] ?? 0) > 0 ? (($detail['inventory']['covered'] ?? 0) . '/' . ($detail['inventory']['tracked'] ?? 0)) : '—'],
                ['kpi' => 'status', 'label' => 'Status', 'value' => $detail['status_label']],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($detail === null)
        <x-slot:kpiHeader>
            @php($previewTotals = $cockpitPreview['totals'] ?? ['sources' => count($cockpitSources), 'groups' => 0, 'positions' => 0, 'unresolved' => 0, 'total_net' => 0])
            <x-foodalchemist::kpi-tiles marker="orders-cockpit-kpis" :tiles="[
                ['kpi' => 'sources', 'label' => 'Quellen', 'value' => number_format((int) ($previewTotals['sources'] ?? 0), 0, ',', '.')],
                ['kpi' => 'tracks', 'label' => 'Schienen', 'value' => number_format((int) ($previewTotals['groups'] ?? 0), 0, ',', '.')],
                ['kpi' => 'positions', 'label' => 'Positionen', 'value' => number_format((int) ($previewTotals['positions'] ?? 0), 0, ',', '.')],
                ['kpi' => 'netto', 'label' => 'Netto Vorschau', 'tone' => 'accent', 'value' => number_format((float) ($previewTotals['total_net'] ?? 0), 2, ',', '.') . ' €'],
                ['kpi' => 'strategy', 'label' => 'Strategie', 'value' => $cockpitStrategy !== '' ? (\Platform\FoodAlchemist\Enums\LeadLaStrategie::tryFrom($cockpitStrategy)?->label() ?? $cockpitStrategy) : 'Team-Standard'],
                ['kpi' => 'clarifications', 'label' => 'Klärpunkte', 'tone' => ((int) ($previewTotals['unresolved'] ?? 0)) > 0 ? 'warn' : 'good', 'value' => number_format((int) ($previewTotals['unresolved'] ?? 0), 0, ',', '.')],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($detail === null)
        <div class="pt-4 space-y-4">
            <x-foodalchemist::modal-section title="Neue Bestellung">
                <x-slot:actions>
                    <button type="button" wire:click="cockpitVorschau" class="{{ $btnGhostXs }}" data-orders-cockpit-preview>Vorschau neu generieren</button>
                    <button type="button" wire:click="cockpitSpeichern" class="{{ $btnPrimary }}" @disabled(count($cockpitSources) === 0) data-orders-cockpit-save>Bestellungen speichern</button>
                </x-slot:actions>
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="text-[10px] text-gray-500">Standard-Liefertag</label>
                        <input type="date" wire:model.live="formDeliveryDate" class="{{ $input }}" />
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500">Einkaufsstrategie</label>
                        <select wire:model.live="cockpitStrategy" class="{{ $input }}">
                            <option value="">Team-Standard</option>
                            @foreach($strategieOptionen as $s)
                                <option value="{{ $s->value }}">{{ $s->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="text-[10px] text-gray-500">Anlass / Referenz</label>
                        <input type="text" wire:model.live="formReference" class="{{ $input }}" placeholder="z. B. Wochenbestellung, Bankett, Produktion" />
                    </div>
                </div>
            </x-foodalchemist::modal-section>

            <div class="grid grid-cols-1 xl:grid-cols-[minmax(280px,0.9fr)_minmax(0,1.25fr)] 2xl:grid-cols-[minmax(280px,0.85fr)_minmax(0,1.25fr)_minmax(260px,0.7fr)] gap-4 min-w-0">
                <div class="space-y-4 min-w-0">
                    <x-foodalchemist::modal-section title="Quellen einfügen">
                        <div class="space-y-3">
                            <div>
                                <label class="text-[10px] text-gray-500">Lieferantenartikel</label>
                                <input type="search" wire:model.live.debounce.300ms="artikelSuche" placeholder="Lieferant / Grundprodukt / Artikel / Art-Nr…" class="{{ $input }}" data-orders-artikel-suche />
                                @if($artikelTreffer->isNotEmpty())
                                    <div class="mt-1 rounded-lg border border-white/10 divide-y divide-white/5 max-h-44 overflow-y-auto">
                                        @foreach($artikelTreffer as $a)
                                            <button type="button" wire:click="cockpitArtikelEinfuegen({{ $a['id'] }})" wire:key="cockpit-art-{{ $a['id'] }}"
                                                class="block w-full text-left px-2.5 py-1.5 hover:bg-violet-500/10">
                                                <span class="text-[12px] text-gray-800">{{ $a['designation'] ?: '—' }}</span>
                                                <span class="text-[10px] text-gray-400 block">{{ $a['supplier'] }}@if($a['gp']) · GP {{ $a['gp'] }}@endif@if($a['article_number']) · Art. {{ $a['article_number'] }}@endif</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div>
                                <label class="text-[10px] text-gray-500">Grundprodukt</label>
                                <input type="search" wire:model.live.debounce.300ms="gpSuche" placeholder="Grundprodukt…" class="{{ $input }}" data-orders-gp-suche />
                                @if($gpTreffer->isNotEmpty())
                                    <div class="mt-1 rounded-lg border border-white/10 divide-y divide-white/5 max-h-44 overflow-y-auto">
                                        @foreach($gpTreffer as $gp)
                                            <button type="button" wire:click="cockpitGpEinfuegen({{ $gp['id'] }})" wire:key="cockpit-gp-{{ $gp['id'] }}"
                                                class="block w-full text-left px-2.5 py-1.5 text-[12px] text-gray-800 hover:bg-violet-500/10">{{ $gp['name'] }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div>
                                <label class="text-[10px] text-gray-500">Gericht / Basisrezept</label>
                                <input type="search" wire:model.live.debounce.300ms="bedarfSuche" placeholder="Gericht / Basisrezept…" class="{{ $input }}" data-orders-bedarf-suche />
                                @if($bedarfTreffer->isNotEmpty())
                                    <div class="mt-1 rounded-lg border border-white/10 divide-y divide-white/5 max-h-44 overflow-y-auto">
                                        @foreach($bedarfTreffer as $r)
                                            <button type="button" wire:click="cockpitRezeptEinfuegen({{ $r['id'] }})" wire:key="cockpit-recipe-{{ $r['id'] }}"
                                                class="flex items-center gap-1.5 w-full text-left px-2.5 py-1.5 hover:bg-violet-500/10">
                                                <span class="text-[12px] text-gray-800 truncate">{{ $r['name'] }}</span>
                                                <span class="{{ $pill }} {{ $variantPill[$r['is_sales_recipe'] ? 'info' : 'secondary'] }} shrink-0">{{ $r['is_sales_recipe'] ? 'Gericht' : 'Basis' }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            <div>
                                <label class="text-[10px] text-gray-500">Produktion</label>
                                <input type="search" wire:model.live.debounce.300ms="produktionSuche" placeholder="Produktionsauftrag…" class="{{ $input }}" data-orders-produktion-suche />
                                @if($produktionTreffer->isNotEmpty())
                                    <div class="mt-1 rounded-lg border border-white/10 divide-y divide-white/5 max-h-44 overflow-y-auto">
                                        @foreach($produktionTreffer as $p)
                                            <button type="button" wire:click="cockpitProduktionEinfuegen({{ $p['id'] }})" wire:key="cockpit-prod-{{ $p['id'] }}"
                                                class="block w-full text-left px-2.5 py-1.5 hover:bg-violet-500/10">
                                                <span class="text-[12px] text-gray-800">{{ $p['name'] }}</span>
                                                @if($p['date'])<span class="text-[10px] text-gray-400 block">{{ $p['date'] }}</span>@endif
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    </x-foodalchemist::modal-section>

                    <x-foodalchemist::modal-section title="Arbeitsstand ({{ count($cockpitSources) }})">
                        <div class="space-y-2">
                            @forelse($cockpitSources as $i => $s)
                                <div class="rounded-md border border-white/10 bg-white/[0.04] p-2 space-y-2" wire:key="cockpit-source-{{ $i }}">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <span class="{{ $pill }} {{ $variantPill[$s['type'] === 'production' ? 'primary' : ($s['type'] === 'recipe' ? 'info' : 'secondary')] }}">{{ $s['type'] }}</span>
                                            <p class="text-[12px] text-gray-800 truncate mt-1">{{ $s['label'] ?? ($s['id'] ?? 'Quelle') }}</p>
                                        </div>
                                        <button type="button" wire:click="cockpitQuelleEntfernen({{ $i }})" class="text-[11px] text-rose-500 shrink-0">Entfernen</button>
                                    </div>
                                    <div class="grid grid-cols-[80px_105px_1fr] gap-1.5">
                                        <input type="number" min="0" step="0.1" wire:model.live="cockpitSources.{{ $i }}.qty" class="{{ $input }} !py-1" />
                                        <select wire:model.live="cockpitSources.{{ $i }}.unit" class="{{ $input }} !py-1">
                                            @if($s['type'] === 'supplier_item')
                                                <option value="gebinde">Gebinde</option>
                                            @elseif($s['type'] === 'gp')
                                                <option value="kg">kg</option>
                                                <option value="g">g</option>
                                                <option value="stk">Stk</option>
                                            @elseif($s['type'] === 'production')
                                                <option value="auftrag">Auftrag</option>
                                            @else
                                                <option value="portions">Portionen</option>
                                                <option value="ansaetze">Ansätze</option>
                                                <option value="kg">kg</option>
                                            @endif
                                        </select>
                                        <input type="date" wire:model.live="cockpitSources.{{ $i }}.delivery_date" class="{{ $input }} !py-1" />
                                    </div>
                                    <input type="text" wire:model.live="cockpitSources.{{ $i }}.reference" class="{{ $input }} !py-1" placeholder="Anlass für diese Quelle…" />
                                </div>
                            @empty
                                <p class="text-[11px] text-gray-400">Noch keine Quelle eingefügt.</p>
                            @endforelse
                        </div>
                    </x-foodalchemist::modal-section>
                </div>

                <x-foodalchemist::modal-section title="Auflösung nach Lieferant + Liefertag" class="min-w-0">
                    @if($cockpitPreview === null)
                        <p class="text-[12px] text-gray-500">Quellen einfügen und Vorschau generieren.</p>
                    @elseif(empty($cockpitPreview['orders_preview']))
                        <p class="text-[12px] text-gray-500">Keine bestellbare Position in der Vorschau.</p>
                    @else
                        <div class="space-y-3">
                            @foreach($cockpitPreview['orders_preview'] as $g)
                                <div class="rounded-md border border-white/10 bg-white/[0.04] overflow-hidden" wire:key="cockpit-preview-{{ $g['supplier_id'] }}-{{ $g['delivery_date'] ?? 'none' }}">
                                    <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 bg-white/[0.05]">
                                        <div>
                                            <div class="text-[13px] font-semibold text-gray-900">{{ $g['supplier'] }}</div>
                                            <div class="text-[10px] text-gray-400">Liefertag {{ $g['delivery_date'] ? \Illuminate\Support\Carbon::parse($g['delivery_date'])->format('d.m.Y') : 'ohne Datum' }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-[13px] font-semibold text-gray-900">{{ number_format($g['total_net'], 2, ',', '.') }} €</div>
                                            @if($g['moq']['unter_mindestbestellwert'])
                                                <div class="text-[10px] text-amber-600">{{ number_format($g['moq']['fehlt_bis_min'], 2, ',', '.') }} € bis Mindestwert</div>
                                            @elseif($g['moq']['frei_haus'])
                                                <div class="text-[10px] text-emerald-600">frei Haus</div>
                                            @endif
                                        </div>
                                    </div>
                                    @if(!empty($g['warnings']))
                                        <div class="flex flex-wrap gap-1 px-3 py-1.5 bg-amber-500/[0.06] border-t border-amber-500/10">
                                            @foreach($g['warnings'] as $w)
                                                <span class="px-1.5 py-0.5 rounded bg-amber-500/10 text-[10px] text-amber-700">{{ $w }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    <div class="divide-y divide-white/5">
                                        @foreach($g['positionen'] as $p)
                                            @php($previewAltKey = (string) ($p['override_key'] ?? md5(($g['supplier_id'] ?? '') . '|' . ($g['delivery_date'] ?? '') . '|' . ($p['source_ref'] ?? '') . '|' . ($p['gp_id'] ?? ''))))
                                            <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2 px-3 py-2">
                                                <div class="min-w-0">
                                                    <div class="text-[12px] text-gray-800 truncate">{{ $p['designation'] ?: ($p['gp'] ?: 'Position') }}</div>
                                                    <div class="text-[10px] text-gray-400">
                                                        @if($p['article_number'])Art. {{ $p['article_number'] }} · @endif
                                                        Bedarf {{ $p['needed_display'] !== null ? rtrim(rtrim(number_format($p['needed_display'], 3, ',', '.'), '0'), ',') . ' ' . $p['needed_unit'] : 'direkt' }}
                                                        @if($p['source_label']) · {{ $p['source_label'] }}@endif
                                                    </div>
                                                    @if(!empty($p['reference']))
                                                        <span class="{{ $pill }} {{ $variantPill['primary'] }} mt-1">{{ $p['reference'] }}</span>
                                                    @endif
                                                    @if(array_key_exists($previewAltKey, $cockpitOverrides))
                                                        <button type="button" wire:click="cockpitAlternativeZuruecksetzen('{{ $previewAltKey }}')" class="{{ $pill }} {{ $variantPill['warning'] }} mt-1">
                                                            manuell gewählt · auto wiederherstellen
                                                        </button>
                                                    @endif
                                                    @if(($p['gp_id'] ?? null) !== null)
                                                        <button type="button"
                                                            wire:click="cockpitAlternativenUmschalten('{{ $previewAltKey }}', {{ (int) $p['gp_id'] }}, {{ (int) $g['supplier_id'] }}, {{ ($p['lead_la_id'] ?? null) !== null ? (int) $p['lead_la_id'] : 'null' }})"
                                                            class="mt-1 text-[10px] text-violet-600 hover:underline">
                                                            {{ $cockpitAltKey === $previewAltKey ? '▾ Alternativen schließen' : '⇄ Lieferant / Artikel wechseln' }}
                                                        </button>
                                                        @if($cockpitAltKey === $previewAltKey)
                                                            <div class="mt-1 rounded-md border border-violet-500/20 bg-violet-500/[0.06] p-1.5 space-y-0.5">
                                                                @forelse($cockpitAlternativen as $alt)
                                                                    <button type="button"
                                                                        wire:click="cockpitAlternativeWaehlen('{{ $previewAltKey }}', {{ $alt['la_id'] }})"
                                                                        @disabled($alt['gesperrt'])
                                                                        class="block w-full text-left px-1.5 py-1 rounded bg-black/[0.03] hover:bg-black/[0.08] {{ $alt['gesperrt'] ? 'opacity-40 cursor-not-allowed' : '' }}"
                                                                        wire:key="preview-alt-{{ md5($previewAltKey) }}-{{ $alt['la_id'] }}">
                                                                        <span class="text-[11px] text-gray-800">{{ $alt['designation'] ?: '—' }}</span>
                                                                        <span class="text-[10px] text-gray-400 block">
                                                                            {{ $alt['supplier'] ?? '—' }}@if($alt['schiene_wechsel']) · andere Schiene @endif
                                                                            @if($alt['ist_stamm']) · Stamm @endif
                                                                            @if($alt['vergleichspreis'] !== null) · {{ number_format($alt['vergleichspreis'], 2, ',', '.') }} {{ $alt['vergleichspreis_einheit'] ?? '' }} @endif
                                                                        </span>
                                                                    </button>
                                                                @empty
                                                                    <p class="text-[10px] text-gray-400 px-1">Keine Alternative.</p>
                                                                @endforelse
                                                            </div>
                                                        @endif
                                                    @endif
                                                </div>
                                                <div class="text-right whitespace-nowrap">
                                                    <div class="text-[12px] text-gray-900">{{ rtrim(rtrim(number_format($p['qty_packs'], 2, ',', '.'), '0'), ',') }} {{ $p['packaging_unit'] }}</div>
                                                    <div class="text-[10px] {{ $p['bestellbar'] ? 'text-gray-400' : 'text-amber-600' }}">{{ number_format($p['line_total'], 2, ',', '.') }} €</div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section title="Klärliste" class="min-w-0">
                    @if($cockpitPreview === null)
                        <p class="text-[12px] text-gray-500">Die Klärliste erscheint nach der Vorschau.</p>
                    @elseif(empty($cockpitPreview['unresolved']))
                        <p class="text-[12px] text-emerald-600">Keine Klärpunkte.</p>
                    @else
                        <div class="space-y-2">
                            @foreach($cockpitPreview['unresolved'] as $u)
                                <div class="rounded-md border border-amber-500/20 bg-amber-500/[0.08] p-2" wire:key="unresolved-{{ $loop->index }}">
                                    <div class="text-[12px] font-medium text-gray-900">{{ $u['label'] }}</div>
                                    <div class="text-[10px] text-amber-700">{{ $u['message'] }}</div>
                                    <div class="text-[10px] text-gray-400 mt-1">{{ $u['code'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if($cockpitPreview && !empty($cockpitPreview['warnings']))
                        <div class="mt-3 space-y-1">
                            @foreach($cockpitPreview['warnings'] as $w)
                                <p class="text-[10px] text-amber-600">{{ $w }}</p>
                            @endforeach
                        </div>
                    @endif
                </x-foodalchemist::modal-section>
            </div>
        </div>
    @else
    <x-foodalchemist::editor-tabs marker="orders" wire-key="orders-tabs-{{ $detail['id'] }}" :init="'positionen'"
        :tabs="[
            'positionen' => 'Positionen',
            'wareneingang' => 'Wareneingang',
            'rechnung' => 'Rechnung',
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
                                @if($z['quota'])
                                    <div class="mt-1 text-[10px] {{ $z['quota']['exceeded'] || ! $z['quota']['is_valid_date'] ? 'text-amber-600' : 'text-emerald-600' }}">
                                        Kontingent: {{ rtrim(rtrim(number_format($z['quota']['remaining_before_packs'], 2, ',', '.'), '0'), ',') }} {{ $z['packaging_unit'] ?: 'Geb.' }} frei
                                        · nach Bestellung {{ rtrim(rtrim(number_format($z['quota']['remaining_after_packs'], 2, ',', '.'), '0'), ',') }}
                                        @if(!$z['quota']['is_valid_date']) · außerhalb Gültigkeit @endif
                                    </div>
                                @endif
                                @if(!empty($z['inventory']))
                                    <div class="mt-1 text-[10px] text-sky-600">
                                        Lager: {{ $z['inventory']['display'] }} verfügbar
                                        · Restbedarf {{ $z['inventory']['shortage_display'] }}
                                    </div>
                                @endif
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
                                        {{ $altLineId === $z['id'] ? '▾ Wechsel schließen' : '⇄ Lieferant / Artikel wechseln' }}
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

        {{-- ═══ Tab: WARENEINGANG ═══ --}}
        <div x-show="tab === 'wareneingang'" x-cloak class="pt-4">
            <x-foodalchemist::modal-section title="Wareneingang">
                <x-slot:actions>
                    @if($detail['wareneingang_editierbar'])
                        @if(($detail['receipt']['backorderable'] ?? 0) > 0)
                            <button type="button" wire:click="createBackorder" class="{{ $btnGhostXs }}">Nachlieferung anlegen</button>
                        @endif
                        <button type="button" wire:click="completeReceipt" class="{{ $btnGhostXs }}">Alles vollständig übernehmen</button>
                    @endif
                </x-slot:actions>
                @php($receipt = $detail['receipt'])
                @if(!$detail['wareneingang_editierbar'])
                    <div class="mb-3 rounded-md border border-amber-500/20 bg-amber-500/[0.08] px-3 py-2 text-[12px] text-amber-700">
                        Wareneingang ist erst nach dem Absenden oder Bestätigen der Bestellung buchbar.
                    </div>
                @endif
                <div class="flex flex-wrap gap-2 text-[11px] mb-3">
                    <span class="px-2 py-0.5 rounded-md bg-black/10 text-gray-600">{{ $receipt['booked'] }}/{{ $receipt['lines'] }} Zeilen gebucht</span>
                    @if($receipt['missing'] > 0)
                        <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-700">{{ $receipt['missing'] }} offen</span>
                    @endif
                    @if($receipt['differences'] > 0)
                        <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-rose-700">{{ $receipt['differences'] }} Differenzen</span>
                    @endif
                    <span class="px-2 py-0.5 rounded-md bg-violet-500/10 text-violet-700">WE netto {{ number_format((float) $receipt['received_net'], 2, ',', '.') }} €</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="{{ $table }}">
                        <thead><tr>
                            <th class="{{ $th }} text-left">Artikel</th>
                            <th class="{{ $th }} text-right">Bestellt</th>
                            <th class="{{ $th }} text-right">Geliefert</th>
                            <th class="{{ $th }} text-right">Differenz</th>
                            <th class="{{ $th }} text-left">Notiz</th>
                        </tr></thead>
                        <tbody>
                            @foreach($detail['zeilen'] as $z)
                                @php($diff = $z['receipt_diff_packs'])
                                <tr class="border-t border-black/5 align-top" wire:key="receipt-line-{{ $z['id'] }}">
                                    <td class="{{ $td }} text-gray-800">
                                        {{ $z['designation'] ?: '—' }}
                                        @if($z['article_number'])<br><span class="text-[10px] text-gray-400">Art. {{ $z['article_number'] }}</span>@endif
                                @if($z['received_at'])<br><span class="text-[10px] text-gray-400">gebucht {{ \Carbon\Carbon::parse($z['received_at'])->format('d.m.Y H:i') }}</span>@endif
                                        @if(!empty($z['inventory']))
                                            <br><span class="text-[10px] text-sky-600">Lager danach: {{ $z['inventory']['display'] }} · Rest {{ $z['inventory']['shortage_display'] }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">{{ rtrim(rtrim(number_format((float) $z['qty_packs'], 2, ',', '.'), '0'), ',') }} {{ $z['packaging_unit'] }}</td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        @if($detail['wareneingang_editierbar'])
                                            <input type="number" min="0" step="0.01" value="{{ $z['received_qty_packs'] }}"
                                                wire:change="updateReceiptLine({{ $z['id'] }}, $event.target.value, null)"
                                                class="w-20 text-right {{ $input }}" />
                                        @else
                                            {{ $z['received_qty_packs'] !== null ? rtrim(rtrim(number_format((float) $z['received_qty_packs'], 2, ',', '.'), '0'), ',') : '—' }}
                                        @endif
                                        @if($z['packaging_unit'])<span class="text-[10px] text-gray-400"> {{ $z['packaging_unit'] }}</span>@endif
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        @if($diff === null)
                                            <span class="text-gray-400">offen</span>
                                        @elseif(abs((float) $diff) < 0.01)
                                            <span class="text-emerald-600">ok</span>
                                        @else
                                            <span class="{{ (float) $diff < 0 ? 'text-rose-600' : 'text-amber-700' }}">{{ (float) $diff > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $diff, 2, ',', '.'), '0'), ',') }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }}">
                                        @if($detail['wareneingang_editierbar'])
                                            <input type="text" value="{{ $z['received_note'] }}" placeholder="Differenz, Ersatz, Bruch..."
                                                wire:change="updateReceiptNote({{ $z['id'] }}, $event.target.value)"
                                                class="{{ $input }} !py-1 !text-[11px]" />
                                        @else
                                            <span class="text-[11px] text-gray-500">{{ $z['received_note'] ?: '—' }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-foodalchemist::modal-section>
        </div>

        {{-- ═══ Tab: RECHNUNG ═══ --}}
        <div x-show="tab === 'rechnung'" x-cloak class="pt-4">
            <x-foodalchemist::modal-section title="Rechnungsprüfung">
                <x-slot:actions>
                    @if($detail['rechnung_editierbar'])
                        <button type="button" wire:click="completeInvoiceFromReceipt" class="{{ $btnGhostXs }}">Aus Wareneingang übernehmen</button>
                    @endif
                </x-slot:actions>
                @php($invoice = $detail['invoice'])
                @php($claims = $detail['claims'])
                @if(!$detail['rechnung_editierbar'])
                    <div class="mb-3 rounded-md border border-amber-500/20 bg-amber-500/[0.08] px-3 py-2 text-[12px] text-amber-700">
                        Rechnungserfassung ist erst nach dem Absenden der Bestellung buchbar. Der Tab bleibt sichtbar, damit du den Prozess findest.
                    </div>
                @endif
                <div class="flex flex-wrap gap-2 text-[11px] mb-3">
                    <span class="px-2 py-0.5 rounded-md bg-black/10 text-gray-600">{{ $invoice['checked'] }}/{{ $invoice['lines'] }} Zeilen geprüft</span>
                    @if($invoice['missing'] > 0)
                        <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-700">{{ $invoice['missing'] }} offen</span>
                    @endif
                    @if($invoice['differences'] > 0)
                        <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-rose-700">{{ $invoice['differences'] }} Differenzen</span>
                    @endif
                    <span class="px-2 py-0.5 rounded-md bg-violet-500/10 text-violet-700">Rechnung netto {{ number_format((float) $invoice['invoice_net'], 2, ',', '.') }} €</span>
                    @if(abs((float) $invoice['diff_net']) >= 0.01)
                        <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-amber-700">Diff. {{ number_format((float) $invoice['diff_net'], 2, ',', '.') }} €</span>
                    @endif
                    @if(($claims['lines'] ?? 0) > 0)
                        <span class="px-2 py-0.5 rounded-md {{ (($claims['open'] ?? 0) + ($claims['credit_expected'] ?? 0)) > 0 ? 'bg-amber-500/10 text-amber-700' : 'bg-emerald-500/10 text-emerald-700' }}">Reklamation {{ $claims['lines'] }} · {{ number_format((float) ($claims['credit_expected_net'] ?? 0), 2, ',', '.') }} €</span>
                    @endif
                </div>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-3 rounded-md border border-white/10 bg-white/[0.04] p-3">
                    <div>
                        <label class="text-[10px] text-gray-500">Rechnungsnummer</label>
                        @if($detail['rechnung_editierbar'])
                            <input type="text" wire:model="formInvoiceNumber" class="{{ $input }}" />
                        @else
                            <div class="text-[12px] text-gray-700">{{ $detail['invoice_number'] ?: '—' }}</div>
                        @endif
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500">Rechnungsdatum</label>
                        @if($detail['rechnung_editierbar'])
                            <input type="date" wire:model="formInvoiceDate" class="{{ $input }}" />
                        @else
                            <div class="text-[12px] text-gray-700">{{ $detail['invoice_date'] ?: '—' }}</div>
                        @endif
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500">Fälligkeit</label>
                        <div class="text-[12px] text-gray-700">
                            {{ $detail['invoice_due_date'] ? \Carbon\Carbon::parse($detail['invoice_due_date'])->format('d.m.Y') : '—' }}
                        </div>
                        @if($detail['payment_term_days'] !== null)
                            <div class="text-[10px] text-gray-400">{{ $detail['payment_term_days'] }} Tage Zahlungsziel</div>
                        @endif
                    </div>
                    <div class="md:col-span-4">
                        <label class="text-[10px] text-gray-500">Rechnungsnotiz</label>
                        @if($detail['rechnung_editierbar'])
                            <textarea wire:model="formInvoiceNote" rows="2" class="{{ $input }}"></textarea>
                            <button type="button" wire:click="saveInvoiceHeader" class="{{ $btnGhostXs }} mt-2">Rechnungskopf speichern</button>
                        @else
                            <div class="text-[12px] text-gray-700">{{ $detail['invoice_note'] ?: '—' }}</div>
                        @endif
                    </div>
                </div>
                @if($detail['invoice_number'] || $detail['invoice_date'])
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-3 rounded-md border border-white/10 bg-white/[0.04] p-3">
                        <div>
                            <label class="text-[10px] text-gray-500">Zahlungsstatus</label>
                            @if($detail['rechnung_editierbar'])
                                <select wire:model="formPaymentStatus" class="{{ $input }}">
                                    <option value="">offen</option>
                                    <option value="open">offen</option>
                                    <option value="disputed">strittig</option>
                                    <option value="paid">bezahlt</option>
                                </select>
                            @else
                                <div class="text-[12px] text-gray-700">{{ $detail['payment']['label'] ?? '—' }}</div>
                            @endif
                        </div>
                        <div>
                            <label class="text-[10px] text-gray-500">Bezahlt am</label>
                            @if($detail['rechnung_editierbar'])
                                <input type="date" wire:model="formInvoicePaidAt" class="{{ $input }}" />
                            @else
                                <div class="text-[12px] text-gray-700">{{ $detail['invoice_paid_at'] ?: '—' }}</div>
                            @endif
                        </div>
                        <div>
                            <label class="text-[10px] text-gray-500">OP-Status</label>
                            <div class="text-[12px] font-medium {{ ($detail['payment']['state'] ?? '') === 'overdue' ? 'text-amber-600' : ((($detail['payment']['state'] ?? '') === 'paid') ? 'text-emerald-600' : 'text-gray-700') }}">
                                {{ $detail['payment']['label'] ?? '—' }}
                            </div>
                            @if(($detail['payment']['overdue_days'] ?? 0) > 0)
                                <div class="text-[10px] text-amber-600">{{ $detail['payment']['overdue_days'] }} Tage überfällig</div>
                            @endif
                        </div>
                        <div class="md:col-span-4">
                            <label class="text-[10px] text-gray-500">Zahlungsnotiz</label>
                            @if($detail['rechnung_editierbar'])
                                <textarea wire:model="formPaymentNote" rows="2" class="{{ $input }}"></textarea>
                                <button type="button" wire:click="savePayment" class="{{ $btnGhostXs }} mt-2">Zahlungsstatus speichern</button>
                            @else
                                <div class="text-[12px] text-gray-700">{{ $detail['payment_note'] ?: '—' }}</div>
                            @endif
                        </div>
                    </div>
                @endif
                <div class="overflow-x-auto">
                    <table class="{{ $table }}">
                        <thead><tr>
                            <th class="{{ $th }} text-left">Artikel</th>
                            <th class="{{ $th }} text-right">Basis</th>
                            <th class="{{ $th }} text-right">Rechnung Menge</th>
                            <th class="{{ $th }} text-right">Rechnung Preis</th>
                            <th class="{{ $th }} text-right">Diff. netto</th>
                            <th class="{{ $th }} text-left">Notiz</th>
                            <th class="{{ $th }} text-left">Reklamation</th>
                        </tr></thead>
                        <tbody>
                            @foreach($detail['zeilen'] as $z)
                                @php($diffNet = $z['invoice_diff_net'])
                                <tr class="border-t border-black/5 align-top" wire:key="invoice-line-{{ $z['id'] }}">
                                    <td class="{{ $td }} text-gray-800">
                                        {{ $z['designation'] ?: '—' }}
                                        @if($z['article_number'])<br><span class="text-[10px] text-gray-400">Art. {{ $z['article_number'] }}</span>@endif
                                        @if($z['invoice_checked_at'])<br><span class="text-[10px] text-gray-400">geprüft {{ \Carbon\Carbon::parse($z['invoice_checked_at'])->format('d.m.Y H:i') }}</span>@endif
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        {{ rtrim(rtrim(number_format((float) ($z['received_qty_packs'] ?? $z['qty_packs']), 2, ',', '.'), '0'), ',') }} {{ $z['packaging_unit'] }}
                                        <br><span class="text-[10px] text-gray-400">{{ $z['pack_price'] !== null ? number_format((float) $z['pack_price'], 2, ',', '.') . ' €' : '—' }}</span>
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        @if($detail['rechnung_editierbar'])
                                            <input type="number" min="0" step="0.01" value="{{ $z['invoice_qty_packs'] }}"
                                                wire:change="updateInvoiceLine({{ $z['id'] }}, $event.target.value, {{ $z['invoice_pack_price'] !== null ? (float) $z['invoice_pack_price'] : 'null' }}, null)"
                                                class="w-20 text-right {{ $input }}" />
                                        @else
                                            {{ $z['invoice_qty_packs'] !== null ? rtrim(rtrim(number_format((float) $z['invoice_qty_packs'], 2, ',', '.'), '0'), ',') : '—' }}
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        @if($detail['rechnung_editierbar'])
                                            <input type="number" min="0" step="0.01" value="{{ $z['invoice_pack_price'] }}"
                                                wire:change="updateInvoiceLine({{ $z['id'] }}, {{ $z['invoice_qty_packs'] !== null ? (float) $z['invoice_qty_packs'] : 'null' }}, $event.target.value, null)"
                                                class="w-24 text-right {{ $input }}" />
                                        @else
                                            {{ $z['invoice_pack_price'] !== null ? number_format((float) $z['invoice_pack_price'], 2, ',', '.') . ' €' : '—' }}
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap">
                                        @if($diffNet === null)
                                            <span class="text-gray-400">offen</span>
                                        @elseif(abs((float) $diffNet) < 0.01)
                                            <span class="text-emerald-600">ok</span>
                                        @else
                                            <span class="{{ (float) $diffNet < 0 ? 'text-emerald-700' : 'text-rose-600' }}">{{ (float) $diffNet > 0 ? '+' : '' }}{{ number_format((float) $diffNet, 2, ',', '.') }} €</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }}">
                                        @if($detail['rechnung_editierbar'])
                                            <input type="text" value="{{ $z['invoice_note'] }}" placeholder="Preisabweichung, Gutschrift..."
                                                wire:change="updateInvoiceNote({{ $z['id'] }}, $event.target.value)"
                                                class="{{ $input }} !py-1 !text-[11px]" />
                                        @else
                                            <span class="text-[11px] text-gray-500">{{ $z['invoice_note'] ?: '—' }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} min-w-[220px]">
                                        @if($detail['rechnung_editierbar'])
                                            <div class="grid grid-cols-2 gap-1">
                                                <select class="{{ $input }} !py-1 !text-[11px]" wire:change="updateClaimStatus({{ $z['id'] }}, $event.target.value)">
                                                    <option value="" @selected(!$z['claim_status'])>—</option>
                                                    <option value="open" @selected($z['claim_status'] === 'open')>offen</option>
                                                    <option value="credit_expected" @selected($z['claim_status'] === 'credit_expected')>Gutschrift erwartet</option>
                                                    <option value="credited" @selected($z['claim_status'] === 'credited')>gutgeschrieben</option>
                                                    <option value="resolved" @selected($z['claim_status'] === 'resolved')>erledigt</option>
                                                </select>
                                                <input type="number" min="0" step="0.01" value="{{ $z['claim_qty_packs'] }}"
                                                    placeholder="Menge"
                                                    wire:change="updateClaimQty({{ $z['id'] }}, $event.target.value)"
                                                    class="{{ $input }} !py-1 !text-[11px]" />
                                                <input type="number" min="0" step="0.01" value="{{ $z['credit_expected_net'] }}"
                                                    placeholder="Gutschrift €"
                                                    wire:change="updateClaimCredit({{ $z['id'] }}, $event.target.value)"
                                                    class="{{ $input }} !py-1 !text-[11px]" />
                                                <input type="text" value="{{ $z['claim_note'] }}" placeholder="Notiz..."
                                                    wire:change="updateClaimNote({{ $z['id'] }}, $event.target.value)"
                                                    class="{{ $input }} !py-1 !text-[11px]" />
                                            </div>
                                        @else
                                            <span class="text-[11px] text-gray-500">{{ $z['claim_status_label'] ?? '—' }}</span>
                                            @if($z['credit_expected_net'] !== null)<br><span class="text-[10px] text-gray-400">{{ number_format((float) $z['credit_expected_net'], 2, ',', '.') }} €</span>@endif
                                            @if($z['claim_note'])<br><span class="text-[10px] text-gray-400">{{ $z['claim_note'] }}</span>@endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-foodalchemist::modal-section>
        </div>

        {{-- ═══ Tab: HINZUFÜGEN (Direktbestellung — nur Entwurf) ═══ --}}
        <div x-show="tab === 'hinzufuegen'" x-cloak class="pt-4 space-y-4">
            @if($detail['editierbar'])
                <x-foodalchemist::modal-section title="Artikel direkt bestellen">
                    <input type="search" wire:model.live.debounce.300ms="artikelSuche" placeholder="Lieferant / Grundprodukt / Artikel / Art-Nr…" class="{{ $input }}" data-orders-artikel-suche />
                    @if($artikelTreffer->isNotEmpty())
                        <div class="mt-1 rounded-lg border border-white/10 divide-y divide-white/5 max-h-60 overflow-y-auto">
                            @foreach($artikelTreffer as $a)
                                <button type="button" wire:click="artikelHinzufuegen({{ $a['id'] }})" wire:key="art-{{ $a['id'] }}"
                                    class="block w-full text-left px-2.5 py-1.5 hover:bg-violet-500/10">
                                    <span class="text-[12px] text-gray-800">{{ $a['designation'] ?: '—' }}</span>
                                    <span class="text-[10px] text-gray-400 block">{{ $a['supplier'] }}@if($a['gp']) · GP {{ $a['gp'] }}@endif@if($a['article_number']) · Art. {{ $a['article_number'] }}@endif</span>
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
            @if(!empty($detail['warnings']))
                <x-foodalchemist::modal-section title="WaWi-Hinweise">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($detail['warnings'] as $w)
                            @php($hard = in_array($w, $detail['send_blockers'] ?? [], true))
                            <span class="px-2 py-0.5 rounded-md text-[11px] {{ $hard ? 'bg-rose-500/10 text-rose-700' : 'bg-amber-500/10 text-amber-700' }}">
                                {{ $w }}
                            </span>
                        @endforeach
                    </div>
                    @if(!empty($detail['send_blockers']))
                        <p class="mt-2 text-[11px] text-rose-600">Absenden ist gesperrt, bis diese Punkte geklärt sind.</p>
                    @endif
                    @if(!empty($detail['logistik']['deadline']))
                        <p class="mt-1 text-[10px] text-gray-400">Bestellschluss: {{ \Carbon\Carbon::parse($detail['logistik']['deadline'])->format('d.m.Y H:i') }}</p>
                    @endif
                </x-foodalchemist::modal-section>
            @endif

            <x-foodalchemist::modal-section title="Freigabe">
                <x-slot:actions>
                    @if($detail['is_owned'])
                        <button type="button" wire:click="saveApproval" class="{{ $btnGhostXs }}">Freigabe speichern</button>
                    @endif
                </x-slot:actions>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    <div>
                        <label class="text-[10px] text-gray-500">Status</label>
                        @if($detail['is_owned'])
                            <select wire:model="formApprovalStatus" class="{{ $input }}">
                                <option value="">keine Freigabe</option>
                                <option value="requested">angefragt</option>
                                <option value="approved">freigegeben</option>
                                <option value="rejected">abgelehnt</option>
                            </select>
                        @else
                            <div class="text-[12px] text-gray-700">{{ $detail['approval']['label'] ?? '—' }}</div>
                        @endif
                    </div>
                    <div>
                        <label class="text-[10px] text-gray-500">Zeitpunkt</label>
                        <div class="text-[12px] text-gray-700">
                            {{ ($detail['approval']['approved_at'] ?? null) ?: (($detail['approval']['requested_at'] ?? null) ?: '—') }}
                        </div>
                    </div>
                    <div class="md:col-span-3">
                        <label class="text-[10px] text-gray-500">Freigabenotiz</label>
                        @if($detail['is_owned'])
                            <textarea wire:model="formApprovalNote" rows="2" class="{{ $input }}"></textarea>
                        @else
                            <div class="text-[12px] text-gray-700">{{ $detail['approval_note'] ?: '—' }}</div>
                        @endif
                    </div>
                </div>
            </x-foodalchemist::modal-section>

            @if($detail['editierbar'])
                <x-foodalchemist::modal-section title="Liefer-Logistik & Anlass">
                    <x-slot:actions>
                        <button type="button" wire:click="saveHeader" class="{{ $btnGhostXs }}" data-orders-kopf-speichern>Kopf speichern</button>
                    </x-slot:actions>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="text-[10px] text-gray-500">Liefertag</label>
                            <input type="date" wire:model="formDeliveryDate" class="{{ $input }}" />
                        </div>
                        <div>
                            <label class="text-[10px] text-gray-500">Anlass / Referenz</label>
                            <input type="text" wire:model="formReference" class="{{ $input }}" placeholder="z. B. Sommerfest" />
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
                @if(!in_array($detail['status'], ['draft', 'cancelled'], true))
                    <x-foodalchemist::modal-section title="Lieferantenbestätigung">
                        <x-slot:actions>
                            <button type="button" wire:click="saveSupplierConfirmation" class="{{ $btnGhostXs }}">Bestätigung speichern</button>
                        </x-slot:actions>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] text-gray-500">Bestell-/AB-Nummer</label>
                                <input type="text" wire:model="formSupplierOrderNumber" class="{{ $input }}" />
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-500">Bestätigter Liefertag</label>
                                <input type="date" wire:model="formConfirmedDeliveryDate" class="{{ $input }}" />
                            </div>
                            <div class="md:col-span-3">
                                <label class="text-[10px] text-gray-500">Bestätigungsnotiz</label>
                                <textarea wire:model="formSupplierConfirmationNote" rows="2" class="{{ $input }}"></textarea>
                            </div>
                        </div>
                    </x-foodalchemist::modal-section>
                @endif
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
