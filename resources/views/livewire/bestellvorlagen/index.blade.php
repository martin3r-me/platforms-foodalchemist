{{-- Spec 68 · Bestellvorlagen: gespeicherte Bestellrunde (Grundprodukte, Rezepte/Gerichte, feste Artikel). --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $typText = ['gp' => 'Grundprodukt', 'recipe' => 'Rezept', 'supplier_item' => 'Artikel'];
    $typTon = ['gp' => 'neutral', 'recipe' => 'info', 'supplier_item' => 'warn'];
    $zahl = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',') ?: '0';
    $segment = 'h-7 px-2.5 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-sm)] font-medium transition-colors duration-150';
@endphp

<x-ui-page>
    <x-slot name="navbar">
        <x-foodalchemist::shell.page-navbar title="Bestellvorlagen" icon="heroicon-o-document-duplicate" />
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Bestellvorlagen" subtitle="Wiederkehrende Bestellungen und Musterproduktionen. Grundprodukte wählen ihren Artikel beim Bestellen nach Strategie, Rezepte werden über die Rezeptur in Bedarf aufgelöst." />

        @if($fehler)<x-fa::notice tone="crit" data-vorlagen-fehler>{{ $fehler }}</x-fa::notice>@endif
        @if($hinweis)<x-fa::notice tone="info">{{ $hinweis }}</x-fa::notice>@endif

        <div class="grid gap-4 lg:grid-cols-[minmax(17rem,22rem)_minmax(0,1fr)] items-start">
            {{-- Liste --}}
            <x-fa::section title="Vorlagen" icon="heroicon-o-document-duplicate" :meta="$vorlagen->count()" data-vorlagen-liste>
                <form wire:submit="anlegen" class="flex items-end gap-2">
                    <x-fa::input wire:model="neuName" size="sm" placeholder="z. B. Montag Molkerei" class="flex-1" aria-label="Name der neuen Vorlage" data-vorlage-neu />
                    <x-fa::button size="sm" type="submit" icon="heroicon-m-plus">Anlegen</x-fa::button>
                </form>
                @if($vorlagen->isEmpty())
                    <p class="{{ $leise }}">Noch keine Vorlagen. Auch aus einer Bestellrunde oder Bestellung lässt sich eine Vorlage speichern.</p>
                @else
                    <ul class="flex flex-col divide-y divide-[var(--fa-line)]">
                        @foreach($vorlagen as $v)
                            <li wire:key="v-{{ $v->id }}">
                                <button type="button" wire:click="waehlen({{ $v->id }})" class="w-full text-left py-2 px-2 rounded-[var(--fa-radius-control)] hover:bg-[var(--fa-hover)] {{ $vorlage?->id === $v->id ? 'bg-[var(--fa-ground)]' : '' }}">
                                    <div class="font-medium text-[var(--fa-ink)]">{{ $v->name }}</div>
                                    <div class="{{ $leise }}">{{ $v->lines_count }} Positionen{{ $v->weekday ? ' · ' . $wochentage[$v->weekday] : '' }}{{ $v->last_used_at ? ' · zuletzt ' . $v->last_used_at->format('d.m.') : '' }}</div>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-fa::section>

            {{-- Detail --}}
            @if($vorlage === null)
                <x-fa::empty icon="heroicon-o-document-duplicate" title="Vorlage wählen oder anlegen">Eine Vorlage kann Grundprodukte (Menge in kg/Stück), Rezepte und Gerichte (Portionen, Ansätze) und feste Artikel (Gebinde) enthalten.</x-fa::empty>
            @else
                <div class="flex flex-col gap-4 min-w-0">
                    <x-fa::section :title="$vorlage->name" icon="heroicon-o-document-text" data-vorlage="{{ $vorlage->id }}">
                        <x-slot:actions>
                            <x-fa::button size="sm" variant="danger" icon="heroicon-m-trash" wire:click="loeschen" wire:confirm="Vorlage löschen?">Löschen</x-fa::button>
                        </x-slot:actions>
                        <div class="grid gap-3 md:grid-cols-3">
                            <x-fa::field label="Name" for="v-name"><input id="v-name" type="text" value="{{ $vorlage->name }}" wire:change="feldSetzen('name', $event.target.value)" class="fa-control h-9 w-full" /></x-fa::field>
                            <x-fa::field label="Üblicher Bestelltag" for="v-tag">
                                <select id="v-tag" wire:change="feldSetzen('weekday', $event.target.value)" class="fa-control fa-select h-9 pr-8 w-full">
                                    <option value="">–</option>
                                    @foreach($wochentage as $n => $t)<option value="{{ $n }}" @selected($vorlage->weekday === $n)>{{ $t }}</option>@endforeach
                                </select>
                            </x-fa::field>
                            <x-fa::field label="Notiz" for="v-notiz"><input id="v-notiz" type="text" value="{{ $vorlage->note }}" wire:change="feldSetzen('note', $event.target.value)" class="fa-control h-9 w-full" placeholder="optional" /></x-fa::field>
                        </div>

                        @if($vorlage->lines->isEmpty())
                            <p class="{{ $leise }}">Noch keine Positionen — unten hinzufügen.</p>
                        @else
                            <div class="overflow-x-auto">
                                <table class="fa-table fa-table--compact min-w-[640px]" data-vorlage-positionen>
                                    <thead><tr><th>Art</th><th>Position</th><th class="text-right">Menge</th><th>Einheit</th><th></th></tr></thead>
                                    <tbody>
                                        @foreach($vorlage->lines as $l)
                                            <tr wire:key="vl-{{ $l->id }}">
                                                <td><x-fa::badge :tone="$typTon[$l->type] ?? 'neutral'">{{ $typText[$l->type] ?? $l->type }}</x-fa::badge></td>
                                                <td>
                                                    <div class="font-medium">{{ $l->bezeichnung() }}</div>
                                                    @if($l->type === 'supplier_item' && $l->supplierItem?->supplier)<div class="{{ $leise }}">{{ $l->supplierItem->supplier->name }}</div>@endif
                                                    @if($l->type === 'recipe')<div class="{{ $leise }}">{{ $l->recipe?->is_sales_recipe ? 'Gericht' : 'Basisrezept' }} — Bedarf aus der Rezeptur</div>@endif
                                                </td>
                                                <td class="text-right"><input type="text" inputmode="decimal" value="{{ str_replace('.', ',', $zahl($l->qty)) }}" wire:change="positionMenge({{ $l->id }}, $event.target.value)" class="fa-control h-7 w-20 text-right tabular-nums text-[length:var(--fa-text-sm)]" aria-label="Menge" /></td>
                                                <td>
                                                    <select wire:change="positionEinheit({{ $l->id }}, $event.target.value)" class="fa-control fa-select h-7 pr-8 text-[length:var(--fa-text-sm)]" aria-label="Einheit">
                                                        @foreach($einheiten[$l->type] ?? [] as $ek => $et)<option value="{{ $ek }}" @selected($l->unit === $ek)>{{ $et }}</option>@endforeach
                                                    </select>
                                                </td>
                                                <td class="text-right"><x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash" label="Position entfernen" wire:click="positionEntfernen({{ $l->id }})" /></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        {{-- Position hinzufügen --}}
                        <div class="flex flex-col gap-2 pt-1" data-vorlage-hinzu>
                            <div class="inline-flex items-center gap-0.5 p-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] self-start">
                                @foreach(['gp' => 'Grundprodukt', 'recipe' => 'Rezept / Gericht', 'supplier_item' => 'Fester Artikel'] as $k => $t)
                                    <button type="button" wire:click="$set('suchArt', '{{ $k }}')" class="{{ $segment }} {{ $suchArt === $k ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}">{{ $t }}</button>
                                @endforeach
                            </div>
                            <x-fa::input type="search" size="sm" wire:model.live.debounce.300ms="suche" :placeholder="['gp' => 'Grundprodukt suchen …', 'recipe' => 'Gericht oder Basisrezept suchen …', 'supplier_item' => 'Artikel oder Artikelnummer …'][$suchArt]" class="w-80" aria-label="Position suchen" data-vorlage-suche />
                            @foreach($treffer as $t)
                                <button type="button" wire:key="t-{{ $suchArt }}-{{ $t['id'] }}" wire:click="positionHinzu({{ $t['id'] }})" class="self-start inline-flex items-center gap-1 text-[length:var(--fa-text-md)] text-[var(--fa-accent)] hover:underline">@svg('heroicon-m-plus', 'w-3.5 h-3.5') {{ $t['name'] }}@if($t['zusatz'])<span class="{{ $leise }}">· {{ $t['zusatz'] }}</span>@endif</button>
                            @endforeach
                        </div>
                    </x-fa::section>

                    {{-- Bestellen --}}
                    <x-fa::section title="Bestellen" icon="heroicon-o-shopping-cart" description="„In Bestellrunde öffnen“ zeigt Artikel, Gebinde und Preise je Lieferant zum Prüfen und Anpassen. „Direkt anlegen“ legt die Entwürfe ohne Zwischenschritt an." data-vorlage-bestellen>
                        <div class="flex flex-wrap items-end gap-2">
                            <x-fa::field label="Liefertag" for="v-liefertag"><x-fa::input id="v-liefertag" type="date" wire:model.live="liefertag" /></x-fa::field>
                            <x-fa::button icon="heroicon-m-eye" wire:click="vorschau" :disabled="$vorlage->lines->isEmpty()" data-vorlage-vorschau>Vorschau</x-fa::button>
                            <x-fa::button variant="primary" icon="heroicon-m-shopping-cart" wire:click="bestellen" :disabled="$vorlage->lines->isEmpty()" data-vorlage-bestellrunde>In Bestellrunde öffnen</x-fa::button>
                            <x-fa::button variant="ghost" wire:click="direktAnlegen" wire:confirm="Bestell-Entwürfe direkt anlegen?" :disabled="$vorlage->lines->isEmpty()" data-vorlage-direkt>Direkt anlegen</x-fa::button>
                        </div>
                        @if($vorschau !== null)
                            @foreach($vorschau['orders_preview'] as $g)
                                <div class="rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] p-2.5" wire:key="vg-{{ $g['supplier_id'] }}-{{ $g['delivery_date'] }}">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="font-semibold">{{ $g['supplier'] }}</span>
                                        <span class="tabular-nums font-semibold"><x-fa::money :value="$g['total_net']" /></span>
                                    </div>
                                    <table class="fa-table fa-table--compact mt-1">
                                        <tbody>
                                            @foreach($g['positionen'] as $i => $p)
                                                <tr wire:key="vp-{{ $g['supplier_id'] }}-{{ $i }}">
                                                    <td>{{ $p['designation'] ?? '–' }}@if(! empty($p['source_label']) && ($p['source_label'] !== ($p['designation'] ?? null)))<div class="{{ $leise }}">aus {{ $p['source_label'] }}</div>@endif</td>
                                                    <td class="text-right tabular-nums">{{ $zahl($p['qty_packs'] ?? 0) }} Gebinde</td>
                                                    <td class="text-right"><x-fa::money :value="$p['line_total'] ?? null" missing="kein Preis" /></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endforeach
                            @foreach($vorschau['unresolved'] as $u)
                                <x-fa::notice tone="warn">{{ $u['label'] }}: {{ $u['message'] }}</x-fa::notice>
                            @endforeach
                            @if($vorschau['orders_preview'] === [] && $vorschau['unresolved'] === [])
                                <p class="{{ $leise }}">Nichts zu bestellen.</p>
                            @endif
                        @endif
                    </x-fa::section>
                </div>
            @endif
        </div>
    </x-ui-page-container>

    {{-- Bestellrunde (derselbe Werkbank-Editor wie unter Bestellungen) --}}
    <livewire:foodalchemist.orders.editor key="vorlagen-orders-editor" />
</x-ui-page>
