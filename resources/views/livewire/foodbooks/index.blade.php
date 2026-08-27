{{-- M11-03 / Doc 15 §9.3: Foodbook-Editor — stellt Concepts zu einem Kunden-Angebot zusammen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($aktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700')
@php($hover = 'text-gray-600 hover:bg-black/[0.03]')

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Foodbook / Portfolio" icon="heroicon-o-book-open" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Foodbook / Portfolio'],
        ]" />
    </x-slot>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Foodbooks" width="w-80">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Foodbook / Kunde suchen …" class="{{ $input }}" />
                {{-- R4.3: Phasen-Filter (Statusmaschine Kontext→…→Freigabe) --}}
                <select wire:model.live="phaseFilter" class="{{ $input }}" data-phase-filter>
                    <option value="">Alle Phasen</option>
                    @foreach(\Platform\FoodAlchemist\Services\PhaseService::LABELS as $pk => $pl)<option value="{{ $pk }}">{{ $pl }}</option>@endforeach
                </select>
                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center">+ Neues Foodbook</button>
                <div class="space-y-0.5 -mx-1">
                    @forelse($foodbooks as $f)
                        <button type="button" wire:key="fb-{{ $f->id }}" wire:click="waehle({{ $f->id }})"
                                class="w-full text-left px-2 py-1 rounded-lg text-xs {{ $selectedId === $f->id ? $aktiv : $hover }}">
                            <span class="truncate block">{{ $f->label }}</span>
                            <span class="text-[10px] text-gray-500">{{ $f->crmCompany?->display_name ?? 'ohne Kunde' }} · {{ $f->chapters_count }} Kapitel · <span class="text-violet-500/80">{{ \Platform\FoodAlchemist\Services\PhaseService::LABELS[$f->phase] ?? $f->phase }}</span></span>
                        </button>
                    @empty
                        <p class="px-2 py-3 text-[11px] text-gray-500">Noch keine Foodbooks.</p>
                    @endforelse
                </div>

                {{-- Kapitel-Navigation lebt jetzt im Editor-Modal (linke Navi-Spalte, Spec 29 / S8).
                     Die Seiten-Sidebar führt nur noch die Foodbook-Liste — Kapitel wechselt man beim
                     Bearbeiten, nicht in der Übersicht (kein doppelter Baum mehr). --}}
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    {{-- Rechtes Detail-Panel (read-only Info) — konsistent zu Speisekarte/Speiseplan.
         Die Leitstelle-Rail bleibt IM Editor-Modal (begleitet die Bearbeitung, Spec 29/S8). --}}
    <x-slot name="activity">
        <x-foodalchemist::detail-sidebar title="Detail" width="w-80" scope="activity_foodbook" side="right" icon="heroicon-o-information-circle" :default-open="true">
            @if($fb)
                @include('foodalchemist::livewire.foodbooks.partials.detail', ['fb' => $fb])
            @else
                <div class="p-4 text-[11px] text-gray-400">Wähle links ein Foodbook, um Details zu sehen.</div>
            @endif
        </x-foodalchemist::detail-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if($fb)
            {{-- ═══ LISTEN-EBENE: Live-Vorschau + Ausgabe/Bearbeiten (Spec 29) ═══
                 Der Editor ist ein Fullscreen-Modal (》Bearbeiten《). Die Seite zeigt dauerhaft das
                 fertige Ergebnis (Kundensicht) + die Ausgabe-Tools. Ansehen ≠ Bearbeiten. --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-lg font-semibold tracking-tight text-gray-900 truncate">{{ $fb->label }}</h1>
                    <p class="text-[11px] text-gray-500">{{ $fb->crmCompany?->display_name ?? 'ohne Kunde' }} · <span class="text-violet-500/80">{{ \Platform\FoodAlchemist\Services\PhaseService::LABELS[$fb->phase] ?? $fb->phase }}</span></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="$dispatch('modal.open', { name: 'foodbook-editor' })" class="{{ $btnPrimary }}" data-fb-bearbeiten>@svg('heroicon-o-pencil-square', 'w-4 h-4') Bearbeiten</button>
                    <a href="{{ route('foodalchemist.foodbooks.dokument', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Dokument (Druck/PDF) — schöne Kundenausgabe, Allergene/Zusatzstoffe an/aus, Kunden-/interne Sicht">Dokument</a>
                    {{-- #5a: technischer Report (Profile + Conceptor-Filter + Produktions-Kaskade + Kapitel-Filter). --}}
                    <a href="{{ route('foodalchemist.foodbooks.report', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Technischer Report — Profile (Kurzblatt … Volle Kaskade) + Filter (Preise/Lieferanten/Deklaration/Nährwerte/Kaskade …) + Kapitel-Filter, wie bei Concept/Format">Report</a>
                    <a href="{{ route('foodalchemist.foodbooks.praesentation', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Externe Kunden-Präsentation (Web-Seite, Preise pro Person, ohne Interna)">Präsentation</a>
                </div>
            </div>

            {{-- Live-Ergebnis (read-only, Kundensicht) — dieselbe Quelle wie Dokument/Präsentation --}}
            @include('foodalchemist::livewire.foodbooks.partials.menue-vorschau')

            {{-- ═══════════════ EDITOR = Fullscreen-Modal (Spec 29) ═══════════════
                 Gleiche Livewire-Komponente (Index), nur in ein Modal gehüllt — Bus/State/Nested
                 bleiben. dark-canvas folgt erst, wenn die Panels auf modal-section stehen (S9),
                 sonst grau-auf-grau. Geöffnet per 》Bearbeiten《 (modal.open). --}}
            @php($fbKapitelN = $fb->chapters->count())
            @php($fbSpeisenN = collect($menue['kapitel'] ?? [])->sum(fn ($k) => collect($k['bloecke'] ?? [])->sum(fn ($b) => collect($b['gerichte'] ?? [])->reject(fn ($g) => in_array($g['type'] ?? '', ['paket', 'header'], true))->count())))
            @php($fbVkPp = (float) ($menue['gesamt']['vk_pro_person'] ?? 0))
            @php($fbErledigt = collect($checkliste)->where('status', 'erledigt')->count())
            @php($fbSchritte = count($checkliste))
            <x-foodalchemist::modal name="foodbook-editor" fullscreen dark-canvas title="Foodbook bearbeiten" :title-name="$fb->label">
                <x-slot:actions>
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-fb-speichern>Speichern</button>
                    {{-- Entry in die Leitstelle-Planung (Stage 2): öffnet die Leitstelle im Owner-Kontext dieses
                         Foodbooks (fb_owner) — Gerüst planen + Kaskaden je Kapitel. Die Planung lebt dort, nicht mehr hier. --}}
                    <button type="button" wire:click="vollKaskadeStarten" class="{{ $btnGhostXs }} text-violet-600" data-fb-in-leitstelle>In der Leitstelle planen</button>
                    <button type="button" wire:click="loeschen({{ $fb->id }})" wire:confirm="Foodbook löschen?" class="{{ $btnGhostXs }} text-red-600" data-fb-loeschen>Löschen</button>
                </x-slot:actions>

                {{-- Spec 29 / S4: KPI-Streifen fix im Modal-Kopf (scrollt nie weg) — geteilter Baustein.
                     Leitwert = VK/Person (accent). Alle Werte aus render()-Daten, keine neuen Service-Calls. --}}
                <x-slot:kpiHeader>
                    <x-foodalchemist::kpi-tiles marker="fb-kpis" :tiles="[
                        ['kpi' => 'kapitel', 'label' => 'Kapitel', 'value' => (string) $fbKapitelN],
                        ['kpi' => 'speisen', 'label' => 'Speisen', 'value' => (string) $fbSpeisenN],
                        ['kpi' => 'vkpp', 'label' => 'VK / Person', 'tone' => 'accent',
                         'value' => $fbVkPp > 0 ? number_format($fbVkPp, 2, ',', '.') . ' €' : '—'],
                        ['kpi' => 'fortschritt', 'label' => 'Fortschritt',
                         'tone' => ($fbSchritte > 0 && $fbErledigt >= $fbSchritte) ? 'good' : 'neutral',
                         'value' => $fbErledigt . '/' . $fbSchritte],
                    ]" />
                </x-slot:kpiHeader>

            {{-- ═══════════════ FOODBOOK-KOPF — Planungs-Cockpit (Tabs) ═══════════════ --}}
            {{-- Das Cockpit rendert IMMER, auch mit gewähltem Kapitel. Bis 2026-07-28 stand hier
                 `@if($selectedKapitelId === null)` — Cockpit XOR Kapitel. Das machte einen ganzen
                 Feature-Strang unerreichbar, weil `$kapitel` (Index.php:1177) genau dann gesetzt ist,
                 wenn `selectedKapitelId` gesetzt ist: innerhalb des Cockpits war `$kapitel` also
                 IMMER null, und damit rendete nichts, was ein Kapitel braucht — der
                 3-Modus-Schalter (E9.4), die Skizzen-Inhalte samt „aus Bestand"-Auswahl und
                 Ideen-/Pakete-Liste (E6.3) und die komplette Pairing-Inspiration.
                 Die Selbstanzeige des Fehlers stand im Kreativ-Tab: „Wähle links ein Kapitel" —
                 eine Anweisung, deren Ausführung die Oberfläche selbst unmöglich machte.
                 Die Absicht war immer Koexistenz (dieser Hinweistext, der `@else`-Zweig und die
                 E9.4-Tests gehen alle davon aus). Kapitel-Editor kommt darunter. --}}
            {{-- Tab-Zustand hält Alpine über Livewire-Morphs hinweg (stabiler wire:key), Muster wie Concepter-Editor.
                 Kalkulations-Leiste bleibt die rechte activity-Sidebar. Phase 1: reiner Reuse, Modals raus. --}}
            {{-- E5.2: Sprung-Event-Bus — die Checkliste dispatcht `fb-goto` {tab, anker}; der Cockpit-Root
                 wechselt (falls der Tab existiert) und scrollt nach dem DOM-Flush zum Anker. Graceful:
                 unbekannter Tab → bleibt stehen (kein Blank), unbekannter Anker → kein Scroll. --}}
            {{-- E5.3: `x-effect` meldet den aktiven Tab per Window-Event an die Leitstelle-Rail
                 (Auto-Default je Tab, sofern die Rail nicht manuell gepinnt ist). --}}
            {{-- Spec 29 / S3: Tab-Zustand + sticky Leiste liegen jetzt im geteilten `editor-tabs`-Baustein
                 (Alpine-Modus, wie Rezept/GP). Der Cockpit-Root hält KEIN eigenes `tab` mehr — sonst
                 überschattet ein äußerer Scope den Baustein-Scope (stiller Desync). Der Sprung-/Melde-Bus
                 (fb-goto ← Checkliste, fb-cockpit-tab → Rail) wandert als headless-Kind IN den Baustein-Scope,
                 damit `tab` und `$root` (mit data-fb-tab/-anker) dieselbe Wurzel teilen. Vorschau-Tab ist
                 entfallen — die Live-Vorschau liegt jetzt auf der Listen-Ebene. --}}
            {{-- Spec 29 / S8 (Option A): 3-Spalten-Cockpit IM Modal — links Navigation (Foodbook-Kopf +
                 Kapitelbaum), Mitte Editor-Tabs, rechts Leitstelle-Rail. So bleibt Kapitel-/Kopf-Wechsel
                 erreichbar, obwohl das Vollbild-Modal die Seiten-Sidebar verdeckt.
                 `-mx-6` hebt das px-6 des Modal-Bodys auf (Spalten randbündig); die Mitte bekommt px-6
                 zurück, damit die sticky editor-tabs-Leiste (-mx-6) wieder auf Spaltenbreite spannt. --}}
            <div class="flex gap-4 -mx-6 items-start"
                 {{-- Picker-Umbau: die rechte Spalte wechselt je Tab. Im Speisen-Tab wird der Katalog die
                      äußerste rechte Spalte (Leitstelle-Rail ausgeblendet, Mitte füllt auf → Katalog ganz rechts);
                      in den Kurier-/Ausgabe-Tabs zeigt sich die Leitstelle-Rail. `fb-cockpit-tab` (Z. 194,
                      im editor-tabs-Scope) bubbelt hierher und trägt den aktiven Tab. --}}
                 x-data="{ ftab: 'board' }" @fb-cockpit-tab="ftab = $event.detail.tab">
                {{-- LINKS: Navigation (Foodbook-Kopf + Kapitelbaum, gespiegelt aus der Seiten-Sidebar).
                     #4: x-data hält den Kapitel-Drag-Zustand (Ziehgriff ⠿ = umsortieren). --}}
                <div class="w-64 shrink-0 pl-6 space-y-1" data-fb-nav x-data="{ dragKapId: null }">
                    <button type="button" wire:click="kopfAnzeigen"
                            class="w-full text-left text-xs px-2 py-1 rounded-lg {{ $selectedKapitelId === null ? $aktiv : $hover }}"
                            data-fb-kopf-modal>@svg('heroicon-o-clipboard-document-list', 'w-3.5 h-3.5 inline-block align-middle') Foodbook-Kopf</button>
                    <div class="flex items-center gap-1 pt-1">
                        <input type="text" wire:model="neuesKapitelTitel" wire:keydown.enter="kapitelNeu" placeholder="Neues Kapitel …" class="{{ $input }} py-0.5" />
                        <button type="button" wire:click="kapitelNeu" class="{{ $btnGhostXs }}" title="Top-Kapitel">+</button>
                    </div>

                    @foreach($kapitelTree as $kt)
                        <div wire:key="ktm-{{ $kt['id'] }}"
                             @dragover.prevent @drop.prevent="if (dragKapId && dragKapId !== {{ $kt['id'] }}) { $wire.kapitelVerschiebenAuf(dragKapId, {{ $kt['id'] }}); } dragKapId = null"
                             :class="dragKapId === {{ $kt['id'] }} ? 'opacity-40' : (dragKapId ? 'ring-1 ring-violet-300/60 rounded-lg' : '')"
                             class="group flex items-center gap-1" style="padding-left: {{ $kt['depth'] * 12 }}px">
                            {{-- #4: Ziehgriff zum Umsortieren (setData ist Pflicht für Safari) --}}
                            <span class="cursor-grab active:cursor-grabbing text-gray-400 hover:text-violet-500 select-none shrink-0 opacity-0 group-hover:opacity-100" draggable="true"
                                  @dragstart="dragKapId = {{ $kt['id'] }}; $event.dataTransfer.setData('text/plain', String({{ $kt['id'] }})); $event.dataTransfer.effectAllowed = 'move'"
                                  @dragend="dragKapId = null" title="ziehen zum Sortieren" data-kapitel-drag>⠿</span>
                            <button type="button" wire:click="kapitelWaehle({{ $kt['id'] }})"
                                    class="flex-1 min-w-0 text-left break-words leading-tight text-xs px-2 py-0.5 rounded-lg {{ $selectedKapitelId === $kt['id'] ? $aktiv : $hover }}">{{ $kt['title'] }}</button>
                            <button type="button" wire:click="kapitelHoch({{ $kt['id'] }})" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-400 text-[10px]" title="hoch">▲</button>
                            <button type="button" wire:click="kapitelRunter({{ $kt['id'] }})" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-400 text-[10px]" title="runter">▼</button>
                            <button type="button" wire:click="kapitelNeu({{ $kt['id'] }})" class="shrink-0 text-violet-400 hover:text-violet-500 text-xs px-1 leading-none" title="Unterkapitel">＋</button>
                            <button type="button" wire:click="kapitelLoeschen({{ $kt['id'] }})" wire:confirm="Kapitel löschen?" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-400 text-[11px]" title="löschen">✕</button>
                        </div>
                    @endforeach
                </div>

                {{-- MITTE: Editor-Tabs --}}
                <div class="flex-1 min-w-0 px-6">
            <div wire:key="fbcockpit-{{ $fb->id }}" class="space-y-4">
                <x-foodalchemist::editor-tabs marker="fb" wire-key="fb-tabs-{{ $fb->id }}"
                    {{-- Spec 42: reine Ausgabe-Form — Planung/Kreativ/DNA/Trend sind in die Leitstelle
                         gezogen. Es bleiben die Kuratier-/Ausgabe-Tabs. --}}
                    {{-- Board (2026-08-27, Dominique): Übersicht/Fortschritt/Preise zu EINER Seite fusioniert
                         (Status + Inhalt + Preis je Kapitel, zugeklappt, Coverage inline). Board = Default-Reiter. --}}
                    :tabs="[
                        'board' => 'Board',
                        'briefing' => 'Kontext',
                        'speisen' => $selectedKapitelId ? 'Speisen' : null,
                        'branding' => 'Branding/CI',
                    ]">

                {{-- headless: Event-Bus im editor-tabs-Scope (kein sichtbares Element).
                     $root = editor-tabs-Wurzel (trägt die data-fb-tab-Buttons + data-fb-anker-Panels). --}}
                <div x-effect="$dispatch('fb-cockpit-tab', { tab })"
                     @fb-goto.window="let d=$event.detail; if(d.tab && $root.querySelector(`[data-fb-tab='${d.tab}']`)) tab=d.tab; $nextTick(()=>{ if(d.anker){ let el=$root.querySelector(`[data-fb-anker='${d.anker}']`); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); } });"></div>

                {{-- Phasen-Stepper (Kontext→…→Freigabe) entfernt (Dominique 2026-08-23): die Phase ist ein
                     Planung-/Leitstelle-Konzept, nicht Sache des reinen Ausgabe-Editors. --}}

                {{-- ═══ Tab: ÜBERSICHT (Speisen-Baum read-only — aus dem aufgelösten rechten Panel) ═══ --}}
                {{-- ═══ Tab: BOARD — Übersicht + Fortschritt + Preise fusioniert (2026-08-27, Dominique) ═══
                     Ein Kapitel-Baum, jedes Kapitel eine zugeklappte Zeile mit allen drei Linsen
                     (WE-Ampel/Status · EK/VK/WE% · Pills), aufklappbar zu Positionen + Coverage inline.
                     Deep-Links: „Planen" → Kapitel öffnen, „VK →" → VK-Editor. --}}
                <div x-show="tab === 'board'" x-cloak class="space-y-3" data-fb-panel="board" data-fb-anker="board">
                    @php($ampelDot = ['gruen' => 'bg-emerald-500', 'gelb' => 'bg-amber-400', 'rot' => 'bg-rose-500', 'unbekannt' => 'bg-gray-300'])
                    @php($ampelText = ['gruen' => 'text-emerald-700', 'gelb' => 'text-amber-700', 'rot' => 'text-rose-700', 'unbekannt' => 'text-gray-400'])
                    @php($befundAmpel = ['erfuellt' => 'text-emerald-600', 'teilerfuellt' => 'text-amber-600', 'verletzt' => 'text-rose-600', 'info' => 'text-sky-600'])
                    @php($angelegtN = collect($kapitelBoard)->where('released', true)->count())
                    @php($ges = $menue['gesamt'] ?? [])

                    {{-- Summen-Leiste --}}
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <div class="{{ $card }} p-3"><p class="text-[11px] text-gray-500 uppercase tracking-wider">Ø VK / Person</p><p class="text-lg font-semibold tabular-nums text-gray-900">{{ ($ges['vk_pro_person'] ?? 0) > 0 ? number_format((float) $ges['vk_pro_person'], 2, ',', '.') . ' €' : '—' }}</p></div>
                        <div class="{{ $card }} p-3"><p class="text-[11px] text-gray-500 uppercase tracking-wider">Ø Wareneinsatz</p><p class="text-lg font-semibold tabular-nums text-gray-900">{{ ($ges['ek_per_person'] ?? 0) > 0 ? number_format((float) $ges['ek_per_person'], 2, ',', '.') . ' €' : '—' }}</p></div>
                        <div class="{{ $card }} p-3"><p class="text-[11px] text-gray-500 uppercase tracking-wider">WE-Quote</p><p class="text-lg font-semibold tabular-nums {{ ($ges['food_cost_percent'] ?? null) !== null ? 'text-amber-600' : 'text-gray-400' }}">{{ ($ges['food_cost_percent'] ?? null) !== null ? number_format((float) $ges['food_cost_percent'], 1, ',', '.') . ' %' : '—' }}</p></div>
                        <div class="{{ $card }} p-3"><p class="text-[11px] text-gray-500 uppercase tracking-wider">Kapitel angelegt</p><p class="text-lg font-semibold tabular-nums text-gray-900">{{ $angelegtN }} / {{ count($kapitelBoard) }}</p></div>
                    </div>

                    {{-- Kapitel-Liste (der EINE Baum) --}}
                    <div class="{{ $card }} divide-y divide-black/5 overflow-hidden">
                        @forelse($kapitelBoard as $kap)
                            @php($we = $kap['wareneinsatz'])
                            @php($agg = $kap['aggregat'])
                            <div wire:key="board-{{ $kap['kapitel_id'] }}" x-data="{ open: false }" class="even:bg-black/[0.015]">
                                {{-- Kopfzeile: klick = auf/zu; Aktions-Cluster rechts stoppt die Propagation --}}
                                <div class="flex items-center gap-1.5 py-1 px-3 cursor-pointer hover:bg-violet-500/[0.04]" @click="open = !open" style="padding-left: {{ 12 + ($kap['depth'] - 1) * 16 }}px">
                                    <i class="ti ti-chevron-right text-gray-400 shrink-0 transition-transform" :class="open && 'rotate-90'" style="font-size:15px"></i>
                                    <span class="w-2 h-2 rounded-full shrink-0 {{ $ampelDot[$we['status']] ?? 'bg-gray-300' }}" title="Wareneinsatz {{ $we['status'] }}"></span>
                                    <span class="text-sm font-medium text-gray-800 min-w-0 break-words">{{ $kap['titel'] }}</span>
                                    @if($kap['pricing_mode'])<span class="text-[10px] uppercase tracking-wide text-gray-400 shrink-0">{{ $kap['pricing_mode'] }}</span>@endif
                                    @if($kap['released'])<span class="text-[10px] text-emerald-600 shrink-0" title="Kapitel angelegt">● angelegt</span>@endif
                                    <div class="ml-auto flex items-center gap-2.5 text-[11px] tabular-nums shrink-0" @click.stop>
                                        <span class="{{ $pill }} {{ $kap['hat_ziele'] ? $variantPill['primary'] : $variantPill['secondary'] }}" title="Ziele/Dimensionen gesetzt">Z</span>
                                        <span class="{{ $pill }} {{ $kap['positionen_count'] > 0 ? $variantPill['info'] : $variantPill['secondary'] }}" title="Positionen">{{ $kap['positionen_count'] }}</span>
                                        <span class="{{ $pill }} {{ $kap['bepreist'] ? $variantPill['success'] : ($kap['hat_inhalt'] ? $variantPill['warning'] : $variantPill['secondary']) }}" title="{{ $kap['bepreist'] ? 'bepreist' : ($kap['hat_inhalt'] ? 'angelegt/ohne Preis' : 'leer') }}">€</span>
                                        @php($istRollup = count($kap['positionen']) === 0 && ($agg['vk_pro_person'] > 0 || $agg['pauschal'] > 0))
                                        @if($agg['ek_per_person'] > 0)<span class="text-gray-400" title="Wareneinsatz €/Gast">EK {{ number_format((float) $agg['ek_per_person'], 2, ',', '.') }}</span>@endif
                                        @if($agg['vk_pro_person'] > 0)<span class="font-semibold text-gray-800" title="{{ $istRollup ? 'VK = Summe der Unterkapitel' : 'VK €/Gast' }}">@if($istRollup)<span class="text-gray-400 font-normal" title="Summe der Unterkapitel">Σ&nbsp;</span>@endif{{ number_format((float) $agg['vk_pro_person'], 2, ',', '.') }} €/G</span>@endif
                                        <span class="inline-flex items-center gap-1 {{ $ampelText[$we['status']] ?? 'text-gray-400' }}" title="WE {{ $we['ist_pct'] !== null ? number_format((float) $we['ist_pct'], 1, ',', '.') . ' %' : 'unbekannt' }} · Ziel {{ number_format((float) $we['ziel_pct'], 1, ',', '.') }} %">
                                            <span class="inline-block h-2 w-2 rounded-full {{ $ampelDot[$we['status']] ?? 'bg-gray-300' }}"></span>{{ $we['ist_pct'] !== null ? number_format((float) $we['ist_pct'], 1, ',', '.') . ' %' : '—' }}
                                        </span>
                                        @unless($kap['released'])<button type="button" wire:click="kapitelWaehle({{ $kap['kapitel_id'] }})" class="text-violet-500 hover:text-violet-700" title="Kapitel öffnen &amp; weiterplanen">Planen</button>@endunless
                                    </div>
                                </div>
                                {{-- Aufgeklappt: Positionen + Coverage inline --}}
                                <div x-show="open" x-cloak class="pb-2 pr-3 space-y-0.5" style="padding-left: {{ 34 + ($kap['depth'] - 1) * 16 }}px">
                                    @forelse($kap['positionen'] as $p)
                                        @php($vkLink = $p['ref_id'] === null ? null : ($p['ref_typ'] === 'concept'
                                            ? route('foodalchemist.concepter.index', ['tab' => 'concepts', 'sel' => $p['ref_id']])
                                            : route('foodalchemist.verkauf.index', ['rezept' => $p['ref_id']])))
                                        <div class="flex items-center gap-2 py-0.5 text-xs">
                                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[9px] uppercase tracking-wide {{ $p['art'] === 'paket' ? 'bg-violet-500/10 text-violet-700' : 'bg-sky-500/10 text-sky-700' }}">{{ $p['art'] === 'paket' ? 'Paket' : 'Einzel' }}</span>
                                            <span class="truncate text-gray-800">{{ $p['label'] }}</span>
                                            <div class="ml-auto flex items-center gap-3 tabular-nums shrink-0">
                                                @if($p['ek'] > 0)<span class="text-gray-400">EK {{ number_format((float) $p['ek'], 2, ',', '.') }}</span>@endif
                                                @if($p['vk'] > 0)<span class="font-semibold text-gray-700">{{ number_format((float) $p['vk'], 2, ',', '.') }} {{ $p['preis_einheit'] === 'gast' ? '€/G' : '€/Pos' }}</span>@else<span class="text-amber-600">kein VK</span>@endif
                                                @if($p['we_pct'] !== null)<span class="text-gray-400" title="Wareneinsatz dieser Position">{{ number_format((float) $p['we_pct'], 1, ',', '.') }} %</span>@endif
                                                @if($vkLink)<a href="{{ $vkLink }}" target="_blank" class="text-violet-600 hover:underline" title="Im VK-Editor öffnen">VK →</a>@endif
                                            </div>
                                        </div>
                                    @empty
                                        @if(($agg['vk_pro_person'] ?? 0) > 0 || ($agg['pauschal'] ?? 0) > 0)
                                            <p class="text-[11px] text-gray-400 py-0.5">Keine eigenen Positionen — der Preis ist die <span class="text-gray-500">Summe der Unterkapitel</span>.</p>
                                        @else
                                            <p class="text-[11px] text-gray-400 py-0.5">Noch keine bepreisten Positionen — im Speisen-Tab / in der Leitstelle anlegen.</p>
                                        @endif
                                    @endforelse
                                    @if(! empty($boardCoverage[$kap['kapitel_id']] ?? []))
                                        <div class="flex flex-wrap gap-x-4 gap-y-1 pt-2 mt-1 border-t border-black/5">
                                            @foreach($boardCoverage[$kap['kapitel_id']] as $b)
                                                <span class="text-[11px] {{ $befundAmpel[$b['ampel']] ?? 'text-gray-500' }}">{{ $b['label'] }}: {{ $b['ist'] }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400 p-5">Noch keine Kapitel — links „Neues Kapitel …" anlegen oder in der Leitstelle planen.</p>
                        @endforelse
                    </div>
                </div>

                {{-- ═══ Tab: BRIEFING (Stammdaten · Kunde · Leitidee) ═══ --}}
                <div x-show="tab === 'briefing'" x-cloak class="space-y-3" data-fb-panel="briefing">
                {{-- Foodbook-Stammdaten --}}
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="fbhdr-{{ $fb->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="md:col-span-2"><label class="{{ $label }}">Bezeichnung</label><input type="text" wire:model="form.label" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Jahr</label><input type="number" wire:model="form.jahr" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Personen</label><input type="number" wire:model="form.personen" class="{{ $input }}" /></div>
                </div>

                {{-- Spec 33 P5: Status, Gültigkeitsfenster und beide Zuordnungsachsen kommen aus
                     einem geteilten Bauteil — dieselbe Bedienung in allen drei Ausgabeformen. --}}
                <div class="pt-1 border-t border-black/5">
                    <x-foodalchemist::ausgabe-status
                        status-model="form.status" von-model="form.gueltig_von" bis-model="form.gueltig_bis"
                        outlet-model="form.outlet_id"
                        :betriebe="$betriebe" :zustand="$fb->laufZustand()" :grund="$fb->laufGrund()"
                        :konflikt="$portfolioKonflikt" toggle="aktivUmschalten" />
                </div>

                {{-- R4.3-Phasen-Stepper wanderte auf Tab-Ebene (E5.2, oben in der Leitstellen-Leiste). --}}

                {{-- Phase 5: Segment (aus Küchen-Typ) = Achse für Portionen/Preis/Komplexität/Ton.
                     Niveau + Convenience = Default-Erwartung des Segments (Vokabular der KI-Rezept-Regler). --}}
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 pt-1 border-t border-black/5" data-segment>
                    <span class="{{ $label }} !mb-0">Segment</span>
                    @if($segment ?? null)
                        <span class="{{ $pill }} {{ $variantPill['primary'] }}">{{ $segment['label'] }}</span>
                        <span class="text-[11px] text-gray-500">Niveau {{ \Platform\FoodAlchemist\Services\TeamSettingsService::NIVEAU_LABEL[$segment['niveau']] ?? $segment['niveau'] }} · {{ \Platform\FoodAlchemist\Services\TeamSettingsService::CONVENIENCE_LABEL[$segment['convenience']] ?? $segment['convenience'] }}</span>
                    @else
                        <span class="text-[11px] text-amber-600">nicht gesetzt — Küchen-Profil in den Einstellungen wählen (steuert Niveau + Convenience der Generierung)</span>
                    @endif
                </div>

                {{-- Kickoff-Wizard → in den Planung-Tab verschoben (Spec 29 / S7 — Briefing entlastet) --}}

                <x-foodalchemist::crm-kunde-picker
                    :ausgabe="$fb" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />

                {{-- Spec-42-Vollzug S3b: Planung raus aus dem Foodbook. Leitplanken (Schreibstil/Kundentyp/
                     Niveau), Briefing/Einleitung + KI-Text leben jetzt in der Leitstelle (Planung\FoodbookKontextRail).
                     Das Foodbook ist reine Ausgabe — hier nur Stammdaten/Status/Kunde. --}}
            </div>

                {{-- Leitidee-Canvas → Leitstelle (Planung\FoodbookKontextRail). --}}
                </div>{{-- /Briefing --}}


                {{-- ═══ Tab: BRANDING / CI (pro Foodbook) — Phase 6, verdrahtet FoodbookService-Branding-API ═══ --}}
                <div x-show="tab === 'branding'" x-cloak class="space-y-3" data-fb-panel="branding"
                     x-data="{ brand: @entangle('brandingForm.brand_color'), band: @entangle('brandingForm.band_color'), footer: @entangle('brandingForm.footer_text') }">
                    <div class="relative overflow-hidden {{ $card }} p-5 space-y-4">
                        <div class="{{ $cardAccent }}"></div>

                        @if($brandingFehler)
                            <div class="rounded-lg bg-rose-500/10 border border-rose-500/30 px-2.5 py-1.5 text-[11px] text-rose-700" data-branding-fehler>{{ $brandingFehler }}</div>
                        @endif
                        @if($brandingGespeichert)
                            <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/25 px-2.5 py-1.5 text-[11px] text-emerald-700">✓ Gespeichert — fließt ins Dokument-PDF.</div>
                        @endif

                        {{-- Live-Vorschau: Kopf-Band (Bandfarbe + Logo) · Fuß-Linie (Marken-Farbe) --}}
                        <div>
                            <p class="{{ $label }} mb-1">Vorschau</p>
                            <div class="rounded-lg overflow-hidden border border-black/10">
                                <div class="flex items-center justify-between gap-2 px-3 h-9 text-white text-[11px] uppercase tracking-wide" :style="`background:${band || brand}`">
                                    <span class="truncate">{{ $fb->label }}</span>
                                    @if($fb->logo_path)<img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($fb->logo_context_file_id, $fb->logo_path) }}" alt="Logo" class="max-h-5 max-w-[90px] object-contain shrink-0" />@endif
                                </div>
                                <div class="px-3 py-3 text-[11px] text-gray-600" :style="`border-top:3px solid ${brand}`">
                                    <span x-text="footer || 'Erstellt mit Food Alchemist'"></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {{-- Marken-Farbe --}}
                            <div>
                                <label class="{{ $label }}">Marken-Farbe</label>
                                <div class="flex items-center gap-2 mt-1">
                                    <input type="color" x-model="brand" class="h-9 w-12 rounded border border-black/10 bg-transparent cursor-pointer p-0.5" data-brand-color />
                                    <input type="text" x-model="brand" class="{{ $input }} w-32 font-mono" placeholder="#6d28d9" />
                                </div>
                                <p class="text-[10px] text-gray-500 mt-1">Rahmen, Linien, Badges im PDF.</p>
                            </div>
                            {{-- Bandfarbe (optional) --}}
                            <div>
                                <label class="{{ $label }}">Bandfarbe (optional)</label>
                                <div class="flex items-center gap-2 mt-1">
                                    <input type="color" x-model="band" class="h-9 w-12 rounded border border-black/10 bg-transparent cursor-pointer p-0.5" />
                                    <input type="text" x-model="band" class="{{ $input }} w-32 font-mono" placeholder="aus Marke" />
                                    <button type="button" @click="band = ''" class="{{ $btnGhostXs }}" title="leeren → leitet aus der Marken-Farbe ab">✕</button>
                                </div>
                                <p class="text-[10px] text-gray-500 mt-1">Kopf-/Fuß-Band. Leer = wie Marken-Farbe.</p>
                            </div>
                        </div>

                        {{-- Footer-Text --}}
                        <div>
                            <label class="{{ $label }}">Footer-Text</label>
                            <input type="text" x-model="footer" class="{{ $input }}" placeholder="Erstellt mit Food Alchemist" />
                        </div>

                        {{-- Logo + Cover --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-black/5">
                            <div>
                                <label class="{{ $label }}">Logo</label>
                                @if($fb->logo_path)
                                    <div class="flex items-center gap-2 mt-1 mb-1">
                                        <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($fb->logo_context_file_id, $fb->logo_path) }}" alt="Logo" class="h-10 max-w-[120px] object-contain rounded border border-black/5 bg-white p-1" />
                                        <button type="button" wire:click="brandingLogoEntfernen" class="{{ $btnGhostXs }} text-red-600" data-logo-entfernen>entfernen</button>
                                    </div>
                                @endif
                                <input type="file" wire:model="logoUpload" accept="image/*" class="block w-full text-[11px] text-gray-600 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-violet-500/10 file:text-violet-700 file:text-[11px] cursor-pointer" data-logo-upload />
                                <div wire:loading wire:target="logoUpload" class="text-[10px] text-gray-500 mt-0.5">lädt …</div>
                                @error('logoUpload')<span class="text-[10px] text-rose-600">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="{{ $label }}">Cover-Bild</label>
                                @if($fb->cover_image_path)
                                    <div class="flex items-center gap-2 mt-1 mb-1">
                                        <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($fb->cover_context_file_id, $fb->cover_image_path) }}" alt="Cover" class="h-10 max-w-[120px] object-cover rounded border border-black/5" />
                                        <button type="button" wire:click="brandingCoverEntfernen" class="{{ $btnGhostXs }} text-red-600" data-cover-entfernen>entfernen</button>
                                    </div>
                                @endif
                                <input type="file" wire:model="coverUpload" accept="image/*" class="block w-full text-[11px] text-gray-600 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-violet-500/10 file:text-violet-700 file:text-[11px] cursor-pointer" data-cover-upload />
                                <div wire:loading wire:target="coverUpload" class="text-[10px] text-gray-500 mt-0.5">lädt …</div>
                                @error('coverUpload')<span class="text-[10px] text-rose-600">{{ $message }}</span>@enderror
                            </div>
                        </div>

                        <div class="flex items-center gap-2 pt-2">
                            <button type="button" wire:click="brandingSpeichern" class="{{ $btnPrimary }}" data-branding-speichern>Speichern</button>
                            <a href="{{ route('foodalchemist.foodbooks.dokument', $fb->id) }}?pdf=1" target="_blank" class="{{ $btnGhost }}" title="Branding im PDF gegenprüfen">→ Im Dokument (PDF) ansehen</a>
                        </div>
                    </div>
                </div>{{-- /Branding --}}

                {{-- ═══ Tab: SPEISEN — der Kapitel/Block-Editor (Spec 29 / S6) ═══
                     Früher stapelte er UNTER dem Cockpit (Doppel-Editor). Jetzt eigener Tab, der nur
                     erscheint, wenn ein Kapitel gewählt ist (Label null ⇒ Tab entfällt, siehe :tabs).
                     Koexistenz-Bugfix bleibt: die $kapitel-abhängigen Cockpit-Inhalte (Kreativ 3-Modus,
                     Skizzen, Pairing) rendern weiter in IHREN Panels — hier wird nichts geklammert,
                     das andere Panels betrifft. --}}
                <div x-show="tab === 'speisen'" x-cloak class="pt-3 space-y-3" data-fb-panel="speisen">
            @if($kapitel)
                {{-- Picker-Umbau: 2-Spalten — links [Kapitel-Kopf + Inhalt], rechts der Katalog. Der Kopf liegt
                     in der linken Spalte, damit der Katalog rechts oben bündig startet (Dominique 2026-08-23). --}}
                <div class="flex gap-4 items-start" data-fb-speisen-2col>
                <div class="flex-1 min-w-0 space-y-3" data-fb-speisen-links>
                {{-- Kapitel-Kopf --}}
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="kaphdr-{{ $kapitel->id }}">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div><label class="{{ $label }}">Kapitel (intern)</label><input type="text" wire:model.blur="kapitelForm.title" wire:change="kapitelSpeichern" class="{{ $input }}" /></div>
                        <div class="md:col-span-2"><label class="{{ $label }}">Konsumententitel</label><input type="text" wire:model.blur="kapitelForm.consumer_title" wire:change="kapitelSpeichern" class="{{ $input }}" placeholder="Marketing-Titel (PDF)" /></div>
                        <div><label class="{{ $label }}">Preis-Modus</label>
                            <select wire:model.live="kapitelForm.price_mode" wire:change="kapitelSpeichern" class="{{ $input }}"><option value="auto">auto (Σ Inhalt)</option><option value="manuell">manuell</option></select>
                        </div>
                    </div>

                    {{-- Spec 03 · L2b: der Kapitel-Kundentext. Bis hierher existierte das Feld nur in
                         der DB (`foodbook_chapters.description`) — nicht im Editor UND in keiner
                         Ausgabe; die Dokument-Projektion ist mit dieser Etappe nachgezogen. --}}
                    <div>
                        <div class="flex items-center justify-between">
                            <label class="{{ $label }}">Hinführung (Kundentext des Kapitels)</label>
                            <button type="button" wire:click="kiKapitelText" wire:loading.attr="disabled" wire:target="kiKapitelText"
                                    title="foodbook.kundentext (Ebene Kapitel): Hinführung aus Kapitel-Inhalt (Wording-Kette), Buch-Einleitung und Marken-Stimme" data-fb-ki-kapiteltext
                                    class="{{ $btnAi }}">
                                <span wire:loading.remove wire:target="kiKapitelText">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Text</span>
                                <span wire:loading wire:target="kiKapitelText">schreibt …</span>
                            </button>
                        </div>
                        <textarea wire:model.blur="kapitelForm.description" wire:change="kapitelSpeichern" rows="2"
                                  class="{{ $input }} resize-none min-h-[3.5rem]"
                                  placeholder="Kurzer Kundentext, der ins Kapitel einführt — „KI-Text" schlägt einen vor"></textarea>
                        @include('foodalchemist::livewire.foodbooks.partials.ki-text-vorschau', [
                            'ziel' => 'kapitel',
                            'vorhanden' => trim((string) ($kapitelForm['description'] ?? '')) !== '',
                        ])
                    </div>

                    {{-- #2: Schreibstil PRO KAPITEL — Standard (aus den Concepten) oder Override. Der ✨-Knopf
                         betextet alle Konzepte dieses Kapitels im gewählten Stil neu und speichert das
                         foodbook-LOKAL (Snapshot); das Concept selbst bleibt unangetastet. --}}
                    <div class="flex items-end gap-2 pt-2 border-t border-black/5" data-fb-kapitel-stil>
                        <div class="flex-1 max-w-xs">
                            <label class="{{ $label }}">Schreibstil (Kapitel)</label>
                            <select wire:model.live="kapitelForm.writing_style_id" wire:change="kapitelSpeichern" class="{{ $input }}" data-fb-kapitel-schreibstil>
                                <option value="">Standard (aus den Concepten)</option>
                                @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </select>
                        </div>
                        <button type="button" wire:click="kapitelWordingGenerieren" wire:loading.attr="disabled" wire:target="kapitelWordingGenerieren"
                                @disabled(($kapitelForm['writing_style_id'] ?? null) === null || ($kapitelForm['writing_style_id'] ?? '') === '')
                                title="Betextet alle Konzepte dieses Kapitels im gewählten Schreibstil neu (foodbook-lokaler Snapshot; das Concept bleibt unangetastet)"
                                class="{{ $btnAi }} shrink-0" data-fb-kapitel-wording>
                            <span wire:loading.remove wire:target="kapitelWordingGenerieren">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Kapitel-Wording</span>
                            <span wire:loading wire:target="kapitelWordingGenerieren">betextet …</span>
                        </button>
                    </div>
                    @error('kapitelWording')<p class="text-[11px] text-rose-500 mt-1" data-fb-kapitel-fehler>{{ $message }}</p>@enderror
                </div>

                {{-- Block-Liste (in der linken Spalte, unter dem Kapitel-Kopf) --}}
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" data-fb-inhalt>
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="font-medium tracking-tight text-gray-900">Inhalt <span class="text-gray-500 text-xs">({{ $kapitel->blocks->count() }})</span></h3>
                        <div class="flex items-center gap-2" x-data="{ presets: false }">
                            @if(count($markiert) >= 2)
                                <button type="button" wire:click="wahlGruppeBilden" class="{{ $btnGhostXs }} text-amber-600">Wahl-Gruppe ({{ count($markiert) }})</button>
                            @endif
                            {{-- Concept/Gericht einfügen → jetzt im permanenten Katalog rechts. --}}
                            <button type="button" wire:click="blockBasis('text')" class="{{ $btnGhostXs }}">+ Text</button>
                            <button type="button" wire:click="blockBasis('spacer')" class="{{ $btnGhostXs }}">+ Leerzeile</button>
                            <div class="relative">
                                <button type="button" @click="presets = !presets" class="{{ $btnGhost }}">+ Header / Preis</button>
                                <div x-show="presets" x-cloak @click.outside="presets = false" class="absolute right-0 mt-1 w-56 max-h-80 overflow-y-auto z-20 {{ $card }} p-1 text-xs">
                                    <button type="button" wire:click="blockBasis('header_frei')" @click="presets=false" class="block w-full text-left px-2 py-1 rounded hover:bg-violet-500/10">— Freier Header</button>
                                    <button type="button" wire:click="blockBasis('header_frei_preis')" @click="presets=false" class="block w-full text-left px-2 py-1 rounded hover:bg-violet-500/10">€ Header + Preis</button>
                                    @foreach($headerPresets as $gruppe => $items)
                                        <div class="{{ $label }} px-2 pt-2 pb-0.5">{{ $gruppe }}</div>
                                        @foreach($items as $p)
                                            <button type="button" @click="presets=false"
                                                    wire:click="presetHinzu(@js($p['type']), @js($p['slug']), @js($p['label']), @js($p['price_basis'] ?? null), {{ ($p['visible'] ?? true) ? 'true' : 'false' }})"
                                                    class="block w-full text-left px-3 py-0.5 rounded hover:bg-violet-500/10 truncate">{{ $p['label'] }}</button>
                                        @endforeach
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>


                    {{-- x-data hält den Drag-Zustand; Ziehgriff ⠿ = umsortieren, ▲▼ bleibt als Kanten-Alternative (Muster wie Concepter-Slots, 2026-07-21) --}}
                    <div class="space-y-1" x-data="{ dragBlockId: null }">
                        @forelse($kapitel->blocks as $block)
                            <div wire:key="block-{{ $block->id }}"
                                 @dragover.prevent @drop.prevent="if (dragBlockId && dragBlockId !== {{ $block->id }}) { $wire.blockVerschiebenAuf(dragBlockId, {{ $block->id }}); } dragBlockId = null"
                                 :class="dragBlockId === {{ $block->id }} ? 'opacity-40' : (dragBlockId ? 'ring-1 ring-violet-300/60' : '')"
                                 class="rounded-lg border {{ $block->variant_group_id ? 'border-amber-400/60' : 'border-black/5' }} px-2 py-1 {{ $block->visible ? '' : 'opacity-60' }}"
                                 style="margin-left: {{ $block->level * 20 }}px">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="flex items-center shrink-0">
                                        {{-- R4: setData ist Pflicht, sonst startet Safari den Drag nicht --}}
                                        <span class="cursor-grab active:cursor-grabbing text-gray-400 hover:text-violet-500 select-none mr-0.5" draggable="true"
                                              @dragstart="dragBlockId = {{ $block->id }}; $event.dataTransfer.setData('text/plain', String({{ $block->id }})); $event.dataTransfer.effectAllowed = 'move'"
                                              @dragend="dragBlockId = null" title="ziehen zum Sortieren" data-block-drag>⠿</span>
                                        <span class="flex flex-col -my-0.5">
                                            <button type="button" wire:click="blockHoch({{ $block->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▲</button>
                                            <button type="button" wire:click="blockRunter({{ $block->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▼</button>
                                        </span>
                                    </span>
                                    @if($block->type === 'concept_ref')
                                        <input type="checkbox" wire:click="markiere({{ $block->id }})" @checked(in_array($block->id, $markiert)) title="Für Wahl-Gruppe markieren" class="shrink-0" />
                                    @else
                                        <span class="w-3 shrink-0"></span>
                                    @endif
                                    <span class="flex-1 min-w-0 truncate">
                                        @switch($block->type)
                                            @case('concept_ref')
                                                <span class="{{ $pill }} {{ $variantPill['primary'] }} mr-1">Concept</span>{{ $block->concept?->name ?? '—' }}
                                                {{-- Preisdarstellung: einzel-Concept zeigt keinen Summenpreis-Chip (die Preise stehen je Gericht in der Vorschau darunter). --}}
                                                @if($block->concept?->price_per_person_cache !== null && ! $block->concept?->istEinzelpreis())<span class="text-gray-500 tabular-nums">· {{ number_format((float) $block->concept->price_per_person_cache, 2, ',', '.') }} €/P</span>@elseif($block->concept?->istEinzelpreis())<span class="text-gray-400 tabular-nums text-[10px]">· Einzelpreise</span>@endif
                                                @if(trim((string) $block->wording) !== '')<span class="italic text-violet-600">· „{{ $block->wording }}“</span>@endif
                                                @break
                                            @case('recipe_ref')
                                                <span class="{{ $pill }} {{ $variantPill['warning'] }} mr-1">Gericht</span>{{ $block->dish?->name ?? '—' }}
                                                <span class="text-gray-500 tabular-nums">{{ $block->dish?->sales_net !== null ? '· ' . number_format((float) $block->dish->sales_net, 2, ',', '.') . ' €' . ($block->price_basis === 'pauschal' ? ' pauschal' : '/Pos') : '' }}</span>
                                                @if(trim((string) $block->wording) !== '')<span class="italic text-violet-600">· „{{ $block->wording }}“</span>@endif
                                                @break
                                            @case('header_neutral') @case('header_frei')
                                                <span class="font-semibold">{{ $block->label ?: '(Header)' }}</span>
                                                @break
                                            @case('header_frei_preis')
                                                <span class="font-semibold">{{ $block->label ?: '(Header)' }}</span>
                                                <span class="text-gray-600">· {{ $block->price_basis === 'staffel' ? 'Staffel' : number_format((float) ($block->price_value ?? 0), 2, ',', '.') . ' € ' . ($block->price_basis === 'pauschal' ? 'pauschal' : '/P') }}</span>
                                                @break
                                            @case('spacer') <span class="italic text-gray-500">Leerzeile ({{ $block->height ?? 'mittel' }})</span> @break
                                            @case('image') <span class="text-gray-600">@svg('heroicon-o-photo', 'w-3.5 h-3.5 inline-block align-middle') Bild</span> @break
                                            @default <span class="italic">{{ \Illuminate\Support\Str::limit($block->customer_text ?? '(Text)', 80) }}</span>
                                        @endswitch
                                    </span>
                                    @if($block->variant_group_id)<button type="button" wire:click="wahlGruppeAufheben({{ $block->id }})" class="{{ $pill }} {{ $variantPill['warning'] }} shrink-0" title="aus Wahl-Gruppe">Wahl #{{ $block->variant_group_id }}</button>@endif
                                    <button type="button" wire:click="blockEbene({{ $block->id }}, -1)" class="text-gray-500 hover:text-violet-500 shrink-0" title="ausrücken">←</button>
                                    <button type="button" wire:click="blockEbene({{ $block->id }}, 1)" class="text-gray-500 hover:text-violet-500 shrink-0" title="einrücken">→</button>
                                    {{-- #6: Deep-Jump ins Concept/Paket — öffnet es direkt im Concepter-Editor (neuer Tab), um dort gezielt daran zu arbeiten. --}}
                                    @if($block->type === 'concept_ref' && $block->concept_id)
                                        <a href="{{ route('foodalchemist.concepter.index', ['edit' => $block->concept_id]) }}" target="_blank" class="shrink-0 text-gray-500 hover:text-violet-500" title="im Concepter öffnen ↗" data-fb-block-concepter>@svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5 inline-block align-middle')</a>
                                    @endif
                                    <button type="button" wire:click="blockSichtbar({{ $block->id }})" class="shrink-0 text-[10px] {{ $block->visible ? 'text-gray-500' : 'text-amber-500' }}" title="sichtbar/intern">@if($block->visible)@svg('heroicon-o-eye', 'w-3.5 h-3.5 inline-block align-middle')@else intern @endif</button>
                                    @if($block->type !== 'spacer')
                                        <button type="button" wire:click="blockBearbeiten({{ $block->id }})" class="shrink-0 text-gray-500 hover:text-violet-500" title="bearbeiten / Notiz">@svg('heroicon-o-pencil', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                    @endif
                                    <button type="button" wire:click="blockRaus({{ $block->id }})" class="shrink-0 text-gray-500 hover:text-red-500" title="entfernen">✕</button>
                                </div>

                                {{-- #7/C2: Live-Menü-Vorschau (aufgelöste gerichtZeilen) — Panel statt nackter Zeilen;
                                     eingebettete Pakete aufgelöst (#1) + Paket-Preis, Einrückung.
                                     C1: direkte Gericht-Zeilen (slot_id, oberste Ebene) inline editierbar (Bleistift),
                                     schreibt den foodbook-lokalen Override. Eingebettete Paket-Zeilen sind read-only. --}}
                                @if($block->type === 'concept_ref' && ! empty($blockMenus[$block->id]))
                                    <div class="mt-1.5 ml-6 rounded-lg bg-violet-500/[0.035] border border-black/5 px-3 py-2 space-y-1" data-fb-block-vorschau>
                                        @foreach($blockMenus[$block->id] as $g)
                                            {{-- editierbar = jede Gericht-Zeile mit slot_id (direkt ODER eingebettetes Paket —
                                                 der Block wird in die Paket-Rekursion durchgereicht, Overrides greifen). --}}
                                            @php($istEditierbar = isset($g['slot_id']))
                                            @php($slotKey = $istEditierbar ? $block->id . ':' . $g['slot_id'] : null)
                                            @if(($g['type'] ?? '') === 'header')
                                                <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-500 mt-1.5 first:mt-0" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px">{{ $g['text'] }}</p>
                                            @elseif(($g['type'] ?? '') === 'paket')
                                                <div class="flex items-center gap-1.5 mt-1" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 12 }}px">
                                                    <span class="{{ $pill }} {{ $variantPill['info'] }} normal-case shrink-0">Paket</span>
                                                    <span class="text-[11px] font-medium text-violet-700 break-words">{{ $g['text'] }}</span>
                                                    @if(($g['preis'] ?? null) !== null)<span class="ml-auto text-[10px] text-gray-500 tabular-nums shrink-0">{{ number_format((float) $g['preis'], 2, ',', '.') }} €/P</span>@endif
                                                </div>
                                            @elseif($slotKey !== null && $editSlotKey === $slotKey)
                                                <div class="flex items-center gap-1" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px" data-fb-slot-editor>
                                                    <input type="text" wire:model="editSlotWording" wire:keydown.enter="slotWordingSpeichern" wire:keydown.escape="slotWordingAbbrechen"
                                                           class="{{ $input }} !py-0.5 !text-[11px] flex-1" placeholder="Anzeigename (Kunde) — leer = Wording-Kette" data-fb-slot-input />
                                                    <button type="button" wire:click="slotWordingSpeichern" class="{{ $pill }} {{ $variantPill['primary'] }} shrink-0" title="Speichern">OK</button>
                                                    <button type="button" wire:click="slotWordingAbbrechen" class="text-gray-400 hover:text-gray-600 shrink-0 text-xs px-1" title="Abbrechen">×</button>
                                                </div>
                                            @else
                                                <div class="group/dish flex items-center gap-1 text-[11px] {{ ($g['source'] ?? null) === 'name' ? 'text-amber-600 italic' : 'text-gray-600' }}" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px">
                                                    <span class="text-gray-300 shrink-0">·</span>
                                                    <span class="break-words">{{ $g['text'] }}</span>
                                                    @if(($g['source'] ?? null) === 'name')<span class="text-[9px] text-amber-500 shrink-0">Wording fehlt</span>@endif
                                                    {{-- Einzelpreis-Concept: VK je direkter Gericht-Zeile auch in der Editor-Vorschau (nicht nur beim Paket). --}}
                                                    @if(($g['preis'] ?? null) !== null)<span class="ml-auto text-[10px] text-gray-500 tabular-nums shrink-0">{{ number_format((float) $g['preis'], 2, ',', '.') }} €/P</span>@endif
                                                    @if($istEditierbar)
                                                        <button type="button" wire:click="slotWordingBearbeiten({{ $block->id }}, {{ $g['slot_id'] }}, @js(($g['source'] ?? null) === 'name' ? '' : $g['text']))"
                                                                class="ml-1 shrink-0 text-gray-300 hover:text-violet-500 opacity-0 group-hover/dish:opacity-100 transition-opacity" title="Anzeigename bearbeiten" data-fb-slot-edit>@svg('heroicon-o-pencil', 'w-3 h-3 inline-block align-middle')</button>
                                                    @endif
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endif

                                @if($editBlockId === $block->id)
                                    <div class="mt-2 space-y-2 pl-6">
                                        @if(in_array($block->type, ['header_neutral', 'header_frei', 'header_frei_preis']))
                                            <input type="text" wire:model="blockForm.label" placeholder="Header-Text" class="{{ $input }}" />
                                        @endif
                                        @if($block->type === 'header_frei_preis')
                                            <div class="flex gap-2">
                                                <select wire:model="blockForm.price_basis" class="{{ $input }} w-32"><option value="person">pro Person</option><option value="pauschal">Pauschal</option><option value="staffel">Staffel</option></select>
                                                <input type="number" step="0.01" wire:model="blockForm.price_value" class="{{ $input }} w-28 text-right tabular-nums" placeholder="0,00 €" />
                                            </div>
                                        @endif
                                        @if($block->type === 'concept_ref')
                                            {{-- Wording-Kette, oberste Stufe: Foodbook-Override → Konzept-Wording → VK-Wording-Standard → Name --}}
                                            <input type="text" wire:model="blockForm.wording" class="{{ $input }}" placeholder="Anzeigename (Kunde) — leer = Wording-Kette (Konzept → Standard → Name)" data-fb-block-wording />
                                        @endif
                                        @if($block->type === 'recipe_ref')
                                            {{-- E1.3: Einzel-Gericht — Wording-Override (Foodbook → VK-Wording-Standard → Name) + Preis-Achse (E1.2) --}}
                                            <input type="text" wire:model="blockForm.wording" class="{{ $input }}" placeholder="Anzeigename (Kunde) — leer = Wording-Kette (Standard → Name)" data-fb-block-wording />
                                            <select wire:model="blockForm.price_basis" class="{{ $input }} w-40" title="Preis-Achse für dieses Gericht"><option value="person">pro Position (×Pax)</option><option value="pauschal">Pauschal</option></select>
                                        @endif
                                        @if($block->type === 'text')
                                            <textarea wire:model="blockForm.customer_text" rows="3" class="{{ $input }}" placeholder="Marketing-Text (kundensichtbar)"></textarea>
                                        @else
                                            <div class="flex gap-1.5 items-start">
                                                <textarea wire:model="blockForm.customer_text" rows="2" class="{{ $input }}" placeholder="Beschreibungstext / Untertitel (kundensichtbar, optional)"></textarea>
                                                @if($block->type === 'concept_ref')
                                                    <button type="button" wire:click="kiKundentext" class="{{ $btnAi }} shrink-0 mt-0.5" title="vk.marketing: verkäuferischer Beschreibungstext zu diesem Concept" data-fb-ki-kundentext>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                                @endif
                                            </div>
                                        @endif
                                        <input type="text" wire:model="blockForm.interne_bemerkung" class="{{ $input }}" placeholder="Interne Notiz (nicht kundensichtbar)" />
                                        <div class="flex gap-2">
                                            <button type="button" wire:click="blockSpeichern" class="{{ $btnPrimary }}">OK</button>
                                            <button type="button" wire:click="$set('editBlockId', null)" class="{{ $btnGhost }}">Abbrechen</button>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            {{-- E1b (Spec 40): Speisen-Tab entzerren — der KI-Weg (Voll-Kaskade) ist NICHT tot, nur woanders.
                                 Empty-State macht ihn sichtbar, statt gefühlter Sackgasse. Tiefen-Ausbau → Werkstrang M. --}}
                            <div class="py-4 text-center space-y-1">
                                <p class="text-xs text-gray-500">Noch kein Inhalt. Rechts im Katalog ein Concept, Paket oder Format einfügen — oder Header/Text/Preis-Block hinzufügen.</p>
                                <p class="text-[11px] text-violet-600/80">Oder KI-befüllen: die <span class="font-medium">Voll-Kaskade</span> (Planung) erzeugt je Kapitel automatisch ein Konzept — es landet direkt hier im Inhalt.</p>
                            </div>
                        @endforelse
                    </div>

                </div>{{-- /Inhalt --}}
                </div>{{-- /linke Spalte (Kopf + Inhalt) --}}

                {{-- #3: Persistenter Katalog — ausschließlich Concept · Paket · Format (kein Gericht-Reiter).
                     Suche + Facetten; „+" bucht Concept/Paket als concept_ref ins gewählte Kapitel, Format
                     als eigenes Kapitel (F5: live concept_ref-Blöcke, kein Sonderweg). Server-Modus. --}}
                <x-foodalchemist::katalog-picker marker="fb" switch="katalogModus" :modes="[
                    ['key' => 'concept', 'label' => 'Concept', 'active' => $pickerModus === 'concept'],
                    ['key' => 'paket', 'label' => 'Paket', 'active' => $pickerModus === 'paket'],
                    ['key' => 'format', 'label' => 'Format', 'active' => $pickerModus === 'format'],
                ]">
                    @if($pickerModus === 'concept')
                        <input type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen …" class="{{ $input }} w-full mb-2 shrink-0" data-fb-katalog-concept />
                        @php($facettenAktiv = collect($conceptFacetten)->filter(fn ($v) => $v !== null)->isNotEmpty())
                        {{-- Facetten als Dropdowns (Produktions-Muster) statt Pill-Wand — Concepter-Dimensionen. --}}
                        <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-fb-concept-facetten>
                            <select wire:model.live="conceptFacetten.eventtyp" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-facet-eventtyp>
                                <option value="">Alle Eventtypen</option>
                                @foreach($facetteEventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                            </select>
                            <select wire:model.live="conceptFacetten.servierform" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-facet-servierform>
                                <option value="">Alle Servierformen</option>
                                @foreach($facetteServierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                            </select>
                            <select wire:model.live="conceptFacetten.einsatzmoment" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-facet-einsatzmoment>
                                <option value="">Alle Einsatzmomente</option>
                                @foreach($facetteMomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                            </select>
                            <select wire:model.live="conceptFacetten.season" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-facet-season>
                                <option value="">Alle Saisons</option>
                                @foreach($facetteSaisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="flex-1 overflow-y-auto space-y-0.5">
                            @forelse($conceptKandidaten as $ck)
                                <x-foodalchemist::katalog-row wire:key="kck-{{ $ck->id }}" wire:click="conceptHinzu({{ $ck->id }})" :title="$ck->name" :price="$ck->price_per_person_cache !== null ? number_format((float) $ck->price_per_person_cache, 2, ',', '.') . ' €' : null">{{ $ck->name }}</x-foodalchemist::katalog-row>
                            @empty
                                <p class="text-[11px] text-gray-500 px-2 py-2">{{ $conceptSuche !== '' || $facettenAktiv ? 'Keine Concepts für diese Auswahl.' : 'Noch keine Concepts angelegt.' }}</p>
                            @endforelse
                        </div>
                    @elseif($pickerModus === 'paket')
                        {{-- #3: Paket-Reiter (kind=paket-Concepts) — dieselben Facetten-Dropdowns wie Concept,
                             zeigt den Kundennamen (consumer_name). Buchung als concept_ref (paketHinzu). --}}
                        <input type="search" wire:model.live.debounce.300ms="paketSuche" placeholder="Paket suchen …" class="{{ $input }} w-full mb-2 shrink-0" data-fb-katalog-paket />
                        @php($paketFacettenAktiv = collect($paketFacetten)->filter(fn ($v) => $v !== null)->isNotEmpty())
                        <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-fb-paket-facetten>
                            <select wire:model.live="paketFacetten.eventtyp" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-paket-facet-eventtyp>
                                <option value="">Alle Eventtypen</option>
                                @foreach($facetteEventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                            </select>
                            <select wire:model.live="paketFacetten.servierform" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-paket-facet-servierform>
                                <option value="">Alle Servierformen</option>
                                @foreach($facetteServierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                            </select>
                            <select wire:model.live="paketFacetten.einsatzmoment" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-paket-facet-einsatzmoment>
                                <option value="">Alle Einsatzmomente</option>
                                @foreach($facetteMomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                            </select>
                            <select wire:model.live="paketFacetten.season" class="{{ $input }} !py-0.5 !text-[11px]" data-fb-paket-facet-season>
                                <option value="">Alle Saisons</option>
                                @foreach($facetteSaisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="flex-1 overflow-y-auto space-y-0.5">
                            @forelse($paketKandidaten as $pk)
                                <x-foodalchemist::katalog-row wire:key="kpk-{{ $pk->id }}" wire:click="paketHinzu({{ $pk->id }})" :title="$pk->consumer_name ?: $pk->name" :price="$pk->price_per_person_cache !== null ? number_format((float) $pk->price_per_person_cache, 2, ',', '.') . ' €' : null">{{ $pk->consumer_name ?: $pk->name }}</x-foodalchemist::katalog-row>
                            @empty
                                <p class="text-[11px] text-gray-500 px-2 py-2">{{ $paketSuche !== '' || $paketFacettenAktiv ? 'Keine Pakete für diese Auswahl.' : 'Noch keine Pakete angelegt.' }}</p>
                            @endforelse
                        </div>
                    @else
                        {{-- F5: Format WIE EIN CONCEPT buchen — wird ein eigenes Kapitel (Editionen als live concept_ref-Blöcke). --}}
                        <input type="search" wire:model.live.debounce.300ms="formatSuche" placeholder="Format suchen …" class="{{ $input }} w-full mb-2 shrink-0" data-fb-katalog-format />
                        @error('formatKapitel')<p class="text-[11px] text-rose-500 px-1 mb-1 shrink-0">{{ $message }}</p>@enderror
                        <p class="text-[10px] text-gray-500 mb-1 shrink-0">Bucht ein Format als eigenes Kapitel (Editionen live).</p>
                        <div class="flex-1 overflow-y-auto space-y-0.5">
                            @forelse($formatKandidaten as $fk)
                                <x-foodalchemist::katalog-row wire:key="kfmt-{{ $fk->id }}" wire:click="formatEinfuegen({{ $fk->id }})" :title="$fk->consumer_name ?: $fk->name">{{ $fk->name }}@if($fk->origin === 'kunde')<span class="text-[9px] text-gray-400 ml-1">(Kunde-IP)</span>@endif</x-foodalchemist::katalog-row>
                            @empty
                                <p class="text-[11px] text-gray-500 px-2 py-2">Keine Formate vorhanden.</p>
                            @endforelse
                        </div>
                    @endif
                </x-foodalchemist::katalog-picker>
            </div>{{-- /2col Speisen --}}
            @else
                <div class="{{ $card }} p-8 text-center text-sm text-gray-500">Links im Kapitelbaum ein Kapitel wählen, um seine Speisen zu bearbeiten.</div>
            @endif{{-- /Kapitel-Editor --}}
                </div>{{-- /Speisen-Tab (Spec 29 / S6) --}}
                </x-foodalchemist::editor-tabs>{{-- /editor-tabs — briefing · planung · speisen · kreativ · trend · branding · preise --}}
            </div>{{-- /fbcockpit --}}
                </div>{{-- /Mitte --}}

                {{-- Rechtes Overview-Panel (Leitstelle-Rail) ENTFERNT (Dominique 2026-08-23): seine drei Views
                     (Fortschritt · Übersicht · Kalkulation) sind jetzt eigene Haupt-Tabs → alles voll-breit,
                     Kapitelnamen voll sichtbar. Die Rail-Komponente bleibt (ungemountet) für evtl. Wiederverwendung. --}}
            </div>{{-- /2-Spalten-Cockpit (nav + mitte; Overview-Panel aufgelöst) --}}
            </x-foodalchemist::modal>{{-- /Editor-Modal (Spec 29) --}}
        @else
            <div class="{{ $card }} p-10 text-center text-sm text-gray-500">
                Links ein Foodbook wählen oder „+ Neues Foodbook". Das Foodbook bündelt fertige <strong>Concepts</strong> zu einem <strong>person-unabhängigen Portfolio</strong> (Kapitel, €/Person) — Pax &amp; Gesamtpreis liegen im <strong>Angebot</strong>, Einzel-Gerichte im Concepter.
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
