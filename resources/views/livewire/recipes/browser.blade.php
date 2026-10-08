{{-- Basisrezept-Browser — Hauptgruppen links, Tabelle Mitte, Detail rechts.
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen und
     data-Marker unverändert. Neu: Status-Filter als Chips,
     Status als Chip mit Menü statt Dropdown je Zeile, fehlender Preis als Signal. --}}
@php
    $statusOptionen = ['' => 'Alle ' . number_format($gesamtCount, 0, ',', '.')];
    foreach ($statusFaelle as $fall) {
        if (($statusCounts[$fall->value] ?? 0) > 0 || $status === $fall->value) {
            $statusOptionen[$fall->value] = $fall->label() . ' ' . number_format($statusCounts[$fall->value] ?? 0, 0, ',', '.');
        }
    }
    $konfidenzTon = ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit'];
    $statusWahl = [\Platform\FoodAlchemist\Enums\RecipeStatus::Draft, \Platform\FoodAlchemist\Enums\RecipeStatus::Review, \Platform\FoodAlchemist\Enums\RecipeStatus::Approved, \Platform\FoodAlchemist\Enums\RecipeStatus::Deprecated];
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Basisrezepte" icon="heroicon-o-book-open" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Hauptgruppen" width="w-80">
            <div class="p-3 flex flex-col gap-3" data-rezept-baum>
                <div class="relative">
                    <label for="rezept-suche" class="sr-only">Rezepte durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="rezept-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Name oder Schlüssel" class="pl-8" data-rezept-suche />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    {{-- MVP-023: zentrale deutsche Labels statt Rohwerte --}}
                    <x-fa::select wire:model.live="geschmack" aria-label="Geschmack" size="sm" placeholder="Jeder Geschmack">
                        @foreach(['suess', 'herzhaft', 'neutral'] as $wert)
                            <option value="{{ $wert }}">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($wert) }}</option>
                        @endforeach
                    </x-fa::select>
                    <x-fa::select wire:model.live="fertigung" aria-label="Fertigung" size="sm" placeholder="Jede Fertigung">
                        @foreach(['from_scratch', 'teilfertig', 'convenience'] as $wert)
                            <option value="{{ $wert }}">{{ \Platform\FoodAlchemist\Support\Labels::fertigung($wert) }}</option>
                        @endforeach
                    </x-fa::select>
                </div>

                {{-- R6: Vorlagen-Filter --}}
                <x-foodalchemist::filter-row wire:click="toggleTemplates" :active="$nurTemplates"
                    :count="$nurTemplates ? null : $templateAnzahl" data-templates-toggle>
                    <span class="inline-flex items-center gap-2">@svg('heroicon-o-square-2-stack', 'w-4 h-4 shrink-0') Nur Vorlagen</span>
                </x-foodalchemist::filter-row>

                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]" data-hg-liste>
                    {{-- MVP-042: Gesamtzahl aus der Tabellenquery, nicht aus der Summe der Hauptgruppen --}}
                    <x-foodalchemist::filter-row wire:click="waehleHauptgruppe(null)"
                        :active="$hauptgruppe === null && ! $ohneKategorie"
                        :count="$gesamtCount" data-gesamt-count>Alle Hauptgruppen</x-foodalchemist::filter-row>
                    @foreach($hauptgruppen as $hg)
                        <div wire:key="hg-{{ $hg->id }}">
                            <x-foodalchemist::filter-row wire:click="waehleHauptgruppe({{ $hg->id }})"
                                :active="$hauptgruppe === $hg->id" :child-active="$kategorie !== null"
                                :count="$hgCounts[$hg->id] ?? 0">{{ $hg->label }}</x-foodalchemist::filter-row>
                            @if($hauptgruppe === $hg->id && $kategorien->isNotEmpty())
                                <x-foodalchemist::filter-ast data-kat-liste>
                                    @foreach($kategorien as $kat)
                                        @if(($katCounts[$kat->id] ?? 0) > 0)
                                            <x-foodalchemist::filter-row level="child" wire:key="kat-{{ $kat->id }}"
                                                wire:click="waehleKategorie({{ $kat->id }})"
                                                :active="$kategorie === $kat->id"
                                                :count="$katCounts[$kat->id]">{{ $kat->label }}</x-foodalchemist::filter-row>
                                        @endif
                                    @endforeach
                                </x-foodalchemist::filter-ast>
                            @endif
                        </div>
                    @endforeach

                    {{-- MVP-042: Rezepte ohne Kategorie über den Baum erreichbar machen --}}
                    @if($ohneKategorieCount > 0 || $ohneKategorie)
                        <x-foodalchemist::filter-row wire:click="waehleOhneKategorie" :active="$ohneKategorie" :count="$ohneKategorieCount"
                            title="Basisrezepte ohne Kategorie — über die Hauptgruppen nicht auffindbar" data-ohne-kategorie>
                            <span class="italic">Ohne Kategorie</span>
                        </x-foodalchemist::filter-row>
                    @endif
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Detail-Spalte erst, wenn ein Rezept gewählt ist — vorher nahm der leere Hinweis ~400 px der Tabelle weg. --}}
    @if($recipeId !== null)
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-96" :maxWidth="760" scope="activity_recipes" side="right" :aufziehen="$sprungDetail">
            <livewire:foodalchemist.recipes.detail-panel :recipe-id="$recipeId" key="recipes-browser--recipes.detail-panel" />
        </x-foodalchemist::detail-sidebar>
    </x-slot>
    @endif

    {{-- Editoren und Dialoge (innerhalb x-ui-page, P-2) --}}
    <livewire:foodalchemist.recipes.recipe-modal key="recipes-browser--recipes.recipe-modal" />
    <livewire:foodalchemist.gps.gp-modal key="recipes-browser--gps.gp-modal" />
    <livewire:foodalchemist.verkauf.vk-modal key="recipes-browser--verkauf.vk-modal" />
    <livewire:foodalchemist.recipes.ingredient-editor key="recipes-browser--recipes.ingredient-editor" />
    <livewire:foodalchemist.recipes.generator-modal key="recipes-browser--recipes.generator-modal" />
    <livewire:foodalchemist.recipes.template-instantiate-modal key="recipes-browser--recipes.template-instantiate-modal" />
    <livewire:foodalchemist.recipes.pairing-netz-modal key="recipes-browser--recipes.pairing-netz-modal" />

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header title="Basisrezepte" :subtitle="number_format($rezepte->total(), 0, ',', '.') . ' Treffer'">
            <x-slot:actions>
                {{-- R6: Aus Vorlage — Liste der Vorlagen, Klick dupliziert + öffnet den Editor --}}
                <div class="relative">
                    <x-fa::button icon="heroicon-m-square-2-stack" iconRight="heroicon-m-chevron-down" wire:click="$toggle('templateWahlOffen')" data-aus-template>Aus Vorlage</x-fa::button>
                    @if($templateWahlOffen)
                        <div class="absolute right-0 top-full mt-1 z-30 w-80 max-h-80 overflow-y-auto fa-surface shadow-xl py-1" data-template-liste>
                            @forelse($templateListe as $template)
                                <button type="button" wire:key="tpl-{{ $template->id }}" wire:click="ausTemplate({{ $template->id }})"
                                        class="flex w-full flex-col items-start px-3 py-2 text-left hover:bg-[var(--fa-hover)]">
                                    <span class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $template->name }}</span>
                                    <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $template->n_ingredients_total }} Zutaten{{ $template->yield_kg !== null ? ' · ' . number_format((float) $template->yield_kg, 2, ',', '.') . ' kg' : '' }}</span>
                                </button>
                            @empty
                                <x-fa::empty compact icon="heroicon-o-square-2-stack" title="Noch keine Vorlagen">Im Rezept-Editor „Als Vorlage" markieren.</x-fa::empty>
                            @endforelse
                        </div>
                    @endif
                </div>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="$dispatch('recipe-modal.oeffnen')" data-rezept-anlegen>Neues Basisrezept</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-fa::choice name="status" :options="$statusOptionen" />
            {{-- E14: Ansichts-Schalter — knappe Spalten je Aufgabe --}}
            <div class="flex items-center gap-3">
                <div role="group" aria-label="Ansicht" class="flex p-0.5 gap-0.5 rounded-[var(--fa-radius-control)] bg-[var(--fa-neutral-soft)]" data-ansicht-schalter>
                    @foreach($ansichten as $ak => [$al, $unused])
                        <button type="button" wire:click="$set('ansicht', '{{ $ak }}')" aria-pressed="{{ $ansicht === $ak ? 'true' : 'false' }}"
                                class="h-7 px-3 rounded-[5px] text-[length:var(--fa-text-sm)] font-medium transition-colors {{ $ansicht === $ak ? 'bg-[var(--fa-surface)] text-[var(--fa-ink)] shadow-sm' : 'text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]' }}"
                                data-ansicht="{{ $ak }}">{{ $al }}</button>
                    @endforeach
                </div>
                <x-fa::select wire:model.live="perPage" size="sm" aria-label="Einträge je Seite" class="w-auto" data-per-page>
                    @foreach([25, 50, 100, 250, 500] as $n)<option value="{{ $n }}">{{ $n }} je Seite</option>@endforeach
                </x-fa::select>
            </div>
        </div>

        @if($bulkRunId !== null)
            @php
                $bulkSvc = app(\Platform\FoodAlchemist\Services\BulkEnrichService::class);
                $run = $bulkSvc->status(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId);
            @endphp
            @if($run !== null)
                <div @if($run->status === 'running') wire:poll.2s @endif data-bulk-progress>
                    @if($run->status === 'running')
                        <x-fa::notice tone="info">KI-Anreicherung läuft: {{ $run->done }} von {{ $run->total }} Rezepten.</x-fa::notice>
                    @else
                        <x-fa::notice :tone="$run->failed > 0 ? 'warn' : 'ok'" title="KI-Anreicherung fertig: {{ $run->done }} von {{ $run->total }}{{ $run->failed > 0 ? ', ' . $run->failed . ' Fehler' : '' }}">
                            {{ $bulkSvc->offeneVorschlaege(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation, $bulkRunId) }} Vorschläge warten auf deine Prüfung.
                            <x-slot:actions>
                                <x-fa::button size="sm" wire:click="bulkSchliessen" title="Vorschläge bleiben offen">Schließen</x-fa::button>
                                <x-fa::button size="sm" variant="primary" wire:click="bulkAlleUebernehmen" data-bulk-alle-uebernehmen>Alle übernehmen</x-fa::button>
                            </x-slot:actions>
                        </x-fa::notice>
                    @endif
                </div>
            @endif
        @endif

        @if(count(array_filter($auswahl)) > 0)
            <div class="flex flex-wrap items-center gap-2 px-3 py-2 rounded-[var(--fa-radius-surface)] bg-[var(--fa-accent-soft)]" data-bulk-status>
                <span class="text-[length:var(--fa-text-md)] font-semibold text-[var(--fa-accent)] tabular-nums">{{ count(array_filter($auswahl)) }} ausgewählt</span>
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">Status setzen:</span>
                @foreach(['draft' => 'Entwurf', 'review' => 'Prüfen', 'approved' => 'Freigeben'] as $wert => $lbl)
                    <x-fa::button size="sm" wire:click="bulkStatus('{{ $wert }}')" data-bulk-status-btn="{{ $wert }}">{{ $lbl }}</x-fa::button>
                @endforeach
                {{-- Spec 77d: Auswahl in eine Sammlung (Freigabe an Standorte) --}}
                @php($sammlungenFuerAuswahl = collect(app(\Platform\FoodAlchemist\Services\InhaltsFreigabeService::class)->sammlungen(\Illuminate\Support\Facades\Auth::user()->currentTeamRelation))->pluck('name', 'id'))
                @if($sammlungenFuerAuswahl->isNotEmpty())
                    <x-fa::select size="sm" class="w-48" wire:model="sammlungZiel" :options="$sammlungenFuerAuswahl" placeholder="Sammlung wählen" aria-label="Sammlung" data-bulk-sammlung />
                    <x-fa::button size="sm" wire:click="zuSammlung" data-bulk-zu-sammlung>Zu Sammlung hinzufügen</x-fa::button>
                @endif
                <span class="ml-auto"></span>
                <x-foodalchemist::ki-action action="bulkAnreichern" variant="ai" icon="heroicon-o-sparkles" label="Mit KI anreichern"
                        title="Beschreibung, Kategorie und Geschmack als Vorschläge zur Prüfung (nie automatisch übernommen)"
                        flash="Anreicherung gestartet" data-bulk-anreichern />
            </div>
        @endif

        <div class="fa-surface overflow-hidden" data-rezept-tabelle>
            {{-- MVP-022: Statuswechsel-Fehler sichtbar statt still verschluckt --}}
            @if($statusFehler !== null)
                <x-fa::notice tone="crit" class="m-3" data-status-fehler>{{ $statusFehler }}</x-fa::notice>
            @endif
            <div class="max-h-[70vh] overflow-auto">
                <table class="fa-table">
                    <thead class="sticky top-0 z-20 bg-[var(--fa-surface)]">
                        <tr>
                            <th class="w-10"><span class="sr-only">Auswahl</span></th>
                            <th class="w-full">Name</th>
                            @foreach($spalten as $sp)
                                <th class="{{ $spaltenKatalog[$sp][1] }} {{ in_array($sp, ['ekkg', 'yield', 'zutaten'], true) ? 'num' : '' }}">{{ $spaltenKatalog[$sp][0] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rezepte as $r)
                            <x-foodalchemist::table-row :active="$recipeId === $r->id" wire:key="r-{{ $r->id }}" wire:click="waehleRezept({{ $r->id }})"
                                x-data x-on:click="$store.ui?.mSet('activity_recipes', 'open', true)"
                                data-rezept-zeile="{{ $r->id }}">
                                <td wire:click.stop>
                                    <input type="checkbox" wire:model.live="auswahl.{{ $r->id }}" aria-label="{{ $r->name }} auswählen" class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-rezept-checkbox="{{ $r->id }}" />
                                </td>
                                {{-- R6: Namens-Klick öffnet direkt den Voll-Editor (Zeilen-Klick bleibt Panel-Auswahl) --}}
                                <td class="min-w-[8rem]" x-on:click.stop title="{{ $r->name }} — Klick: bearbeiten">
                                    <span class="flex items-center gap-2">
                                        <button type="button"
                                                x-on:click.stop="$dispatch('modal.open', { name: 'recipe-modal' }); Livewire.dispatch('recipe-modal.oeffnen', { id: {{ $r->id }} })"
                                                class="text-left font-medium text-[var(--fa-ink)] hover:text-[var(--fa-accent)] hover:underline"
                                                data-rezept-name>{{ $r->name }}</button>
                                        @if($r->is_template)<x-fa::badge tone="info" icon="heroicon-m-square-2-stack" data-template-badge>Vorlage</x-fa::badge>@endif
                                    </span>
                                </td>
                                @if(in_array('kategorie', $spalten, true))<td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ $r->category?->label ?? '–' }}</td>@endif
                                @if(in_array('geschmack', $spalten, true))<td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ \Platform\FoodAlchemist\Support\Labels::geschmack($r->taste_direction) }}</td>@endif
                                @if(in_array('fertigung', $spalten, true))<td class="whitespace-nowrap text-[var(--fa-ink-2)]">{{ \Platform\FoodAlchemist\Support\Labels::fertigung($r->production_depth) }}</td>@endif
                                @if(in_array('ekkg', $spalten, true))<td class="num" title="Einkaufspreis je Kilogramm">@if($r->ek_per_kg_eur !== null)<x-fa::money :value="$r->ek_per_kg_eur" />@else<x-fa::badge tone="crit">Preis fehlt</x-fa::badge>@endif</td>@endif
                                @if(in_array('yield', $spalten, true))<td class="num text-[var(--fa-ink-2)]">{{ $r->yield_kg !== null ? number_format((float) $r->yield_kg, 3, ',', '.') . ' kg' : '–' }}</td>@endif
                                @if(in_array('zutaten', $spalten, true))<td class="num text-[var(--fa-ink-2)]">
                                    <span class="inline-flex items-center gap-1.5">{{ $r->n_ingredients_total }}
                                    @if($r->n_ingredients_unmapped > 0)<x-fa::badge tone="warn" title="Zutaten ohne Produkt-Zuordnung: Allergene unbekannt">{{ $r->n_ingredients_unmapped }} offen</x-fa::badge>@endif</span>
                                </td>@endif
                                @if(in_array('allergen', $spalten, true))<td class="whitespace-nowrap">
                                    <x-fa::badge :tone="$konfidenzTon[$r->allergens_confidence] ?? 'neutral'">{{ \Platform\FoodAlchemist\Support\Labels::konfidenz($r->allergens_confidence) }}</x-fa::badge>
                                </td>@endif
                                @if(in_array('status', $spalten, true))
                                {{-- Status als Chip; Kuratoren ändern ihn über ein kleines Menü (Stub bleibt Auto-Zustand) --}}
                                <td class="whitespace-nowrap" wire:click.stop @click.stop>
                                    @if(\Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $r) && $r->status !== \Platform\FoodAlchemist\Enums\RecipeStatus::Stub)
                                        <div class="relative inline-block" x-data="faMenu()" x-on:keydown.escape="offen = false" x-on:click.outside="offen = false" wire:key="rst-{{ $r->id }}-{{ $r->status->value }}">
                                            <button type="button" x-on:click="toggle($event)" class="inline-flex items-center gap-0.5" aria-haspopup="menu" x-bind:aria-expanded="offen" aria-label="Status von {{ $r->name }} ändern" data-status-select>
                                                <x-fa::status :value="$r->status" />@svg('heroicon-m-chevron-down', 'w-3.5 h-3.5 text-[var(--fa-ink-3)]')
                                            </button>
                                            <div x-bind:class="{ hidden: ! offen }" x-bind:style="pos" role="menu" class="hidden w-40 fa-surface shadow-lg py-1">
                                                @foreach($statusWahl as $fall)
                                                    <button type="button" role="menuitem" x-on:click="offen = false" wire:click="statusSetzen({{ $r->id }}, '{{ $fall->value }}')"
                                                            class="flex w-full items-center justify-between px-3 py-1.5 text-left text-[length:var(--fa-text-md)] hover:bg-[var(--fa-hover)] {{ $r->status === $fall ? 'font-semibold text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}">
                                                        {{ $fall->label() }}@if($r->status === $fall)@svg('heroicon-m-check', 'w-4 h-4')@endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @else
                                        <x-fa::status :value="$r->status" />
                                    @endif
                                </td>@endif
                            </x-foodalchemist::table-row>
                        @empty
                            <tr>
                                <td colspan="{{ count($spalten) + 2 }}">
                                    <x-fa::empty icon="heroicon-o-book-open" title="Keine Rezepte gefunden">Filter zurücksetzen oder ein neues Basisrezept anlegen.</x-fa::empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-[var(--fa-line)]">{{ $rezepte->links('foodalchemist::components.fa.pagination') }}</div>
        </div>
    </x-ui-page-container>
</x-ui-page>
