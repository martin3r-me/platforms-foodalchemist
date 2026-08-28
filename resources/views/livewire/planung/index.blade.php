{{-- Planungs-/Kreativ-Cockpit (Doppel-Diamant). Haus-Layout: links Kategorie→Session-Baum,
     Mitte Dashboard/Vorschau, rechts Detail; „Öffnen" → Fullscreen-Dark-Editor (Analyse·Skizzen·Planung·Composer). --}}
@assets
<script src="/_platform/fa-assets/foodalchemist-pairing-netz.iife.js?v={{ config('platform.fa_pairing_netz_hash', '0') }}" defer></script>
@endassets
@php
    extract(\Platform\FoodAlchemist\Support\Ui::maps());
    $statusLabel = ['divergenz' => 'Divergenz', 'konvergenz' => 'Konvergenz', 'erledigt' => 'Erledigt'];
    $modeLabel = ['voll_kreativ' => 'Voll kreativ', 'hybrid' => 'Hybrid', 'datenbank' => 'Datenbank'];
    // Wirkungs-Hints je Modus (die EINE Reuse-Achse — ersetzt den früheren „Bestand-Nutzung"-Regler):
    $modeHint = [
        'voll_kreativ' => 'Neu erfinden — Bestand wird ignoriert, alle Komponenten frisch angelegt.',
        'hybrid' => 'Bestand zuerst — vorhandene Basisrezepte werden wiederverwendet, Neues nur für echte Lücken.',
        'datenbank' => 'Nur Bestand — ausschließlich vorhandene Basisrezepte; für Lücken ohne Treffer entsteht KEIN neues Rezept (offene Zeile bleibt sichtbar).',
    ];
    $chip = fn ($t, $c = 'bg-black/[0.04] text-gray-600') => '<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ' . $c . '">' . e($t) . '</span>';
    $skizzenAnzahl = $skizzen ? (count($skizzen['einzel']) + collect($skizzen['gruppen'])->sum(fn ($g) => count($g['ideen']))) : 0;
    // Skizzen-Lauf-Status (Etappe 4, Teil 2b): jüngster aus der Skizze gestarteter Gericht-Go → Karte.
    $laufStatus = [
        'running' => ['läuft', 'bg-amber-100 text-amber-700'],
        'review' => ['prüfen', 'bg-violet-100 text-violet-700'],
        'done' => ['fertig', 'bg-emerald-100 text-emerald-700'],
        'failed' => ['fehlgeschlagen', 'bg-rose-100 text-rose-700'],
    ];
    $skizzeLaufBadge = function ($ideaId) use ($skizzenLauf, $laufStatus, $chip) {
        $l = $skizzenLauf[(int) $ideaId] ?? null;
        if ($l === null) {
            return '';
        }
        [$lbl, $cls] = $laufStatus[$l['status']] ?? [$l['status'], 'bg-black/[0.04] text-gray-600'];
        $html = $chip('▸ ' . $lbl, $cls);
        // E5 (Spec 40): „daraus entstanden" — der materialisierte Artefakt-Name (Lern-/Rückblick-Signal).
        if (trim((string) ($l['ergebnis'] ?? '')) !== '') {
            $html .= ' <span class="text-[10px] text-gray-500" title="daraus entstanden">→ ' . e($l['ergebnis']) . '</span>';
        }
        return $html;
    };
    // Finale Etappe (Hauptseite): Kaskaden-Status-Badge je Session aus $kaskaden (jüngster Lauf).
    // Kein Lauf → „Entwurf" (verwaister Entwurf, sichtbar). Farben spiegeln $laufStatus.
    $kaskaden = $kaskaden ?? [];
    $kaskadeStatusStil = [
        'entwurf' => 'bg-black/[0.04] text-gray-500',
        'läuft' => 'bg-amber-100 text-amber-700',
        'prüfen' => 'bg-violet-100 text-violet-700',
        'fertig' => 'bg-emerald-100 text-emerald-700',
        'fehlgeschlagen' => 'bg-rose-100 text-rose-700',
    ];
    $kaskadeBadge = function ($sessionId) use ($kaskaden, $kaskadeStatusStil, $chip) {
        $status = $kaskaden[(int) $sessionId]['status'] ?? 'entwurf';
        return $chip(\Illuminate\Support\Str::ucfirst($status), $kaskadeStatusStil[$status] ?? $kaskadeStatusStil['entwurf']);
    };
    $kaskadeLaeuft = fn ($sessionId) => (bool) ($kaskaden[(int) $sessionId]['running'] ?? false);
    // Stufen-Fortschritt kompakt: „Gerichte 1/1 · Basisrezepte 0/3".
    $kaskadeFortschritt = function ($sessionId) use ($kaskaden) {
        $stufen = $kaskaden[(int) $sessionId]['stufen'] ?? [];
        return collect($stufen)->map(fn ($st) => $st['label'] . ' ' . $st['fertig'] . '/' . $st['total'])->implode(' · ');
    };
    // UX: Auto-Anzeige-Titel — Ergebnis-Name (Kaskaden-Artefakt) → Analyse-Anfang → gespeicherter Titel.
    $anzeigeTitel = function ($s) use ($kaskaden) {
        $t = $kaskaden[(int) $s->id]['titel'] ?? null;
        if (is_string($t) && trim($t) !== '') {
            return trim($t);
        }
        $ana = trim((string) ($s->analysis ?? ''));
        return $ana !== '' ? \Illuminate\Support\Str::limit($ana, 42) : $s->title;
    };
    // UX: Typ-Icon je Session (aus dem jüngsten Lauf-Scope).
    $typIconMap = ['concept' => 'heroicon-o-squares-2x2', 'gericht' => 'heroicon-o-cake', 'rezept' => 'heroicon-o-beaker', 'vollkaskade' => 'heroicon-o-bolt'];
    $typIcon = fn ($s) => $typIconMap[$kaskaden[(int) $s->id]['scope'] ?? ''] ?? 'heroicon-o-light-bulb';
    // Board: Ausgabe-Ziel-Chip (Owner). Kein Owner → „Frei". Text-Chip, keine Emojis.
    $ausgabeZielLabel = ['foodbook' => 'Foodbook', 'speisekarte' => 'Speisekarte', 'speiseplan' => 'Speiseplan', 'offer' => 'Angebot', 'concept' => 'Concept'];
    $ausgabeChip = function ($sessionId) use ($kaskaden, $ausgabeZielLabel, $chip) {
        $ot = $kaskaden[(int) $sessionId]['owner_type'] ?? null;
        if ($ot === null) {
            return $chip('Frei', 'bg-black/[0.04] text-gray-500');
        }
        $name = trim((string) ($kaskaden[(int) $sessionId]['owner_name'] ?? ''));
        $lbl = ($ausgabeZielLabel[$ot] ?? \Illuminate\Support\Str::ucfirst($ot)) . ($name !== '' ? ' · ' . $name : '');
        return $chip($lbl, 'bg-sky-500/10 text-sky-700');
    };
@endphp

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Planung" icon="heroicon-o-light-bulb" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Planung'],
        ]" />
    </x-slot>

    {{-- LINKS: Neue Planung + Kategorie→Session-Baum --}}
    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Planungen" width="w-80">
            <div class="p-3 space-y-3">
                <div class="flex gap-2">
                    <input type="text" wire:model="neuTitel" wire:keydown.enter="neuePlanung"
                           placeholder="Neue Planung …" class="{{ $input }}" />
                    <button wire:click="neuePlanung" class="{{ $btnPrimary }} shrink-0" title="Neue Planung">
                        @svg('heroicon-o-plus', 'w-4 h-4')
                    </button>
                </div>

                {{-- Suche + Status-Filter (finale Etappe #17) — filtern Liste UND Zuletzt-Karten. --}}
                <div class="space-y-2">
                    <input type="text" wire:model.live.debounce.300ms="sucheListe" placeholder="Planungen durchsuchen …"
                           class="{{ $input }} !py-1.5" data-planung-suche />
                    <div class="flex gap-2">
                        <select wire:model.live="filterStatus" class="{{ $input }} !py-1.5 text-xs" data-planung-filter-status>
                            <option value="">Alle Status</option>
                            <option value="entwurf">Entwurf</option>
                            <option value="läuft">Läuft</option>
                            <option value="prüfen">Prüfen</option>
                            <option value="fertig">Fertig</option>
                            <option value="fehlgeschlagen">Fehlgeschlagen</option>
                        </select>
                        <select wire:model.live="filterTyp" class="{{ $input }} !py-1.5 text-xs" data-planung-filter-typ>
                            <option value="">Alle Typen</option>
                            <option value="rezept">Basisrezept</option>
                            <option value="gericht">Gericht</option>
                            <option value="concept">Concept</option>
                            <option value="foodbook">Foodbook</option>
                            <option value="speisekarte">Speisekarte</option>
                            <option value="speiseplan">Speiseplan</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-2 max-h-[68vh] overflow-y-auto -mx-1 px-1">
                    @forelse($baum as $ast)
                        <div wire:key="cat-{{ $loop->index }}">
                            <p class="text-[11px] font-semibold text-gray-500 uppercase tracking-wide px-1 mb-0.5">{{ $ast['category'] }}</p>
                            <div class="space-y-0.5">
                                @foreach($ast['sessions'] as $s)
                                    {{-- Zeile = wählbarer Button + hover-eingeblendeter Papierkorb (Löschen, #17-Rest);
                                         kein verschachtelter Button (group-flex-div). --}}
                                    <div wire:key="sess-{{ $s->id }}"
                                         class="group flex items-center gap-1 rounded-md {{ $active && $active->id === $s->id ? 'bg-violet-500/10' : 'hover:bg-black/[0.04]' }}">
                                        <button type="button" wire:click="waehle({{ $s->id }})"
                                                class="flex-1 min-w-0 flex items-center justify-between gap-2 text-left px-2 py-1 text-xs {{ $active && $active->id === $s->id ? 'text-violet-700' : 'text-gray-700' }}">
                                            {{-- UX: Typ-Icon + Auto-Anzeige-Titel (Ergebnis-Name → Analyse → Titel). --}}
                                            <span class="flex items-center gap-1.5 min-w-0">
                                                @svg($typIcon($s), 'w-3.5 h-3.5 shrink-0 text-gray-400')
                                                <span class="truncate">{{ $anzeigeTitel($s) }}</span>
                                            </span>
                                            @php $stat = $kaskaden[$s->id]['status'] ?? 'entwurf'; @endphp
                                            {{-- UX: deutlicheres Status-Badge (farbige Pille statt kleiner chip). --}}
                                            <span class="shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $kaskadeStatusStil[$stat] ?? $kaskadeStatusStil['entwurf'] }}" data-planung-status="{{ $stat }}">
                                                @if($kaskadeLaeuft($s->id))<span class="w-1.5 h-1.5 rounded-full bg-current opacity-70 animate-pulse" data-planung-puls></span>@endif
                                                {{ \Illuminate\Support\Str::ucfirst($stat) }}
                                            </span>
                                        </button>
                                        <button type="button" wire:click="planungVerwerfen({{ $s->id }})"
                                                wire:confirm="Diese Planung verwerfen? (reversibel — Soft-Delete)"
                                                class="shrink-0 px-1.5 py-1 rounded text-gray-300 hover:text-rose-600 opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity"
                                                title="Planung verwerfen" data-planung-listen-verwerfen="{{ $s->id }}">
                                            @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        @if($sucheListe !== '' || $filterStatus !== '')
                            <p class="text-xs text-gray-500 px-1">Keine Planung passt zu Suche/Filter.</p>
                        @else
                            <p class="text-xs text-gray-500 px-1">Noch keine Planungen — links oben eine starten oder im Trendradar „In Planung öffnen".</p>
                        @endif
                    @endforelse
                </div>
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- MITTE: Vorschau / Dashboard --}}
    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        {{-- Leitstelle: freie 1-Klick-Erstellung — die eine KI-Erstell-Fläche (de-trend). Legt eine
             leichte „Freie Erstellung"-Session (cockpit_frei) an und öffnet den Editor auf dem
             Planung-Tab mit den Regler-Leitplanken. Trend bleibt EIN Input, nicht der Rahmen. --}}
        <div class="{{ $card }} p-4 relative z-40" x-data="{ fbOpen: @js($fbPanelAuf), skOpen: false, spOpen: false, offOpen: @js($offerPanelAuf), neuMenu: false }">
            <div class="flex flex-wrap items-center gap-2">
                {{-- Ein „Neu erstellen"-Knopf (Dominique 2026-08-23) statt sechs Buttons — Dropdown-Menü. --}}
                <div class="relative" @click.outside="neuMenu = false">
                    <button type="button" @click="neuMenu = !neuMenu" class="{{ $btnPrimary }}" data-frei-neu :class="neuMenu ? 'ring-2 ring-violet-400' : ''">
                        @svg('heroicon-o-plus', 'w-4 h-4') Neu erstellen @svg('heroicon-o-chevron-down', 'w-3.5 h-3.5')
                    </button>
                    <div x-show="neuMenu" x-cloak x-transition class="absolute left-0 mt-1 z-50 w-60 rounded-xl border border-black/10 bg-white shadow-xl p-1 space-y-0.5" data-frei-menu>
                        <button wire:click="schnellErstellen('rezept')" @click="neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-rezept>@svg('heroicon-o-beaker', 'w-4 h-4 text-violet-500') Basisrezept</button>
                        <button wire:click="schnellErstellen('gericht')" @click="neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-gericht>@svg('heroicon-o-cake', 'w-4 h-4 text-violet-500') Gericht</button>
                        <button wire:click="schnellErstellen('concept')" @click="neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-concept>@svg('heroicon-o-squares-2x2', 'w-4 h-4 text-violet-500') Concept</button>
                        <button wire:click="schnellImport" @click="neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-import>@svg('heroicon-o-document-arrow-down', 'w-4 h-4 text-violet-500') Rezept importieren</button>
                        <button wire:click="schnellComposer" @click="neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-composer>@svg('heroicon-o-sparkles', 'w-4 h-4 text-violet-500') Composer</button>
                        <button type="button" @click="fbOpen = true; skOpen=false; spOpen=false; offOpen=false; neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-foodbook>@svg('heroicon-o-book-open', 'w-4 h-4 text-violet-500') Foodbook aus Brief</button>
                        <button type="button" @click="skOpen = true; fbOpen=false; spOpen=false; offOpen=false; neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-speisekarte>@svg('heroicon-o-clipboard-document-list', 'w-4 h-4 text-violet-500') Speisekarte aus Brief</button>
                        <button type="button" @click="spOpen = true; fbOpen=false; skOpen=false; offOpen=false; neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-speiseplan>@svg('heroicon-o-calendar-days', 'w-4 h-4 text-violet-500') Speiseplan aus Brief</button>
                        <button type="button" @click="offOpen = true; fbOpen=false; skOpen=false; spOpen=false; neuMenu=false" class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg text-sm text-gray-800 hover:bg-violet-500/10 text-left" data-frei-angebot>@svg('heroicon-o-document-text', 'w-4 h-4 text-violet-500') Angebot aus Brief</button>
                    </div>
                </div>
                <span class="text-[11px] text-gray-500 ml-1">— Basisrezept · Gericht · Concept · Import · Composer · Foodbook · Speisekarte · Speiseplan · Angebot (KI, mit Regler-Leitplanken).</span>
            </div>

            {{-- Spec 42 F1: Ein ganzes Foodbook aus einem Brief planen — Rahmen (Gerüst/Struktur) +
                 Inhalte entstehen HIER in der Leitstelle; das Foodbook ist reine Ausgabe. --}}
            <div x-show="fbOpen" x-cloak class="mt-3 border-t border-gray-200 pt-3 space-y-2" data-foodbook-brief-panel>
                @if($fbOwnerId)
                    <p class="text-[11px] text-violet-700 bg-violet-500/10 rounded px-2 py-1" data-fb-owner-hinweis>
                        Planung für ein bestehendes Foodbook — Brief eingeben, Struktur + Inhalte entstehen hier und docken zurück.
                    </p>
                @else
                    <input type="text" wire:model="fbTitel" data-fb-titel
                           placeholder="Foodbook-Name (optional)"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400">
                @endif
                <textarea wire:model="fbBrief" rows="3" data-fb-brief
                          placeholder="Brief: Anlass, Gäste, Saison, Niveau, Budget …"
                          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400"></textarea>
                @if($fbMeldung)
                    <p class="text-xs text-rose-600" data-fb-meldung>{{ $fbMeldung }}</p>
                @endif
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="foodbookAusBrief" wire:loading.attr="disabled" wire:target="foodbookAusBrief"
                            class="{{ $btnPrimary }}" data-fb-erzeugen>
                        <span wire:loading.remove wire:target="foodbookAusBrief">@svg('heroicon-o-sparkles', 'w-4 h-4') Foodbook erzeugen (KI)</span>
                        <span wire:loading wire:target="foodbookAusBrief">Erzeuge …</span>
                    </button>
                    <span class="text-[11px] text-gray-500">— Struktur + Inhalte laufen in der Leitstelle und docken automatisch ins Foodbook.</span>
                </div>
            </div>

            {{-- Speisekarte aus Brief (Landing-Panel, gespiegelt von Foodbook) --}}
            <div x-show="skOpen" x-cloak class="mt-3 border-t border-gray-200 pt-3 space-y-2" data-speisekarte-brief-panel>
                <input type="text" wire:model="skTitel" placeholder="Speisekarten-Name (optional)"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400" data-landing-sk-titel>
                <textarea wire:model="skBrief" rows="3" placeholder="Brief: Anlass, Küchenstil, Saison, Niveau, Preis-Korridor …"
                          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400" data-landing-sk-brief></textarea>
                @if($skMeldung)<p class="text-xs text-rose-600" data-landing-sk-meldung>{{ $skMeldung }}</p>@endif
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="speisekarteAusBrief" wire:loading.attr="disabled" wire:target="speisekarteAusBrief" class="{{ $btnPrimary }}" data-landing-sk-erzeugen>
                        <span wire:loading.remove wire:target="speisekarteAusBrief">@svg('heroicon-o-sparkles', 'w-4 h-4') Speisekarte erzeugen (KI)</span>
                        <span wire:loading wire:target="speisekarteAusBrief">Erzeuge …</span>
                    </button>
                    <span class="text-[11px] text-gray-500">— je Gang/Kategorie eine Rubrik; Inhalte docken automatisch in die Karte.</span>
                </div>
            </div>

            {{-- Speiseplan aus Brief (Landing-Panel, gespiegelt von Foodbook) --}}
            <div x-show="spOpen" x-cloak class="mt-3 border-t border-gray-200 pt-3 space-y-2" data-speiseplan-brief-panel>
                <input type="text" wire:model="spTitel" placeholder="Speiseplan-Name (optional)"
                       class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400" data-landing-sp-titel>
                <textarea wire:model="spBrief" rows="3" placeholder="Brief: Anlass, Saison, Küchenstil, Zyklus (z. B. „4 Wochen“), Diät-Fokus …"
                          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400" data-landing-sp-brief></textarea>
                @if($spMeldung)<p class="text-xs text-rose-600" data-landing-sp-meldung>{{ $spMeldung }}</p>@endif
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="speiseplanAusBrief" wire:loading.attr="disabled" wire:target="speiseplanAusBrief" class="{{ $btnPrimary }}" data-landing-sp-erzeugen>
                        <span wire:loading.remove wire:target="speiseplanAusBrief">@svg('heroicon-o-sparkles', 'w-4 h-4') Speiseplan erzeugen (KI)</span>
                        <span wire:loading wire:target="speiseplanAusBrief">Erzeuge …</span>
                    </button>
                    <span class="text-[11px] text-gray-500">— GV-Linien + Zyklus als Standard; die Kaskade füllt die Zellen brief-gesteuert.</span>
                </div>
            </div>

            {{-- #5 (2026-08-28): Angebot aus Brief (Landing-Panel, gespiegelt von Speisekarte) --}}
            <div x-show="offOpen" x-cloak class="mt-3 border-t border-gray-200 pt-3 space-y-2" data-angebot-brief-panel>
                @if($offerOwnerId)
                    <p class="text-[11px] text-violet-700 bg-violet-500/10 rounded px-2 py-1" data-offer-owner-hinweis>
                        Planung für ein bestehendes Angebot — Brief eingeben, die Konzepte entstehen hier und docken ans Angebot zurück.
                    </p>
                @else
                    <input type="text" wire:model="offerTitel" placeholder="Angebots-Name (optional)"
                           class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400" data-landing-offer-titel>
                @endif
                <textarea wire:model="offerBrief" rows="3" placeholder="Brief: Anlass, Gäste/Pax, Saison, Niveau, Budget, Servierform …"
                          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-violet-400 focus:ring-1 focus:ring-violet-400" data-landing-offer-brief></textarea>
                @if($offerMeldung)<p class="text-xs text-rose-600" data-landing-offer-meldung>{{ $offerMeldung }}</p>@endif
                <div class="flex flex-wrap items-center gap-2">
                    <button wire:click="angebotAusBrief" wire:loading.attr="disabled" wire:target="angebotAusBrief" class="{{ $btnPrimary }}" data-landing-offer-erzeugen>
                        <span wire:loading.remove wire:target="angebotAusBrief">@svg('heroicon-o-sparkles', 'w-4 h-4') Angebot erzeugen (KI)</span>
                        <span wire:loading wire:target="angebotAusBrief">Erzeuge …</span>
                    </button>
                    <span class="text-[11px] text-gray-500">— je Slot ein Konzept; die Konzepte docken automatisch ans Angebot (Preise folgen im Angebots-Editor).</span>
                </div>
            </div>
        </div>

            {{-- Board (Leitstelle-Dashboard) — ersetzt die frühere Zuletzt/Prüfen-Ansicht. Immer sichtbar,
                 auch bei gewählter Session (Karte-Klick füllt NUR die rechte Details-Sidebar + markiert die
                 Karte — das Board bleibt als Überblick stehen). Der linke Filter grenzt ein: dieselbe
                 gefilterte $sessions-Menge landet in den Status-Spalten. „Öffnen" = Editor. Poll nur, wenn
                 tatsächlich etwas läuft (kein Dauer-Poll). --}}
            <div data-planung-board {{ $irgendeinLaeuft ? 'wire:poll.3s' : '' }}>
                @include('foodalchemist::livewire.planung.partials.board-worker-kopf')

                @php
                    $spalten = ['entwurf' => 'Entwurf', 'läuft' => 'Läuft', 'prüfen' => 'Zu prüfen', 'fertig' => 'Fertig', 'fehlgeschlagen' => 'Fehlgeschlagen'];
                    $nachStatus = $sessions->groupBy(fn ($s) => $kaskaden[(int) $s->id]['status'] ?? 'entwurf');
                @endphp

                @if($sessions->count() === 0)
                    <div class="{{ $card }} p-4 text-xs text-gray-500" data-planung-board-leer>Noch keine Planungen — oben „Neu erstellen".</div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-5 gap-3 items-start" data-planung-board-spalten>
                        @foreach($spalten as $key => $label)
                            @php $spaltenSessions = ($nachStatus[$key] ?? collect())->values(); @endphp
                            <div class="min-w-0" data-planung-spalte="{{ $key }}">
                                <div class="flex items-center gap-1.5 mb-2 px-0.5">
                                    <span class="text-[11px] font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</span>
                                    <span class="inline-flex items-center justify-center min-w-[1.25rem] h-4 px-1 rounded-full text-[10px] font-bold {{ $kaskadeStatusStil[$key] ?? 'bg-black/[0.04] text-gray-500' }}" data-planung-spalte-count>{{ $spaltenSessions->count() }}</span>
                                </div>
                                <div class="space-y-2">
                                    @forelse($spaltenSessions as $s)
                                        @include('foodalchemist::livewire.planung.partials.board-karte', ['s' => $s])
                                    @empty
                                        <div class="text-[10px] text-gray-300 px-1 py-1.5">—</div>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>{{-- /board --}}
    </x-ui-page-container>

    {{-- RECHTS: Detail --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Details" width="w-80" :maxWidth="560" scope="activity_planung" side="right">
            @if($active)
                <div class="p-4 space-y-3">
                    <h3 class="text-sm font-semibold text-gray-800">{{ $active->title }}</h3>
                    <div class="flex flex-wrap gap-1">
                        {!! $kaskadeBadge($active->id) !!}
                        {!! $chip($statusLabel[$active->status] ?? $active->status, 'bg-violet-500/10 text-violet-700') !!}
                        {!! $chip($modeLabel[$active->creative_mode] ?? $active->creative_mode) !!}
                    </div>
                    <dl class="text-[11px] text-gray-500 space-y-1">
                        <div class="flex justify-between"><dt>Herkunft</dt><dd>{{ $active->source_knowledge_document_id ? 'Trend #' . $active->source_knowledge_document_id : 'Freier Brief' }}</dd></div>
                    </dl>
                    {{-- Kaskaden-Kurzstatus je Stufe — ohne den Editor zu öffnen (finale Etappe). --}}
                    @php $aktStufen = $kaskaden[$active->id]['stufen'] ?? []; @endphp
                    @if($aktStufen !== [])
                        <div class="pt-1" data-planung-kaskadenstand>
                            <p class="text-[11px] font-semibold text-gray-600 mb-1">Kaskaden-Stand</p>
                            <div class="space-y-0.5">
                                @foreach($aktStufen as $st)
                                    <div class="flex items-center justify-between text-[11px]">
                                        <span class="text-gray-600">{{ $st['label'] }}</span>
                                        <span class="text-gray-500">{{ $st['fertig'] }}/{{ $st['total'] }} · {{ $st['zustand'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div class="flex items-center gap-2 pt-1">
                        <button wire:click="oeffne({{ $active->id }})" class="{{ $btnGhostXs }}">Im Editor öffnen</button>
                        <button wire:click="planungVerwerfen({{ $active->id }})"
                                wire:confirm="Diese Planung verwerfen? (reversibel — Soft-Delete)"
                                class="{{ $btnGhostXs }} !text-rose-600" data-planung-details-verwerfen>
                            @svg('heroicon-o-trash', 'w-3.5 h-3.5') Verwerfen
                        </button>
                    </div>
                </div>
            @else
                <div class="p-4 text-xs text-gray-500">Eine Planung wählen, um Herkunft, Status und Lineage zu sehen.</div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    {{-- FULLSCREEN-DARK-EDITOR --}}
    <x-foodalchemist::modal name="planung-editor" fullscreen dark-canvas title="Planung"
                            :title-name="$active?->title" tab-init="gericht">
        <x-slot:actions>
            <button wire:click="speichern" class="{{ $btnPrimary }}">
                @svg('heroicon-o-check', 'w-4 h-4')
                Speichern
            </button>
            @if($meldung !== null)
                <span class="text-xs text-emerald-300">{{ $meldung }}</span>
            @endif
            @if($fehler !== null)
                <span class="text-xs text-rose-300">{{ $fehler }}</span>
            @endif
            @if($margenWarnung !== null)
                <span class="text-xs text-amber-300" data-margen-warnung>⚠ {{ $margenWarnung }}</span>
            @endif
        </x-slot:actions>

        <x-slot:tabs>
            <div class="flex gap-1">
                {{-- Analyse + Skizzen (Spec-40-E0-Ideations-Einstieg) retired (Dominique 2026-08-23):
                     ungeerdeter Brainstorm-Umweg, abgelöst durch Composer (geerdet) + Brief-Kaskaden.
                     DishIdea/IdeenService bleibt intern (Materialisierung, Kapitel-Ideen-MCP). --}}
                <button type="button" @click="tab='basisrezept'"
                        :class="tab==='basisrezept' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Basisrezept</button>
                <button type="button" @click="tab='gericht'"
                        :class="tab==='gericht' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Gericht</button>
                <button type="button" @click="tab='concept'"
                        :class="tab==='concept' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Concept</button>
                <button type="button" @click="tab='composer'"
                        :class="tab==='composer' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Composer</button>
                <button type="button" @click="tab='import'"
                        :class="tab==='import' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Import</button>
                {{-- Ausgabe-Formen (Spec-42-Vollzug): eigene Kickoff-Tabs — die ganze Planung lebt in der
                     Leitstelle, die Module kuratieren nur. Optisch abgesetzt (Trennstrich). --}}
                <span class="mx-1 self-center h-4 w-px bg-white/15"></span>
                <button type="button" @click="tab='foodbook'"
                        :class="tab==='foodbook' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Foodbook</button>
                <button type="button" @click="tab='speisekarte'"
                        :class="tab==='speisekarte' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Speisekarte</button>
                <button type="button" @click="tab='speiseplan'"
                        :class="tab==='speiseplan' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Speiseplan</button>
                {{-- Worker (Ausführung/Status) bewusst ganz am Ende (Dominique 2026-08-24): erst erstellen/
                     planen, dann die Kaskade beobachten. --}}
                <span class="mx-1 self-center h-4 w-px bg-white/15"></span>
                <button type="button" @click="tab='worker'"
                        :class="tab==='worker' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium inline-flex items-center gap-1">Worker @if($laeuft)<span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>@endif</button>
            </div>
        </x-slot:tabs>

        @if($active)
            @if(($ownerKontext ?? null))
                {{-- E1b (Spec 40): Owner-Kontext-Banner — macht den Einbahn-Sprung zum sichtbaren Round-Trip:
                     WOFÜR wird hier geplant + Rückweg ins Ausgabe-Modul (Deep-Link auf die Ausgabe). --}}
                <div class="mb-3 flex items-center justify-between gap-2 rounded-lg border border-violet-500/30 bg-violet-500/10 px-3 py-2">
                    <p class="text-[11px] text-violet-200">
                        @svg('heroicon-o-link', 'w-3.5 h-3.5 inline align-text-bottom')
                        Planung für {{ $ownerKontext['typ_label'] }} „{{ $ownerKontext['name'] }}" — die hier erstellten Konzepte landen automatisch dort.
                    </p>
                    <a href="{{ route($ownerKontext['route'], $ownerKontext['route_param']) }}"
                       class="shrink-0 inline-flex items-center gap-1 text-[11px] text-violet-300 hover:text-violet-100">
                        @svg('heroicon-o-arrow-left', 'w-3.5 h-3.5') zurück zum {{ $ownerKontext['typ_label'] }}
                    </a>
                </div>
            @endif
            {{-- IMPORT — bestehende Rezeptur (Text/Web-Copy/Text-PDF) TREU übernehmen + GEERDET anlegen.
                 Getrennt vom Generator (der veredelt): hier 1:1 extrahieren, dann am Resolver an GPs binden.
                 Foto/Bild NICHT hier (Vision noch nicht in der Plattform-LLM) — Foto gibst du dem Assistenten im Chat. --}}
            {{-- AUSGABE-FORMEN (Spec-42-Vollzug) — Kickoff-Tabs: aus einem Brief plant die Leitstelle die
                 ganze Ausgabeform (owner-getaggte Voll-Kaskade), die Inhalte docken automatisch zurück.
                 Foodbook + Speisekarte live; Speiseplan folgt (andere Struktur: Linien+Zyklus statt Gänge). --}}
            <div wire:key="planung-tab-foodbook" x-show="tab==='foodbook'" x-cloak class="space-y-4">
                {{-- Stage 2 (Dominique): ein BESTEHENDES Foodbook wählen → Gerüst planen → Kaskaden je Kapitel
                     durchgehen. Oder leer lassen = neues Foodbook aus Brief. --}}
                <x-foodalchemist::modal-section title="Foodbook planen">
                    <label class="block text-[11px] text-slate-400 mb-1">Bestehendes Foodbook wählen</label>
                    <select wire:model.live="fbOwnerId" class="{{ $input }} w-full" data-tab-fb-auswahl>
                        <option value="">— neues Foodbook aus Brief —</option>
                        @foreach($fbAuswahl as $fbo)<option value="{{ $fbo->id }}">{{ $fbo->label }}</option>@endforeach
                    </select>
                    <p class="text-[11px] text-slate-400 mt-2">
                        Wählen: Gerüst planen + Kaskaden durchgehen (Buch-Ebene + Kapitel-Steuerung erscheinen unten).
                        Leer: ein neues Foodbook aus einem Brief.
                    </p>
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section :title="$fbOwnerId ? 'Gerüst aus Brief (füllt das gewählte Foodbook)' : 'Neues Foodbook aus Brief'">
                    <p class="text-[11px] text-slate-400 mb-2">
                        Struktur (Kapitel) + Inhalte entstehen hier in der Leitstelle und docken automatisch ins Foodbook (reine Ausgabe).
                    </p>
                    @unless($fbOwnerId)
                        <input type="text" wire:model="fbTitel" class="{{ $input }} w-full mb-2" placeholder="Foodbook-Name (optional)" data-tab-fb-titel>
                    @endunless
                    <textarea wire:model="fbBrief" rows="4" class="{{ $input }} w-full" placeholder="Brief: Anlass, Gäste, Saison, Niveau, Budget …" data-tab-fb-brief></textarea>
                    @if($fbMeldung) <p class="text-[11px] text-rose-400 mt-2" data-tab-fb-meldung>{{ $fbMeldung }}</p> @endif
                    <div class="mt-3">
                        <button type="button" wire:click="foodbookAusBrief" wire:loading.attr="disabled" wire:target="foodbookAusBrief" class="{{ $btnPrimary }} disabled:opacity-40" data-tab-fb-erzeugen>
                            <span wire:loading.remove wire:target="foodbookAusBrief">{{ $fbOwnerId ? 'Gerüst planen + Kaskade (KI)' : 'Foodbook erzeugen (KI)' }}</span>
                            <span wire:loading wire:target="foodbookAusBrief">erzeuge …</span>
                        </button>
                    </div>
                </x-foodalchemist::modal-section>

                {{-- Buch-Ebene + Kapitel-Steuerung (die aus dem Foodbook-Modul verschobene Planung): sobald ein
                     Foodbook GEWÄHLT ist (fbOwnerId) ODER die aktive Session ein Foodbook ist. Die Rails brauchen
                     nur die foodbook-id; „Kapitel erzeugen" verlangt ein Gerüst (sonst Hinweis in der Rail). --}}
                @php
                    $fbAktiv = $fbOwnerId;
                    if ($fbAktiv === null && ($ownerKontext['owner_type'] ?? null) === 'foodbook') {
                        $fbAktiv = (int) $ownerKontext['owner_id'];
                    }
                @endphp
                @if($fbAktiv !== null)
                    <x-foodalchemist::modal-section title="Buch-Ebene (Leitplanken · Briefing · Leitidee)">
                        <livewire:foodalchemist.planung.foodbook-kontext-rail
                            :foodbook-id="$fbAktiv"
                            :key="'fbkontext-'.$fbAktiv" />
                    </x-foodalchemist::modal-section>
                    <x-foodalchemist::modal-section title="Kapitel-Steuerung">
                        <livewire:foodalchemist.planung.kapitel-rail
                            :foodbook-id="$fbAktiv"
                            :session-id="$sessionId"
                            :key="'kaprail-'.$fbAktiv" />
                    </x-foodalchemist::modal-section>
                @endif
            </div>

            <div wire:key="planung-tab-speisekarte" x-show="tab==='speisekarte'" x-cloak class="space-y-4">
                {{-- Stage 2 (SK/SP-Parität): bestehende Speisekarte wählen ODER neu aus Brief. --}}
                <x-foodalchemist::modal-section title="Speisekarte planen">
                    <label class="block text-[11px] text-slate-400 mb-1">Bestehende Speisekarte wählen</label>
                    <select wire:model.live="skOwnerId" class="{{ $input }} w-full" data-tab-sk-auswahl>
                        <option value="">— neue Speisekarte aus Brief —</option>
                        @foreach($skAuswahl as $sko)<option value="{{ $sko->id }}">{{ $sko->name }}</option>@endforeach
                    </select>
                    <p class="text-[11px] text-slate-400 mt-2">
                        Wählen: Struktur (Rubriken) + Inhalte werden für die gewählte Karte geplant. Leer: eine neue Speisekarte.
                    </p>
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section :title="$skOwnerId ? 'Aus Brief planen (füllt die gewählte Speisekarte)' : 'Neue Speisekarte aus Brief'">
                    <p class="text-[11px] text-slate-400 mb-2">
                        Je Gang/Kategorie entsteht eine Rubrik, die Inhalte docken automatisch als Positionen in die Karte.
                    </p>
                    @unless($skOwnerId)
                        <input type="text" wire:model="skTitel" class="{{ $input }} w-full mb-2" placeholder="Speisekarten-Name (optional)" data-tab-sk-titel>
                    @endunless
                    <textarea wire:model="skBrief" rows="4" class="{{ $input }} w-full" placeholder="Brief: Anlass, Küchenstil, Saison, Niveau, Preis-Korridor …" data-tab-sk-brief></textarea>
                    @if($skMeldung) <p class="text-[11px] text-rose-400 mt-2" data-tab-sk-meldung>{{ $skMeldung }}</p> @endif
                    <div class="mt-3">
                        <button type="button" wire:click="speisekarteAusBrief" wire:loading.attr="disabled" wire:target="speisekarteAusBrief" class="{{ $btnPrimary }} disabled:opacity-40" data-tab-sk-erzeugen>
                            <span wire:loading.remove wire:target="speisekarteAusBrief">{{ $skOwnerId ? 'Planen + Kaskade (KI)' : 'Speisekarte erzeugen (KI)' }}</span>
                            <span wire:loading wire:target="speisekarteAusBrief">erzeuge …</span>
                        </button>
                    </div>
                </x-foodalchemist::modal-section>
            </div>

            <div wire:key="planung-tab-speiseplan" x-show="tab==='speiseplan'" x-cloak class="space-y-4">
                {{-- Stage 2 (SK/SP-Parität): bestehenden Speiseplan wählen ODER neu aus Brief. --}}
                <x-foodalchemist::modal-section title="Speiseplan planen">
                    <label class="block text-[11px] text-slate-400 mb-1">Bestehenden Speiseplan wählen</label>
                    <select wire:model.live="spOwnerId" class="{{ $input }} w-full" data-tab-sp-auswahl>
                        <option value="">— neuer Speiseplan aus Brief —</option>
                        @foreach($spAuswahl as $spo)<option value="{{ $spo->id }}">{{ $spo->name }}</option>@endforeach
                    </select>
                    <p class="text-[11px] text-slate-400 mt-2">
                        Wählen: die Zellen (Tag × Mahlzeit × Linie) werden für den gewählten Plan gefüllt. Leer: ein neuer Speiseplan.
                    </p>
                </x-foodalchemist::modal-section>

                <x-foodalchemist::modal-section :title="$spOwnerId ? 'Aus Brief planen (füllt den gewählten Speiseplan)' : 'Neuer Speiseplan aus Brief'">
                    <p class="text-[11px] text-slate-400 mb-2">
                        @unless($spOwnerId)Menü-Linien (Menü 1 / Vegetarisch / Dessert) + Zyklus entstehen als GV-Standard (im Speiseplan-Editor frei änderbar); @endunless
                        die Kaskade füllt jede Zelle (Tag × Mahlzeit × Linie) brief-gesteuert.
                    </p>
                    @unless($spOwnerId)
                        <input type="text" wire:model="spTitel" class="{{ $input }} w-full mb-2" placeholder="Speiseplan-Name (optional)" data-tab-sp-titel>
                    @endunless
                    <textarea wire:model="spBrief" rows="4" class="{{ $input }} w-full" placeholder="Brief: Anlass, Saison, Küchenstil, Zyklus (z. B. „4 Wochen"), Diät-Fokus …" data-tab-sp-brief></textarea>
                    @if($spMeldung) <p class="text-[11px] text-rose-400 mt-2" data-tab-sp-meldung>{{ $spMeldung }}</p> @endif
                    <div class="mt-3">
                        <button type="button" wire:click="speiseplanAusBrief" wire:loading.attr="disabled" wire:target="speiseplanAusBrief" class="{{ $btnPrimary }} disabled:opacity-40" data-tab-sp-erzeugen>
                            <span wire:loading.remove wire:target="speiseplanAusBrief">{{ $spOwnerId ? 'Planen + Kaskade (KI)' : 'Speiseplan erzeugen (KI)' }}</span>
                            <span wire:loading wire:target="speiseplanAusBrief">erzeuge …</span>
                        </button>
                    </div>
                </x-foodalchemist::modal-section>
            </div>

            <div wire:key="planung-tab-import" x-show="tab==='import'" class="space-y-4">
                @if($importStep === 'eingabe')
                    <x-foodalchemist::modal-section title="Rezeptur importieren">
                        <p class="text-[11px] text-slate-400 mb-2">
                            Bestehendes Rezept einfügen oder als Text-PDF hochladen — wird TREU übernommen
                            (nichts erfunden) und im System <strong>geerdet</strong> (Zutaten an Grundprodukte
                            gebunden). Verschachtelte Rezepte (Gericht mit Sauce/Püree) werden als verknüpfte
                            Sub-Rezepte angelegt.
                        </p>
                        <div class="flex items-center gap-2 mb-2">
                            <label class="text-[11px] text-slate-400">Anlegen als</label>
                            <select wire:model="importTyp" class="{{ $input }} sm:w-48">
                                <option value="basisrezept">Basisrezept</option>
                                <option value="gericht">Gericht (Verkauf)</option>
                            </select>
                            <span class="text-[10px] text-slate-500">(Vorschlag wird nach dem Lesen gesetzt)</span>
                        </div>
                        <textarea wire:model="importText" rows="10" class="{{ $input }} w-full font-mono text-[12px]"
                                  placeholder="Rezept-Text hier einfügen (Zutaten + Zubereitung; Sektionen wie »Für die Sauce: …« werden als Komponenten erkannt) …"></textarea>
                        <div class="flex items-center gap-3 mt-2">
                            <input type="file" wire:model="importPdf" accept="application/pdf" class="text-[11px] text-slate-300" />
                            <span wire:loading wire:target="importPdf" class="text-[10px] text-amber-300">lädt …</span>
                        </div>
                        @error('importPdf') <p class="text-[10px] text-rose-400 mt-1">{{ $message }}</p> @enderror
                        <div class="mt-3">
                            <button type="button" wire:click="importExtrahieren" wire:loading.attr="disabled"
                                    wire:target="importExtrahieren,importPdf" class="{{ $btnPrimary }} disabled:opacity-40">
                                <span wire:loading.remove wire:target="importExtrahieren">Lesen &amp; strukturieren</span>
                                <span wire:loading wire:target="importExtrahieren">liest … (kann ~15 s dauern)</span>
                            </button>
                        </div>
                        @if($importMeldung) <p class="text-[11px] text-rose-400 mt-2">{{ $importMeldung }}</p> @endif
                    </x-foodalchemist::modal-section>
                @elseif($importStep === 'vorschau')
                    <x-foodalchemist::modal-section title="Vorschau — prüfen &amp; anlegen">
                        <div class="flex items-center gap-2 mb-2">
                            <input type="text" wire:model="importVorschau.name" class="{{ $input }} flex-1" placeholder="Name" />
                            <select wire:model="importTyp" class="{{ $input }} w-40">
                                <option value="basisrezept">Basisrezept</option>
                                <option value="gericht">Gericht</option>
                            </select>
                        </div>
                        <p class="text-[11px] text-slate-400 mb-1">Zutaten (Menge · Einheit · Bezeichnung)</p>
                        <div class="space-y-1 mb-2">
                            @foreach(($importVorschau['zutaten'] ?? []) as $zi => $z)
                                {{-- #6 (Dominique 2026-08-28): das geteilte $input trägt w-full → kollidierte mit w-16/flex-1,
                                     die breite Bezeichnungs-Spalte kollabierte (Namen unsichtbar, obwohl extrahiert). Feste
                                     Breiten inline erzwingen (gewinnt gegen w-full, braucht keinen Asset-Rebuild). --}}
                                <div class="flex items-center gap-1" wire:key="izut-{{ $zi }}">
                                    <input type="text" wire:model="importVorschau.zutaten.{{ $zi }}.quantity" class="{{ $input }}" style="flex:0 0 4.5rem;width:4.5rem" placeholder="Menge" data-import-zutat-menge />
                                    <input type="text" wire:model="importVorschau.zutaten.{{ $zi }}.unit" class="{{ $input }}" style="flex:0 0 5.5rem;width:5.5rem" placeholder="Einheit" data-import-zutat-einheit />
                                    <input type="text" wire:model="importVorschau.zutaten.{{ $zi }}.text" class="{{ $input }}" style="flex:1 1 0;width:auto;min-width:0" placeholder="Bezeichnung" data-import-zutat-text />
                                </div>
                            @endforeach
                        </div>
                        @if(!empty($importVorschau['komponenten']))
                            <p class="text-[11px] text-slate-400 mb-1">Erkannte Komponenten (werden als Sub-Rezepte angelegt)</p>
                            <div class="space-y-1 mb-2">
                                @foreach($importVorschau['komponenten'] as $ki => $k)
                                    <div class="rounded border border-white/10 px-2 py-1 text-[11px] text-slate-300" wire:key="ikomp-{{ $ki }}">
                                        <strong>{{ $k['name'] ?? '—' }}</strong> · {{ count($k['zutaten'] ?? []) }} Zutaten
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <p class="text-[11px] text-slate-400 mb-1">Zubereitung</p>
                        <textarea wire:model="importVorschau.preparation" rows="6" class="{{ $input }} w-full text-[12px] mb-2"></textarea>
                        <div class="flex gap-2">
                            <button type="button" wire:click="importAnlegen" wire:loading.attr="disabled" wire:target="importAnlegen"
                                    class="{{ $btnPrimary }} disabled:opacity-40">
                                <span wire:loading.remove wire:target="importAnlegen">Geerdet anlegen</span>
                                <span wire:loading wire:target="importAnlegen">erdet …</span>
                            </button>
                            <button type="button" wire:click="importReset" class="{{ $btnGhost }}">Verwerfen</button>
                        </div>
                        @if($importMeldung) <p class="text-[11px] text-rose-400 mt-2">{{ $importMeldung }}</p> @endif
                    </x-foodalchemist::modal-section>
                @elseif($importStep === 'fertig' && $importErgebnis)
                    <x-foodalchemist::modal-section title="Importiert (Entwurf)">
                        <p class="text-[12px] text-slate-200 mb-1">
                            „{{ $importErgebnis['name'] }}" als Entwurf angelegt
                            @if(!empty($importErgebnis['sub_recipes'])) · {{ count($importErgebnis['sub_recipes']) }} Sub-Rezept(e) @endif
                        </p>
                        @if(($importErgebnis['offen'] ?? 0) > 0)
                            <p class="text-[11px] text-amber-300 mb-2">⚠ {{ $importErgebnis['offen'] }} Zutat(en) ohne GP-Treffer — noch nicht geerdet.</p>
                            <button type="button" wire:click="importGpsMinten" wire:loading.attr="disabled" wire:target="importGpsMinten" class="{{ $btnGhost }} mb-2">
                                <span wire:loading.remove wire:target="importGpsMinten">Fehlende GPs anlegen</span>
                                <span wire:loading wire:target="importGpsMinten">legt an …</span>
                            </button>
                        @else
                            <p class="text-[11px] text-emerald-300 mb-2">✓ Alle Zutaten geerdet.</p>
                        @endif
                        <button type="button" wire:click="importReset" class="{{ $btnGhost }}">Weiteres importieren</button>
                    </x-foodalchemist::modal-section>
                @endif
            </div>

            {{-- ANALYSE --}}
            {{-- BASISREZEPT — eigener Tab mit seinen Leitplanken --}}
            <div wire:key="planung-tab-basisrezept" x-show="tab==='basisrezept'">
                @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'rezept', 'vk' => false, 'goLabel' => 'Basisrezept', 'goIcon' => 'heroicon-o-beaker'])
            </div>

            {{-- GERICHT — Leitplanken inkl. VK-Achsen --}}
            <div wire:key="planung-tab-gericht" x-show="tab==='gericht'">
                @include('foodalchemist::livewire.planung.partials.erstellen-tab', ['scope' => 'gericht', 'vk' => true, 'goLabel' => 'Gericht', 'goIcon' => 'heroicon-o-cake'])
            </div>

            {{-- CONCEPT (= das „Menü"): Briefing → LLM füllt die semantischen Hüllen → Zusammenstellung
                 (Pakete/Buffet) nach den Leitplanken; braucht Gerichte → kaskadiert nach unten. --}}
            <div wire:key="planung-tab-concept" x-show="tab==='concept'" class="space-y-4">
                <x-foodalchemist::modal-section title="Briefing — was für ein Menü / Concept">
                    @include('foodalchemist::livewire.planung.partials.schnellstart-chips', ['scope' => 'concept'])
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Titel (optional)</label>
                    <input type="text" wire:model="eingabe.concept.titel" class="{{ $input }} mb-3" placeholder="z. B. CHEFS.CORNER — Sommer-Menü" data-planung-titel />
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Briefing (geht in die Erzeugung)</label>
                    <textarea wire:model="eingabe.concept.brief" rows="4" class="{{ $input }} mb-3" placeholder="Anlass, Zielgruppe, Richtung, Pakete/Buffet-Struktur, Gänge …"></textarea>
                    <label class="{{ $label ?? 'text-[11px] text-gray-500' }}">Kreativ-Modus</label>
                    <select wire:model.live="eingabe.concept.creative_mode" class="{{ $input }}">
                        @foreach($modeLabel as $val => $lbl)
                            <option value="{{ $val }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                    <p class="text-[11px] text-gray-500 mt-1">{{ $modeHint[$eingabe['concept']['creative_mode'] ?? 'voll_kreativ'] ?? '' }}</p>
                </x-foodalchemist::modal-section>

                {{-- KI-Kopf (Etappe 2b, geplanter Pfad): arbeitet den Plan-Entwurf vorab aus (Leitidee/USP/
                     Inszenierung/Geschmackswelten + Gänge-Gerüst) und öffnet ihn im Conceptor zur Prüfung —
                     NOCH ohne Gerichte. Danach der Go „aus geprüftem Plan". Neben dem direkten „Go" unten. --}}
                {{-- DF-2 (Spec 41, Entscheid 2026-08-21): KI-Kopf ist der EMPFOHLENE Weg fürs Concepting
                     (reicheres Ergebnis: ausgearbeiteter, prüfbarer Plan vor der Erzeugung). Primär-Button;
                     der direkte Go unten ist der Schnellweg (sekundär). --}}
                <x-foodalchemist::modal-section title="KI-Kopf — Plan ausarbeiten (empfohlen)">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">Empfohlener Weg: Die KI arbeitet aus dem Briefing einen vollständigen Konzept-Entwurf aus (Leitidee, USP, Inszenierung, Geschmackswelten, Gänge-Gerüst) und öffnet ihn zur Prüfung/Korrektur — <b>noch ohne</b> Gerichte zu erzeugen. Danach der Go „aus geprüftem Plan".</p>
                    <button type="button" wire:click="kiKopf" @disabled($laeuft)
                            wire:loading.attr="disabled" wire:target="kiKopf"
                            class="{{ $btnPrimary }} disabled:opacity-40" data-planung-kikopf>
                        <span wire:loading.remove wire:target="kiKopf">@svg('heroicon-o-sparkles', 'w-4 h-4') KI-Kopf: Plan ausarbeiten</span>
                        <span wire:loading wire:target="kiKopf">Plan wird ausgearbeitet …</span>
                    </button>
                </x-foodalchemist::modal-section>

                @include('foodalchemist::livewire.planung.partials.leitplanken', ['scope' => 'concept'])

                @include('foodalchemist::livewire.planung.partials.schnellstart-speichern', ['scope' => 'concept'])

                <x-foodalchemist::modal-section title="Go — Concept erzeugen (Draft)">
                    @include('foodalchemist::livewire.planung.partials.worker-praesenz')
                    @if($planConceptId)
                        {{-- A0/A1: der ausgearbeitete Plan bleibt hier SICHTBAR + editierbar (Semantik + Menü-
                             Aufbau) — kein Wegsprung in den Conceptor mehr. --}}
                        @include('foodalchemist::livewire.planung.partials.concept-plan')
                        {{-- Geplanter Pfad (Etappe 2b): ein KI-Kopf-Plan ist vorbereitet — der Go referenziert ihn
                             statt neu zu generieren. „Plan verwerfen" wechselt zurück auf den Schnell-Pfad. --}}
                        <div class="mb-2 flex items-center gap-2 text-[11px] text-emerald-700" data-planung-plan-bereit>
                            @svg('heroicon-o-check-badge', 'w-4 h-4')
                            <span>Geprüfter Plan vorbereitet — der Go verwendet ihn (statt neu zu generieren).</span>
                            <button type="button" wire:click="planVerwerfen" @disabled($laeuft) class="underline hover:text-emerald-200 disabled:opacity-40">Plan verwerfen (frisch generieren)</button>
                        </div>
                        <button wire:click="goKaskade('concept')" @click="tab='worker'" @disabled($laeuft) class="{{ $btnPrimary }} disabled:opacity-40">
                            @svg('heroicon-o-squares-2x2', 'w-4 h-4') Go aus geprüftem Plan
                        </button>
                    @else
                        {{-- DF-2: Schnellweg (sekundär) — ohne Vorab-Plan direkt erzeugen. Empfohlen ist der
                             KI-Kopf oben (ausgearbeiteter, prüfbarer Plan). --}}
                        <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">Schnellweg (ohne Vorab-Plan): Die LLM baut aus dem Briefing direkt die Zusammenstellung (Pakete/Buffet) nach den Leitplanken; die Gerichte kommen nach der Freigabe. Für ein ausgearbeitetes Konzept den <b>KI-Kopf</b> oben nutzen. Fortschritt im <b>Worker</b>-Tab.</p>
                        <button wire:click="goKaskade('concept')" @click="tab='worker'" @disabled($laeuft) class="{{ $btnGhost }} disabled:opacity-40">
                            @svg('heroicon-o-squares-2x2', 'w-4 h-4') Direkt erzeugen (Schnellweg)
                        </button>
                    @endif
                </x-foodalchemist::modal-section>
            </div>

            {{-- WORKER — alle Läufe/Entwürfe zusammen: Status + Fan-out-Baum + Freigabe --}}
            <div wire:key="planung-tab-worker" x-show="tab==='worker'" class="space-y-4">
                @if($laeuft || $anreicherungLaeuft)
                    <div wire:poll.1500ms="pruefeLauf" class="flex items-center gap-2 text-xs text-amber-300">
                        @svg('heroicon-o-arrow-path', 'w-4 h-4 animate-spin')
                        <span>
                            @if($laeuft)
                                Läuft — der Worker arbeitet die Kaskade ab …
                            @else
                                Freigegeben — die Anreicherung läuft nach (Beschreibung, Kalkulation, Allergene) …
                            @endif
                        </span>
                    </div>
                    @if($hinweis !== null)
                        <p class="text-[11px] text-amber-400" data-planung-watchdog>⏱ {{ $hinweis }}</p>
                        {{-- Recovery (Idempotenz/Resume): verwaiste Steps freiräumen → Lauf wieder handlungsfähig --}}
                        <button type="button" wire:click="laufFortsetzen" wire:loading.attr="disabled"
                                data-planung-fortsetzen
                                class="mt-1 text-[11px] text-amber-300 underline underline-offset-2 hover:text-amber-200">
                            Abgebrochene Schritte freiräumen
                        </button>
                    @endif
                @endif

            {{-- Worker-Ergebnis: Status + Fan-out-Baum + Freigabe (Gate 2) --}}
                @if($lauf)
                    @include('foodalchemist::livewire.planung.partials.ergebnis')
                @else
                    <div class="{{ $card }} p-4 text-xs text-gray-500">Noch kein Lauf — starte in „Basisrezept", „Gericht" oder „Concept" einen Go, der Fortschritt läuft hier durch.</div>
                @endif
            </div>

            {{-- COMPOSER — Foodpairing-Fläche: Anker zusammenstellen, Netz zeigt live was passt (★★★/★★).
                 Gezielte Kreation: aus den gewählten Ankern ein Basisrezept/Gericht erzeugen
                 (Anker = verbindliche Leit-Aromen). Klick auf Kandidat nimmt ihn auf. --}}
            <div wire:key="planung-tab-composer" x-show="tab==='composer'">
                <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] gap-4 items-start">
                {{-- LINKE SPALTE: Picker + Kohäsion --}}
                <div class="space-y-4 min-w-0">
                {{-- Reicher Anker-Picker: Kategorie + Suche + browsebare Liste + gewählte Chips --}}
                <x-foodalchemist::modal-section title="Foodpairing — Komposition">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-2">
                        Zutaten/Anker zusammenstellen — das Netz zeigt live, was harmoniert (★★★ Best · ★★ Good).
                        Filtere/such unten oder klick einen Kandidaten im Netz.
                    </p>

                    <div class="flex flex-wrap gap-2 mb-2">
                        <select wire:model.live="composerCategory" class="{{ $input }} sm:w-56">
                            <option value="">Alle Kategorien</option>
                            @foreach($composerBrowse['kategorien'] as $kat)
                                <option value="{{ $kat }}">{{ $kat }}</option>
                            @endforeach
                        </select>
                        <input type="search" wire:model.live.debounce.300ms="composerTerm"
                               placeholder="Anker suchen …" class="{{ $input }} flex-1 min-w-[12rem]" />
                    </div>

                    @if(!empty($composerAnker))
                        <div class="flex flex-wrap gap-1.5 mb-2">
                            @foreach($composerAnker as $a)
                                <span wire:key="canker-{{ $a['id'] }}"
                                      class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-[11px] bg-violet-500/25 text-violet-100 border border-violet-400/40">
                                    {{ $a['label'] }}
                                    <button type="button" wire:click="composerRemove({{ $a['id'] }})"
                                            class="text-violet-200 hover:text-white leading-none">&times;</button>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    <p class="text-[10px] text-slate-500 mb-1">
                        @if($composerFocus !== null && $composerFokusLabel)
                            Punkt = passt zu <span class="text-violet-300">{{ $composerFokusLabel }}</span> · Klick fügt hinzu
                        @else
                            {{ $composerBrowse['total'] }} Anker · Punkt = Fit zur Auswahl · Klick fügt hinzu
                        @endif
                    </p>
                    <div class="max-h-64 overflow-y-auto rounded-lg border border-white/10 divide-y divide-white/5">
                        @forelse($composerBrowse['items'] as $it)
                            <button type="button" wire:key="cbrowse-{{ $it['id'] }}" wire:click="composerAdd({{ $it['id'] }})"
                                    class="w-full flex items-center gap-2 px-2.5 py-1.5 text-left text-[12px] text-slate-100 hover:bg-white/10">
                                <span class="w-2 h-2 rounded-full shrink-0"
                                      style="background: {{ $it['typ'] === 'stern3' ? '#fcd34d' : ($it['typ'] === 'stern2' ? '#f59e0b' : 'transparent') }}; {{ $it['typ'] ? '' : 'border:1px solid rgba(148,163,184,.35);' }}"
                                      title="{{ $it['typ'] === 'stern3' ? '★★★ Best-Match' : ($it['typ'] === 'stern2' ? '★★ Good-Match' : 'kein Match zur Auswahl') }}"></span>
                                <span class="flex-1 truncate">{{ $it['label'] }}</span>
                                @if($it['category'])
                                    <span class="text-[10px] text-slate-500 shrink-0">{{ $it['category'] }}</span>
                                @endif
                                <span class="text-violet-300 shrink-0">+</span>
                            </button>
                        @empty
                            <p class="px-2.5 py-2 text-[12px] text-slate-500">Keine Anker — Filter/Suche anpassen.</p>
                        @endforelse
                    </div>
                </x-foodalchemist::modal-section>

                {{-- Foodpairing-Composer: aus den gewählten Ankern in den Erstellen-Tab springen. Der Brief
                     wird aus den Ankern vorbefüllt, die Anker reisen als verbindliche Leit-Aromen
                     (seed_anker) mit — im Erstellen-Tab setzt du die Leitplanken (voller Regler) und startest. --}}
                <x-foodalchemist::modal-section title="Aus diesen Pairings weiterbauen">
                    <p class="text-[11px] text-slate-400 mb-2">
                        Übernimmt die Anker als <strong>verbindliche Leit-Aromen</strong> und springt in den
                        Erstellen-Tab: Brief ist vorbefüllt, dort die <strong>Leitplanken</strong> setzen und starten.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="composerUebernehmen('rezept')" @click="tab='basisrezept'"
                                @disabled(empty($composerAnker))
                                class="{{ $btnPrimary }} disabled:opacity-40" data-composer-go-rezept>
                            Als Basisrezept vorbereiten →
                        </button>
                        <button type="button" wire:click="composerUebernehmen('gericht')" @click="tab='gericht'"
                                @disabled(empty($composerAnker))
                                class="{{ $btnGhost }} disabled:opacity-40" data-composer-go-gericht>
                            Als Gericht vorbereiten →
                        </button>
                    </div>
                    @if(empty($composerAnker))
                        <p class="text-[10px] text-slate-500 mt-1">Erst Anker wählen.</p>
                    @endif
                </x-foodalchemist::modal-section>

                {{-- „Passt das zusammen?" — geerdet auf GETEILTE Partner (Brücken), nicht auf die
                     in Inspire fast immer leeren Direktkanten (die die irreführende 0 % erzeugten). --}}
                @php $bridge = $composerNetz['meta']['bridge'] ?? null; @endphp
                @if($bridge !== null && count($composerAnker) >= 2)
                    <x-foodalchemist::modal-section title="Passt das zusammen?">
                        <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-[12px] text-slate-200">
                            <span>Verbunden:
                                <strong class="{{ ($bridge['pairs_total'] > 0 && $bridge['pairs_connected'] === $bridge['pairs_total']) ? 'text-emerald-300' : ($bridge['pairs_connected'] > 0 ? 'text-amber-300' : 'text-rose-300') }}">{{ $bridge['pairs_connected'] }}/{{ $bridge['pairs_total'] }}</strong>
                                Anker-Paare über gemeinsame Partner
                            </span>
                            @php $tiers = $bridge['tiers'] ?? []; @endphp
                            @if(($tiers['best'] ?? 0) + ($tiers['good'] ?? 0) > 0)
                                <span class="text-slate-400">davon
                                    @if(($tiers['best'] ?? 0) > 0)<strong class="text-violet-300">{{ $tiers['best'] }}× stark</strong>@endif
                                    @if(($tiers['best'] ?? 0) > 0 && ($tiers['good'] ?? 0) > 0), @endif
                                    @if(($tiers['good'] ?? 0) > 0){{ $tiers['good'] }}× mittel @endif
                                </span>
                            @endif
                            @if(!empty($bridge['top']))
                                <span class="text-slate-400">stärkste Brücken: {{ implode(', ', $bridge['top']) }}</span>
                            @endif
                        </div>
                        @if($composerCohesion !== null && ($composerCohesion['rated_pairs'] ?? 0) > 0)
                            <p class="mt-1 text-[11px] text-slate-500">direktes Pairing: {{ $composerCohesion['rated_pairs'] }}/{{ $composerCohesion['total_pairs'] }} Paare (Kohäsion {{ $composerCohesion['score'] }}%)</p>
                        @endif
                        @if(!empty($bridge['orphans']))
                            <p class="mt-1 text-[12px] text-amber-300">⚠ passt (noch) nicht zu den anderen: {{ implode(', ', $bridge['orphans']) }}</p>
                        @endif
                    </x-foodalchemist::modal-section>
                @endif
                </div>{{-- /linke Spalte --}}

                {{-- RECHTE SPALTE: Netz --}}
                <div class="min-w-0">
                {{-- Netz + Filter-Chips in EINER Alpine-Instanz (wie im Detail-Modal) --}}
                <x-foodalchemist::modal-section title="Netz">
                    @if(empty($composerAnker))
                        <p class="text-[13px] text-slate-400">
                            Noch keine Zutat gewählt — oben eine hinzufügen. Dann zeigt das Netz die passenden
                            Kandidaten (★★★/★★), wie die Anker zusammenhängen und wo etwas nicht passt.
                        </p>
                    @else
                        @if($composerFocus !== null && $composerFokusLabel)
                            <div class="mb-2 flex flex-wrap items-center gap-2 text-[11px]">
                                <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-violet-500/25 text-violet-100 border border-violet-400/40">
                                    Fokus: {{ $composerFokusLabel }}
                                    <button type="button" wire:click="composerFocus({{ $composerFocus }})" class="text-violet-200 hover:text-white leading-none" title="Fokus aufheben">&times;</button>
                                </span>
                                <span class="text-slate-500">nur seine Verbindungen · Klick aufs Zentrum oder × hebt auf</span>
                            </div>
                        @else
                            <p class="mb-2 text-[10px] text-slate-500">Tipp: Klick auf einen Anker fokussiert ihn — nur seine Verbindungen + Stärke bleiben sichtbar.</p>
                        @endif
                        <div wire:ignore
                             wire:key="composer-netz-{{ $composerNetz['meta']['sig'] ?? '0' }}-f{{ $composerFocus ?? 0 }}"
                             x-data="pairingNetzGraph({
                                 nodes: @js($composerNetz['nodes']),
                                 edges: @js($composerNetz['edges']),
                                 mode: 'modal',
                                 canvasW: {{ (float) ($composerNetz['meta']['canvas_w'] ?? 1000) }},
                                 canvasH: {{ (float) ($composerNetz['meta']['canvas_h'] ?? 760) }},
                                 typDefault: @js($composerNetz['meta']['typ_default'] ?? ['stern3' => true, 'stern2' => true]),
                                 focusId: {{ $composerFocus ?? 'null' }},
                                 onKandidatClick: (id) => $wire.composerAdd(id),
                                 onAnkerClick: (id) => $wire.composerFocus(id),
                             })">
                            <div class="flex flex-wrap items-center gap-2 mb-2 text-[11px]">
                                <span class="text-slate-400 mr-1">Zeigen:</span>
                                <button type="button" @click="toggleTyp('stern3')"
                                        :class="typAktiv['stern3'] ? 'ring-2 ring-offset-1 ring-offset-slate-900' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-slate-200"
                                        style="border-color:#fcd34d; --tw-ring-color:#fcd34d;">
                                    <span class="w-2 h-2 rounded-full" style="background:#fcd34d"></span> ★★★ Best
                                </button>
                                <button type="button" @click="toggleTyp('stern2')"
                                        :class="typAktiv['stern2'] ? 'ring-2 ring-offset-1 ring-offset-slate-900' : 'opacity-45'"
                                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-slate-200"
                                        style="border-color:#f59e0b; --tw-ring-color:#f59e0b;">
                                    <span class="w-2 h-2 rounded-full" style="background:#f59e0b"></span> ★★ Good
                                </button>
                            </div>
                            <svg viewBox="0 0 1200 980" preserveAspectRatio="xMidYMid meet"
                                 class="w-full rounded-xl" style="height:70vh; background:#0b1120" data-fa-netz-mount></svg>
                            {{-- Legende: Punkt auf der Anker-Linie = Beziehungsstärke (groß→klein) --}}
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[11px] text-slate-300">
                                <span class="text-slate-500">Beziehungsstärke:</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block rounded-full" style="width:18px;height:18px;background:#a78bfa;box-shadow:0 0 0 2px #ede9fe"></span> Best</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block rounded-full" style="width:11px;height:11px;background:#a78bfa;opacity:.82"></span> Good</span>
                                <span class="inline-flex items-center gap-2"><span class="inline-block rounded-full" style="width:5px;height:5px;background:#a78bfa;opacity:.5"></span> Match</span>
                                <span class="text-[10px] text-slate-500">· groß→klein = stark→schwach · violett = geteilte Partner, gold = direktes Pairing · Hover zeigt die Partner</span>
                            </div>
                        </div>
                    @endif
                </x-foodalchemist::modal-section>
                </div>{{-- /rechte Spalte --}}
                </div>{{-- /grid --}}
            </div>
        @endif
    </x-foodalchemist::modal>
    {{-- Leitstelle-In-Context: die erzeugten Entwürfe (Basisrezept/Gericht) im Cockpit ansehen,
         statt auf die Listen-Seite zu springen. DOM NACH dem Editor-Modal → z-Stacking (öffnet darüber). --}}
    <livewire:foodalchemist.recipes.recipe-modal />
    <livewire:foodalchemist.verkauf.vk-modal />
    {{-- Vollen Conceptor-Editor inline: ein erzeugtes Concept öffnet mit allen Tabs/KPIs/Score/Kalkulation/
         Geschirr direkt hier (öffnet via concepter-editor.oeffnen aus der step-zeile). Gleiches Muster wie Angebote. --}}
    <livewire:foodalchemist.concepter.editor />
</x-ui-page>
