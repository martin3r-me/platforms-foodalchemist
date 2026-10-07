{{-- Pakete (M10-02 / Doc 15 §M10): Bündel mehrerer Gerichte mit eigenem Preis je Person.
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen und Feldnamen
     unverändert. Neu: Hauptaktion im Seitenkopf, Rollen als Filterzeilen mit „Alle", Preismodus und Niveau als
     Chips, veralteter Preis ganz oben im Detail, Löschen getrennt von Speichern. --}}
@php
    $niveauLabel = ['haute' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'];
    $modusLabel = ['auto' => 'aus Gerichten', 'fixed' => 'fixiert', 'manuell' => 'von Hand'];
    $geld = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Pakete" icon="heroicon-o-puzzle-piece" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Pakete'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Rollen" width="w-72">
            <div class="p-3 flex flex-col gap-3">
                <div class="relative">
                    <label for="pakete-suche" class="sr-only">Pakete durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="pakete-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Paket suchen" class="pl-8" />
                </div>
                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                    <p class="px-2.5 pb-1 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-3)]">Rolle</p>
                    <x-foodalchemist::filter-row wire:click="$set('rolleFilter', '')" :active="$rolleFilter === ''">Alle Rollen</x-foodalchemist::filter-row>
                    @foreach($rollen as $role)
                        <x-foodalchemist::filter-row wire:key="role-{{ $loop->index }}" wire:click="$set('rolleFilter', @js($role))" :active="$rolleFilter === $role">{{ $role }}</x-foodalchemist::filter-row>
                    @endforeach
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Paket" width="w-96" :maxWidth="640" scope="activity_pakete" side="right">
            @if($selected)
                @php
                    $nGerichte = $selected->dishes->count();
                    $nOhneVk = $selected->dishes->filter(fn ($g) => $g->dish === null || $g->dish->sales_net === null)->count();
                    $sumVk = $selected->dishes->sum(fn ($g) => (float) ($g->dish?->sales_net ?? 0));
                    $istAuto = $form['price_mode'] === 'auto';

                    // Kennzahlen (höchstens 3): Paketpreis ist die Hauptzahl; die Zahl der Gerichte steht am Abschnitt „Gerichte im Paket".
                    $kennzahlen = [
                        $selected->price_per_person !== null
                            ? ['label' => 'Paket €/Person', 'value' => $geld($selected->price_per_person), 'primary' => true]
                            : ['label' => 'Paket €/Person', 'value' => 'Preis fehlt', 'tone' => 'crit'],
                        $selected->food_cost_percent !== null
                            ? ['label' => 'Wareneinsatz', 'value' => number_format((float) $selected->food_cost_percent, 1, ',', '.') . ' %']
                            : ['label' => 'Wareneinsatz', 'value' => 'fehlt', 'tone' => 'crit'],
                        $nGerichte === 0
                            ? ['label' => 'Summe Gerichte-VK', 'value' => '–']
                            : ($nOhneVk === $nGerichte
                                ? ['label' => 'Summe Gerichte-VK', 'value' => 'Preis fehlt', 'tone' => 'crit']
                                : ['label' => 'Summe Gerichte-VK', 'value' => $geld($sumVk)] + ($nOhneVk > 0 ? ['hint' => 'unvollständig', 'hint_title' => $nOhneVk === 1 ? 'Einem Gericht fehlt der Verkaufspreis' : $nOhneVk . ' Gerichten fehlt der Verkaufspreis'] : [])),
                    ];
                @endphp
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" wire:key="edit-{{ $selected->id }}">
                    {{-- Kopf (Anatomie Detail-Panels): Name, Einordnung, eine Hauptaktion (Speichern, das Detail ist hier der Editor), Löschen im Menü --}}
                    <x-fa::detail-kopf :title="$selected->name" :subtitle="$selected->role ? 'Rolle: ' . $selected->role : 'Ohne Rolle'">
                        <x-slot:badges>
                            <x-fa::badge tone="info" icon="heroicon-m-puzzle-piece">Paket</x-fa::badge>
                            @if($selected->level)<x-fa::badge title="Niveau">{{ $niveauLabel[$selected->level] ?? $selected->level }}</x-fa::badge>@endif
                            <x-fa::badge :tone="$selected->price_mode === 'auto' ? 'info' : 'neutral'" title="Preis">{{ 'Preis ' . ($modusLabel[$selected->price_mode] ?? $selected->price_mode) }}</x-fa::badge>
                        </x-slot:badges>
                        <x-slot:aktion>
                            <x-fa::button variant="primary" size="sm" icon="heroicon-m-check" wire:click="speichern">Speichern</x-fa::button>
                        </x-slot:aktion>
                        <x-slot:menue>
                            <x-fa::menu-item danger icon="heroicon-m-trash" wire:click="loeschen({{ $selected->id }})" wire:confirm="Paket löschen?">Paket löschen</x-fa::menu-item>
                        </x-slot:menue>
                    </x-fa::detail-kopf>

                    {{-- B-07: Kennzahlen + offene Punkte --}}
                    <div class="flex flex-col gap-3">
                        <x-fa::kpis :items="$kennzahlen" />
                        @if($selected->price_stale || $nGerichte === 0)
                            <div class="flex flex-col gap-1">
                                @if($nGerichte === 0)
                                    <x-fa::signal tone="crit">Noch keine Gerichte im Paket.</x-fa::signal>
                                @endif
                                @if($selected->price_stale)
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <x-fa::signal tone="warn">Preis veraltet: ein Gericht hat sich geändert.</x-fa::signal>
                                        @if($istAuto)
                                            <x-fa::button size="sm" icon="heroicon-m-arrow-path" wire:click="neuBerechnen">Preis neu berechnen</x-fa::button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-col">
                        {{-- Gerichte im Paket (B-03: einfügen wie im Gerichte-Screen). Speichert sofort. --}}
                        <x-fa::section variant="plain" title="Gerichte im Paket" icon="heroicon-o-queue-list" :meta="$nGerichte"
                            description="Nur Verkaufsgerichte, keine Basisrezepte. Änderungen hier gelten sofort.">
                            <div class="flex flex-col gap-1">
                                @forelse($selected->dishes as $g)
                                    <div wire:key="bg-{{ $g->id }}" class="flex items-center gap-2 px-1 py-1 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)] bg-[var(--fa-surface)]">
                                        <span class="flex flex-col shrink-0">
                                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-up" label="Gericht nach oben" wire:click="gerichtHoch({{ $g->id }})" />
                                            <x-fa::icon-button size="sm" icon="heroicon-m-chevron-down" label="Gericht nach unten" wire:click="gerichtRunter({{ $g->id }})" />
                                        </span>
                                        <span class="flex-1 min-w-0 break-words text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $g->dish?->name ?? 'Gericht fehlt' }}</span>
                                        <x-fa::input size="sm" numeric type="number" step="1" min="0" wire:model.blur="mengeForm.{{ $g->id }}" wire:change="gerichtMengeSpeichern({{ $g->id }})"
                                            class="w-20" placeholder="g/P" title="Menge pro Person in Gramm" aria-label="Menge pro Person" />
                                        @if($g->dish)
                                            <x-fa::money :value="$g->dish->sales_net" class="shrink-0 w-20 text-right text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" />
                                        @endif
                                        <x-fa::icon-button size="sm" icon="heroicon-m-x-mark" tone="danger" label="Aus dem Paket entfernen" wire:click="gerichtRaus({{ $g->sales_recipe_id }})" />
                                    </div>
                                @empty
                                    <x-fa::empty compact icon="heroicon-o-queue-list" title="Noch keine Gerichte">Unten ein Gericht suchen und hinzufügen.</x-fa::empty>
                                @endforelse
                            </div>
                            <div class="relative">
                                @svg('heroicon-m-plus', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                                <x-fa::input type="search" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Gericht suchen und hinzufügen" aria-label="Gericht suchen und hinzufügen" class="pl-8" />
                            </div>
                            @if($gerichtSuche !== '' && $kandidaten->isNotEmpty())
                                <div class="flex flex-col max-h-48 overflow-y-auto">
                                    @foreach($kandidaten as $k)
                                        <button type="button" wire:key="kand-{{ $k->id }}" wire:click="gerichtHinzu({{ $k->id }})"
                                                class="flex w-full items-center justify-between gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                            <span class="inline-flex min-w-0 items-center gap-1.5">@svg('heroicon-m-plus', 'w-4 h-4 shrink-0 text-[var(--fa-accent)]')<span class="truncate" title="{{ $k->name }}">{{ $k->name }}</span></span>
                                            <x-fa::money :value="$k->sales_net" class="shrink-0 text-[var(--fa-ink-2)]" />
                                        </button>
                                    @endforeach
                                </div>
                            @elseif($gerichtSuche !== '')
                                <p class="px-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kein passendes Gericht gefunden.</p>
                            @endif
                        </x-fa::section>

                        {{-- Preis (Fachabschnitt) — übernimmt erst mit „Speichern" oben --}}
                        <x-fa::section variant="plain" title="Preis" icon="heroicon-o-banknotes" description="Änderungen mit „Speichern“ oben übernehmen.">
                            <x-fa::choice name="form.price_mode" label="Preis" idPrefix="paket" :options="['auto' => 'Aus den Gerichten berechnen', 'fixed' => 'Fixiert']" />
                            <div class="grid grid-cols-3 gap-2">
                                <x-fa::field label="€/Person" for="paket-vk">
                                    <x-fa::input id="paket-vk" numeric type="number" step="0.01" wire:model="form.price_per_person" :disabled="$istAuto" />
                                </x-fa::field>
                                <x-fa::field label="EK/Person" for="paket-ek">
                                    <x-fa::input id="paket-ek" numeric type="number" step="0.0001" wire:model="form.ek_per_person" :disabled="$istAuto" />
                                </x-fa::field>
                                <x-fa::field label="Wareneinsatz %" for="paket-we">
                                    <x-fa::input id="paket-we" numeric type="number" step="0.1" wire:model="form.food_cost_percent" :disabled="$istAuto" />
                                </x-fa::field>
                            </div>
                            @if($istAuto)
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Der Preis wird aus den Gerichten berechnet.</p>
                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-path" wire:click="neuBerechnen">Preis neu berechnen</x-fa::button>
                                </div>
                            @endif
                            @if(in_array(($form['price_mode'] ?? 'auto'), ['fixed', 'manuell'], true))
                                <x-fa::field label="Begründung der Preisabweichung" for="paket-grund">
                                    <x-fa::input id="paket-grund" wire:model="form.price_override_reason" />
                                </x-fa::field>
                            @endif
                        </x-fa::section>

                        {{-- Angaben (Fachabschnitt) — übernimmt erst mit „Speichern" oben --}}
                        <x-fa::section variant="plain" title="Angaben" icon="heroicon-o-identification" description="Änderungen mit „Speichern“ oben übernehmen.">
                            <x-fa::field label="Name" for="paket-name">
                                <x-fa::input id="paket-name" wire:model="form.name" />
                            </x-fa::field>
                            <x-fa::field label="Rolle" for="paket-rolle" hint="Frei wählbar. Im Concept tauschbar gegen Pakete derselben Rolle.">
                                <x-fa::input id="paket-rolle" wire:model="form.role" list="rollen-liste" placeholder="z. B. Vorspeise" />
                                <datalist id="rollen-liste">@foreach($rollen as $r)<option value="{{ $r }}"></option>@endforeach</datalist>
                            </x-fa::field>
                            <x-fa::choice name="form.level" label="Niveau" :live="false" idPrefix="paket" :options="['' => 'Ohne'] + $niveauLabel" />
                        </x-fa::section>
                    </div>
                </div>
            @else
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    <x-fa::empty icon="heroicon-o-puzzle-piece" title="Kein Paket gewählt">In der Tabelle ein Paket anklicken oder oben rechts mit „Neues Paket“ eines anlegen.</x-fa::empty>
                </div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Pakete" :subtitle="number_format($pakete->total(), 0, ',', '.') . ($pakete->total() === 1 ? ' Paket' : ' Pakete')">
            <x-slot:actions>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu">Neues Paket</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>
        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[70ch]">Ein Paket ist ein bepreistes Bündel mehrerer Gerichte für eine Rolle. Im Concept lässt es sich gegen Pakete derselben Rolle tauschen.</p>

        <div class="fa-surface overflow-hidden">
            <div class="max-h-[70vh] overflow-auto">
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-full">Name</th>
                            <th>Rolle</th>
                            <th>Niveau</th>
                            <th class="num">Gerichte</th>
                            <th class="num">€/Person</th>
                            <th class="num" title="Wareneinsatz: Einkauf im Verhältnis zum Verkaufspreis">Wareneinsatz</th>
                            <th>Preis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pakete as $b)
                            @php
                                $vkBetrieb = $vkDisplay[$b->id] ?? null;
                            @endphp
                            <x-foodalchemist::table-row :active="$selectedId === $b->id" wire:key="b-{{ $b->id }}" wire:click="waehle({{ $b->id }})">
                                <td class="min-w-[12rem] font-medium text-[var(--fa-ink)]">{{ $b->name }}</td>
                                <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $b->role ?? '–' }}</td>
                                <td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $b->level ? ($niveauLabel[$b->level] ?? $b->level) : '–' }}</td>
                                <td class="num {{ $b->dishes_count === 0 ? 'text-[var(--fa-ink-3)]' : 'text-[var(--fa-ink-2)]' }}">{{ $b->dishes_count }}</td>
                                <td class="num"
                                    @if($vkBetrieb !== null) title="Preis für {{ $aktiverBetrieb }}, Team-Basis: {{ $b->price_per_person !== null ? $geld($b->price_per_person) : 'fehlt' }}" @endif>
                                    @if($vkBetrieb !== null)
                                        <span class="inline-flex items-center gap-1 font-medium">@svg('heroicon-m-building-storefront', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')<x-fa::money :value="$vkBetrieb" /></span>
                                    @else
                                        <x-fa::money :value="$b->price_per_person" />
                                    @endif
                                </td>
                                <td class="num text-[var(--fa-ink-2)]">{{ $b->food_cost_percent !== null ? number_format((float) $b->food_cost_percent, 1, ',', '.') . ' %' : '–' }}</td>
                                <td class="whitespace-nowrap"><x-fa::badge :tone="$b->price_mode === 'auto' ? 'info' : 'neutral'">{{ $modusLabel[$b->price_mode] ?? $b->price_mode }}</x-fa::badge></td>
                            </x-foodalchemist::table-row>
                        @empty
                            <tr>
                                <td colspan="7">
                                    @if($search !== '' || $rolleFilter !== '')
                                        <x-fa::empty icon="heroicon-o-funnel" title="Keine Treffer">Suche lockern oder „Alle Rollen“ wählen.</x-fa::empty>
                                    @else
                                        <x-fa::empty icon="heroicon-o-puzzle-piece" title="Noch keine Pakete">Mit „Neues Paket“ oben rechts ein Bündel aus Gerichten anlegen.</x-fa::empty>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $pakete->links('foodalchemist::components.fa.pagination') }}</div>
        </div>
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
