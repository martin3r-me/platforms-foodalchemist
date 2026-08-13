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

    {{-- Fullscreen-Editor (pro Bestellung), geöffnet per orders-editor.bearbeiten --}}
    <livewire:foodalchemist.orders.editor />

    <x-ui-page-container padding="px-6 pt-4 pb-6" spacing="space-y-4">

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        {{-- Neue Bestellung: neutral öffnen; Lieferant entsteht erst aus Artikel/Bedarf. --}}
        <div class="flex flex-wrap items-end gap-2">
            <div>
                <span class="{{ $label }} block mb-1">Neue Bestellung</span>
                <div class="flex flex-wrap gap-1">
                    <input type="date" wire:model="neuerLiefertag" class="{{ $input }}" title="Liefertag" />
                    <select wire:model="neueStrategie" class="{{ $input }}" title="Einkaufsstrategie">
                        <option value="">Team-Standard</option>
                        @foreach($strategieOptionen as $s)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="neueBestellung" class="{{ $btnPrimary }} shrink-0" data-orders-neu>+ Bestellung öffnen</button>
                </div>
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

        {{-- Filter: Datumsachse · Zeitraum · Fenster · Status · Suche · Lieferant (sekundär) --}}
        <div class="flex flex-wrap items-end gap-x-4 gap-y-2">
            <div>
                <span class="{{ $label }} block mb-1">Datumsachse</span>
                <div class="inline-flex rounded-lg bg-black/[0.03] p-0.5 text-xs">
                    <button type="button" wire:click="$set('datumsbasis','liefertag')" class="px-2.5 py-1 rounded-md {{ $datumsbasis === 'liefertag' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Liefertag</button>
                    <button type="button" wire:click="$set('datumsbasis','bestelldatum')" class="px-2.5 py-1 rounded-md {{ $datumsbasis === 'bestelldatum' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">Bestelldatum</button>
                </div>
            </div>
            <div>
                <span class="{{ $label }} block mb-1">Zeitraum</span>
                <div class="inline-flex flex-wrap rounded-lg bg-black/[0.03] p-0.5 text-xs">
                    @foreach($zeitraeume as $key => $lbl)
                        <button type="button" wire:click="waehleZeitraum('{{ $key }}')" class="px-2.5 py-1 rounded-md {{ $zeitraum === $key ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">{{ $lbl }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <span class="{{ $label }} block mb-1">von</span>
                <input type="date" wire:model.live="von" class="{{ $input }}" />
            </div>
            <div>
                <span class="{{ $label }} block mb-1">bis</span>
                <input type="date" wire:model.live="bis" class="{{ $input }}" />
            </div>
            <div>
                <span class="{{ $label }} block mb-1">Status</span>
                <div class="inline-flex flex-wrap rounded-lg bg-black/[0.03] p-0.5 text-xs">
                    <button type="button" wire:click="$set('statusFilter','')" class="px-2.5 py-1 rounded-md {{ $statusFilter === '' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">alle</button>
                    @foreach(['draft','sent','confirmed','delivered','cancelled'] as $s)
                        <button type="button" wire:click="$set('statusFilter','{{ $s }}')" class="px-2.5 py-1 rounded-md {{ $statusFilter === $s ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">{{ $statusLabels[$s] }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <span class="{{ $label }} block mb-1">Suche</span>
                <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Lieferant / Produktion / Anlass…" class="{{ $input }}" />
            </div>
            <div>
                <span class="{{ $label }} block mb-1">Lieferant</span>
                <select wire:model.live="supplierFilter" class="{{ $input }}">
                    <option value="">alle Lieferanten</option>
                    @foreach($lieferanten as $l)
                        <option value="{{ $l['id'] }}">{{ $l['name'] }}</option>
                    @endforeach
                </select>
            </div>
            @if($produktionen->isNotEmpty())
                <div>
                    <span class="{{ $label }} block mb-1">Produktion</span>
                    <select wire:model.live="productionFilter" class="{{ $input }}">
                        <option value="">alle Produktionen</option>
                        @foreach($produktionen as $p)
                            <option value="{{ $p['id'] }}">{{ $p['label'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <label class="inline-flex items-center gap-2 pb-2 text-[12px] text-gray-600">
                <input type="checkbox" wire:model.live="nurMitPositionen" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
                nur mit Positionen
            </label>
            <label class="inline-flex items-center gap-2 pb-2 text-[12px] text-gray-600">
                <input type="checkbox" wire:model.live="nurMitKlaerung" class="rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
                nur mit Klärung
            </label>
            <button type="button" wire:click="leereEntwuerfeLoeschen"
                onclick="return confirm('Alle leeren Entwürfe ohne Positionen löschen?')"
                class="{{ $btnGhostXs }} mb-1">Leere Entwürfe löschen</button>
        </div>

        <div class="flex flex-wrap gap-2 text-[12px]">
            @foreach(['bestellungen' => 'Bestellungen', 'liefertage' => 'Liefertage', 'lieferanten' => 'Lieferanten'] as $key => $lbl)
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
                <h3 class="font-medium tracking-tight text-gray-900">{{ ['bestellungen' => 'Bestellungen finden', 'liefertage' => 'Nach Liefertag planen', 'lieferanten' => 'Nach Lieferant bündeln'][$sicht] ?? 'Bestellungen' }}</h3>
                <span class="{{ $label }}">{{ number_format($liste->count(), 0, ',', '.') }} Treffer</span>
            </div>
            <div class="max-h-[70vh] overflow-auto">
                @if($sicht === 'liefertage')
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
                        <th class="{{ $th }} whitespace-nowrap sticky top-0 z-20 bg-white/95 backdrop-blur-xl">Beleg</th>
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
                            <tr><td colspan="9" class="px-5 py-10 text-center text-gray-500">Keine Bestellungen. „Neue Bestellung" oben oder Bedarf aus der Produktion übergeben.</td></tr>
                        @else
                            @foreach($gruppen as $tag => $zeilen)
                                @if($gruppiert)
                                    <tr class="bg-black/[0.02]">
                                        <td colspan="9" class="px-5 py-1.5 text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                            {{ $tag === '' ? 'Ohne Liefertag' : \Carbon\Carbon::parse($tag)->locale('de')->isoFormat('dddd, DD.MM.YYYY') }}
                                            <span class="text-gray-400">· {{ $zeilen->count() }}</span>
                                        </td>
                                    </tr>
                                @endif
                                @foreach($zeilen as $o)
                                    <x-foodalchemist::table-row wire:key="ord-{{ $o['id'] }}" wire:click="oeffnen({{ $o['id'] }})" data-orders-zeile="{{ $o['id'] }}">
                                        <td class="{{ $td }} text-gray-600 whitespace-nowrap">
                                            <div class="font-medium text-gray-900">{{ $o['order_label'] }}</div>
                                            @if($o['supplier_order_number'])<div class="text-[10px] text-gray-400">AB {{ $o['supplier_order_number'] }}</div>@endif
                                            @if($o['invoice_number'])<div class="text-[10px] text-gray-400">RE {{ $o['invoice_number'] }}</div>@endif
                                            @if($o['invoice_due_date'])<div class="text-[10px] text-amber-600">fällig {{ \Carbon\Carbon::parse($o['invoice_due_date'])->format('d.m.Y') }}</div>@endif
                                            @if(($o['payment']['status'] ?? null))<div class="text-[10px] {{ ($o['payment']['state'] ?? '') === 'paid' ? 'text-emerald-600' : ((($o['payment']['state'] ?? '') === 'overdue') ? 'text-amber-600' : 'text-gray-400') }}">OP {{ $o['payment']['label'] }}</div>@endif
                                            @if(($o['approval']['status'] ?? null))<div class="text-[10px] {{ ($o['approval']['state'] ?? '') === 'approved' ? 'text-emerald-600' : ((($o['approval']['state'] ?? '') === 'rejected') ? 'text-rose-600' : 'text-amber-600') }}">Freigabe {{ $o['approval']['label'] }}</div>@endif
                                        </td>
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
