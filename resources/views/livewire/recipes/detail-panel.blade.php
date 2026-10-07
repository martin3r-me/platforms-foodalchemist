{{-- M4-05: Rezept-DetailPanel. fa-pass (2026-10-05): auf Bausteine <x-fa::…> umgestellt, gleiche
     Sprache wie das GP-Detail. Standalone-Detail nach der Anatomie Detail-Panels (DESIGN.md), Geschwister
     des Gericht-Details: Kopf (eine Hauptaktion „Im Editor öffnen", Drucken/PDF/Neu berechnen/Status/
     Duplizieren/Vorlage im Menü) · Kennzahlen (EK je kg, EK je Ansatz, Ausbeute) · Offene Punkte ·
     Allergene und Diät · Zutaten · Endprodukt · Anleitung · Fotos · Pairing-Netz · Eignung · Nährwerte ·
     Ersatz · Equipment · KI-Kontext · Wo verwendet? · Verwaltung.
     Editor-Einbettungen (section=eignung|ersatz, embedded-Details-Reiter) behalten ihre Karteien. --}}
@php
    $nurErsatz = ($section ?? null) === 'ersatz';
    $nurEignung = ($section ?? null) === 'eignung';
    $nurSektion = $nurErsatz || $nurEignung;

    $zahl = fn ($wert, $stellen = 2) => number_format((float) $wert, $stellen, ',', '.');
    $menge = fn ($wert) => rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',');
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $titelKlein = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $listenKnopf = 'flex w-full items-center gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $chip = 'inline-flex items-center gap-1 h-7 px-2.5 rounded-full border text-[length:var(--fa-text-sm)] transition-colors';
    $chipAn = 'border-[var(--fa-accent)] bg-[var(--fa-accent-soft)] text-[var(--fa-accent)] font-medium';
    $chipAus = 'border-[var(--fa-line-strong)] bg-[var(--fa-surface)] text-[var(--fa-ink-2)] hover:border-[var(--fa-ink-3)] hover:text-[var(--fa-ink)]';

    // Eignungs-Vokabular kommt als Kürzel aus dem Service — hier nur lesbar gemacht.
    $eignungText = [
        'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch',
        'business' => 'Betriebsgastronomie', 'care' => 'Care', 'crew' => 'Crew-Verpflegung',
        'event_privat' => 'Privates Event', 'kita_schule' => 'Kita und Schule', 'restaurant' => 'Restaurant',
    ];
    $eignungLabel = fn (string $slug) => $eignungText[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
    $statusWahl = ['draft' => 'Zurück auf Entwurf', 'review' => 'Zur Prüfung geben', 'approved' => 'Freigeben'];
@endphp

<div class="{{ $nurSektion || ($embedded ?? false) ? 'flex flex-col gap-3' : 'p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]' }}" data-rezept-panel>
    @if($rezept === null)
        @unless($nurSektion)
            <x-fa::empty icon="heroicon-o-cursor-arrow-rays" title="Kein Rezept gewählt">Rezept in der Tabelle anklicken, dann erscheinen hier Kosten, Zutaten und Allergene.</x-fa::empty>
        @endunless

    {{-- ── Editor-Kartei: NUR Eignung (Eigenschaften-Reiter, ohne Panel-Kopf) ── --}}
    @elseif($nurEignung)
        @php
            $eignungVokab = \Platform\FoodAlchemist\Services\RecipeService::eignungVokabular();
            $eignungAktiv = ['level' => $rezept->levelSuitabilities->keyBy('level_slug'), 'sektor' => $rezept->sectorSuitabilities->keyBy('sector_slug')];
        @endphp
        <div class="flex flex-col gap-2" data-eignungen>
            @if($fehlerEignung !== null)<x-fa::signal tone="crit" data-eignung-fehler>{{ $fehlerEignung }}</x-fa::signal>@endif
            @foreach(['level' => 'Niveau', 'sektor' => 'Sektor'] as $typ => $typLabel)
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-16 shrink-0 {{ $titelKlein }}">{{ $typLabel }}</span>
                    @foreach($eignungVokab[$typ]['slugs'] as $slug)
                        @php $eintrag = $eignungAktiv[$typ][$slug] ?? null; @endphp
                        <button type="button" wire:key="eig-{{ $typ }}-{{ $slug }}" wire:click="eignungToggle('{{ $typ }}', '{{ $slug }}')"
                                class="{{ $chip }} {{ $eintrag !== null ? $chipAn : $chipAus }}" aria-pressed="{{ $eintrag !== null ? 'true' : 'false' }}"
                                data-eignung-chip="{{ $typ }}-{{ $slug }}">@if($eintrag !== null)@svg('heroicon-m-check', 'w-3.5 h-3.5')@endif{{ $eignungLabel($slug) }}</button>
                    @endforeach
                </div>
            @endforeach
        </div>

    {{-- ── Editor-Kartei: NUR Ersatz (Eigenschaften-Reiter) ── --}}
    @elseif($nurErsatz)
        <div class="flex flex-col gap-1.5" data-sektion="ersatz">
            @forelse($ersatz as $e)
                <div class="flex items-center gap-2 text-[length:var(--fa-text-md)]" wire:key="rq-equiv-{{ $e->id }}">
                    <x-fa::badge :tone="$e->gegen_kind === 'recipe' ? 'info' : 'neutral'" class="shrink-0">{{ $e->gegen_kind === 'recipe' ? 'Rezept' : 'Grundprodukt' }}</x-fa::badge>
                    <span class="min-w-0 flex-1 truncate text-[var(--fa-ink)]" title="{{ $e->gegen_name }}">{{ $e->gegen_name }}</span>
                    @if((float) $e->umrechnungsfaktor !== 1.0)<span class="shrink-0 tabular-nums {{ $leise }}" title="Umrechnungsfaktor">× {{ rtrim(rtrim(number_format($e->umrechnungsfaktor, 4, ',', '.'), '0'), ',') }}</span>@endif
                    <x-fa::icon-button size="sm" tone="danger" icon="heroicon-m-x-mark" label="Ersatz lösen" wire:click="ersatzLoesen({{ $e->id }})" />
                </div>
            @empty
                <p class="{{ $leise }}" data-ersatz-leer>Kein Ersatz hinterlegt.</p>
            @endforelse
            @if($fehlerAnker !== null)<x-fa::signal tone="crit" data-ersatz-fehler>{{ $fehlerAnker }}</x-fa::signal>@endif
            <div class="flex flex-col gap-1 pt-1" data-ersatz-verknuepfen>
                <x-fa::input size="sm" type="search" wire:model.live.debounce.300ms="ersatzSuche" placeholder="Ersatz verknüpfen: Grundprodukt oder Rezept suchen" aria-label="Ersatz suchen und verknüpfen" data-ersatz-suche />
                @foreach($ersatzKandidaten as $k)
                    <button type="button" wire:key="rq-ersk-{{ $k->kind }}-{{ $k->id }}" wire:click="ersatzVerknuepfen('{{ $k->kind }}', {{ $k->id }})" class="{{ $listenKnopf }}">
                        <x-fa::badge :tone="$k->kind === 'recipe' ? 'info' : 'neutral'" class="shrink-0">{{ $k->kind === 'recipe' ? 'Rezept' : 'Grundprodukt' }}</x-fa::badge>
                        <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                    </button>
                @endforeach
            </div>
        </div>

    {{-- ── Editor-Reiter „Details" (embedded, ohne section): reduzierter Ausschnitt ── --}}
    @elseif($embedded ?? false)
        @include('foodalchemist::livewire.recipes.partials.deklaration')
        @if($eltern->isNotEmpty())
            <x-fa::section variant="plain" title="Verwendet in" icon="heroicon-o-link" :meta="$eltern->count()" data-eltern>
                <div class="flex flex-col gap-0.5">
                    @foreach($eltern as $parent)
                        <button type="button" wire:key="el-{{ $parent->id }}"
                                @if($parent->is_sales_recipe) wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $parent->id }} })" @else wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ $parent->id }} })" @endif
                                class="{{ $listenKnopf }}" title="{{ $parent->is_sales_recipe ? 'Gericht öffnen' : 'Rezept öffnen' }}" data-eltern-link>
                            @svg($parent->is_sales_recipe ? 'heroicon-o-banknotes' : 'heroicon-o-arrow-up', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                            <span class="min-w-0 flex-1 truncate">{{ $parent->name }}</span>
                        </button>
                    @endforeach
                </div>
            </x-fa::section>
        @endif
        <div class="flex flex-wrap items-center gap-1.5 pt-3 border-t border-[var(--fa-line)]" data-workflow>
            @foreach($statusWahl as $wert => $lbl)
                @if($rezept->status->value !== $wert)<x-fa::button size="sm" wire:click="statusSetzen('{{ $wert }}')" data-status-btn="{{ $wert }}">{{ $lbl }}</x-fa::button>@endif
            @endforeach
            <x-fa::button size="sm" variant="ghost" icon="heroicon-o-document-duplicate" wire:click="duplizieren" data-duplizieren-btn>Duplizieren</x-fa::button>
            <x-fa::button size="sm" variant="ghost" :icon="$rezept->is_template ? 'heroicon-s-star' : 'heroicon-o-star'" wire:click="templateToggle"
                class="{{ $rezept->is_template ? 'text-[var(--fa-accent)]' : '' }}" title="{{ $rezept->is_template ? 'Ist Vorlage. Klick nimmt die Markierung zurück' : 'Als Vorlage für neue Rezepte markieren' }}" data-template-btn>{{ $rezept->is_template ? 'Vorlage' : 'Als Vorlage markieren' }}</x-fa::button>
        </div>

    {{-- ── STANDALONE-Detail rechts im Basisrezepte-Browser (Anatomie Detail-Panels) ── --}}
    @else
        @php
            $priced = $rezept->ek_n_ingredients_priced;
            $ptotal = $rezept->ek_n_ingredients_total;
            $vollstaendig = $priced !== null && $ptotal !== null && $ptotal > 0 && $priced >= $ptotal;
            // Kennzahlen (Kosten): EK je kg ist die eine Hauptzahl. Fehlende Werte werden gezeigt, nicht geschätzt.
            $kennzahlen = [
                [
                    'label' => 'EK je kg',
                    'value' => $rezept->ek_per_kg_eur !== null ? $zahl($rezept->ek_per_kg_eur) . ' €' : 'Preis fehlt',
                    'primary' => $rezept->ek_per_kg_eur !== null,
                    'tone' => $rezept->ek_per_kg_eur === null ? 'crit' : null,
                    'kpi' => 'ek-kg',
                ],
                [
                    'label' => 'EK je Ansatz',
                    'value' => $rezept->ek_total_eur !== null ? $zahl($rezept->ek_total_eur) . ' €' : 'fehlt',
                    'tone' => $rezept->ek_total_eur === null ? 'crit' : null,
                    'title' => 'Einkauf für die ganze Rezeptmenge',
                    'kpi' => 'ek-gesamt',
                ],
                [
                    'label' => 'Ausbeute',
                    'value' => $rezept->yield_kg !== null ? $zahl($rezept->yield_kg_manual ?? $rezept->yield_kg, 3) . ' kg' : 'fehlt',
                    'tone' => $rezept->yield_kg === null ? 'warn' : null,
                    'hint' => $rezept->yield_kg_manual !== null ? 'von Hand' : null,
                    'kpi' => 'ausbeute',
                ],
            ];
            $nichtZugeordnet = $rezept->ingredients->filter(fn ($z) => $z->gp === null && $z->referencedRecipe === null)->count();
            $konfTon = ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit', 'unknown' => 'neutral'];
            $naehrwertFelder = [
                'nutri_kcal_per_100g' => 'kcal', 'nutri_protein_g_per_100g' => 'Eiweiß', 'nutri_fat_g_per_100g' => 'Fett',
                'nutri_saturated_fat_g_per_100g' => 'davon gesättigt', 'nutri_carbs_g_per_100g' => 'Kohlenhydrate',
                'nutri_sugar_g_per_100g' => 'davon Zucker', 'nutri_salt_g_per_100g' => 'Salz',
            ];
            // Kennung (recipe_key) bewusst nicht im Untertitel (Dominique 2026-10-06): für die Küche wertlos, wirkt wie eine ID.
            $untertitel = 'Version ' . $rezept->version . ($rezept->work_time_min ? ' · Arbeitszeit ' . $rezept->work_time_min . ' min' : '');
        @endphp

        {{-- 1 · Kopf: Name, Einordnung, eine Hauptaktion, Weiteres im Menü --}}
        <x-fa::detail-kopf :title="$rezept->name" :subtitle="$untertitel" data-rezept-aktionen>
            @if(!empty($rezeptBildUrl))
                <img src="{{ $rezeptBildUrl }}" alt="" class="mt-2 h-12 w-12 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" data-rezept-mini-bild>
            @endif
            @if($rezept->description)
                <p class="mt-2 text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)]" data-description>{{ $rezept->description }}</p>
            @endif
            <x-slot:badges>
                <x-fa::status :value="$rezept->status" />
                <x-fa::badge tone="info">{{ $rezept->category?->label ?? 'Ohne Kategorie' }}</x-fa::badge>
                @if($rezept->is_template)<x-fa::badge tone="accent" icon="heroicon-m-star">Vorlage</x-fa::badge>@endif
            </x-slot:badges>
            <x-slot:aktion>
                <div class="flex flex-wrap items-center gap-2">
                    <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" sofort />
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-top-right-on-square" wire:click="$dispatch('recipe-modal.oeffnen', { id: {{ $rezept->id }} })" data-rezept-bearbeiten>Im Editor öffnen</x-fa::button>
                </div>
            </x-slot:aktion>
            <x-slot:menue>
                <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.rezepte.dokument', ['id' => $rezept->id, 'profil' => 'produktion'])" target="_blank"
                    title="Rezeptblatt im neuen Fenster drucken" data-rezept-panel-druck>Rezeptblatt drucken</x-fa::menu-item>
                <x-fa::menu-item icon="heroicon-m-arrow-down-tray" :href="route('foodalchemist.rezepte.dokument', ['id' => $rezept->id, 'profil' => 'produktion', 'pdf' => 1])"
                    title="Rezeptblatt als PDF herunterladen" data-rezept-panel-pdf>PDF herunterladen</x-fa::menu-item>
                <x-fa::menu-item icon="heroicon-m-arrow-path" wire:click="neuBerechnen"
                    title="Kosten, Allergene und Ausbeute neu berechnen, auch in den Rezepten, die dieses verwenden" data-recompute-btn>Kosten neu berechnen</x-fa::menu-item>
                <div data-workflow>
                    @foreach($statusWahl as $wert => $lbl)
                        @if($rezept->status->value !== $wert)<x-fa::menu-item icon="heroicon-m-flag" wire:click="statusSetzen('{{ $wert }}')" data-status-btn="{{ $wert }}">{{ $lbl }}</x-fa::menu-item>@endif
                    @endforeach
                </div>
                <x-fa::menu-item icon="heroicon-m-document-duplicate" wire:click="duplizieren" title="Kopie dieses Rezepts anlegen" data-duplizieren-btn>Rezept duplizieren</x-fa::menu-item>
                <x-fa::menu-item :icon="$rezept->is_template ? 'heroicon-s-star' : 'heroicon-m-star'" wire:click="templateToggle"
                    title="{{ $rezept->is_template ? 'Ist Vorlage. Klick nimmt die Markierung zurück' : 'Als Vorlage für neue Rezepte markieren' }}" data-template-btn>{{ $rezept->is_template ? 'Vorlage-Markierung entfernen' : 'Als Vorlage markieren' }}</x-fa::menu-item>
            </x-slot:menue>
        </x-fa::detail-kopf>

        {{-- Spec 65: Detailspalte — Änderungen erst nach „Bearbeiten" (gleiche Sperre wie der Editor), „Fertig" gibt frei --}}
        <fieldset @disabled(in_array($sperr['modus'], ['lesen', 'fremd'], true)) class="contents" data-fa-lesemodus="{{ in_array($sperr['modus'], ['lesen', 'fremd'], true) ? '1' : '0' }}">

        {{-- 2 · Kennzahlen --}}
        <div data-kpi-karte>
            <x-fa::kpis :items="$kennzahlen" />
        </div>

        {{-- 3 · Offene Punkte: was vor der Verwendung fehlt --}}
        @if(($ptotal !== null && $ptotal > 0) || $rezept->yield_kg_manual !== null || $nichtZugeordnet > 0)
            <div class="flex flex-col gap-1" data-rezept-offen>
                @if($ptotal !== null && $ptotal > 0)
                    @if($vollstaendig)
                        <x-fa::signal tone="ok" data-kpi="bepreist">Alle {{ $ptotal }} Zutaten mit Preis</x-fa::signal>
                    @else
                        <x-fa::signal tone="warn" data-kpi="bepreist" title="Zutaten mit Preis">{{ $priced ?? 0 }} von {{ $ptotal }} Zutaten mit Preis, der EK ist nur vorläufig</x-fa::signal>
                        <p class="{{ $leise }}">Fehlende Preise stehen unten an der Zutat.</p>
                    @endif
                @endif
                @if($nichtZugeordnet > 0)
                    <x-fa::signal tone="warn">{{ $nichtZugeordnet }} {{ $nichtZugeordnet === 1 ? 'Zutat ist' : 'Zutaten sind' }} keinem Grundprodukt oder Rezept zugeordnet</x-fa::signal>
                @endif
                @if($rezept->yield_kg_manual !== null)
                    <x-fa::signal tone="warn">Ausbeute von Hand gesetzt, berechnet wären {{ $zahl($rezept->yield_kg, 3) }} kg</x-fa::signal>
                @endif
            </div>
        @endif

        <div class="flex flex-col">
            {{-- 4 · Deklaration: Allergene und Diät --}}
            <x-fa::section variant="plain" title="Allergene und Diät" icon="heroicon-o-shield-exclamation"
                :meta="'Sicherheit ' . \Platform\FoodAlchemist\Support\Labels::konfidenz($rezept->allergens_confidence)" data-allergen-konfidenz>
                @include('foodalchemist::livewire.recipes.partials.deklaration')
            </x-fa::section>

            {{-- 5 · Inhalt: Zutaten (Menge · Grundprodukt oder Unterrezept · EK der Zeile) --}}
            <x-fa::section variant="plain" title="Zutaten" icon="heroicon-o-list-bullet" :meta="$rezept->ingredients->count()" data-zutaten>
                @if($rezept->ingredients->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Zutaten">Im Editor die Zutaten erfassen, dann rechnet der Food.Alchemist Kosten und Allergene.</x-fa::empty>
                @else
                    <div class="flex flex-col">
                        @foreach($rezept->ingredients as $z)
                            <div wire:key="z-{{ $z->id }}" class="flex items-baseline gap-2.5 py-1.5 border-b border-[var(--fa-line)] last:border-b-0 text-[length:var(--fa-text-md)] {{ $z->is_optional ? 'opacity-60' : '' }}">
                                <span class="w-20 shrink-0 text-right tabular-nums text-[var(--fa-ink-2)]">{{ $menge($z->quantity) }}{{ $z->quantity_max !== null ? '–' . $menge($z->quantity_max) : '' }} <span class="text-[var(--fa-ink-3)]">{{ $z->unit?->slug }}</span></span>
                                <span class="min-w-0 flex-1">
                                    @if($z->gp !== null)
                                        <a href="{{ route('foodalchemist.gps.index', ['gp' => $z->gp_id]) }}" class="text-[var(--fa-accent)] hover:underline" title="Grundprodukt öffnen">{{ $z->gp->name }}</a>
                                    @elseif($z->referencedRecipe !== null)
                                        <a href="#" role="button" wire:click.prevent="zeige({{ $z->referenced_recipe_id }})" class="inline-flex items-baseline gap-1 text-left text-[var(--fa-info)] hover:underline" title="Unterrezept anzeigen">
                                            @svg('heroicon-m-arrow-turn-down-right', 'w-3.5 h-3.5 shrink-0 self-center'){{ $z->referencedRecipe->name }}
                                        </a>{{-- Spec 65: <a> statt <button> — bleibt im gesperrten Lesemodus (fieldset) bedienbar --}}
                                    @else
                                        <span class="text-[var(--fa-ink-2)]">{{ $z->display_name ?? $z->raw_text }}</span>
                                        <x-fa::signal tone="warn" class="ml-1" title="Keinem Grundprodukt oder Rezept zugeordnet">nicht zugeordnet</x-fa::signal>
                                    @endif
                                    @if($z->is_optional)<span class="{{ $leise }}"> (optional)</span>@endif
                                    @if(filled($z->raw_text) && $z->raw_text !== ($z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name))
                                        <span class="block truncate italic {{ $leise }}" title="{{ $z->raw_text }}">{{ $z->raw_text }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 tabular-nums {{ isset($zeilenEk[$z->id]) ? 'text-[var(--fa-ink)]' : 'text-[length:var(--fa-text-sm)] text-[var(--fa-warn)]' }}" data-zeilen-ek>{{ isset($zeilenEk[$z->id]) ? $zahl($zeilenEk[$z->id]) . ' €' : 'Preis fehlt' }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-fa::section>

            {{-- Spec 27: Endprodukt-Bild — „so soll es fertig aussehen" --}}
            @if($endprodukt !== null)
                <x-fa::section variant="plain" title="Endprodukt" icon="heroicon-o-photo" data-panel-endprodukt>
                    <figure class="flex flex-col gap-1">
                        <img src="{{ $endprodukt->url() }}" alt="{{ $endprodukt->caption ?? 'Endprodukt' }}"
                             class="w-full max-h-48 object-cover rounded-[var(--fa-radius-surface)] border border-[var(--fa-line)]" loading="lazy" />
                        @if($endprodukt->caption)
                            <figcaption class="{{ $leise }}">{{ $endprodukt->caption }}</figcaption>
                        @endif
                    </figure>
                </x-fa::section>
            @endif

            {{-- Spec 27: Anleitung = Schritte (Nummer + Text + Foto), nur lesen --}}
            @if($schritte->isNotEmpty())
                <x-fa::section variant="plain" title="Anleitung" icon="heroicon-o-queue-list" :meta="$schritte->count() . ' ' . ($schritte->count() === 1 ? 'Schritt' : 'Schritte')" data-panel-anleitung>
                    @php $letztePhase = '__init__'; @endphp
                    <ol class="flex flex-col gap-2">
                        @foreach($schritte as $s)
                            @if(($s->phase ?? '') !== $letztePhase)
                                @php $letztePhase = $s->phase ?? ''; @endphp
                                @if($letztePhase !== '')
                                    <li class="pt-1 {{ $titelKlein }} text-[var(--fa-accent)]">{{ $letztePhase }}</li>
                                @endif
                            @endif
                            <li class="flex items-start gap-2.5" wire:key="pstep-{{ $s->id }}">
                                <span class="shrink-0 grid place-items-center w-6 h-6 rounded-full bg-[var(--fa-neutral-soft)] text-[length:var(--fa-text-sm)] font-medium tabular-nums text-[var(--fa-ink-2)]">{{ $s->position }}</span>
                                <div class="min-w-0 flex-1 pt-0.5">
                                    <div class="text-[length:var(--fa-text-md)] leading-snug text-[var(--fa-ink)]">{!! \Illuminate\Support\Str::inlineMarkdown((string) $s->text) !!}</div>
                                    @if($s->photos->isNotEmpty())
                                        <div class="flex flex-wrap gap-1.5 mt-1.5">
                                            @foreach($s->photos as $foto)
                                                <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}"
                                                     class="w-20 h-14 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" loading="lazy" wire:key="pstepf-{{ $s->id }}-{{ $foto->id }}" />
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </x-fa::section>
            @endif

            @if($allgemeineFotos->isNotEmpty())
                <x-fa::section variant="plain" title="Rezept-Fotos" icon="heroicon-o-photo" data-panel-rezept-fotos>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($allgemeineFotos as $foto)
                            <img src="{{ $foto->url() }}" alt="{{ $foto->caption ?? '' }}" title="{{ $foto->caption ?? '' }}"
                                 class="w-20 h-14 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" loading="lazy" wire:key="pallgf-{{ $foto->id }}" />
                        @endforeach
                    </div>
                </x-fa::section>
            @endif

            {{-- 6 · Fachabschnitte --}}
            {{-- Pairing-Netz: Kombinationslogik + Graph (Spec 60: Anker ergeben sich aus dem Aromenprofil, keine Handpflege) --}}
            <x-fa::section variant="plain" title="Pairing-Netz" icon="heroicon-o-share"
                :meta="($kombination ?? null) !== null ? (($kombination['kennzahlen']['harmoniert'] ?? 0) . ' harmonieren · ' . ($kombination['kennzahlen']['spannung'] ?? 0) . ' Spannung') : null" data-kern-anker>
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ghost" icon-right="heroicon-m-arrow-up-right" href="#" x-on:click.prevent="" wire:click="$dispatch('pairing-netz.oeffnen', { recipeId: {{ $rezept->id }} })"
                        title="Ganzes Netz mit verwandten Rezepten und Vorschlägen öffnen" data-pairing-netz-btn>Netz öffnen</x-fa::button>
                </x-slot:actions>
                @if($pairingBereit)
                    @if($kombination ?? null)
                        <x-foodalchemist::kombination :daten="$kombination" />
                    @endif
                    <x-foodalchemist::pairing-netz :recipe-id="$rezept->id" :netz="$netz" />
                @elseif($pairingErlaubt)
                    <div wire:key="pairing-laden-{{ $rezept->id }}" x-init="$wire.pairingLaden()"
                         class="flex items-center gap-2 py-6 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-pairing-laedt>
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin') Pairing wird geladen …
                    </div>
                @endif
            </x-fa::section>

            {{-- #5 (2026-08): manuelle Pairings-Sektion (aroma/kontrast) bleibt AUSGEBLENDET. Das echte
                 Pairing kommt aus dem Anker-Graph (Pairing-Netz oben). Service + Daten bleiben
                 (setRecipePairing/recipePairings/removeRecipePairing) + ManuellePairingTest. --}}

            {{-- Eignung: Niveau und Sektor als Umschalter --}}
            @php
                $eignungVokab = \Platform\FoodAlchemist\Services\RecipeService::eignungVokabular();
                $eignungAktiv = ['level' => $rezept->levelSuitabilities->keyBy('level_slug'), 'sektor' => $rezept->sectorSuitabilities->keyBy('sector_slug')];
            @endphp
            <x-fa::section variant="plain" title="Eignung" icon="heroicon-o-user-group" description="Für welches Niveau und welchen Betrieb das Rezept passt. Klick schaltet um." data-eignungen>
                @if($fehlerEignung !== null)<x-fa::signal tone="crit" data-eignung-fehler>{{ $fehlerEignung }}</x-fa::signal>@endif
                <div class="flex flex-col gap-2">
                    @foreach(['level' => 'Niveau', 'sektor' => 'Sektor'] as $typ => $typLabel)
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="w-16 shrink-0 {{ $titelKlein }}">{{ $typLabel }}</span>
                            @foreach($eignungVokab[$typ]['slugs'] as $slug)
                                @php $eintrag = $eignungAktiv[$typ][$slug] ?? null; @endphp
                                <button type="button" wire:key="eig-{{ $typ }}-{{ $slug }}" wire:click="eignungToggle('{{ $typ }}', '{{ $slug }}')"
                                        class="{{ $chip }} {{ $eintrag !== null ? $chipAn : $chipAus }}" aria-pressed="{{ $eintrag !== null ? 'true' : 'false' }}"
                                        title="{{ $eintrag !== null ? 'Geeignet, ' . ($eintrag->source === 'manual' ? 'von Hand gesetzt' : 'von der KI vorgeschlagen') . ($eintrag->ai_confidence !== null ? ', ' . round($eintrag->ai_confidence * 100) . ' % sicher' : '') . '. Klick entfernt die Eignung' : 'Klick markiert als geeignet' }}"
                                        data-eignung-chip="{{ $typ }}-{{ $slug }}">@if($eintrag !== null)@svg('heroicon-m-check', 'w-3.5 h-3.5')@endif{{ $eignungLabel($slug) }}</button>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </x-fa::section>

            {{-- Nährwerte je 100 g (vorher nur als Fußzeile) --}}
            <x-fa::section variant="plain" title="Nährwerte je 100 g" icon="heroicon-o-chart-bar" data-rezept-naehrwerte>
                @if($rezept->nutri_kcal_per_100g !== null)
                    <x-slot:actions>
                        <x-fa::badge :tone="$konfTon[$rezept->nutri_confidence] ?? 'neutral'" title="Sicherheit der Nährwerte">{{ \Platform\FoodAlchemist\Support\Labels::konfidenz($rezept->nutri_confidence) }}</x-fa::badge>
                    </x-slot:actions>
                    <dl class="grid grid-cols-2 gap-x-4">
                        @foreach($naehrwertFelder as $feld => $lbl)
                            <div class="flex items-baseline justify-between gap-2 py-1 border-b border-[var(--fa-line)] {{ $feld === 'nutri_kcal_per_100g' ? 'col-span-2' : '' }}">
                                <dt class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $lbl }}</dt>
                                <dd class="text-[length:var(--fa-text-md)] font-semibold tabular-nums text-[var(--fa-ink)]">
                                    @if($rezept->{$feld} !== null && $feld === 'nutri_kcal_per_100g')
                                        {{ number_format((float) $rezept->{$feld}, 0, ',', '.') }}
                                    @elseif($rezept->{$feld} !== null)
                                        <x-fa::menge :value="$rezept->{$feld}" :decimals="1" unit="g" />
                                    @else
                                        <span class="font-normal text-[var(--fa-ink-3)]">fehlt</span>
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @else
                    <p class="{{ $leise }}">Nährwerte fehlen noch.</p>
                @endif
            </x-fa::section>

            {{-- Ersatz: selbst herstellen oder fertig kaufen --}}
            <x-fa::section variant="plain" title="Ersatz" icon="heroicon-o-scale" description="Fertigprodukt oder selbst hergestellt: was dieses Rezept ersetzen kann." data-sektion="ersatz">
                <div class="flex flex-col gap-1.5">
                    @forelse($ersatz as $e)
                        <div class="flex items-center gap-2 text-[length:var(--fa-text-md)]" wire:key="rq-equiv-{{ $e->id }}">
                            <x-fa::badge :tone="$e->gegen_kind === 'recipe' ? 'info' : 'neutral'" class="shrink-0">{{ $e->gegen_kind === 'recipe' ? 'Rezept' : 'Grundprodukt' }}</x-fa::badge>
                            <span class="min-w-0 flex-1 truncate text-[var(--fa-ink)]" title="{{ $e->gegen_name }}">{{ $e->gegen_name }}</span>
                            @if((float) $e->umrechnungsfaktor !== 1.0)<span class="shrink-0 tabular-nums {{ $leise }}" title="Umrechnungsfaktor">× {{ rtrim(rtrim(number_format($e->umrechnungsfaktor, 4, ',', '.'), '0'), ',') }}</span>@endif
                            <x-fa::icon-button size="sm" tone="danger" icon="heroicon-m-x-mark" label="Ersatz lösen" wire:click="ersatzLoesen({{ $e->id }})" />
                        </div>
                    @empty
                        <p class="{{ $leise }}" data-ersatz-leer>Kein Ersatz hinterlegt.</p>
                    @endforelse
                    @if($fehlerAnker !== null)<x-fa::signal tone="crit" data-ersatz-fehler>{{ $fehlerAnker }}</x-fa::signal>@endif
                    <div class="flex flex-col gap-1 pt-1" data-ersatz-verknuepfen>
                        <x-fa::input size="sm" type="search" wire:model.live.debounce.300ms="ersatzSuche" placeholder="Ersatz verknüpfen: Grundprodukt oder Rezept suchen" aria-label="Ersatz suchen und verknüpfen" data-ersatz-suche />
                        @foreach($ersatzKandidaten as $k)
                            <button type="button" wire:key="rq-ersk-{{ $k->kind }}-{{ $k->id }}" wire:click="ersatzVerknuepfen('{{ $k->kind }}', {{ $k->id }})" class="{{ $listenKnopf }}">
                                <x-fa::badge :tone="$k->kind === 'recipe' ? 'info' : 'neutral'" class="shrink-0">{{ $k->kind === 'recipe' ? 'Rezept' : 'Grundprodukt' }}</x-fa::badge>
                                <span class="min-w-0 flex-1 truncate">{{ $k->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </x-fa::section>

            @if($rezept->equipment->isNotEmpty())
                <x-fa::section variant="plain" title="Equipment" icon="heroicon-o-wrench-screwdriver" data-equipment>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($rezept->equipment as $geraet)<x-fa::badge>{{ $geraet->name }}</x-fa::badge>@endforeach
                    </div>
                </x-fa::section>
            @endif

            {{-- KI-Kontext der Erstellung (Call-Log ↔ Rezept) — nur bei KI-erstellten Rezepten befüllt --}}
            @include('foodalchemist::livewire.recipes.partials.ki-kontext')

            {{-- 7 · Verwendung: Rezepte und Gerichte, die dieses Rezept als Zutat führen --}}
            <x-fa::section variant="plain" title="Wo verwendet?" icon="heroicon-o-link" :meta="$eltern->count()" data-eltern>
                @if($eltern->isNotEmpty())
                    <div class="flex flex-col gap-0.5">
                        @foreach($eltern as $parent)
                            <a href="#" role="button" wire:key="el-{{ $parent->id }}"
                                    @if($parent->is_sales_recipe) wire:click.prevent="$dispatch('vk-modal.oeffnen', { id: {{ $parent->id }} })" @else wire:click.prevent="zeige({{ $parent->id }})" @endif
                                    class="{{ $listenKnopf }}" title="{{ $parent->is_sales_recipe ? 'Gericht öffnen' : 'Rezept anzeigen' }}" data-eltern-link>
                                @svg($parent->is_sales_recipe ? 'heroicon-o-banknotes' : 'heroicon-o-arrow-up', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                <span class="min-w-0 flex-1 truncate">{{ $parent->name }}</span>
                                <span class="shrink-0 {{ $leise }}">{{ $parent->is_sales_recipe ? 'Gericht' : 'Rezept' }}</span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="{{ $leise }}">In keinem Rezept und keinem Gericht verwendet.</p>
                @endif
            </x-fa::section>

            {{-- VERWALTUNG: tauschen + löschen (Pendant zum GP-Verwaltungsblock, 2026-09-04) — bewusst ganz am Ende.
                 Nur im Standalone-Panel: der Editor zeigt dasselbe Partial in seinem Verwaltungs-Reiter. --}}
            @if($tauschReferenzen !== null || ($tauschBilanz !== null && ($tauschBilanz['zeilen'] > 0 || $tauschBilanz['fremd_zeilen'] > 0)))
                <x-fa::section variant="plain" title="Verwaltung" icon="heroicon-o-cog-6-tooth" data-sektion="verwaltung">
                    @include('foodalchemist::livewire.recipes.partials.verwaltung', ['rezeptName' => $rezept->name, 'kompakt' => true])
                </x-fa::section>
            @endif
        </div>
        </fieldset>
    @endif
</div>
