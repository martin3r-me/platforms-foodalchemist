{{-- Spec 17/S2 + Spec 20/E1 — Bestellungen: 3-Panel-Cockpit (Browser · Positionen · Detail) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($statusLabels = ['draft' => 'Entwurf', 'sent' => 'versendet', 'confirmed' => 'bestätigt', 'delivered' => 'geliefert', 'cancelled' => 'storniert'])

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

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">

        @if($hinweis)<div class="{{ $sectionCard }} !bg-emerald-500/[0.06] !border-emerald-500/20 text-[12px] text-emerald-700">✓ {{ $hinweis }}</div>@endif
        @if($fehler)<div class="{{ $sectionCard }} !bg-rose-500/[0.06] !border-rose-500/20 text-[12px] text-rose-700">{{ $fehler }}</div>@endif

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">

            {{-- ══ Panel 1 · Schienen-Browser ══ --}}
            <div class="{{ $sectionCard }} lg:col-span-3 space-y-3">
                <h3 class="font-medium tracking-tight text-gray-900">Schienen &amp; Bestellungen</h3>

                {{-- E2 · Direktbestellung (manuelle Artikel · neue Schiene · Bedarf-Schnellerfassung) --}}
                <div class="rounded-lg border border-violet-500/20 bg-violet-500/[0.03]">
                    <button type="button" wire:click="$toggle('direktOffen')"
                        class="w-full flex items-center justify-between px-3 py-2 text-[12px] font-medium text-violet-700">
                        <span>＋ Direktbestellung</span>
                        <span class="text-violet-400">{{ $direktOffen ? '−' : '+' }}</span>
                    </button>
                    @if($direktOffen)
                        <div class="px-3 pb-3 space-y-3 border-t border-violet-500/10 pt-2">

                            {{-- Neue Bestellung je Lieferant --}}
                            <div>
                                <span class="{{ $label }} block mb-1">Neue Bestellung</span>
                                <div class="flex gap-1">
                                    <select wire:model="neuerLieferant" class="{{ $input }}">
                                        <option value="">Lieferant…</option>
                                        @foreach($alleLieferanten as $l)
                                            <option value="{{ $l['id'] }}">{{ $l['name'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="neueBestellung" class="{{ $btnGhostXs }} shrink-0">anlegen</button>
                                </div>
                            </div>

                            {{-- ＋ Artikel: globale LA-Livesearch --}}
                            <div>
                                <span class="{{ $label }} block mb-1">Artikel direkt bestellen</span>
                                <input type="search" wire:model.live.debounce.300ms="artikelSuche" placeholder="Artikel / Art-Nr…" class="{{ $input }}" />
                                @if($artikelTreffer->isNotEmpty())
                                    <div class="mt-1 space-y-0.5 max-h-52 overflow-y-auto">
                                        @foreach($artikelTreffer as $a)
                                            <button type="button" wire:click="artikelHinzufuegen({{ $a['id'] }})" wire:key="art-{{ $a['id'] }}"
                                                class="block w-full text-left px-2 py-1 rounded hover:bg-black/[0.04]">
                                                <span class="text-[12px] text-gray-800">{{ $a['designation'] ?: '—' }}</span>
                                                <span class="text-[10px] text-gray-400 block">{{ $a['supplier'] }}@if($a['article_number']) · Art. {{ $a['article_number'] }}@endif</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif(mb_strlen(trim($artikelSuche)) >= 2)
                                    <p class="text-[11px] text-gray-400 mt-1">Kein Artikel gefunden.</p>
                                @endif
                            </div>

                            {{-- Bedarf-Schnellerfassung (Gericht/Basisrezept → addNeedFromTarget) --}}
                            <div>
                                <span class="{{ $label }} block mb-1">Bedarf aus Gericht/Basisrezept</span>
                                @if($bedarfRecipeId === null)
                                    <input type="search" wire:model.live.debounce.300ms="bedarfSuche" placeholder="Gericht / Basisrezept…" class="{{ $input }}" />
                                    @if($bedarfTreffer->isNotEmpty())
                                        <div class="mt-1 space-y-0.5 max-h-52 overflow-y-auto">
                                            @foreach($bedarfTreffer as $r)
                                                <button type="button" wire:click="bedarfRezeptWaehlen({{ $r['id'] }})" wire:key="brz-{{ $r['id'] }}"
                                                    class="flex items-center gap-1.5 w-full text-left px-2 py-1 rounded hover:bg-black/[0.04]">
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
                                        <div class="flex gap-1">
                                            <input type="number" min="0" step="0.1" wire:model="bedarfMenge" placeholder="Menge" class="{{ $input }}" />
                                            @if($bedarfRecipeVk)
                                                <span class="inline-flex items-center px-2 text-[11px] text-gray-500 whitespace-nowrap">Portionen</span>
                                            @else
                                                <select wire:model="bedarfEinheit" class="{{ $input }} !w-auto">
                                                    <option value="ansaetze">Ansätze</option>
                                                    <option value="kg">kg</option>
                                                </select>
                                            @endif
                                        </div>
                                        <button type="button" wire:click="bedarfUebernehmen" class="{{ $btnGhostXs }} w-full justify-center">Bedarf übernehmen</button>
                                    </div>
                                @endif
                            </div>

                        </div>
                    @endif
                </div>

                {{-- Status-Filter --}}
                <div>
                    <span class="{{ $label }} block mb-1">Status</span>
                    <div class="inline-flex flex-wrap rounded-lg bg-black/[0.03] p-0.5 text-xs">
                        <button wire:click="$set('statusFilter','')" class="px-2.5 py-1 rounded-md {{ $statusFilter === '' ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">alle</button>
                        @foreach(['draft','sent','confirmed','delivered','cancelled'] as $s)
                            <button wire:click="$set('statusFilter','{{ $s }}')" class="px-2.5 py-1 rounded-md {{ $statusFilter === $s ? 'bg-white shadow-sm text-violet-600' : 'text-gray-600' }}">{{ $statusLabels[$s] }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Lieferant-Filter + Suche --}}
                <div class="grid grid-cols-1 gap-2">
                    <div>
                        <span class="{{ $label }} block mb-1">Lieferant</span>
                        <select wire:model.live="supplierFilter" class="{{ $input }}">
                            <option value="">alle Lieferanten</option>
                            @foreach($lieferanten as $l)
                                <option value="{{ $l['id'] }}">{{ $l['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <span class="{{ $label }} block mb-1">Suche</span>
                        <input type="search" wire:model.live.debounce.300ms="suche" placeholder="Lieferant / Anlass…" class="{{ $input }}" />
                    </div>
                </div>

                <div class="space-y-1">
                    @forelse($liste as $o)
                        <button wire:click="select({{ $o['id'] }})" wire:key="ord-{{ $o['id'] }}"
                            class="block w-full text-left px-3 py-2 rounded-lg border {{ $selectedId === $o['id'] ? 'border-violet-500/40 bg-violet-500/5' : 'border-black/5 hover:bg-black/[0.02]' }}">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[13px] font-medium text-gray-900">{{ $o['supplier'] }}</span>
                                <span class="{{ $pill }} {{ $variantPill[$o['status']->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $o['status']->label() }}</span>
                            </div>
                            <div class="text-[11px] text-gray-500 mt-0.5">{{ number_format($o['total_net'], 2, ',', '.') }} € netto @if($o['reference'])· {{ $o['reference'] }}@endif</div>
                        </button>
                    @empty
                        <p class="text-[12px] text-gray-500 py-6 text-center">Keine Bestellungen. Bedarf im Planungs-Blatt übernehmen.</p>
                    @endforelse
                </div>
            </div>

            {{-- ══ Panel 2 · Positionen ══ --}}
            <div class="{{ $sectionCard }} lg:col-span-6">
                @if($detail === null)
                    <p class="text-[12px] text-gray-500 py-10 text-center">Eine Bestellschiene links wählen.</p>
                @else
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <h3 class="font-medium tracking-tight text-gray-900">Positionen</h3>
                        <span class="text-[11px] text-gray-500">{{ count($detail['zeilen']) }} Artikel</span>
                    </div>

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
                                        {{-- Herkunfts-Badges --}}
                                        @if(!empty($z['herkunft']))
                                            <div class="flex flex-wrap gap-1 mt-1">
                                                @foreach($z['herkunft'] as $h)
                                                    <span class="{{ $pill }} {{ $variantPill[$h['type'] === 'produktion' ? 'primary' : ($h['type'] === 'concept' ? 'info' : 'secondary')] }}" title="{{ $h['ref'] }}">{{ $h['label'] }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                        {{-- Zeilen-Notiz --}}
                                        @if($detail['editierbar'])
                                            <input type="text" value="{{ $z['note'] }}" placeholder="Notiz…"
                                                wire:change="updateLineNote({{ $z['id'] }}, $event.target.value)"
                                                class="mt-1 {{ $input }} !py-1 !text-[11px]" />
                                        @elseif($z['note'])
                                            <div class="text-[10px] text-gray-500 mt-1 italic">{{ $z['note'] }}</div>
                                        @endif
                                        {{-- E3b · Alternativ-Artikel (nur Bedarfs-Zeilen mit GP; leere Direktbestellungen ohne GP haben keine Ausweichquelle) --}}
                                        @if($detail['editierbar'] && $z['gp_id'] !== null)
                                            <button type="button" wire:click="alternativenUmschalten({{ $z['id'] }})"
                                                class="mt-1 text-[10px] text-violet-600 hover:underline">
                                                {{ $altLineId === $z['id'] ? '▾ Ausweichquelle schließen' : '⇄ Ausweichquelle' }}
                                            </button>
                                            @if($altLineId === $z['id'])
                                                <div class="mt-1 rounded-md border border-violet-500/20 bg-violet-500/[0.03] p-1.5 space-y-0.5">
                                                    @forelse($alternativen as $alt)
                                                        <button type="button" wire:key="alt-{{ $z['id'] }}-{{ $alt['la_id'] }}"
                                                            wire:click="alternativeWaehlen({{ $z['id'] }}, {{ $alt['la_id'] }})"
                                                            @if($alt['schiene_wechsel']) onclick="return confirm('Anderer Lieferant ({{ $alt['supplier'] }}) — die Position wandert in dessen Bestellschiene. Fortfahren?')" @endif
                                                            @disabled($alt['gesperrt'])
                                                            class="block w-full text-left px-1.5 py-1 rounded hover:bg-black/[0.04] {{ $alt['gesperrt'] ? 'opacity-40 cursor-not-allowed' : '' }}">
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
                                            @if($z['is_manual_qty'])<button wire:click="resetLineQty({{ $z['id'] }})" title="Auto-Menge" class="text-[10px] text-violet-600 ml-1">auto</button>@endif
                                        @else
                                            {{ (float) $z['qty_packs'] }}
                                        @endif
                                        @if($z['packaging_unit'])<span class="text-[10px] text-gray-400"> {{ $z['packaging_unit'] }}</span>@endif
                                    </td>
                                    <td class="{{ $td }} text-right whitespace-nowrap text-gray-700">{{ $z['pack_price'] !== null ? number_format($z['pack_price'], 2, ',', '.') . ' €' : '—' }}</td>
                                    <td class="{{ $td }} text-right whitespace-nowrap font-medium text-gray-900">{{ number_format($z['line_total'], 2, ',', '.') }} €</td>
                                    <td class="{{ $td }} text-right">
                                        @if($detail['editierbar'])<button wire:click="removeLine({{ $z['id'] }})" title="Entfernen" class="text-[11px] text-rose-500">✕</button>@endif
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
                @endif
            </div>

            {{-- ══ Panel 3 · Detail / Aktionen ══ --}}
            <div class="{{ $sectionCard }} lg:col-span-3 space-y-4">
                @if($detail === null)
                    <p class="text-[12px] text-gray-500 py-10 text-center">Kein Beleg gewählt.</p>
                @else
                    <div>
                        <h3 class="font-medium tracking-tight text-gray-900">{{ $detail['supplier'] }}</h3>
                        <p class="text-[11px] text-gray-500">{{ $detail['status_label'] }} · {{ number_format($detail['total_net'], 2, ',', '.') }} € netto</p>
                    </div>

                    {{-- Status-Buttons --}}
                    @if($erlaubteStatus)
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($erlaubteStatus as $z)
                                <button wire:click="setStatus('{{ $z->value }}')"
                                    class="{{ $z->value === 'sent' ? $btnPrimary : $btnGhost }}"
                                    @if($z->value === 'cancelled') onclick="return confirm('Bestellung stornieren?')" @endif
                                    data-status-{{ $z->value }}>{{ $z === \Platform\FoodAlchemist\Enums\OrderStatus::Sent ? 'Absenden' : $z->label() }}</button>
                            @endforeach
                        </div>
                    @endif

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
                            <span class="px-2 py-0.5 rounded-md bg-black/5 text-gray-600">{{ number_format($moq['fehlt_bis_frei_haus'], 2, ',', '.') }} € bis frei Haus</span>
                        @endif
                    </div>

                    {{-- Kopf-Felder (nur im offenen Entwurf editierbar) --}}
                    @if($detail['editierbar'])
                        <div class="space-y-2 pt-2 border-t border-black/5">
                            <span class="{{ $label }} block">Liefer-Logistik &amp; Anlass</span>
                            <div>
                                <label class="text-[10px] text-gray-500">Anlass / Referenz</label>
                                <input type="text" wire:model="formReference" class="{{ $input }}" placeholder="z. B. Sommerfest" />
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-500">Wunsch-Liefertermin</label>
                                <input type="date" wire:model="formDeliveryDate" class="{{ $input }}" />
                            </div>
                            <div>
                                <label class="text-[10px] text-gray-500">Notiz</label>
                                <textarea wire:model="formNote" rows="2" class="{{ $input }}" placeholder="interne Notiz…"></textarea>
                            </div>
                            <button wire:click="saveHeader" class="{{ $btnGhost }}">Kopf speichern</button>
                        </div>
                    @else
                        <div class="space-y-1 pt-2 border-t border-black/5 text-[11px] text-gray-600">
                            @if($detail['reference'])<div><span class="text-gray-400">Anlass:</span> {{ $detail['reference'] }}</div>@endif
                            @if($detail['desired_delivery_date'])<div><span class="text-gray-400">Liefertermin:</span> {{ $detail['desired_delivery_date'] }}</div>@endif
                            @if($detail['note'])<div><span class="text-gray-400">Notiz:</span> {{ $detail['note'] }}</div>@endif
                        </div>
                    @endif

                    {{-- E3b · Preisstrategie + „Neu quellen" (nur offener Entwurf) --}}
                    @if($detail['editierbar'])
                        <div class="space-y-2 pt-2 border-t border-black/5">
                            <span class="{{ $label }} block">Preisstrategie</span>
                            <select wire:model="formStrategy" class="{{ $input }}">
                                <option value="">Haupteinstellung (Team)</option>
                                @foreach($strategieOptionen as $s)
                                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                @endforeach
                            </select>
                            @if($resourceVorschau === null)
                                <button wire:click="neuQuellenVorschau" class="{{ $btnGhost }}" data-neu-quellen-vorschau>Neu quellen (Vorschau)</button>
                            @else
                                <div class="rounded-md border border-violet-500/20 bg-violet-500/[0.03] p-2 space-y-1">
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
                                        <button wire:click="neuQuellenAnwenden" class="{{ $btnPrimary }}" @disabled(empty($resourceVorschau['wechsel'])) data-neu-quellen-anwenden>Anwenden</button>
                                        <button wire:click="neuQuellenAbbrechen" class="{{ $btnGhost }}">Abbrechen</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @elseif($detail['sourcing_strategy'])
                        <div class="pt-2 border-t border-black/5 text-[11px] text-gray-600">
                            <span class="text-gray-400">Preisstrategie:</span> {{ $detail['sourcing_strategy'] }}
                        </div>
                    @endif

                    {{-- Herkunft (Schienen-Aggregat, mit Links) --}}
                    @if(!empty($detail['herkunft']))
                        <div class="space-y-1 pt-2 border-t border-black/5">
                            <span class="{{ $label }} block">Herkunft</span>
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
                        </div>
                    @endif

                    {{-- Export / Versand --}}
                    <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-black/5">
                        <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id']]) }}" target="_blank" class="{{ $btnGhostXs }}">🖨 Dokument</a>
                        <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'pdf' => 1]) }}" class="{{ $btnGhostXs }}">PDF</a>
                        <a href="{{ route('foodalchemist.orders.dokument', ['order' => $detail['id'], 'csv' => 1]) }}" class="{{ $btnGhostXs }}">CSV</a>
                        @if($mailto)
                            <a href="{{ $mailto }}" class="{{ $btnGhostXs }}">✉ E-Mail</a>
                        @else
                            <span class="text-[10px] text-gray-400">✉ keine Bestell-Mail (Lieferant → email_order)</span>
                        @endif
                    </div>
                @endif
            </div>

        </div>

    </x-ui-page-container>
</x-ui-page>
