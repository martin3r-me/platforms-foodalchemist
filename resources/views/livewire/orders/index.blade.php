{{-- Bestellungen: bestell-zentrierter Browser (Liefertag/Bestelldatum + Filter + Neue Bestellung). Bearbeiten im Fullscreen-Editor. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabels = ['draft' => 'Entwurf', 'sent' => 'versendet', 'confirmed' => 'bestätigt', 'delivered' => 'geliefert', 'cancelled' => 'storniert'])
@php($zeitraeume = ['' => 'alle', 'heute' => 'heute', 'woche' => 'diese Woche', 'naechste' => 'nächste Woche'])

<x-ui-page>
    <x-slot name="navbar">
        <x-ui-page-navbar title="Bestellungen" icon="heroicon-o-shopping-cart" />
    </x-slot>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Bestellungen'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Filter" width="w-72">
            <div class="p-3 space-y-3">
                <input type="search" wire:model.live.debounce.300ms="suche" placeholder="{{ $sicht === 'bedarfe' ? 'Produktion suchen …' : 'Beleg, Artikel, Produktion …' }}" class="{{ $input }}" />
                @if($sicht !== 'bedarfe')
                    <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs w-full">
                        <button type="button" wire:click="$set('datumsbasis','liefertag')" class="flex-1 px-2 py-1 rounded-md {{ $datumsbasis === 'liefertag' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Liefertag</button>
                        <button type="button" wire:click="$set('datumsbasis','bestelldatum')" class="flex-1 px-2 py-1 rounded-md {{ $datumsbasis === 'bestelldatum' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Bestelldatum</button>
                    </div>
                    <div>
                        <span class="{{ $label }}">Status</span>
                        <div class="mt-1 space-y-0.5">
                            <x-foodalchemist::filter-row wire:click="$set('statusFilter','')" :active="$statusFilter === ''"><span>Alle Status</span></x-foodalchemist::filter-row>
                            @foreach(['draft','sent','confirmed','delivered','cancelled'] as $s)
                                <x-foodalchemist::filter-row wire:key="order-status-{{ $s }}" wire:click="$set('statusFilter','{{ $s }}')" :active="$statusFilter === $s">{{ $statusLabels[$s] }}</x-foodalchemist::filter-row>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div>
                    <span class="{{ $label }}">Zeitraum</span>
                    <div class="flex flex-wrap gap-1 mt-1">
                        @foreach($zeitraeume as $key => $lbl)<button type="button" wire:click="waehleZeitraum('{{ $key }}')" class="{{ $pill }} {{ $zeitraum === $key ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $lbl }}</button>@endforeach
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <input type="date" wire:model.live="von" class="{{ $input }}" title="Von" />
                    <input type="date" wire:model.live="bis" class="{{ $input }}" title="Bis" />
                </div>
                @if($sicht !== 'bedarfe')
                    <select wire:model.live="supplierFilter" class="{{ $input }}">
                        <option value="">alle Lieferanten</option>
                        @foreach($lieferanten as $l)<option value="{{ $l['id'] }}">{{ $l['name'] }}</option>@endforeach
                    </select>
                    <label class="flex items-center gap-2 text-[12px] text-gray-600"><input type="checkbox" wire:model.live="nurMitPositionen" /> nur mit Positionen</label>
                    <label class="flex items-center gap-2 text-[12px] text-gray-600"><input type="checkbox" wire:model.live="nurMitKlaerung" /> nur mit Klärung</label>
                    <button type="button" wire:click="leereEntwuerfeLoeschen" onclick="return confirm('Alle leeren Entwürfe ohne Positionen löschen?')" class="{{ $btnGhostXs }}">Leere Entwürfe löschen</button>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Bestellung" width="w-96" :maxWidth="760" scope="activity_orders" side="right">
            <livewire:foodalchemist.orders.detail-panel :order-id="$selectedOrderId" :key="'order-detail-'.($selectedOrderId ?? 'empty')" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- Fullscreen-Editor (pro Bestellung), geöffnet per orders-editor.bearbeiten --}}
    <livewire:foodalchemist.orders.editor key="orders-editor-shell" />

    <x-foodalchemist::modal name="orders-batch" title="Bestellungen auslösen">
        <div class="space-y-4 p-1" data-orders-batch>
            @if($batchResult)
                <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.07] p-4">
                    <div class="flex items-start gap-3">
                        @svg('heroicon-o-check-circle', 'w-5 h-5 text-emerald-600 shrink-0')
                        <div>
                            <h3 class="text-[14px] font-semibold text-gray-900">{{ $batchResult['sent'] }} Bestellung(en) ausgelöst</h3>
                            <p class="text-[11px] text-gray-500 mt-0.5">Versandzeitpunkt {{ $batchResult['sent_at'] }}</p>
                        </div>
                    </div>
                </div>
                @if(!empty($batchResult['sent_ids']))
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $batchResult['sent_ids'])]) }}" target="_blank" class="{{ $btnPrimary }}">@svg('heroicon-o-printer', 'w-3.5 h-3.5') Versandprotokoll drucken</a>
                        <a href="{{ route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $batchResult['sent_ids']), 'pdf' => 1]) }}" class="{{ $btnGhost }}">PDF</a>
                    </div>
                @endif
                @if($batchResult['blocked'] > 0)
                    <x-foodalchemist::alert tone="warning">{{ $batchResult['blocked'] }} Beleg(e) blieben wegen Klärpunkten offen.</x-foodalchemist::alert>
                @endif
            @elseif($batchPreview)
                <div class="grid grid-cols-3 gap-2">
                    <div class="rounded-lg border border-black/5 p-3"><div class="{{ $label }}">Auswahl</div><div class="text-lg font-semibold">{{ $batchPreview['selected'] }}</div></div>
                    <div class="rounded-lg border border-emerald-500/20 bg-emerald-500/[0.05] p-3"><div class="{{ $label }}">Versandfähig</div><div class="text-lg font-semibold text-emerald-700">{{ $batchPreview['ready'] }}</div></div>
                    <div class="rounded-lg border border-amber-500/20 bg-amber-500/[0.05] p-3"><div class="{{ $label }}">Klärung</div><div class="text-lg font-semibold text-amber-700">{{ $batchPreview['blocked'] }}</div></div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="batchAlleWaehlen" class="{{ $btnGhostXs }}">Alle auswählen</button>
                        <button type="button" wire:click="batchAuswahlLeeren" class="{{ $btnGhostXs }}">Keine</button>
                    </div>
                    @if(count($selectedOrderIds) > 0)
                        <div class="flex items-center gap-2">
                            <a href="{{ route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds)]) }}" target="_blank" class="{{ $btnGhostXs }}" title="Auswahl gebündelt drucken">@svg('heroicon-o-printer', 'w-3.5 h-3.5') Drucken</a>
                            <a href="{{ route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds), 'pdf' => 1]) }}" class="{{ $btnGhostXs }}" title="Auswahl als PDF herunterladen">PDF</a>
                        </div>
                    @endif
                </div>
                <div class="max-h-[44vh] overflow-auto divide-y divide-black/5 border-y border-black/5">
                    @foreach(($batchCandidates['orders'] ?? []) as $order)
                        <div wire:key="batch-order-{{ $order['id'] }}" class="py-2.5 flex items-start justify-between gap-4" data-orders-batch-row="{{ $order['id'] }}">
                            <div class="min-w-0 flex items-start gap-3">
                                <input type="checkbox"
                                       class="mt-0.5"
                                       wire:click="batchBestellungUmschalten({{ $order['id'] }})"
                                       @checked(in_array((int) $order['id'], array_map('intval', $selectedOrderIds), true))
                                       aria-label="Bestellung ord-{{ $order['id'] }} von {{ $order['supplier'] }} auswählen" />
                                <div class="min-w-0">
                                <div class="text-[13px] font-medium text-gray-900">{{ $order['supplier'] }} · ord-{{ $order['id'] }}</div>
                                <div class="text-[11px] text-gray-500 flex flex-wrap gap-x-3">
                                    <span>{{ $order['positions'] }} Positionen</span>
                                    <span>Bestelldatum: {{ $order['created_at'] ? \Carbon\Carbon::parse($order['created_at'])->format('d.m.Y') : '—' }}</span>
                                    <span>Liefertag: {{ $order['desired_delivery_date'] ? \Carbon\Carbon::parse($order['desired_delivery_date'])->format('d.m.Y') : '—' }}</span>
                                </div>
                                @if(!$order['sendable'])<div class="text-[11px] text-amber-700 mt-1">{{ implode(' · ', $order['blockers']) }}</div>@endif
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="text-[13px] font-semibold tabular-nums">{{ number_format($order['total_net'], 2, ',', '.') }} €</div>
                                <span class="{{ $pill }} {{ $order['sendable'] ? $variantPill['success'] : $variantPill['warning'] }}">{{ $order['sendable'] ? 'bereit' : 'Klärung' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="text-[12px] text-gray-600">Versandfähige Summe <strong>{{ number_format($batchPreview['total_net'], 2, ',', '.') }} €</strong></div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="auswahlStornieren" onclick="return confirm('Ausgewählte Entwürfe wirklich stornieren?')" class="{{ $btnGhost }}">Stornieren</button>
                        <button type="button" wire:click="auswahlAusloesen" onclick="return confirm('{{ $batchPreview['ready'] }} versandfähige Bestellungen jetzt auslösen?')" class="{{ $btnPrimary }}" @disabled($batchPreview['ready'] === 0)>{{ $batchPreview['ready'] }} auslösen</button>
                    </div>
                </div>
            @else
                <p class="text-[12px] text-gray-500">Keine Entwürfe ausgewählt.</p>
            @endif
        </div>
    </x-foodalchemist::modal>

    <x-ui-page-container padding="px-6 pt-4 pb-6" spacing="space-y-4">

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        {{-- Neue Bestellung: neutral öffnen; Lieferant entsteht erst aus Artikel/Bedarf. --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <span class="{{ $label }} block mb-1">Neue Bestellrunde</span>
                <div class="flex flex-wrap gap-1">
                    <input type="date" wire:model="neuerLiefertag" class="{{ $input }}" title="Liefertag" />
                    <select wire:model="neueStrategie" class="{{ $input }}" title="Einkaufsstrategie">
                        <option value="">Team-Standard</option>
                        @foreach($strategieOptionen as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="neueBestellung" class="{{ $btnPrimary }} shrink-0" data-orders-neu>+ Bestellrunde öffnen</button>
                </div>
                <p class="text-[11px] text-gray-500 mt-1">Eine Runde erzeugt beim Speichern die passenden Lieferanten-Belege je Lieferant + Liefertag.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 pb-0.5">
                @if($sicht === 'bestellungen')
                    <button type="button" wire:click="alleVersandfaehigenWaehlen" class="{{ $btnGhostXs }}" @disabled($kpis['ready'] === 0)>Alle versandfähigen</button>
                    @if(count($selectedOrderIds) > 0)
                        <button type="button" wire:click="auswahlLeeren" class="{{ $btnGhostXs }}" title="Auswahl aufheben">@svg('heroicon-o-x-mark', 'w-3.5 h-3.5')</button>
                        <a href="{{ route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds)]) }}" target="_blank" class="{{ $btnGhostXs }}" title="Ausgewählte Bestellungen gebündelt drucken">@svg('heroicon-o-printer', 'w-3.5 h-3.5') Drucken</a>
                        <a href="{{ route('foodalchemist.orders.versandprotokoll', ['ids' => implode(',', $selectedOrderIds), 'pdf' => 1]) }}" class="{{ $btnGhostXs }}" title="Ausgewählte Bestellungen als PDF herunterladen">PDF</a>
                    @endif
                    <button type="button" wire:click="sammelversandPruefen" class="{{ $btnPrimary }}" @disabled(count($selectedOrderIds) === 0)>
                        @svg('heroicon-o-paper-airplane', 'w-3.5 h-3.5') Auswahl prüfen{{ count($selectedOrderIds) > 0 ? ' (' . count($selectedOrderIds) . ')' : '' }}
                    </button>
                @endif
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'einkauf']) }}#lagerorte"
                   class="{{ $btnGhostXs }}">Lagerorte</a>
            </div>
        </div>

        <x-foodalchemist::kpi-tiles marker="orders-overview-kpis" :cols="6" :tiles="[
            ['kpi' => 'orders', 'label' => 'Bestellungen', 'value' => number_format($kpis['orders'], 0, ',', '.')],
            ['kpi' => 'ready', 'label' => 'Versandfähig', 'tone' => $kpis['ready'] > 0 ? 'good' : 'neutral', 'value' => number_format($kpis['ready'], 0, ',', '.')],
            ['kpi' => 'positions', 'label' => 'Positionen', 'value' => number_format($kpis['positions'], 0, ',', '.')],
            ['kpi' => 'netto', 'label' => 'Netto gesamt', 'tone' => 'accent', 'value' => number_format($kpis['total_net'], 2, ',', '.') . ' €'],
            ['kpi' => 'suppliers', 'label' => 'Lieferanten', 'value' => number_format($kpis['suppliers'], 0, ',', '.')],
            ['kpi' => 'clarifications', 'label' => 'Klärpunkte', 'tone' => $kpis['clarifications'] > 0 ? 'warn' : 'good', 'value' => number_format($kpis['clarifications'], 0, ',', '.')],
        ]" />

        <div class="flex flex-wrap gap-2 text-[12px]">
            @foreach(['bestellungen' => 'Bestellungen', 'liefertage' => 'Liefertage', 'lieferanten' => 'Lieferanten', 'runden' => 'Runden', 'bedarfe' => 'Bedarfe'] as $key => $lbl)
                <button type="button" wire:click="$set('sicht','{{ $key }}')"
                    class="px-3 py-1.5 rounded-md font-medium {{ $sicht === $key ? 'bg-violet-600 text-white shadow-sm' : 'bg-black/[0.04] text-gray-600 hover:bg-black/[0.07]' }}">
                    {{ $lbl }}
                </button>
            @endforeach
        </div>

        {{-- Bestell-Hub: drei Sichten auf dieselben gefilterten Daten. --}}
        <div class="relative overflow-hidden {{ $card }}" data-orders-tabelle>
            <div class="{{ $cardAccent }}"></div>
            <div class="px-5 pt-4 pb-2 flex items-baseline justify-between">
                <div>
                    <h3 class="font-medium tracking-tight text-gray-900">{{ ['bestellungen' => 'Bestellungen finden', 'liefertage' => 'Nach Liefertag planen', 'lieferanten' => 'Nach Lieferant bündeln', 'runden' => 'Bestellrunden', 'bedarfe' => 'Freigegebene Materialbedarfe'][$sicht] ?? 'Bestellungen' }}</h3>
                    @if($sicht === 'bestellungen')
                        <p class="text-[11px] text-gray-500 mt-0.5">Zeilen sind Lieferanten-Belege einer Bestellrunde; Suche geht über Beleg, Referenz, Artikel, Produktion und Lieferant.</p>
                    @endif
                </div>
                <span class="{{ $label }}">{{ number_format($sicht === 'runden' ? $runden->count() : ($sicht === 'bedarfe' ? $bedarfe->count() : $liste->count()), 0, ',', '.') }} Treffer</span>
            </div>
            <div class="max-h-[70vh] overflow-auto">
                @if($sicht === 'runden')
                    <div class="grid min-h-[420px] lg:grid-cols-[minmax(0,1fr)_340px]">
                        <div class="divide-y divide-black/5">
                            @forelse($runden as $runde)
                                <button type="button" wire:click="rundeWaehlen({{ $runde['id'] }})" wire:key="round-{{ $runde['id'] }}"
                                    class="w-full px-5 py-3 text-left hover:bg-black/[0.025] {{ $selectedRoundId === $runde['id'] ? 'bg-violet-500/[0.06]' : '' }}">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="text-[13px] font-semibold text-gray-900 truncate">{{ $runde['label'] }}</div>
                                            <div class="text-[11px] text-gray-500 mt-0.5">{{ $runde['supplier_count'] }} Lieferanten · {{ $runde['order_count'] }} Belege · {{ $runde['position_count'] }} Positionen</div>
                                        </div>
                                        <div class="text-right shrink-0">
                                            <div class="text-[13px] font-semibold tabular-nums text-gray-900">{{ number_format($runde['total_net'], 2, ',', '.') }} €</div>
                                            <span class="{{ $pill }} {{ $runde['sendable'] ? $variantPill['success'] : $variantPill['secondary'] }}">{{ $runde['draft_count'] }} offen</span>
                                        </div>
                                    </div>
                                </button>
                            @empty
                                <div class="px-5 py-10 text-center text-gray-500">Noch keine gespeicherte Bestellrunde.</div>
                            @endforelse
                        </div>
                        <aside class="border-l border-black/5 bg-black/[0.015] p-4">
                            @if($selectedRound)
                                <div class="flex items-start justify-between gap-2 mb-3">
                                    <div>
                                        <h4 class="text-[14px] font-semibold text-gray-900">{{ $selectedRound['label'] }}</h4>
                                        <p class="text-[11px] text-gray-500">{{ $selectedRound['supplier_count'] }} Lieferanten · {{ $selectedRound['position_count'] }} Positionen</p>
                                    </div>
                                    <span class="text-[13px] font-semibold tabular-nums">{{ number_format($selectedRound['total_net'], 2, ',', '.') }} €</span>
                                </div>
                                <div class="divide-y divide-black/5 border-y border-black/5">
                                    @foreach($selectedRound['orders'] as $order)
                                        <button type="button" wire:click="oeffnen({{ $order['id'] }})" class="w-full py-2 text-left flex items-center justify-between gap-2">
                                            <span class="text-[12px] text-gray-900">{{ $order['supplier'] }}</span>
                                            <span class="text-[11px] text-gray-500">{{ $order['positions'] }} Pos. · {{ number_format($order['total_net'], 2, ',', '.') }} €</span>
                                        </button>
                                    @endforeach
                                </div>
                                @if(!empty($selectedRound['blockers']))
                                    <div class="mt-3 text-[11px] text-amber-700">{{ implode(' · ', $selectedRound['blockers']) }}</div>
                                @endif
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <button type="button" wire:click="rundeBearbeiten" class="{{ $btnGhost }}" @disabled(!$selectedRound['editable']) data-orders-round-edit>Runde bearbeiten</button>
                                    <button type="button" wire:click="rundeVersenden" class="{{ $btnPrimary }}" @disabled(!$selectedRound['sendable'])>Runde versenden</button>
                                </div>
                                @if(!$selectedRound['editable'])
                                    <p class="mt-2 text-[11px] text-gray-500">Ausgelöste Runden sind eingefroren. Änderungen erfolgen als Korrektur über die einzelnen Belege.</p>
                                @endif
                            @else
                                <div class="py-12 text-center text-[12px] text-gray-500">Bestellrunde auswählen.</div>
                            @endif
                        </aside>
                    </div>
                @elseif($sicht === 'bedarfe')
                    @if(count($selectedDemandIds) > 0)
                        <div class="px-5 py-2.5 border-b border-black/5 bg-violet-500/[0.04] flex items-center justify-between gap-3">
                            <span class="text-[12px] font-medium text-gray-700">{{ count($selectedDemandIds) }} Produktionen ausgewählt</span>
                            <button type="button" wire:click="ausgewaehlteBedarfePlanen" class="{{ $btnPrimary }}">Gemeinsam planen</button>
                        </div>
                    @endif
                    <div class="divide-y divide-black/5">
                        @forelse($bedarfe as $bedarf)
                            <div class="px-5 py-3 flex items-center justify-between gap-4" wire:key="demand-{{ $bedarf['id'] }}">
                                <div class="min-w-0 flex items-start gap-3">
                                    <input type="checkbox" wire:model.live="selectedDemandIds" value="{{ $bedarf['id'] }}" class="mt-1" @disabled($bedarf['stale'] || $bedarf['triggered']) aria-label="{{ $bedarf['name'] }} auswählen" />
                                    <div class="min-w-0">
                                    <div class="text-[13px] font-semibold text-gray-900 truncate">{{ $bedarf['name'] }}</div>
                                    <div class="text-[11px] text-gray-500 mt-0.5">{{ $bedarf['production_date'] ? \Carbon\Carbon::parse($bedarf['production_date'])->format('d.m.Y') : 'ohne Datum' }} · {{ $bedarf['targets'] }} Ziele · {{ $bedarf['orders'] }} Bestellungen</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="{{ $pill }} {{ $bedarf['stale'] ? $variantPill['warning'] : ($bedarf['status'] === 'geplant' ? $variantPill['success'] : $variantPill['info']) }}">{{ $bedarf['status'] }}</span>
                                    <button type="button" wire:click="$dispatch('orders-editor.production', { id: {{ $bedarf['id'] }}, roundId: {{ $bedarf['round_id'] ?? 'null' }} })" class="{{ $btnPrimary }}" @disabled($bedarf['stale'] || $bedarf['triggered'])>{{ $bedarf['triggered'] ? 'Ausgelöst' : ($bedarf['round_id'] ? 'Planung öffnen' : 'Planen') }}</button>
                                </div>
                            </div>
                        @empty
                            <div class="px-5 py-10 text-center text-gray-500">Keine freigegebenen Materialbedarfe.</div>
                        @endforelse
                    </div>
                @elseif($sicht === 'liefertage')
                    <div class="divide-y divide-black/5">
                        @forelse($liefertagGruppen as $gruppe)
                            <div class="px-5 py-3">
                                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                                    <div>
                                        <div class="text-[13px] font-semibold text-gray-900">{{ $gruppe['label'] }}</div>
                                        <div class="text-[11px] text-gray-500">{{ $gruppe['suppliers'] }} Lieferanten · {{ $gruppe['orders']->count() }} Bestellungen · {{ $gruppe['line_count'] }} Positionen</div>
                                    </div>
                                    <div class="text-[13px] font-semibold text-gray-900">{{ number_format($gruppe['total_net'], 2, ',', '.') }} €</div>
                                </div>
                                <table class="{{ $table }}">
                                    <thead><tr>
                                        <th class="{{ $th }} text-left">Beleg</th>
                                        <th class="{{ $th }} text-left">Lieferant</th>
                                        <th class="{{ $th }} text-left">Produktion / Anlass</th>
                                        <th class="{{ $th }} text-right">Pos.</th>
                                        <th class="{{ $th }} text-right">Netto</th>
                                        <th class="{{ $th }}">Status</th>
                                        <th class="{{ $th }}">Strategie</th>
                                        <th class="{{ $th }}">Hinweise</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($gruppe['orders'] as $o)
                                            <x-foodalchemist::table-row wire:key="day-{{ md5($gruppe['key']) }}-{{ $o['id'] }}" wire:click="oeffnen({{ $o['id'] }})">
                                                <td class="{{ $td }} text-gray-600 whitespace-nowrap">
                                                    <div class="font-medium text-gray-900">{{ $o['order_label'] }}</div>
                                                    @if($o['supplier_order_number'])<div class="text-[10px] text-gray-400">AB {{ $o['supplier_order_number'] }}</div>@endif
                                                    @if($o['invoice_number'])<div class="text-[10px] text-gray-400">RE {{ $o['invoice_number'] }}</div>@endif
                                                    @if($o['invoice_due_date'])<div class="text-[10px] text-amber-600">fällig {{ \Carbon\Carbon::parse($o['invoice_due_date'])->format('d.m.Y') }}</div>@endif
                                                    @if(($o['payment']['status'] ?? null))<div class="text-[10px] {{ ($o['payment']['state'] ?? '') === 'paid' ? 'text-emerald-600' : ((($o['payment']['state'] ?? '') === 'overdue') ? 'text-amber-600' : 'text-gray-400') }}">OP {{ $o['payment']['label'] }}</div>@endif
                                                    @if(($o['approval']['status'] ?? null))<div class="text-[10px] {{ ($o['approval']['state'] ?? '') === 'approved' ? 'text-emerald-600' : ((($o['approval']['state'] ?? '') === 'rejected') ? 'text-rose-600' : 'text-amber-600') }}">Freigabe {{ $o['approval']['label'] }}</div>@endif
                                                </td>
                                                <td class="{{ $td }} font-medium text-gray-900 whitespace-nowrap">{{ $o['supplier'] }}</td>
                                                <td class="{{ $td }} text-gray-600">
                                                    @if(!empty($o['herkunft']))
                                                        <div class="flex flex-wrap gap-1">
                                                            @foreach($o['herkunft'] as $h)
                                                                <span class="{{ $pill }} {{ $variantPill[($h['production_order_id'] ?? null) !== null ? 'primary' : ($h['type'] === 'concept' ? 'info' : 'secondary')] }}">{{ $h['label'] }}</span>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        {{ $o['reference'] ?: '—' }}
                                                    @endif
                                                </td>
                                                <td class="{{ $td }} text-right tabular-nums">{{ $o['line_count'] }}</td>
                                                <td class="{{ $td }} text-right tabular-nums">{{ number_format($o['total_net'], 2, ',', '.') }} €</td>
                                                <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $variantPill[$o['status']->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o['status']->label() }}</span></td>
                                                <td class="{{ $td }}"><span class="{{ $pill }} {{ $o['strategy'] !== '' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $o['strategy_label'] }}</span></td>
                                                <td class="{{ $td }}">@foreach($o['warnings'] as $w)<span class="{{ $pill }} {{ $variantPill['warning'] ?? $variantPill['secondary'] }}">{{ $w }}</span>@endforeach</td>
                                            </x-foodalchemist::table-row>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @empty
                            <div class="px-5 py-10 text-center text-gray-500">Keine Liefertage im Filter.</div>
                        @endforelse
                    </div>
                @elseif($sicht === 'lieferanten')
                    <div class="divide-y divide-black/5">
                        @forelse($lieferantGruppen as $gruppe)
                            <div class="px-5 py-3">
                                <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                                    <div>
                                        <div class="text-[13px] font-semibold text-gray-900">{{ $gruppe['supplier'] }}</div>
                                        <div class="text-[11px] text-gray-500">{{ $gruppe['dates'] }} Liefertage · {{ $gruppe['orders']->count() }} Bestellungen · {{ $gruppe['line_count'] }} Positionen</div>
                                    </div>
                                    <div class="text-[13px] font-semibold text-gray-900">{{ number_format($gruppe['total_net'], 2, ',', '.') }} €</div>
                                </div>
                                <table class="{{ $table }}">
                                    <thead><tr>
                                        <th class="{{ $th }} text-left">Beleg</th>
                                        <th class="{{ $th }} text-left">Datum</th>
                                        <th class="{{ $th }} text-left">Produktion / Anlass</th>
                                        <th class="{{ $th }} text-right">Pos.</th>
                                        <th class="{{ $th }} text-right">Netto</th>
                                        <th class="{{ $th }}">Status</th>
                                        <th class="{{ $th }}">Strategie</th>
                                        <th class="{{ $th }}">Hinweise</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($gruppe['orders'] as $o)
                                            <x-foodalchemist::table-row wire:key="supplier-{{ md5($gruppe['supplier']) }}-{{ $o['id'] }}" wire:click="oeffnen({{ $o['id'] }})">
                                                <td class="{{ $td }} text-gray-600 whitespace-nowrap">
                                                    <div class="font-medium text-gray-900">{{ $o['order_label'] }}</div>
                                                    @if($o['supplier_order_number'])<div class="text-[10px] text-gray-400">AB {{ $o['supplier_order_number'] }}</div>@endif
                                                    @if($o['invoice_number'])<div class="text-[10px] text-gray-400">RE {{ $o['invoice_number'] }}</div>@endif
                                                    @if($o['invoice_due_date'])<div class="text-[10px] text-amber-600">fällig {{ \Carbon\Carbon::parse($o['invoice_due_date'])->format('d.m.Y') }}</div>@endif
                                                    @if(($o['payment']['status'] ?? null))<div class="text-[10px] {{ ($o['payment']['state'] ?? '') === 'paid' ? 'text-emerald-600' : ((($o['payment']['state'] ?? '') === 'overdue') ? 'text-amber-600' : 'text-gray-400') }}">OP {{ $o['payment']['label'] }}</div>@endif
                                                    @if(($o['approval']['status'] ?? null))<div class="text-[10px] {{ ($o['approval']['state'] ?? '') === 'approved' ? 'text-emerald-600' : ((($o['approval']['state'] ?? '') === 'rejected') ? 'text-rose-600' : 'text-amber-600') }}">Freigabe {{ $o['approval']['label'] }}</div>@endif
                                                </td>
                                                <td class="{{ $td }} whitespace-nowrap tabular-nums text-gray-700">{{ $o['liefertag'] ? \Carbon\Carbon::parse($o['liefertag'])->format('d.m.Y') : '—' }}</td>
                                                <td class="{{ $td }} text-gray-600">
                                                    @if(!empty($o['herkunft']))
                                                        <div class="flex flex-wrap gap-1">
                                                            @foreach($o['herkunft'] as $h)
                                                                <span class="{{ $pill }} {{ $variantPill[($h['production_order_id'] ?? null) !== null ? 'primary' : ($h['type'] === 'concept' ? 'info' : 'secondary')] }}">{{ $h['label'] }}</span>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        {{ $o['reference'] ?: '—' }}
                                                    @endif
                                                </td>
                                                <td class="{{ $td }} text-right tabular-nums">{{ $o['line_count'] }}</td>
                                                <td class="{{ $td }} text-right tabular-nums">{{ number_format($o['total_net'], 2, ',', '.') }} €</td>
                                                <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $variantPill[$o['status']->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o['status']->label() }}</span></td>
                                                <td class="{{ $td }}"><span class="{{ $pill }} {{ $o['strategy'] !== '' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $o['strategy_label'] }}</span></td>
                                                <td class="{{ $td }}">@foreach($o['warnings'] as $w)<span class="{{ $pill }} {{ $variantPill['warning'] ?? $variantPill['secondary'] }}">{{ $w }}</span>@endforeach</td>
                                            </x-foodalchemist::table-row>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @empty
                            <div class="px-5 py-10 text-center text-gray-500">Keine Lieferanten im Filter.</div>
                        @endforelse
                    </div>
                @else
                <table class="{{ $table }}">
                    <thead><tr class="text-left">
                        <th class="{{ $th }} w-10 sticky top-0 z-20 bg-white/95 backdrop-blur-xl">
                            <input type="checkbox" wire:click="versandfaehigeAuswahlUmschalten" @checked($kpis['ready'] > 0 && count($selectedOrderIds) === $kpis['ready']) @disabled($kpis['ready'] === 0) aria-label="Alle versandfähigen Bestellungen auswählen" />
                        </th>
                        <th class="{{ $th }} whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Beleg</th>
                        <th class="{{ $th }} whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Bestelldatum</th>
                        <th class="{{ $th }} whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Liefertag</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Lieferant</th>
                        <th class="{{ $th }} w-full sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Produktion / Anlass</th>
                        <th class="{{ $th }} text-right whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Pos.</th>
                        <th class="{{ $th }} text-right whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Netto</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Status</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Strategie</th>
                        <th class="{{ $th }} sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Hinweise</th>
                    </tr></thead>
                    <tbody>
                        @if($liste->isEmpty())
                            <tr><td colspan="11" class="px-5 py-10 text-center text-gray-500">Keine Bestellungen. „Neue Bestellrunde" oben oder Bedarf aus der Produktion übergeben.</td></tr>
                        @else
                            @foreach($gruppen as $tag => $zeilen)
                                @if($gruppiert)
                                    <tr class="bg-black/[0.02]">
                                        <td colspan="11" class="px-5 py-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                            {{ $tag === '' ? 'Ohne Liefertag' : \Carbon\Carbon::parse($tag)->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
                                            <span class="text-gray-400">· {{ $zeilen->count() }}</span>
                                        </td>
                                    </tr>
                                @endif
                                @foreach($zeilen as $o)
                                    <x-foodalchemist::table-row wire:key="ord-{{ $o['id'] }}" wire:click="oeffnen({{ $o['id'] }})" data-orders-zeile="{{ $o['id'] }}">
                                        <td class="{{ $td }}" onclick="event.stopPropagation()">
                                            <input type="checkbox" wire:model.live="selectedOrderIds" value="{{ $o['id'] }}" @disabled($o['status'] !== \Platform\FoodAlchemist\Enums\OrderStatus::Draft) aria-label="ord-{{ $o['id'] }} auswählen" />
                                        </td>
                                        <td class="{{ $td }} text-gray-600 whitespace-nowrap">
                                            <div class="font-medium text-gray-900">{{ $o['order_label'] }}</div>
                                            @if($o['supplier_order_number'])<div class="text-[10px] text-gray-400">AB {{ $o['supplier_order_number'] }}</div>@endif
                                            @if($o['invoice_number'])<div class="text-[10px] text-gray-400">RE {{ $o['invoice_number'] }}</div>@endif
                                            @if($o['invoice_due_date'])<div class="text-[10px] text-amber-600">fällig {{ \Carbon\Carbon::parse($o['invoice_due_date'])->format('d.m.Y') }}</div>@endif
                                            @if(($o['payment']['status'] ?? null))<div class="text-[10px] {{ ($o['payment']['state'] ?? '') === 'paid' ? 'text-emerald-600' : ((($o['payment']['state'] ?? '') === 'overdue') ? 'text-amber-600' : 'text-gray-400') }}">OP {{ $o['payment']['label'] }}</div>@endif
                                            @if(($o['approval']['status'] ?? null))<div class="text-[10px] {{ ($o['approval']['state'] ?? '') === 'approved' ? 'text-emerald-600' : ((($o['approval']['state'] ?? '') === 'rejected') ? 'text-rose-600' : 'text-amber-600') }}">Freigabe {{ $o['approval']['label'] }}</div>@endif
                                        </td>
                                        <td class="{{ $td }} whitespace-nowrap tabular-nums text-gray-700">{{ $o['bestelldatum'] ? \Carbon\Carbon::parse($o['bestelldatum'])->format('d.m.Y') : '—' }}</td>
                                        <td class="{{ $td }} whitespace-nowrap tabular-nums text-gray-700">{{ $o['liefertag'] ? \Carbon\Carbon::parse($o['liefertag'])->format('d.m.Y') : '—' }}</td>
                                        <td class="{{ $td }} font-medium text-gray-900 whitespace-nowrap">{{ $o['supplier'] }}</td>
                                        <td class="{{ $td }} text-gray-600">
                                            @if(!empty($o['herkunft']))
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($o['herkunft'] as $h)
                                                        @if(($h['production_order_id'] ?? null) !== null)
                                                            <a href="{{ route('foodalchemist.produktion.index', ['auftrag' => $h['production_order_id']]) }}"
                                                               onclick="event.stopPropagation()"
                                                               class="{{ $pill }} {{ $variantPill['primary'] }} hover:underline"
                                                               title="{{ $h['key'] }}">{{ $h['label'] }} ↗</a>
                                                        @else
                                                            <span class="{{ $pill }} {{ $variantPill[$h['type'] === 'concept' ? 'info' : 'secondary'] }}" title="{{ $h['key'] }}">{{ $h['label'] }}</span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                                @if($o['reference'])
                                                    <div class="mt-1 text-[11px] text-gray-500">{{ $o['reference'] }}</div>
                                                @endif
                                            @else
                                                {{ $o['reference'] ?: '—' }}
                                            @endif
                                        </td>
                                        <td class="{{ $td }} text-right whitespace-nowrap tabular-nums text-gray-700">{{ $o['line_count'] }}</td>
                                        <td class="{{ $td }} text-right whitespace-nowrap tabular-nums text-gray-700">
                                            {{ number_format($o['total_net'], 2, ',', '.') }} €
                                            @if($o['line_count'] === 0)
                                                <div class="text-[10px] text-amber-600">leer</div>
                                            @elseif((float) $o['total_net'] === 0.0)
                                                <div class="text-[10px] text-amber-600">Preis/Klärung</div>
                                            @endif
                                        </td>
                                        <td class="{{ $td }}"><span class="{{ $pill }} font-medium {{ $variantPill[$o['status']->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o['status']->label() }}</span></td>
                                        <td class="{{ $td }}"><span class="{{ $pill }} {{ $o['strategy'] !== '' ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $o['strategy_label'] }}</span></td>
                                        <td class="{{ $td }}">@foreach($o['warnings'] as $w)<span class="{{ $pill }} {{ $variantPill['warning'] ?? $variantPill['secondary'] }}">{{ $w }}</span>@endforeach</td>
                                    </x-foodalchemist::table-row>
                                @endforeach
                            @endforeach
                        @endif
                    </tbody>
                </table>
                @endif
            </div>
        </div>

    </x-ui-page-container>
</x-ui-page>
