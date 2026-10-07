{{-- Gericht-Detail (Verkauf) — Preis- und Rohertrags-Linse.
     fa-pass (2026-10-05): Anatomie Detail-Panels (DESIGN.md), Geschwister des Basisrezept-Details:
     Kopf (eine Hauptaktion „Im Editor öffnen", Komponenten bearbeiten/Drucken/PDF im Menü) ·
     Kennzahlen (VK brutto, EK, Wareneinsatz) mit Wareneinsatz-Balken und VK-Herleitung · Offene Punkte
     (Blocker, Klasse, KI-Vorschlag) · Allergene und Diät · Komponenten (Rollen-Vorschlag) · Pairing-Netz ·
     KI-Analyse · Eignung · Nährwerte · KI-Kontext · Wo verwendet?.
     Alles direkt sichtbar (nicht ausklappbar). Funktion, wire:-Bindungen und data-Marker unverändert. --}}
@php
    $cockpitDa = $rezept !== null && $cockpit !== null;
    $we = $cockpitDa ? ($cockpit['marge']['wareneinsatz_pct'] ?? null) : null;
    [$weTon, $weText] = $we === null ? ['neutral', 'kein VK'] : ($we > 35 ? ['crit', 'zu hoch'] : ($we > 30 ? ['warn', 'knapp'] : ['ok', 'im Ziel']));
    $weFuell = ['neutral' => 'bg-[var(--fa-ink-3)]', 'ok' => 'bg-[var(--fa-ok)]', 'warn' => 'bg-[var(--fa-warn)]', 'crit' => 'bg-[var(--fa-crit)]'][$weTon];
    $weBreite = $we === null ? 0 : round(max(0.0, min(100.0, (float) $we / 50 * 100)), 1);
    $vkQuelle = $cockpitDa ? (['manuell' => 'von Hand', 'class' => 'aus Klasse'][$cockpit['vk']['source'] ?? ''] ?? null) : null;
    $urteil = $kohaerenzStatus['cache'] ?? null;
    $heberJson = $urteil?->heber_json;
    $heberIdeen = $heberJson['vorschlaege'] ?? [];
    $kleinLabel = 'text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]';
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $listenKnopf = 'flex w-full items-center gap-2 px-2 py-1.5 rounded-[var(--fa-radius-control)] text-left text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]';
    $rollenText = ['aroma_treiber' => 'Aromaträger', 'komponente' => 'Komponente', 'beilage' => 'Beilage', 'garnitur' => 'Garnitur'];
    $geld = fn ($wert) => number_format((float) $wert, 2, ',', '.') . ' €';

    $kennzahlen = [];
    if ($cockpitDa) {
        $vkBrutto = $cockpit['sales_gross'];
        // Kennzahlen: VK brutto ist die eine Hauptzahl. Fehlende Werte werden gezeigt, nicht geschätzt.
        $kennzahlen = [
            [
                'label' => 'VK brutto',
                'value' => $vkBrutto !== null ? $geld($vkBrutto) : 'VK fehlt',
                'primary' => $vkBrutto !== null,
                'tone' => $vkBrutto === null ? 'crit' : null,
                'kpi' => 'vk-brutto',
            ],
            [
                'label' => 'EK',
                'value' => $rezept->ek_total_eur !== null ? $geld($rezept->ek_total_eur) : 'EK fehlt',
                'tone' => $rezept->ek_total_eur === null ? 'crit' : null,
                'title' => 'Einkauf des Gerichts',
                'kpi' => 'ek',
            ],
            [
                'label' => 'Wareneinsatz',
                'value' => $we !== null ? number_format((float) $we, 1, ',', '.') . ' %' : 'fehlt',
                'tone' => $we === null ? 'crit' : $weTon,
                'hint' => in_array($weTon, ['warn', 'crit'], true) && $we !== null ? $weText : null,
                'title' => $we !== null ? 'Ziel bis 30 %, knapp bis 35 % · ' . $weText : 'Wareneinsatz braucht VK und EK',
                'kpi' => 'wareneinsatz',
            ],
        ];
    }
    $nichtZugeordnet = $rezept !== null ? $rezept->ingredients->filter(fn ($z) => $z->gp === null && $z->referencedRecipe === null)->count() : 0;
    $konfTon = ['high' => 'ok', 'medium' => 'warn', 'low' => 'crit', 'unknown' => 'neutral'];
    $naehrwertFelder = [
        'nutri_kcal_per_100g' => 'kcal', 'nutri_protein_g_per_100g' => 'Eiweiß', 'nutri_fat_g_per_100g' => 'Fett',
        'nutri_saturated_fat_g_per_100g' => 'davon gesättigt', 'nutri_carbs_g_per_100g' => 'Kohlenhydrate',
        'nutri_sugar_g_per_100g' => 'davon Zucker', 'nutri_salt_g_per_100g' => 'Salz',
    ];
@endphp

<div class="p-4 flex flex-col gap-5 min-h-full bg-[var(--fa-ground)]" data-vk-panel>
    @if($rezept === null)
        <x-fa::empty icon="heroicon-o-banknotes" title="Kein Gericht gewählt">
            Gericht in der Liste anklicken. Hier erscheinen Preis, Wareneinsatz und Deklaration.
        </x-fa::empty>
    @else
        {{-- 1 · Kopf: Name, Verkaufstext, Status und Klasse, eine Hauptaktion, Weiteres im Menü --}}
        <x-fa::detail-kopf :title="$rezept->name" :subtitle="$rezept->sales_wording_standard" data-vk-aktionen>
            @if(!empty($rezeptBildUrl))
                <img src="{{ $rezeptBildUrl }}" alt="" class="mt-2 h-12 w-12 object-cover rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" data-vk-mini-bild>
            @endif
            @if($rezept->description !== null)
                <p class="mt-2 text-[length:var(--fa-text-md)] leading-relaxed text-[var(--fa-ink-2)]" data-vk-beschreibung>{{ $rezept->description }}</p>
            @endif
            <x-slot:badges>
                <x-fa::status :value="$rezept->status" />
                @if($rezept->dishClass !== null)
                    <x-fa::badge tone="info" title="{{ $rezept->dishClass->label }}">{{ $rezept->dishClass->mainGroup?->code ?? 'Gruppe offen' }} · {{ $rezept->dishClass->label }}</x-fa::badge>
                    @if($rezept->dishClass->diet_form)<x-fa::badge>{{ $rezept->dishClass->diet_form }}</x-fa::badge>@endif
                @else
                    <x-fa::badge tone="warn" title="Ohne Speisen-Klasse gibt es keinen VK-Vorschlag">Ohne Speisen-Klasse</x-fa::badge>
                @endif
            </x-slot:badges>
            <x-slot:aktion>
                <div class="flex flex-wrap items-center gap-2">
                    {{-- Spec 65: Änderungen in der Spalte erst nach „Bearbeiten" (gleiche Sperre wie der Gericht-Editor), „Fertig" gibt frei --}}
                    <x-foodalchemist::bearbeiten-leiste :zustand="$sperr" sofort />
                    <x-fa::button size="sm" variant="ghost" icon="heroicon-m-arrow-top-right-on-square" wire:click="$dispatch('vk-modal.oeffnen', { id: {{ $rezept->id }} })" data-vk-bearbeiten>Im Editor öffnen</x-fa::button>
                </div>
            </x-slot:aktion>
            <x-slot:menue>
                <x-fa::menu-item icon="heroicon-m-squares-2x2" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" data-vk-komponenten>Komponenten bearbeiten</x-fa::menu-item>
                <x-fa::menu-item icon="heroicon-m-printer" :href="route('foodalchemist.rezepte.dokument', ['id' => $rezept->id, 'profil' => 'produktion'])" target="_blank"
                    title="Druckansicht mit Profilen und Filtern" data-vk-panel-druck>Rezeptblatt drucken</x-fa::menu-item>
                <x-fa::menu-item icon="heroicon-m-arrow-down-tray" :href="route('foodalchemist.rezepte.dokument', ['id' => $rezept->id, 'profil' => 'produktion', 'pdf' => 1])"
                    title="PDF herunterladen" data-vk-panel-pdf>PDF herunterladen</x-fa::menu-item>
                @if($rezept->dishClass !== null)
                    <x-fa::menu-item icon="heroicon-m-sparkles" wire:click="ai_klassifizieren" title="Speisen-Klasse per KI neu vorschlagen" data-vk-klassifizieren>Klasse neu vorschlagen</x-fa::menu-item>
                @endif
            </x-slot:menue>
        </x-fa::detail-kopf>

        {{-- Spec 65: Detailspalte — Änderungen erst nach „Bearbeiten" (gleiche Sperre wie der Editor), „Fertig" gibt frei --}}
        <fieldset @disabled(in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true)) class="contents" data-fa-lesemodus="{{ in_array($sperr['modus'] ?? 'aus', ['lesen', 'fremd'], true) ? '1' : '0' }}">

        {{-- 2 · Kennzahlen: VK brutto, EK, Wareneinsatz — darunter Balken mit Schwellen 30/35 % und VK-Herleitung --}}
        <div class="flex flex-col gap-3" data-vk-kpis>
            <x-fa::kpis :items="$kennzahlen" data-vk-brutto />

            <div data-wareneinsatz>
                <div class="relative h-1.5 rounded-full bg-[var(--fa-neutral-soft)] overflow-hidden" role="presentation" title="Wareneinsatz: Ziel bis 30 %, knapp bis 35 %">
                    <div class="absolute inset-y-0 left-0 rounded-full {{ $weFuell }}" style="width: {{ $weBreite }}%"></div>
                    @foreach([30, 35] as $schwelle)
                        <div class="absolute inset-y-0 w-px bg-[var(--fa-line-strong)]" style="left: {{ $schwelle / 50 * 100 }}%"></div>
                    @endforeach
                </div>
            </div>

            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-[length:var(--fa-text-sm)]">
                <div class="flex items-baseline justify-between gap-2 min-w-0">
                    <dt class="text-[var(--fa-ink-2)]">VK netto @if($vkQuelle)<span class="text-[var(--fa-ink-3)]">{{ $vkQuelle }}</span>@endif</dt>
                    <dd class="font-medium text-[var(--fa-ink)]" data-vk-netto><x-fa::money :value="$cockpit['vk']['sales_net']" missing="VK fehlt" /></dd>
                </div>
                @if($cockpit['pro_einheit'] !== null)
                    <div class="flex items-baseline justify-between gap-2 min-w-0">
                        <dt class="text-[var(--fa-ink-2)]">Brutto je Einheit</dt>
                        <dd class="font-medium text-[var(--fa-ink)]"><x-fa::money :value="$cockpit['pro_einheit']['vk_brutto_pro_einheit']" /></dd>
                    </div>
                @endif
            </dl>

            @if($cockpit['verkauft_als'] !== null)
                @php
                    $va = $cockpit['verkauft_als'];
                @endphp
                <p class="flex flex-wrap items-baseline gap-x-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]" data-verkauft-als>
                    <span class="font-medium text-[var(--fa-ink)]">Verkauft als</span>
                    <span class="tabular-nums">{{ $va['anzahl'] !== null ? rtrim(rtrim(number_format((float) $va['anzahl'], 1, ',', '.'), '0'), ',') : 'Anzahl offen' }} {{ $va['unit'] }}</span>
                    @if($va['g_pro_einheit'] !== null)<span class="tabular-nums">· etwa {{ number_format($va['g_pro_einheit'], 0, ',', '.') }} g je {{ $va['unit'] }}</span>@endif
                    @if($va['yield_kg'] !== null)<span class="tabular-nums">· Ausbeute {{ number_format($va['yield_kg'], 2, ',', '.') }} kg</span>@endif
                </p>
            @endif

            {{-- Formel-Klartext: wie der VK zustande kommt --}}
            @if($rezept->markupClass !== null && ! $cockpit['formel_fehlt'] && $cockpit['vk']['vorschlag'] !== null)
                <p class="{{ $leise }}" data-formel-klartext><span class="font-mono">{{ $rezept->markupClass->code }}</span> · {{ $cockpit['vk']['vorschlag']['formel'] }}</p>
            @endif
        </div>

        {{-- 3 · Offene Punkte: warum (noch) kein VK-Vorschlag, fehlende Einordnung, KI-Vorschlag zur Klasse --}}
        @if($kiFehler !== null || $cockpit['formel_fehlt'] || $rezept->ek_total_eur === null || $rezept->markupClass === null || $rezept->dishClass === null || $nichtZugeordnet > 0 || $klasseVorschlag !== null)
            <div class="flex flex-col gap-2" data-vk-offen>
                @if($kiFehler !== null)
                    <x-fa::notice tone="crit" data-ki-fehler>{{ $kiFehler }}</x-fa::notice>
                @endif
                @if($cockpit['formel_fehlt'])
                    <x-fa::signal tone="warn" data-formel-fehlt>Für die Aufschlagsklasse {{ $rezept->markupClass?->code }} ist die Deckungsbeitrags-Formel noch nicht festgelegt. Den VK bitte von Hand setzen.</x-fa::signal>
                @elseif($rezept->ek_total_eur === null)
                    <x-fa::signal tone="warn" data-cockpit-leer>Noch kein Einkaufspreis. Komponenten ergänzen oder Lieferantenartikel zuordnen.</x-fa::signal>
                @elseif($rezept->markupClass === null)
                    <x-fa::signal tone="warn" data-cockpit-leer>Keine Aufschlagsklasse gesetzt. Einen VK-Vorschlag gibt es erst, wenn das Gericht eingeordnet ist.</x-fa::signal>
                @endif
                @if($nichtZugeordnet > 0)
                    <x-fa::signal tone="warn">{{ $nichtZugeordnet }} {{ $nichtZugeordnet === 1 ? 'Komponente ist' : 'Komponenten sind' }} keinem Grundprodukt oder Rezept zugeordnet</x-fa::signal>
                @endif
                @if($rezept->dishClass === null)
                    <div class="flex flex-wrap items-center gap-2">
                        <x-fa::signal tone="warn">Ohne Speisen-Klasse gibt es keinen VK-Vorschlag.</x-fa::signal>
                        <x-foodalchemist::ki-action action="ai_klassifizieren" variant="ai" icon="heroicon-o-sparkles" label="Klasse vorschlagen"
                            title="Speisen-Klasse per KI vorschlagen" data-vk-klassifizieren busy="Wird eingeordnet …" flash="Vorschlag da" />
                    </div>
                @endif

                {{-- M6-05: KI-Vorschlag Klasse — erst prüfen, dann übernehmen --}}
                @if($klasseVorschlag !== null)
                    <x-fa::notice tone="info" title="Vorschlag Speisen-Klasse: {{ $klasseVorschlag['klasse_name'] ?? 'kein sicherer Treffer' }}" data-klasse-vorschlag>
                        <span class="tabular-nums text-[var(--fa-ink-2)]">Sicherheit {{ round($klasseVorschlag['confidence'] * 100) }} %</span>
                        @if($klasseVorschlag['reasoning'] !== null)<p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">{{ $klasseVorschlag['reasoning'] }}</p>@endif
                        <div class="flex gap-1.5 mt-2">
                            @if($klasseVorschlag['klasse_id'] !== null)
                                <x-fa::button size="sm" variant="primary" wire:click="accept_klasse" data-klasse-accept>Klasse übernehmen</x-fa::button>
                            @endif
                            <x-fa::button size="sm" variant="ghost" wire:click="reject_klasse" data-klasse-reject>Verwerfen</x-fa::button>
                        </div>
                    </x-fa::notice>
                @endif
            </div>
        @endif

        <div class="flex flex-col">
            {{-- 4 · Deklaration: Allergene und Diät — Enthalten/Spuren zuerst, Vollliste eingeklappt --}}
            <x-fa::section variant="plain" title="Allergene und Diät" icon="heroicon-o-shield-exclamation" :meta="'Sicherheit ' . \Platform\FoodAlchemist\Support\Labels::konfidenz($rezept->allergens_confidence)">
                @include('foodalchemist::livewire.recipes.partials.deklaration')
            </x-fa::section>

            {{-- 5 · Inhalt: Komponenten, was auf dem Teller liegt --}}
            <x-fa::section variant="plain" title="Komponenten" icon="heroicon-o-list-bullet" :meta="$rezept->ingredients->count()" data-vk-zutaten>
                <x-slot:actions>
                    <x-foodalchemist::ki-action action="ai_rollen" variant="ai" icon="heroicon-o-user-group" label="Rollen verteilen"
                        title="Rollen der Komponenten per KI vorschlagen (Aromaträger, Komponente, Beilage, Garnitur)" data-vk-rollen busy="Wird verteilt …" flash="Vorschlag da" />
                </x-slot:actions>
                {{-- M6-05: KI-Vorschlag Rollen — erst prüfen, dann übernehmen --}}
                @if($rollenVorschlag !== null)
                    <x-fa::notice tone="info" title="Vorschlag Rollen-Verteilung" data-rollen-vorschlag>
                        <span class="tabular-nums text-[var(--fa-ink-2)]">Sicherheit {{ round($rollenVorschlag['confidence'] * 100) }} %</span>
                        @if($rollenVorschlag['rollen'] === [])
                            <p class="mt-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">Kein gültiger Vorschlag. Erlaubt sind Aromaträger, Komponente, Beilage und Garnitur.</p>
                        @else
                            <ul class="mt-1.5 flex flex-col gap-0.5">
                                @foreach($rollenVorschlag['rollen'] as $zeileId => $role)
                                    @php
                                        $zeile = $rezept->ingredients->firstWhere('id', $zeileId);
                                    @endphp
                                    <li class="flex items-baseline justify-between gap-3 text-[length:var(--fa-text-sm)]" wire:key="rv-{{ $zeileId }}">
                                        <span class="min-w-0 text-[var(--fa-ink)]">{{ $zeile?->referencedRecipe?->name ?? $zeile?->gp?->name ?? $zeile?->display_name ?? "Zeile {$zeileId}" }}</span>
                                        <span class="shrink-0 font-medium">{{ $rollenText[$role] ?? str_replace('_', ' ', (string) $role) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="flex gap-1.5 mt-2">
                            @if($rollenVorschlag['rollen'] !== [])
                                <x-fa::button size="sm" variant="primary" wire:click="accept_rollen" data-rollen-accept>Rollen übernehmen</x-fa::button>
                            @endif
                            <x-fa::button size="sm" variant="ghost" wire:click="reject_rollen" data-rollen-reject>Verwerfen</x-fa::button>
                        </div>
                    </x-fa::notice>
                @endif
                @if($rezept->ingredients->isEmpty())
                    <x-fa::empty compact icon="heroicon-o-list-bullet" title="Noch keine Komponenten">Über „Weitere Aktionen" und „Komponenten bearbeiten" zusammenstellen.</x-fa::empty>
                @else
                    <ul class="flex flex-col">
                        @foreach($rezept->ingredients as $z)
                            <li class="flex items-baseline gap-3 py-1.5 border-b border-[var(--fa-line)] last:border-0 text-[length:var(--fa-text-md)]" wire:key="vkz-{{ $z->id }}">
                                <span class="w-20 shrink-0 text-right text-[var(--fa-ink-2)]">@if($z->quantity !== null)<x-fa::menge :value="$z->quantity" :unit="$z->unit?->slug ?? ''" :decimals="2" />@endif</span>
                                <span class="min-w-0 flex-1 text-[var(--fa-ink)] break-words">
                                    @if($z->referencedRecipe !== null)@svg('heroicon-m-arrow-turn-down-right', 'w-3.5 h-3.5 inline-block align-[-2px] mr-1 text-[var(--fa-ink-3)]')@endif{{ $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-fa::section>

            {{-- 6 · Fachabschnitte --}}
            {{-- Pairing-Netz — Kombination (Spec 60) + Inline-Graph --}}
            <x-fa::section variant="plain" title="Pairing-Netz" icon="heroicon-o-share"
                :meta="($kombination ?? null) !== null ? (($kombination['kennzahlen']['harmoniert'] ?? 0) . ' harmonieren · ' . ($kombination['kennzahlen']['spannung'] ?? 0) . ' Spannung') : null" data-vk-kern-anker>
                <x-slot:actions>
                    <x-fa::button size="sm" variant="ghost" iconRight="heroicon-m-arrow-up-right" href="#" x-on:click.prevent="" wire:click="$dispatch('pairing-netz.oeffnen', { recipeId: {{ $rezept->id }} })"
                        title="Voller Graph mit verwandten Rezepten und Vorschlägen" data-vk-pairing-netz>Netz öffnen</x-fa::button>
                </x-slot:actions>
                {{-- Spec 60: Kombinationslogik statt Kern-Anker-Pflege --}}
                @if($pairingBereit)
                    @if($kombination)
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

            {{-- KI-Analyse — Kohärenz-Urteil und Teller-Heber (gecacht) --}}
            <x-fa::section variant="plain" title="KI-Analyse" icon="heroicon-o-sparkles" data-vk-ki-analyse>
                <div class="flex flex-col gap-3 text-[length:var(--fa-text-md)]">
                    <div class="flex flex-col gap-1">
                        <div class="flex items-center gap-2 flex-wrap" data-vk-kohaerenz>
                            <span class="text-[var(--fa-ink-2)]">Kulinarische Kohärenz</span>
                            @if($urteil?->score !== null)
                                <span class="font-semibold tabular-nums {{ $urteil->score >= 80 ? 'text-[var(--fa-ok)]' : ($urteil->score >= 50 ? 'text-[var(--fa-warn)]' : 'text-[var(--fa-crit)]') }}">{{ $urteil->score }} %</span>
                                @if($urteil->label !== null)<span class="text-[var(--fa-ink)]">{{ $urteil->label }}</span>@endif
                                @if($kohaerenzStatus['stale'] ?? false)<x-fa::signal tone="warn" data-vk-kohaerenz-stale>veraltet</x-fa::signal>@endif
                            @else
                                <span class="text-[var(--fa-ink-3)]">noch kein Urteil</span>
                            @endif
                            <x-foodalchemist::ki-action action="pruefeKohaerenz" variant="ai" icon="heroicon-o-sparkles"
                                :label="$urteil?->score !== null ? 'Erneut prüfen' : 'Kohärenz prüfen'" class="ml-auto"
                                data-vk-kohaerenz-pruefen busy="Wird geprüft …" flash="Geprüft" />
                        </div>
                        @if($urteil?->reasoning !== null)<p class="text-[length:var(--fa-text-sm)] leading-relaxed text-[var(--fa-ink-2)]">{{ $urteil->reasoning }}</p>@endif
                        @if($urteil?->score !== null)<p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] tabular-nums">{{ $urteil->judged_at?->format('d.m.Y') }} · {{ $urteil->judge_model }}</p>@endif
                    </div>
                    <div class="flex flex-col gap-1 pt-3 border-t border-[var(--fa-line)]">
                        <div class="flex items-center gap-2 flex-wrap" data-vk-heber>
                            <span class="text-[var(--fa-ink-2)]">Was hebt den Teller?</span>
                            @if($heberIdeen !== [])
                                <span class="text-[var(--fa-ink)] tabular-nums">{{ count($heberIdeen) }} {{ count($heberIdeen) === 1 ? 'Idee' : 'Ideen' }}</span>
                            @else
                                <span class="text-[var(--fa-ink-3)]">noch keine</span>
                            @endif
                            <x-foodalchemist::ki-action action="schlageHeberVor" variant="ai" icon="heroicon-o-sparkles"
                                :label="$heberIdeen !== [] ? 'Neu vorschlagen' : 'Ideen vorschlagen'" class="ml-auto"
                                data-vk-heber-vorschlagen busy="Wird vorgeschlagen …" flash="Vorschlag da" />
                        </div>
                        @if(($heberJson['einschaetzung'] ?? null) !== null)<p class="text-[length:var(--fa-text-sm)] leading-relaxed text-[var(--fa-ink-2)]">{{ $heberJson['einschaetzung'] }}</p>@endif
                    </div>
                    <p class="pt-3 border-t border-[var(--fa-line)] text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-vk-nachbarn>Aroma-Nachbarn: aromaverwandte Zutaten zeigt das Pairing-Netz oben.</p>
                </div>
            </x-fa::section>

            {{-- Eignung — Sektor und Niveau pflegen --}}
            <x-fa::section variant="plain" title="Eignung" icon="heroicon-o-user-group"
                :meta="$sektorEignungen->count() + $niveauEignungen->count()" data-vk-eignung>
                <x-slot:actions>
                    <x-foodalchemist::ki-action action="kiEignung" variant="ai" icon="heroicon-o-sparkles" label="Eignung vorschlagen"
                        title="Sektor und Niveau per KI vorschlagen, nur eindeutig geeignete" data-ki-eignung
                        busy="Wird ermittelt …" flash="Vorschlag da" />
                </x-slot:actions>
                @if($eignungVorschlag !== null)
                    <x-fa::notice tone="info" title="Vorschlag: geeignet für" data-eignung-vorschlag>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($eignungVorschlag['slugs'] as $slug => $typ)<x-fa::badge tone="info">{{ $typ === 'level' ? 'Niveau' : 'Sektor' }}: {{ $slug }}</x-fa::badge>@endforeach
                        </div>
                        <div class="flex items-center gap-1.5 mt-2">
                            <x-fa::button size="sm" variant="primary" wire:click="eignungUebernehmen" data-eignung-uebernehmen>Eignung übernehmen</x-fa::button>
                            <x-fa::button size="sm" variant="ghost" wire:click="eignungVerwerfen">Verwerfen</x-fa::button>
                            <span class="ml-auto text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)] tabular-nums">Sicherheit {{ round($eignungVorschlag['confidence'] * 100) }} %</span>
                        </div>
                    </x-fa::notice>
                @endif
                <div class="flex flex-col gap-2">
                    @foreach(['sektor' => ['Sektor', $sektorEignungen, 'sector_slug'], 'level' => ['Niveau', $niveauEignungen, 'level_slug']] as $typ => [$lbl, $eignungen, $slugSpalte])
                        <div class="flex items-center gap-1.5 flex-wrap" data-eignung-zeile="{{ $typ }}">
                            <span class="w-14 shrink-0 {{ $kleinLabel }}">{{ $lbl }}</span>
                            @forelse($eignungen as $e)
                                <x-fa::badge :tone="$typ === 'sektor' ? 'neutral' : 'info'" wire:key="eig-{{ $typ }}-{{ $e->id }}" class="pr-0.5" title="{{ $e->source }}{{ $e->ai_confidence !== null ? ' · ' . round($e->ai_confidence * 100) . ' %' : '' }}">
                                    {{ $e->{$slugSpalte} }}
                                    <button type="button" wire:click="eignungEntfernen('{{ $typ }}', '{{ $e->{$slugSpalte} }}')" class="inline-flex items-center justify-center w-5 h-5 rounded-full hover:bg-[var(--fa-surface)]" aria-label="{{ $lbl }} {{ $e->{$slugSpalte} }} entfernen" title="Entfernen">@svg('heroicon-m-x-mark', 'w-3.5 h-3.5')</button>
                                </x-fa::badge>
                            @empty
                                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">noch keine</span>
                            @endforelse
                            <x-fa::select size="sm" wire:change="eignungSetzen('{{ $typ }}', $event.target.value)" aria-label="{{ $lbl }} hinzufügen" class="w-36 ml-auto" data-eignung-select="{{ $typ }}">
                                <option value="">{{ $lbl }} hinzufügen</option>
                                @foreach($eignungVokabular[$typ]['slugs'] as $slug)
                                    @if(!$eignungen->contains($slugSpalte, $slug))<option value="{{ $slug }}">{{ $slug }}</option>@endif
                                @endforeach
                            </x-fa::select>
                        </div>
                    @endforeach
                </div>
            </x-fa::section>

            {{-- Nährwerte je 100 g (Geschwister des Basisrezept-Panels) --}}
            <x-fa::section variant="plain" title="Nährwerte je 100 g" icon="heroicon-o-chart-bar" data-vk-naehrwerte>
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

            {{-- KI-Kontext der Erstellung (Call-Log ↔ Gericht) — nur bei KI-generierten Gerichten befüllt --}}
            @include('foodalchemist::livewire.recipes.partials.ki-kontext')

            {{-- 7 · Verwendung: Concepts und Pakete mit einer Position auf dem Gericht, Rezepte mit dem Gericht als Komponente --}}
            <x-fa::section variant="plain" title="Wo verwendet?" icon="heroicon-o-link" :meta="$inConcepts->count() + $eltern->count()" data-vk-verwendung>
                @if($inConcepts->isEmpty() && $eltern->isEmpty())
                    <p class="{{ $leise }}">In keinem Concept, Paket oder Rezept verwendet.</p>
                @else
                    <div class="flex flex-col gap-0.5">
                        @foreach($inConcepts as $c)
                            <div class="flex items-center justify-between gap-3 px-2 py-1 text-[length:var(--fa-text-md)]" wire:key="vkc-{{ $c->id }}">
                                <span class="min-w-0 break-words text-[var(--fa-ink)]">{{ $c->name }}</span>
                                <span class="shrink-0 {{ $leise }}">{{ $c->kind === 'paket' ? 'Paket' : 'Concept' }}</span>
                            </div>
                        @endforeach
                        @foreach($eltern as $parent)
                            <a href="#" role="button" wire:key="vkel-{{ $parent->id }}"
                                    @if($parent->is_sales_recipe) wire:click.prevent="zeige({{ $parent->id }})" @else wire:click.prevent="$dispatch('recipe-modal.oeffnen', { id: {{ $parent->id }} })" @endif
                                    class="{{ $listenKnopf }}" title="{{ $parent->is_sales_recipe ? 'Gericht anzeigen' : 'Rezept öffnen' }}">
                                @svg($parent->is_sales_recipe ? 'heroicon-o-banknotes' : 'heroicon-o-arrow-up', 'w-4 h-4 shrink-0 text-[var(--fa-ink-3)]')
                                <span class="min-w-0 flex-1 truncate">{{ $parent->name }}</span>
                                <span class="shrink-0 {{ $leise }}">{{ $parent->is_sales_recipe ? 'Gericht' : 'Rezept' }}</span>
                            </a>{{-- Spec 65: <a> statt <button> — bleibt im gesperrten Lesemodus (fieldset) bedienbar --}}
                        @endforeach
                    </div>
                @endif
            </x-fa::section>
        </div>
        </fieldset>
    @endif
</div>
