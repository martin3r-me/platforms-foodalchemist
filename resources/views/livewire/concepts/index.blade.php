{{-- Concepts (M10-03/04/05 / Doc 15 §M10): Positionen-Gerüst bauen, jede Position mit einem Paket (tauschbar) ODER
     festem Gericht füllen, Live-Preis aus den gespeicherten Paket-Preisen. Liste links, Editor Mitte, Preis rechts.
     fa-pass Welle 2 (2026-10-05): auf Bausteine <x-fa::…> umgestellt. Funktion, wire:-Bindungen und data-Marker
     unverändert. Neu: Concept-Liste zuerst (mit Blättern), Kategorien eingeklappt darunter, Hauptaktion und
     Kundenbrief im Seitenkopf, Löschen getrennt von Speichern. --}}
@php
    $phasen = \Platform\FoodAlchemist\Services\PhaseService::LABELS;
    $konfTon = ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit', 'unknown' => 'neutral'];
    $neuText = $showVorlagen ? 'Neue Vorlage' : 'Neues Concept';
    $geld = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-foodalchemist::shell.page-navbar title="Concepts" icon="heroicon-o-rectangle-stack" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Concepts'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Concepts" width="w-80">
            <div class="p-3 flex flex-col gap-3">
                <div class="relative">
                    <label for="concepts-suche" class="sr-only">Concepts durchsuchen</label>
                    @svg('heroicon-m-magnifying-glass', 'w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-[var(--fa-ink-3)] pointer-events-none')
                    <x-fa::input id="concepts-suche" type="search" wire:model.live.debounce.300ms="search" placeholder="Concept suchen" class="pl-8" />
                </div>
                {{-- R4.3: Phasen-Filter --}}
                <x-fa::select wire:model.live="phaseFilter" aria-label="Phase" size="sm" placeholder="Alle Phasen" data-phase-filter>
                    @foreach($phasen as $pk => $pl)<option value="{{ $pk }}">{{ $pl }}</option>@endforeach
                </x-fa::select>
                <x-foodalchemist::filter-row wire:click="$set('showVorlagen', {{ $showVorlagen ? 'false' : 'true' }})" :active="$showVorlagen">
                    <span class="inline-flex items-center gap-2">@svg('heroicon-o-square-2-stack', 'w-4 h-4 shrink-0') Nur Vorlagen</span>
                </x-foodalchemist::filter-row>

                {{-- Concept-Liste: die eigentliche Navigation dieser Seite --}}
                <div class="flex flex-col gap-0.5 pt-2 border-t border-[var(--fa-line)]">
                    @forelse($concepts as $c)
                        @php
                            $preisListe = ($vkDisplay[$c->id] ?? null) !== null ? (float) $vkDisplay[$c->id] : ($c->price_per_person_cache !== null ? (float) $c->price_per_person_cache : null);
                            $aktiv = $selectedId === $c->id;
                        @endphp
                        <div wire:key="c-{{ $c->id }}" class="group flex items-center gap-1 rounded-[var(--fa-radius-control)] {{ $aktiv ? 'bg-[var(--fa-accent-soft)]' : 'hover:bg-[var(--fa-hover)]' }}">
                            <button type="button" wire:click="waehle({{ $c->id }})" class="min-w-0 flex-1 px-2.5 py-1.5 text-left" @if($aktiv) aria-current="true" @endif>
                                <span class="block truncate text-[length:var(--fa-text-md)] {{ $aktiv ? 'font-medium text-[var(--fa-accent)]' : 'text-[var(--fa-ink)]' }}" title="{{ $c->name }}">{{ $c->name }}</span>
                                <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">
                                    {{ $c->slots_count }} {{ $c->slots_count === 1 ? 'Position' : 'Positionen' }}
                                    @if($preisListe !== null)
                                        · <span class="{{ isset($vkDisplay[$c->id]) ? 'font-medium text-[var(--fa-ink-2)]' : '' }}" @if(isset($vkDisplay[$c->id])) title="€/Gast für {{ $aktiverBetrieb }}" @endif>{{ $geld($preisListe) }}</span>
                                    @endif
                                    · {{ $phasen[$c->phase] ?? $c->phase }}
                                </span>
                            </button>
                            @if($showVorlagen)
                                <x-fa::button size="sm" variant="ghost" icon="heroicon-m-document-duplicate" wire:click="ausVorlage({{ $c->id }})"
                                    class="shrink-0 mr-1 opacity-0 group-hover:opacity-100 focus:opacity-100" title="Neues Concept aus dieser Vorlage anlegen">Vorlage nutzen</x-fa::button>
                            @endif
                        </div>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-rectangle-stack" :title="$showVorlagen ? 'Noch keine Vorlagen' : 'Noch keine Concepts'">
                            {{ $showVorlagen ? 'Ein Concept rechts als Vorlage speichern.' : 'Mit „Neues Concept“ oben rechts anlegen.' }}
                        </x-fa::empty>
                    @endforelse
                    @if($concepts->hasPages())
                        <div class="pt-2">{{ $concepts->links('foodalchemist::components.fa.pagination') }}</div>
                    @endif
                </div>

                {{-- M10c-B: Kategorien (Filter + Pflege). Eingeklappt, offen sobald gewählt. --}}
                <details class="group pt-2 border-t border-[var(--fa-line)]" @if($categoryFilter !== '') open @endif>
                    <summary class="flex items-center gap-1.5 h-8 px-2.5 rounded-[var(--fa-radius-control)] cursor-pointer select-none text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)] hover:bg-[var(--fa-hover)] hover:text-[var(--fa-ink)]">
                        @svg('heroicon-m-chevron-right', 'w-4 h-4 shrink-0 transition-transform group-open:rotate-90')
                        <span class="{{ $categoryFilter !== '' ? 'font-semibold text-[var(--fa-ink)]' : '' }}">Kategorien</span>
                    </summary>
                    <div class="mt-1 flex flex-col gap-0.5">
                        <x-foodalchemist::filter-row wire:click="kategorieWaehlen('')" :active="$categoryFilter === ''">Alle Kategorien</x-foodalchemist::filter-row>
                        <x-foodalchemist::filter-row wire:click="kategorieWaehlen('none')" :active="$categoryFilter === 'none'"><span class="italic">Ohne Kategorie</span></x-foodalchemist::filter-row>
                        @foreach($kategorienFlat as $kat)
                            <div wire:key="kat-{{ $kat['id'] }}" class="group/kat flex items-center gap-0.5" style="padding-left: {{ $kat['depth'] * 12 }}px">
                                @if($editKatId === $kat['id'])
                                    <x-fa::input size="sm" wire:model="editKatName" wire:keydown.enter="kategorieRename" wire:blur="kategorieRename" aria-label="Kategorie umbenennen" class="flex-1" autofocus />
                                @else
                                    <div class="min-w-0 flex-1">
                                        <x-foodalchemist::filter-row wire:click="kategorieWaehlen('{{ $kat['id'] }}')" :active="$categoryFilter === (string) $kat['id']">{{ $kat['name'] }}</x-foodalchemist::filter-row>
                                    </div>
                                    <x-fa::icon-button size="sm" icon="heroicon-m-pencil" label="Kategorie umbenennen"
                                        wire:click="kategorieEditStart({{ $kat['id'] }}, @js($kat['name']))" class="opacity-0 group-hover/kat:opacity-100 focus:opacity-100" />
                                    <x-fa::icon-button size="sm" icon="heroicon-m-trash" tone="danger" label="Kategorie löschen"
                                        wire:click="kategorieLoeschen({{ $kat['id'] }})" wire:confirm="Kategorie löschen? Unterkategorien und Concepts rücken zur übergeordneten Kategorie."
                                        class="opacity-0 group-hover/kat:opacity-100 focus:opacity-100" />
                                @endif
                            </div>
                        @endforeach
                        <div class="flex gap-1 pt-1">
                            <x-fa::input size="sm" wire:model="neueKategorie" wire:keydown.enter="kategorieNeu" aria-label="Neue Kategorie"
                                placeholder="{{ is_numeric($categoryFilter) ? 'Unterkategorie anlegen' : 'Kategorie anlegen' }}" />
                            <x-fa::icon-button size="sm" icon="heroicon-m-plus" label="Kategorie anlegen" wire:click="kategorieNeu" />
                        </div>
                    </div>
                </details>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-80" :maxWidth="520" scope="activity_concepts" side="right">
            @if($selected && $cockpit)
                @php
                    // Anatomie Detail-Panels (DESIGN.md): Kopf, Kennzahlen, offene Punkte, Deklaration, Aufbau.
                    $panelStatus = ['draft' => ['Entwurf', 'neutral'], 'active' => ['Aktiv', 'ok'], 'archiviert' => ['Archiviert', 'neutral']];
                    $konfSatz = [
                        'high' => 'Allergene vollständig belegt',
                        'medium' => 'Allergene teilweise belegt, stichprobenartig prüfen',
                        'low' => 'Allergene unsicher, vor Ausgabe prüfen',
                        'unknown' => 'Allergene noch nicht bewertet',
                    ];
                    $nZeilen = count($cockpit['zeilen']);
                    // Von Hand festgelegter Preis gilt auch ohne Positionen (preisCockpit: price_mode fixed/manuell).
                    $preisVonHand = ($cockpit['price_mode'] ?? 'auto') !== 'auto';
                    $kennzahlen = [
                        $nZeilen > 0 || $preisVonHand
                            ? ['label' => '€/Person', 'value' => $geld($cockpit['price_per_person']), 'primary' => true, 'kpi' => 'preis'] + ($preisVonHand ? ['hint' => 'von Hand', 'hint_title' => 'Preis von Hand festgelegt, Summe der Positionen: ' . $geld($cockpit['summe_pro_person'] ?? 0)] : [])
                            : ['label' => '€/Person', 'value' => 'Preis fehlt', 'tone' => 'crit', 'kpi' => 'preis'],
                        $nZeilen > 0
                            ? ['label' => 'Einkauf je Person', 'value' => $geld($cockpit['ek_per_person']), 'kpi' => 'ek'] + (! empty($cockpit['hat_ek_luecke']) ? ['hint' => 'unvollständig', 'hint_title' => 'Mindestens einem Gericht fehlt das Portionsgewicht'] : [])
                            : ['label' => 'Einkauf je Person', 'value' => 'fehlt', 'tone' => 'crit', 'kpi' => 'ek'],
                    ];
                    $offen = [];
                    if ($nZeilen === 0) {
                        $offen[] = ['crit', 'Noch keine Positionen angelegt.'];
                    }
                    if ($cockpit['hat_leer']) {
                        $offen[] = ['crit', 'Es gibt noch leere Positionen.'];
                    }
                    if ($cockpit['hat_stale']) {
                        $offen[] = ['warn', 'Ein Paketpreis ist veraltet.'];
                    }
                    if (! empty($cockpit['hat_ek_luecke'])) {
                        $offen[] = ['warn', 'Einkauf unvollständig: mindestens einem Gericht fehlt das Portionsgewicht.'];
                    }
                    [$statusText, $statusTon] = $panelStatus[$selected->status] ?? [$selected->status, 'neutral'];
                @endphp
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    {{-- Kopf: das Concept wird in der Seitenmitte bearbeitet, hier nur Einordnung + eine Aktion --}}
                    <x-fa::detail-kopf :title="$selected->name" :subtitle="($selected->is_template ? 'Vorlage' : 'Concept') . ' · ' . ($phasen[$selected->phase] ?? $selected->phase)">
                        <x-slot:badges>
                            @if($selected->status)<x-fa::badge :tone="$statusTon">{{ $statusText }}</x-fa::badge>@endif
                            @if($selected->is_template)<x-fa::badge tone="info" icon="heroicon-m-square-2-stack">Vorlage</x-fa::badge>@endif
                            @if($selected->occasion)<x-fa::badge title="Anlass">{{ $selected->occasion }}</x-fa::badge>@endif
                        </x-slot:badges>
                        @unless($selected->is_template)
                            <x-slot:aktion>
                                <x-fa::button variant="primary" size="sm" icon="heroicon-m-square-2-stack" wire:click="alsVorlage">Als Vorlage speichern</x-fa::button>
                            </x-slot:aktion>
                        @endunless
                    </x-fa::detail-kopf>

                    {{-- Kennzahlen: €/Person ist die Hauptzahl, Gesamtpreis erst mit Gästezahl --}}
                    <div class="flex flex-col gap-3">
                        <x-fa::kpis :items="$kennzahlen" />
                        <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Gästezahl und Gesamtpreis erst im Foodbook oder Angebot.</p>
                        @if($offen !== [])
                            <div class="flex flex-col gap-1">
                                @foreach($offen as [$ton, $text])
                                    <x-fa::signal :tone="$ton">{{ $text }}</x-fa::signal>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- C-09: Allergen-/Diät-Rollup übers ganze Concept (schwächstes Gericht bestimmt die Konfidenz) --}}
                    @if($rollup && $rollup['n_gerichte'] > 0)
                        <div class="flex flex-col gap-2">
                            <x-fa::signal :tone="($konfTon[$rollup['confidence']] ?? 'neutral') === 'neutral' ? 'warn' : $konfTon[$rollup['confidence']]"
                                title="Allergen-Konfidenz (schwächstes Gericht): {{ \Platform\FoodAlchemist\Support\Labels::konfidenz($rollup['confidence']) }}">{{ $konfSatz[$rollup['confidence']] ?? $rollup['confidence'] }}</x-fa::signal>
                            <div class="flex flex-wrap gap-1.5">
                                @if($rollup['is_vegan'])<x-fa::badge tone="ok" icon="heroicon-m-check">vegan</x-fa::badge>
                                @elseif($rollup['is_vegetarian'])<x-fa::badge tone="ok" icon="heroicon-m-check">vegetarisch</x-fa::badge>@endif
                                @if($rollup['is_gluten_free'])<x-fa::badge tone="ok" icon="heroicon-m-check">glutenfrei</x-fa::badge>@endif
                                @if($rollup['is_lactose_free'])<x-fa::badge tone="ok" icon="heroicon-m-check">laktosefrei</x-fa::badge>@endif
                                @if($rollup['is_halal'])<x-fa::badge tone="ok" icon="heroicon-m-check">halal</x-fa::badge>@endif
                                @if($rollup['contains_pork'])<x-fa::badge tone="warn">enthält Schwein</x-fa::badge>@endif
                                @if($rollup['contains_beef'])<x-fa::badge tone="warn">enthält Rind</x-fa::badge>@endif
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-col">
                        {{-- Aufbau: Positionen mit Preis je Person --}}
                        <x-fa::section variant="plain" title="Aufbau" icon="heroicon-o-list-bullet" :meta="$nZeilen">
                            @if($nZeilen === 0)
                                <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Positionen">In der Mitte unter „Positionen“ eine Rolle eintragen und anlegen.</x-fa::empty>
                            @else
                                <ul class="flex flex-col">
                                    @foreach($cockpit['zeilen'] as $z)
                                        <li class="flex items-center justify-between gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0">
                                            <span class="min-w-0">
                                                <span class="block text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $z['role'] ?: 'Ohne Rolle' }}</span>
                                                <span class="flex flex-wrap items-center gap-1.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                                                    <span class="min-w-0 break-words">{{ $z['label'] }}</span>
                                                    @if($z['type'] === 'paket')<x-fa::badge tone="info">Paket</x-fa::badge>@elseif($z['type'] === 'leer')<x-fa::badge tone="crit">leer</x-fa::badge>@endif
                                                </span>
                                            </span>
                                            @unless($z['type'] === 'leer')<x-fa::money :value="$z['price']" class="shrink-0" />@endunless
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </x-fa::section>
                    </div>
                </div>
            @else
                <div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]">
                    <x-fa::empty icon="heroicon-o-banknotes" title="Kein Concept gewählt">Links ein Concept wählen, dann stehen hier Preis je Person, Allergene und Aufbau.</x-fa::empty>
                </div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 py-6" spacing="space-y-4">
        <x-fa::page-header :title="$selected ? $selected->name : 'Concepts'"
            :subtitle="$selected ? ($selected->is_template ? 'Vorlage' : 'Concept') . ' · ' . ($phasen[$selected->phase] ?? $selected->phase) : number_format($concepts->total(), 0, ',', '.') . ($showVorlagen ? ' Vorlagen' : ' Concepts')">
            <x-slot:actions>
                {{-- R6.1: Brief → Concept aus echten VK-Gerichten (KI baut den Rahmen, Graph wählt die Gerichte) --}}
                <x-fa::button variant="ai" icon="heroicon-m-sparkles" wire:click="generatorOeffnen" data-generator-oeffnen>Concept aus Kundenbrief</x-fa::button>
                <x-fa::button variant="primary" icon="heroicon-m-plus" wire:click="neu">{{ $neuText }}</x-fa::button>
            </x-slot:actions>
        </x-fa::page-header>

        @if($generatorOffen)
            <x-fa::section title="Concept aus Kundenbrief" icon="heroicon-o-sparkles"
                description="Nur echte Verkaufsgerichte. Positionen ohne Treffer bleiben leer, mit Begründung. Das Ergebnis ist ein Entwurf." data-generator-panel>
                <x-slot:actions>
                    <x-fa::icon-button size="sm" icon="heroicon-m-x-mark" label="Kundenbrief schließen" wire:click="$set('generatorOffen', false)" />
                </x-slot:actions>
                <x-fa::field label="Kundenbrief" for="generator-brief" hint="Anlass, Gäste, Budget je Person, Diät-Anforderungen, No-Gos">
                    <x-fa::textarea id="generator-brief" wire:model="generatorBrief" rows="5" placeholder="Kundenbrief einfügen" />
                </x-fa::field>
                <x-fa::field label="Name" for="generator-name" optional>
                    <x-fa::input id="generator-name" wire:model="generatorName" placeholder="z. B. Sommerfest Buffet" />
                </x-fa::field>
                {{-- 06·H4: opt-in Favoriten-Modus (Default aus) --}}
                <div class="flex flex-col gap-1.5">
                    <label class="flex items-start gap-2 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">
                        <input type="checkbox" wire:model.live="generatorFavorites" class="mt-0.5 rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-generator-favoriten />
                        <span class="inline-flex items-center gap-1.5">@svg('heroicon-m-star', 'w-4 h-4 text-[var(--fa-warn)]') Auf Basis meiner Favoriten bauen <span class="text-[var(--fa-ink-3)]">(bevorzugt, nicht ausschließlich)</span></span>
                    </label>
                    <label x-show="$wire.generatorFavorites" class="flex items-center gap-2 ml-6 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">
                        <input type="checkbox" wire:model="generatorFavoritesConvenienceOnly" class="rounded border-[var(--fa-line-strong)] text-[var(--fa-accent)] focus:ring-[var(--fa-accent)]" data-generator-favoriten-conv /> nur Convenience-Favoriten
                    </label>
                </div>
                <div class="flex justify-end">
                    <x-foodalchemist::ki-action action="generatorStart" variant="primary" label="Concept erstellen"
                            busy="Baue Gerüst und wähle Gerichte …" data-generator-start />
                </div>

                @if($generatorFehler)
                    <x-fa::notice tone="crit" data-generator-fehler>{{ $generatorFehler }}</x-fa::notice>
                @endif
                @if($generatorErgebnis)
                    <x-fa::notice tone="ok" title="„{{ $generatorErgebnis['concept_name'] }}“ als Entwurf angelegt" data-generator-ergebnis>
                        <p class="text-[var(--fa-ink-2)] tabular-nums">Zusammenhalt {{ $generatorErgebnis['kohaesion_score'] ?? '–' }} ({{ $generatorErgebnis['kohaesion_coverage'] ?? 0 }} % aus dem Aromagraph) · Abdeckung {{ $generatorErgebnis['coverage_gesamt'] ?? '–' }}</p>
                        <ul class="mt-1.5 flex flex-col gap-0.5">
                            @foreach($generatorErgebnis['protokoll'] as $p)
                                <li class="{{ $p['status'] === 'leer' ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-ink-2)]' }}">
                                    <span class="font-medium">{{ $p['slot'] }}:</span>
                                    {{ $p['status'] === 'leer' ? 'leer, ' . $p['begruendung'] : collect($p['gerichte'])->pluck('name')->implode(', ') }}
                                </li>
                            @endforeach
                        </ul>
                        <x-slot:actions>
                            <x-fa::button size="sm" variant="primary" iconRight="heroicon-m-arrow-right" wire:click="waehle({{ $generatorErgebnis['concept_id'] }})">Concept öffnen</x-fa::button>
                        </x-slot:actions>
                    </x-fa::notice>
                @endif
            </x-fa::section>
        @endif

        @if($selected)
            @php $lesemodus = in_array($sperr['modus'], ['lesen', 'fremd'], true); @endphp
            {{-- Concept-Stammdaten --}}
            <x-fa::section title="Stammdaten" icon="heroicon-o-identification" wire:key="hdr-{{ $selected->id }}">
                {{-- Spec 65: Felder erst nach „Bearbeiten" (Sperre) änderbar; die Bearbeiten-Leiste steht außerhalb --}}
                <fieldset @disabled($lesemodus) class="contents" data-fa-lesemodus="{{ $lesemodus ? '1' : '0' }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <x-fa::field :label="$selected->is_template ? 'Name der Vorlage' : 'Name'" for="concept-name" class="lg:col-span-2">
                        <x-fa::input id="concept-name" wire:model="form.name" />
                    </x-fa::field>
                    <x-fa::field label="Anlass" for="concept-anlass">
                        <x-fa::input id="concept-anlass" wire:model="form.occasion" placeholder="z. B. Sommerfest" />
                    </x-fa::field>
                    <x-fa::field label="Kategorie" for="concept-kategorie">
                        <x-fa::select id="concept-kategorie" wire:model="form.category_id" placeholder="Ohne Kategorie">
                            @foreach($kategorienFlat as $kat)<option value="{{ $kat['id'] }}">{{ $kat['label'] }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                    <x-fa::field label="Status" for="concept-status">
                        <x-fa::select id="concept-status" wire:model="form.status">
                            @foreach(['draft' => 'Entwurf', 'active' => 'Aktiv', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </div>
                </fieldset>
                <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <x-fa::button variant="danger" size="sm" icon="heroicon-m-trash" wire:click="loeschen({{ $selected->id }})" wire:confirm="Concept löschen?" :disabled="$lesemodus">Concept löschen</x-fa::button>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-fa::button variant="ghost" icon="heroicon-m-printer" :href="route('foodalchemist.concepts.dokument', ['id' => $selected->id, 'profil' => 'voll'])" target="_blank"
                            title="Druck- und PDF-Report mit allen Gerichten, Basisrezepten und Produkten" data-concept-druck>Report drucken</x-fa::button>
                        <x-fa::button :variant="$zielModus ? 'ai' : 'secondary'" icon="heroicon-m-flag" wire:click="zielpreisToggle" aria-pressed="{{ $zielModus ? 'true' : 'false' }}">Zielpreis planen</x-fa::button>
                        {{-- Spec 65: erst „Bearbeiten" (Sperre), dann Abbrechen/Speichern; Speichern beendet die Bearbeitung --}}
                        <x-foodalchemist::bearbeiten-leiste :zustand="$sperr">
                            <x-fa::button variant="primary" wire:click="speichern" data-concept-speichern>Speichern</x-fa::button>
                        </x-foodalchemist::bearbeiten-leiste>
                    </div>
                </div>

                {{-- M13: Zielpreis-Konfigurator (greift nur an Paket-Positionen) --}}
                @if($zielModus)
                    <div class="flex flex-col gap-3 p-3 rounded-[var(--fa-radius-surface)] bg-[var(--fa-accent-soft)]">
                        <div class="flex flex-wrap items-end gap-3">
                            <x-fa::field label="Zielpreis €/Person" for="zielpreis">
                                <x-fa::input id="zielpreis" numeric type="number" step="0.01" min="0" wire:model="zielPreis" wire:keydown.enter="zielpreisBerechnen" class="w-32" placeholder="36,00" />
                            </x-fa::field>
                            <x-fa::button wire:click="zielpreisBerechnen">Vorschlag berechnen</x-fa::button>
                            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] max-w-[48ch]">Tauscht Pakete derselben Rolle. Feste Gerichte bleiben als Fixkosten.</p>
                        </div>
                        @if($zielVorschlag)
                            @php
                                $abweichung = $zielVorschlag['price'] - $zielVorschlag['ziel'];
                            @endphp
                            <x-fa::kpis :items="[
                                ['label' => 'Aktuell', 'value' => $geld($zielVorschlag['aktuell'])],
                                ['label' => 'Vorschlag', 'value' => $geld($zielVorschlag['price']), 'primary' => true],
                                ['label' => 'Ziel', 'value' => $geld($zielVorschlag['ziel'])],
                                ['label' => 'Abweichung', 'value' => $geld($abweichung), 'tone' => abs($abweichung) < 0.01 ? 'ok' : 'warn'],
                                ['label' => 'Tausch', 'value' => (string) $zielVorschlag['aenderungen']],
                            ]" />
                            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">Mit den vorhandenen Paketen erreichbar: {{ $geld($zielVorschlag['min']) }} bis {{ $geld($zielVorschlag['max']) }} je Person{{ $zielVorschlag['fix'] > 0 ? ' (inkl. ' . $geld($zielVorschlag['fix']) . ' feste Gerichte)' : '' }}.</p>
                            <div class="flex justify-end gap-2">
                                <x-fa::button variant="ghost" wire:click="$set('zielVorschlag', null)">Vorschlag verwerfen</x-fa::button>
                                <x-fa::button variant="primary" wire:click="zielpreisUebernehmen" :disabled="$lesemodus || $zielVorschlag['aenderungen'] === 0">{{ $zielVorschlag['aenderungen'] === 1 ? '1 Tausch übernehmen' : $zielVorschlag['aenderungen'] . ' Tausche übernehmen' }}</x-fa::button>
                            </div>
                        @endif
                    </div>
                @endif
            </x-fa::section>

            {{-- Positionen-Gerüst — Spec 65: im Lesemodus gesperrt --}}
            <fieldset @disabled($lesemodus) class="contents" data-fa-lesemodus="{{ $lesemodus ? '1' : '0' }}">
            <x-fa::section title="Positionen" icon="heroicon-o-list-bullet" :meta="$selected->slots->count()"
                description="Jede Position wird mit einem Paket derselben Rolle oder einem festen Gericht gefüllt.">
                <x-slot:actions>
                    <x-fa::input size="sm" wire:model="neuerSlotRolle" wire:keydown.enter="slotHinzu" placeholder="Rolle, z. B. Vorspeise" aria-label="Rolle der neuen Position" class="w-48" />
                    <x-fa::button size="sm" icon="heroicon-m-plus" wire:click="slotHinzu">Position anlegen</x-fa::button>
                </x-slot:actions>

                <div class="flex flex-col gap-2">
                    @forelse($selected->slots as $slot)
                        <div wire:key="slot-{{ $slot->id }}" class="flex flex-col gap-2 p-3 rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)]">
                            <div class="flex items-center gap-2">
                                <span class="flex flex-col shrink-0">
                                    <x-fa::icon-button size="sm" icon="heroicon-m-chevron-up" label="Position nach oben" wire:click="slotHoch({{ $slot->id }})" />
                                    <x-fa::icon-button size="sm" icon="heroicon-m-chevron-down" label="Position nach unten" wire:click="slotRunter({{ $slot->id }})" />
                                </span>
                                <x-fa::input size="sm" wire:model.blur="slotForm.{{ $slot->id }}.role" wire:change="slotSpeichern({{ $slot->id }})"
                                    aria-label="Rolle" placeholder="Rolle" class="w-40" />
                                <x-fa::input size="sm" wire:model.blur="slotForm.{{ $slot->id }}.title" wire:change="slotSpeichern({{ $slot->id }})"
                                    aria-label="Titel" placeholder="Titel (optional)" class="flex-1" />
                                <x-fa::icon-button size="sm" icon="heroicon-m-trash" tone="danger" label="Position entfernen" wire:click="slotRaus({{ $slot->id }})" />
                            </div>

                            {{-- Befüllung --}}
                            <div class="flex flex-wrap items-center gap-2 text-[length:var(--fa-text-md)]">
                                @if($slot->package_id && $slot->package)
                                    <x-fa::badge tone="info">Paket</x-fa::badge>
                                    <span class="font-medium text-[var(--fa-ink)]">{{ $slot->package->name }}</span>
                                    <x-fa::money :value="$slot->package->price_per_person" class="text-[var(--fa-ink-2)]" />
                                @elseif($slot->sales_recipe_id && $slot->dish)
                                    <x-fa::badge>Festes Gericht</x-fa::badge>
                                    <span class="font-medium text-[var(--fa-ink)]">{{ $slot->dish->name }}</span>
                                    <x-fa::money :value="$slot->dish->sales_net" class="text-[var(--fa-ink-2)]" />
                                @else
                                    <x-fa::signal tone="warn">Noch leer: Paket wählen oder festes Gericht setzen.</x-fa::signal>
                                @endif
                                @if($slot->package_id || $slot->sales_recipe_id)
                                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-x-mark" wire:click="slotLeeren({{ $slot->id }})">Position leeren</x-fa::button>
                                @endif
                            </div>

                            {{-- Steuerung: Paket (gleiche Rolle) wählen ODER festes Gericht suchen --}}
                            <div class="flex flex-wrap items-center gap-2">
                                <x-fa::select size="sm" x-on:change="$wire.fuellePaket({{ $slot->id }}, $event.target.value); $event.target.value=''"
                                    aria-label="Paket für {{ $slot->role ?: 'diese Position' }} wählen" class="w-64">
                                    <option value="">Paket wählen ({{ $slot->role ?: 'ohne Rolle' }})</option>
                                    @foreach(($tauschbar[$slot->id] ?? []) as $b)
                                        <option value="{{ $b->id }}">{{ $b->name }}{{ $b->price_per_person !== null ? ' (' . $geld($b->price_per_person) . ')' : '' }}</option>
                                    @endforeach
                                </x-fa::select>
                                <x-fa::button size="sm" icon="heroicon-m-magnifying-glass" wire:click="gerichtPicker({{ $slot->id }})">Festes Gericht wählen</x-fa::button>
                            </div>

                            @if($fillSlotId === $slot->id)
                                <div class="flex flex-col gap-1">
                                    <x-fa::input size="sm" type="search" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Gericht suchen" aria-label="Gericht suchen" autofocus />
                                    @if($gerichtSuche !== '' && $kandidaten->isNotEmpty())
                                        <div class="flex flex-col max-h-48 overflow-y-auto">
                                            @foreach($kandidaten as $k)
                                                <button type="button" wire:key="k-{{ $slot->id }}-{{ $k->id }}" wire:click="fuelleGericht({{ $slot->id }}, {{ $k->id }})"
                                                        class="flex w-full items-center justify-between gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]">
                                                    <span class="min-w-0 truncate" title="{{ $k->name }}">{{ $k->name }}</span>
                                                    <x-fa::money :value="$k->sales_net" class="shrink-0 text-[var(--fa-ink-2)]" />
                                                </button>
                                            @endforeach
                                        </div>
                                    @elseif($gerichtSuche !== '')
                                        <p class="px-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Kein Gericht gefunden.</p>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Positionen">Oben eine Rolle eintragen, z. B. Vorspeise, und „Position anlegen“.</x-fa::empty>
                    @endforelse
                </div>
            </x-fa::section>
            </fieldset>
        @else
            <div class="fa-surface">
                <x-fa::empty icon="heroicon-o-rectangle-stack" title="Kein Concept gewählt">
                    Links ein Concept wählen oder oben rechts ein neues anlegen. Aus einer Vorlage entsteht über „Vorlage nutzen“ ein eigenständiges Concept.
                </x-fa::empty>
            </div>
        @endif
    </x-ui-page-container>
    {{-- Spec 53/F Stufe 2: Sprachbefehl-Mount auf Seitenebene (Modal + optionales schwebendes Element). --}}
</x-ui-page>
