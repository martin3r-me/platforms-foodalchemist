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
        <div class="{{ $card }} p-4">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-gray-800 mr-1">Neu erstellen</span>
                <button wire:click="schnellErstellen('rezept')" class="{{ $btnPrimary }}" data-frei-rezept>
                    @svg('heroicon-o-beaker', 'w-4 h-4') Basisrezept
                </button>
                <button wire:click="schnellErstellen('gericht')" class="{{ $btnPrimary }}" data-frei-gericht>
                    @svg('heroicon-o-cake', 'w-4 h-4') Gericht
                </button>
                <button wire:click="schnellErstellen('concept')" class="{{ $btnPrimary }}" data-frei-concept>
                    @svg('heroicon-o-squares-2x2', 'w-4 h-4') Concept
                </button>
                <span class="text-[11px] text-gray-500 ml-1">— mit KI, direkt mit Regler-Leitplanken.</span>
            </div>
        </div>

        @if($active)
            <div class="{{ $card }} p-5 space-y-3">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-gray-800">{{ $active->title }}</h2>
                        <div class="mt-1 flex flex-wrap gap-1">
                            {!! $kaskadeBadge($active->id) !!}
                            {!! $chip($statusLabel[$active->status] ?? $active->status, 'bg-violet-500/10 text-violet-700') !!}
                            {!! $chip($active->source_knowledge_document_id ? 'aus Trend' : 'Freier Brief', 'bg-sky-500/10 text-sky-700') !!}
                            {!! $chip($skizzenAnzahl . ' Skizzen') !!}
                        </div>
                    </div>
                    <button wire:click="oeffne({{ $active->id }})" class="{{ $btnPrimary }} shrink-0">
                        @svg('heroicon-o-pencil-square', 'w-4 h-4')
                        Öffnen
                    </button>
                </div>
                @if($active->brief)
                    <p class="text-sm text-gray-600">{{ $active->brief }}</p>
                @endif
                @if($active->analysis)
                    <p class="text-xs text-gray-500 line-clamp-3">{{ \Illuminate\Support\Str::limit($active->analysis, 320) }}</p>
                @endif
            </div>
        @else
            <div class="{{ $card }} p-5">
                <h2 class="text-base font-semibold text-gray-800">Planung — KI-Leitstelle</h2>
                <p class="text-sm text-gray-600 mt-1">
                    Erstelle direkt ein Basisrezept, Gericht oder Concept — mit KI und Regler-Leitplanken (oben „Neu erstellen").
                    Ein Trend ist EIN möglicher Input (im Trendradar „In Planung öffnen"), nicht der Rahmen.
                </p>
            </div>
            {{-- UX: Landing-Umschalter [Zuletzt | Zu prüfen (N)] — die zu prüfenden Läufe quer gebündelt. --}}
            <div x-data="{ landView: 'zuletzt' }">
                @php $pruefen = $sessions->filter(fn ($s) => ($kaskaden[$s->id]['status'] ?? 'entwurf') === 'prüfen')->values(); @endphp
                <div class="flex items-center gap-2 mb-3" data-planung-landing-tabs>
                    <button type="button" @click="landView='zuletzt'" data-planung-landtab="zuletzt"
                            :class="landView==='zuletzt' ? 'bg-violet-500/15 text-violet-700' : 'text-gray-500 hover:text-gray-700'"
                            class="px-3 py-1 rounded-md text-sm font-semibold">Zuletzt</button>
                    <button type="button" @click="landView='pruefen'" data-planung-landtab="pruefen"
                            :class="landView==='pruefen' ? 'bg-violet-500/15 text-violet-700' : 'text-gray-500 hover:text-gray-700'"
                            class="px-3 py-1 rounded-md text-sm font-semibold inline-flex items-center gap-1.5">
                        Zu prüfen
                        @if($pruefen->count() > 0)<span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1 rounded-full bg-violet-600 text-white text-[11px] font-bold" data-planung-pruef-count>{{ $pruefen->count() }}</span>@endif
                    </button>
                </div>

                {{-- Ansicht: Zuletzt --}}
                <div x-show="landView==='zuletzt'">
                @if($sessions->count() === 0)
                    <div class="{{ $card }} p-4 text-xs text-gray-500">Noch keine Planungen.</div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                        @foreach($sessions->take(9) as $s)
                            <div wire:key="dash-{{ $s->id }}" class="{{ $card }} p-3" data-planung-karte="{{ $s->id }}">
                                <button type="button" wire:click="waehle({{ $s->id }})" class="w-full text-left hover:opacity-80 transition-opacity">
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="text-xs font-semibold text-gray-800 truncate flex items-center gap-1.5">@svg($typIcon($s), 'w-3.5 h-3.5 shrink-0 text-gray-400'){{ $anzeigeTitel($s) }}</span>
                                        @if($kaskadeLaeuft($s->id))<span class="shrink-0 w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse mt-1" title="läuft"></span>@endif
                                    </div>
                                    <div class="mt-2 flex flex-wrap gap-1">
                                        {!! $kaskadeBadge($s->id) !!}
                                        @if($s->category)  {!! $chip($s->category, 'bg-sky-500/10 text-sky-700') !!} @endif
                                    </div>
                                    @php $fort = $kaskadeFortschritt($s->id); @endphp
                                    @if($fort !== '')<p class="mt-1.5 text-[10px] text-gray-500 truncate" data-planung-fortschritt>{{ $fort }}</p>@endif
                                </button>
                                {{-- Direkt-Aktionen (finale Etappe #17): Öffnen · Duplizieren · Verwerfen (Soft-Delete).
                                     Freigabe bleibt bewusst per-Step im Editor (kein Karten-Bulk-Freigeben). --}}
                                <div class="mt-2 flex items-center gap-1 border-t border-black/5 pt-2" data-planung-karten-aktionen>
                                    <button type="button" wire:click="oeffne({{ $s->id }})" class="{{ $btnGhostXs }}" title="Im Editor öffnen" data-planung-karte-oeffnen>
                                        @svg('heroicon-o-pencil-square', 'w-3.5 h-3.5')
                                    </button>
                                    <button type="button" wire:click="planungDuplizieren({{ $s->id }})" class="{{ $btnGhostXs }}" title="Duplizieren" data-planung-karte-duplizieren>
                                        @svg('heroicon-o-document-duplicate', 'w-3.5 h-3.5')
                                    </button>
                                    <button type="button" wire:click="planungVerwerfen({{ $s->id }})" wire:confirm="Diese Planung verwerfen? (reversibel — Soft-Delete)" class="{{ $btnGhostXs }} !text-rose-600" title="Verwerfen" data-planung-karte-verwerfen>
                                        @svg('heroicon-o-trash', 'w-3.5 h-3.5')
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
                </div>{{-- /Ansicht Zuletzt --}}

                {{-- UX: Ansicht „Zu prüfen" — alle Läufe im Status prüfen quer gebündelt (Worker/Status auf einen Blick). --}}
                <div x-show="landView==='pruefen'" x-cloak class="space-y-2" data-planung-pruef-liste>
                    @forelse($pruefen as $s)
                        <div wire:key="pruef-{{ $s->id }}" class="{{ $card }} p-3 flex items-center gap-3" data-planung-pruef-karte="{{ $s->id }}">
                            @svg($typIcon($s), 'w-5 h-5 shrink-0 text-violet-500')
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-800 truncate">{{ $anzeigeTitel($s) }}</p>
                                @php $fort = $kaskadeFortschritt($s->id); @endphp
                                <p class="text-[11px] text-gray-500 truncate">{{ $fort !== '' ? $fort : 'wartet auf Freigabe' }}@if($s->category) · {{ $s->category }}@endif</p>
                            </div>
                            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $kaskadeStatusStil['prüfen'] }}">Prüfen</span>
                            <button type="button" wire:click="oeffne({{ $s->id }})" class="{{ $btnPrimary }} shrink-0" title="Im Editor öffnen + prüfen">
                                @svg('heroicon-o-pencil-square', 'w-4 h-4') Öffnen
                            </button>
                        </div>
                    @empty
                        <div class="{{ $card }} p-4 text-xs text-gray-500">Nichts zu prüfen — alle Läufe sind Entwurf, laufen noch oder sind fertig/freigegeben.</div>
                    @endforelse
                </div>
            </div>{{-- /x-data landView --}}
        @endif
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
                        <div class="flex justify-between"><dt>Skizzen</dt><dd>{{ $skizzenAnzahl }}</dd></div>
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
                            :title-name="$active?->title" tab-init="analyse">
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
                <button type="button" @click="tab='analyse'"
                        :class="tab==='analyse' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Analyse</button>
                <button type="button" @click="tab='skizzen'"
                        :class="tab==='skizzen' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Skizzen</button>
                <button type="button" @click="tab='basisrezept'"
                        :class="tab==='basisrezept' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Basisrezept</button>
                <button type="button" @click="tab='gericht'"
                        :class="tab==='gericht' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Gericht</button>
                <button type="button" @click="tab='concept'"
                        :class="tab==='concept' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Concept</button>
                <button type="button" @click="tab='worker'"
                        :class="tab==='worker' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium inline-flex items-center gap-1">Worker @if($laeuft)<span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span>@endif</button>
                <button type="button" @click="tab='composer'"
                        :class="tab==='composer' ? 'bg-violet-500/25 text-white' : 'text-gray-300 hover:text-white'"
                        class="px-3 py-1.5 rounded-t-md text-xs font-medium">Composer</button>
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
            {{-- ANALYSE --}}
            <div wire:key="planung-tab-analyse" x-show="tab==='analyse'" class="space-y-4">
                <x-foodalchemist::modal-section title="Analyse / Ausgangslage">
                    <p class="{{ $label ?? 'text-[11px] text-gray-500' }} mb-1">Trend-Inhalt bzw. deine Analyse — die Grundlage für Skizzen und „Go".</p>
                    <textarea wire:model="form.analysis" rows="14" class="{{ $input }} font-mono text-[12px] leading-relaxed"
                              placeholder="Was ist der Trend? Was ist die Idee? Constraints, Anlass, Richtung …"></textarea>
                    {{-- Spec 40 E0: Analyse → Skizzen (KI-Divergenz auf Session-Ebene). Sprung auf den Skizzen-Tab beim Klick. --}}
                    <div class="mt-2 flex items-center gap-2 flex-wrap">
                        <button type="button" wire:click="skizzenAusAnalyse" @click="tab='skizzen'"
                                wire:loading.attr="disabled" wire:target="skizzenAusAnalyse"
                                class="{{ $btnGhost }} disabled:opacity-40">
                            <span wire:loading.remove wire:target="skizzenAusAnalyse">@svg('heroicon-o-sparkles', 'w-4 h-4') Skizzen aus Analyse ableiten</span>
                            <span wire:loading wire:target="skizzenAusAnalyse">Skizzen werden abgeleitet …</span>
                        </button>
                        <span class="text-[10px] text-gray-500">KI leitet Gericht-Skizzen ab — sie landen im Skizzen-Tab (kein „Go", keine Erdung).</span>
                    </div>
                </x-foodalchemist::modal-section>
            </div>

            {{-- SKIZZEN (Divergenz-Board) --}}
            <div wire:key="planung-tab-skizzen" x-show="tab==='skizzen'" class="space-y-4">
                <x-foodalchemist::modal-section title="Skizze hinzufügen">
                    <div class="flex gap-2">
                        <input type="text" wire:model="ideeTitel" wire:keydown.enter="ideeHinzu"
                               placeholder="Gericht-Skizze (Titel) …" class="{{ $input }}" />
                        <button wire:click="ideeHinzu" class="{{ $btnPrimary }} shrink-0">Skizze</button>
                    </div>
                    <div class="flex gap-2 mt-2">
                        <input type="text" wire:model="paketName" wire:keydown.enter="paketBilden"
                               placeholder="Paket/Gruppe (Name) …" class="{{ $input }}" />
                        <button wire:click="paketBilden" class="{{ $btnGhost }} shrink-0">Paket</button>
                    </div>
                </x-foodalchemist::modal-section>

                {{-- Batch-Kaskaden-Eingang (Etappe 4, Teil 3): Skizzen als gestufte Gericht-Läufe starten.
                     Ohne Häkchen = alle bearbeitbaren; mit Häkchen (Teil 3b) = nur die gewählten. Stand je
                     Karte (Teil 2b), Freigabe je Stück. --}}
                @if($skizzenAnzahl > 0)
                    @php $auswahlN = count($skizzenAuswahl); @endphp
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[11px] text-gray-500">
                            @if($auswahlN > 0)
                                {{ $auswahlN }} Skizze{{ $auswahlN === 1 ? '' : 'n' }} gewählt — als gestufte Gericht-Läufe starten (Stand je Karte, Freigabe je Stück).
                            @else
                                Skizzen als gestufte Gericht-Läufe starten — anhaken für gezielte Auswahl, sonst alle. Stand je Karte, Freigabe je Stück.
                            @endif
                        </p>
                        <div class="flex items-center gap-2 shrink-0">
                            @if($auswahlN > 0)
                                <button wire:click="skizzenAuswahlLeeren" class="text-[10px] text-gray-500 hover:text-gray-700">Auswahl leeren</button>
                            @endif
                            <button wire:click="skizzenBatchAlsGerichte" wire:loading.attr="disabled"
                                    class="{{ $btnGhost }} shrink-0">{{ $auswahlN > 0 ? 'Auswahl als Gerichte' : 'Alle als Gerichte' }}</button>
                        </div>
                    </div>
                @endif

                {{-- Live-Poll der Karten-Badges (Etappe 4, Teil 3b-b): solange ein aus einer Skizze
                     gestarteter Lauf noch läuft (running), refresht sich das Board selbst — bare
                     wire:poll → $refresh, render() liest $skizzenLauf frisch → die Badges kippen live
                     von „läuft" auf „prüfen"/„fertig". Kein Einzel-Cockpit-Hijack ($laufId/$laeuft
                     unangetastet). Sobald kein verknüpfter Lauf mehr running ist, entfällt das Element
                     → Polling stoppt. --}}
                @if($skizzenLaufAktiv)
                    <div wire:poll.2500ms data-skizzen-poll class="flex items-center gap-1.5 text-[11px] text-amber-600">
                        @svg('heroicon-o-arrow-path', 'w-3.5 h-3.5 animate-spin')
                        <span>Stand der Skizzen-Läufe aktualisiert automatisch …</span>
                    </div>
                @endif

                <x-foodalchemist::modal-section title="Skizzen ({{ $skizzenAnzahl }})">
                    @if($skizzen)
                        @forelse($skizzen['gruppen'] as $g)
                            <div wire:key="grp-{{ $g['gruppe']->id }}" class="mb-3">
                                <p class="text-xs font-semibold text-gray-800">📦 {{ $g['gruppe']->name }}</p>
                                <div class="pl-3 space-y-1 mt-1">
                                    @foreach($g['ideen'] as $i)
                                        <div wire:key="gi-{{ $i->id }}" class="flex items-center justify-between text-xs text-gray-700">
                                            <label class="flex items-center gap-1.5 min-w-0">
                                                <input type="checkbox" wire:model.live="skizzenAuswahl" value="{{ $i->id }}"
                                                       class="shrink-0 rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
                                                <span class="truncate">{{ $i->title }}</span>
                                            </label>
                                            <div class="flex items-center gap-2 shrink-0">
                                                {!! $skizzeLaufBadge($i->id) !!}
                                                <button wire:click="skizzeAlsGericht({{ $i->id }})" @click="tab='gericht'" class="text-[10px] text-violet-600 hover:text-violet-700">als Gericht</button>
                                                <button wire:click="ideeVerwerfen({{ $i->id }})" class="text-[10px] text-rose-500 hover:text-rose-600">verwerfen</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                        @endforelse

                        @forelse($skizzen['einzel'] as $i)
                            <div wire:key="ei-{{ $i->id }}" class="flex items-center justify-between text-xs text-gray-700 py-0.5">
                                <label class="flex items-center gap-1.5 min-w-0">
                                    <input type="checkbox" wire:model.live="skizzenAuswahl" value="{{ $i->id }}"
                                           class="shrink-0 rounded border-gray-300 text-violet-600 focus:ring-violet-500" />
                                    <span class="truncate">{{ $i->title }}</span>
                                </label>
                                <div class="flex items-center gap-2 shrink-0">
                                    {!! $skizzeLaufBadge($i->id) !!}
                                    <button wire:click="skizzeAlsGericht({{ $i->id }})" @click="tab='gericht'" class="text-[10px] text-violet-600 hover:text-violet-700">als Gericht</button>
                                    <button wire:click="ideeVerwerfen({{ $i->id }})" class="text-[10px] text-rose-500 hover:text-rose-600">verwerfen</button>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">Noch keine Skizzen — oben eine anlegen.</p>
                        @endforelse
                    @endif
                </x-foodalchemist::modal-section>
            </div>

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
                 Graph-only (keine Generierung — separates Thema). Klick auf Kandidat nimmt ihn auf. --}}
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
