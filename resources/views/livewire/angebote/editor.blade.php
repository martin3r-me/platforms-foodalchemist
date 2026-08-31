{{-- Angebote-Editor (Fullscreen-Dark, pro Angebot) — 1:1-Fork des Foodbook-Editors (Doc 15 §9.3 /
     resources/views/livewire/foodbooks/index.blade.php) auf das ANGEBOT. Methoden/Properties bleiben
     wo möglich IDENTISCH zum Foodbook (B1 spiegelt die Namen); Models/Service sind ersetzt
     (FoodAlchemistAngebot + OfferCompositionService/AngebotService), plus Angebot-Spezifika:
     Anfrage-Kopf, Zuschlagskalkulation (B3-Partial), Gerüst, Status-Workflow, → Produktion.
     Der Rahmen ist das bestehende Angebot-Modal (name="angebot-editor", fullscreen, dark-canvas). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($aktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700')
@php($hover = 'text-gray-600 hover:bg-black/[0.03]')

<x-foodalchemist::modal name="angebot-editor" fullscreen dark-canvas title="Angebot bearbeiten"
    :title-name="$angebot->name ?? null">
    <x-slot:actions>
        @if($angebot)
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-angebot-speichern>Speichern</button>
            {{-- Workflow-Übergänge (Status-Maschine) --}}
            @foreach($angebot->status->uebergaenge() as $next)
                <button type="button" wire:click="statusSetzen('{{ $next->value }}')" class="{{ $btnGhostXs }}" data-angebot-status="{{ $next->value }}">→ {{ $next->label() }}</button>
            @endforeach
            <span class="text-gray-300">|</span>
            {{-- Entry in die Leitstelle-Planung (spiegelt Foodbook »In der Leitstelle planen«). --}}
            <button type="button" wire:click="vollKaskadeStarten" class="{{ $btnGhostXs }} text-violet-600" data-angebot-in-leitstelle>In der Leitstelle planen</button>
            <span class="text-gray-300">|</span>
            <a href="{{ route('foodalchemist.angebote.karte', $angebot->id) }}" target="_blank" class="{{ $btnGhostXs }}" title="Schöne Angebots-Karte (Kundenausgabe, Druck/PDF)">@svg('heroicon-o-printer', 'w-3.5 h-3.5 inline align-text-bottom') Druck/Karte</a>
            <a href="{{ route('foodalchemist.angebote.dokument', $angebot->id) }}" target="_blank" class="{{ $btnGhostXs }}" title="Schlichtes Angebots-Dokument (Druck/PDF)">Dokument</a>
            <a href="{{ route('foodalchemist.angebote.praesentation', $angebot->id) }}" target="_blank" class="{{ $btnGhostXs }}" title="Externe Kunden-Präsentation (Web-Seite, ohne Interna)">Präsentation</a>
            {{-- Stufe 3 — Angebot → Produktion (concept × Pax → Produktionsauftrag am Event-Tag). --}}
            <button type="button" wire:click="anProduktion" class="{{ $btnGhostXs }}"
                    title="Angebot in die Produktion übergeben — danach im Tagesplan planbar" data-angebot-produktion>→ Produktion</button>
            <button type="button" wire:click="loeschen" wire:confirm="Angebot löschen?" class="{{ $btnGhostXs }} text-red-600" data-angebot-loeschen>Löschen</button>
        @endif
    </x-slot:actions>

    @if($angebot)
        {{-- KPI-Streifen: Angebot-Leitwerte (€/Person·Pax·Gesamt·WE·Status) + Foodbook-Kennzahlen
             (Kapitel·Speisen·Fertig X/Y). Alle aus render()-Daten, keine neuen Service-Calls. --}}
        <x-slot:kpiHeader>
            @php($k = $kalkulation)
            @php($voll = $k && ! ($k['leer'] ?? true))
            @php($weTone = match($wareneinsatzAmpel ?? 'unbekannt') { 'gruen' => 'good', 'gelb' => 'warn', 'rot' => 'bad', default => null })
            @php($angBoard = collect($kapitelBoard ?? []))
            @php($angKapitelN = count($kapitelTree ?? []))
            @php($angSpeisenN = (int) $angBoard->sum('positionen_count'))
            @php($angFertig = $angBoard->where('fortschritt', 'fertig')->count())
            <x-foodalchemist::kpi-tiles marker="angebot-kpis" :tiles="[
                ['kpi' => 'vkpp', 'label' => '€ / Person', 'tone' => 'accent',
                 'value' => $voll ? number_format((float) $k['vk_pro_person'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'pax', 'label' => 'Pax', 'value' => (string) ($k['pax'] ?? ($angebot->personen ?: '—'))],
                ['kpi' => 'gesamt', 'label' => 'Gesamt VK',
                 'value' => $voll ? number_format((float) $k['gesamt_vk'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'we', 'label' => 'Wareneinsatz', 'tone' => $weTone,
                 'title' => ($voll && $k['wareneinsatz_pct'] !== null) ? 'Ziel des Teams: ' . number_format((float) $zielWareneinsatzPct, 1, ',', '.') . ' %' : null,
                 'value' => ($voll && $k['wareneinsatz_pct'] !== null) ? number_format((float) $k['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—'],
                ['kpi' => 'kapitel', 'label' => 'Kapitel', 'value' => (string) $angKapitelN],
                ['kpi' => 'speisen', 'label' => 'Speisen', 'value' => (string) $angSpeisenN],
                ['kpi' => 'fertig', 'label' => 'Fertig',
                 'tone' => ($angKapitelN > 0 && $angFertig >= $angKapitelN) ? 'good' : 'neutral',
                 'value' => $angFertig . '/' . $angKapitelN],
                ['kpi' => 'status', 'label' => 'Status', 'value' => $angebot->status->label()],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($angebot === null)
        <p class="pt-4 text-[12px] text-gray-500">Kein Angebot geladen.</p>
    @else
    {{-- ═══ 2-Spalten-Cockpit IM Modal — links Navigation (Angebot-Kopf + Kapitelbaum), Mitte Editor-Tabs.
         `-mx-6` hebt das px-6 des Modal-Bodys auf (Spalten randbündig); die Mitte bekommt px-6 zurück. --}}
    <div class="flex gap-4 -mx-6 items-start">
        {{-- LINKS: Navigation (Angebot-Kopf + Kapitelbaum). x-data hält den Kapitel-Drag-Zustand. --}}
        <div class="w-64 shrink-0 pl-6 space-y-1" data-angebot-nav x-data="{ dragKapId: null }">
            <button type="button" wire:click="kopfAnzeigen" @click="$dispatch('angebot-goto', { tab: 'anfrage' })"
                    class="w-full text-left text-xs px-2 py-1 rounded-lg {{ $selectedKapitelId === null ? $aktiv : $hover }}"
                    data-angebot-kopf>@svg('heroicon-o-clipboard-document-list', 'w-3.5 h-3.5 inline-block align-middle') Angebot-Kopf</button>
            <div class="flex items-center gap-1 pt-1">
                <input type="text" wire:model="neuesKapitelTitel" wire:keydown.enter="kapitelNeu" placeholder="Neues Kapitel …" class="{{ $input }} py-0.5" />
                <button type="button" wire:click="kapitelNeu" class="{{ $btnGhostXs }}" title="Top-Kapitel">+</button>
            </div>

            @foreach($kapitelTree ?? [] as $kt)
                <div wire:key="ktm-{{ $kt['id'] }}"
                     @dragover.prevent @drop.prevent="if (dragKapId && dragKapId !== {{ $kt['id'] }}) { $wire.kapitelVerschiebenAuf(dragKapId, {{ $kt['id'] }}); } dragKapId = null"
                     :class="dragKapId === {{ $kt['id'] }} ? 'opacity-40' : (dragKapId ? 'ring-1 ring-violet-300/60 rounded-lg' : '')"
                     class="group flex items-center gap-1" style="padding-left: {{ $kt['depth'] * 12 }}px">
                    <span class="cursor-grab active:cursor-grabbing text-gray-400 hover:text-violet-500 select-none shrink-0 opacity-0 group-hover:opacity-100" draggable="true"
                          @dragstart="dragKapId = {{ $kt['id'] }}; $event.dataTransfer.setData('text/plain', String({{ $kt['id'] }})); $event.dataTransfer.effectAllowed = 'move'"
                          @dragend="dragKapId = null" title="ziehen zum Sortieren" data-kapitel-drag>⠿</span>
                    <button type="button" wire:click="kapitelWaehle({{ $kt['id'] }})" @click="$dispatch('angebot-goto', { tab: 'aufbau' })"
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
            <div wire:key="angcockpit-{{ $angebot->id }}" class="space-y-4">
                <x-foodalchemist::editor-tabs marker="angebot" wire-key="angebot-tabs-{{ $angebot->id }}" :init="'anfrage'"
                    :tabs="[
                        'anfrage' => 'Anfrage',
                        'board' => 'Board',
                        'aufbau' => 'Aufbau',
                        'kalkulation' => 'Kalkulation',
                        'kunde' => 'Kunde & Business-Case',
                        'branding' => 'Branding & Präsentation',
                    ]">

                {{-- headless: Sprung-Bus im editor-tabs-Scope (kein sichtbares Element). $root = editor-tabs-Wurzel
                     (trägt die data-angebot-tab-Buttons + data-angebot-anker-Panels). --}}
                <div @angebot-goto.window="let d=$event.detail; if(d.tab && $root.querySelector(`[data-angebot-tab='${d.tab}']`)) tab=d.tab; $nextTick(()=>{ if(d.anker){ let el=$root.querySelector(`[data-angebot-anker='${d.anker}']`); if(el) el.scrollIntoView({behavior:'smooth',block:'start'}); } });"></div>

                {{-- ═══ Tab: ANFRAGE (bestehende Angebot-Kopf-Felder) ═══ --}}
                <div x-show="tab === 'anfrage'" x-cloak class="pt-4" data-angebot-panel="anfrage">
                    <x-foodalchemist::modal-section title="Anfrage / Briefing">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <div class="md:col-span-2"><label class="{{ $label }}">Name</label><input type="text" wire:model="form.name" class="{{ $input }}" /></div>
                            <div><label class="{{ $label }}">Pax</label><input type="number" min="0" wire:model="form.personen" wire:change="speichern" class="{{ $input }} text-right tabular-nums" title="treibt den Auto-Gesamtpreis" /></div>
                            <div><label class="{{ $label }}">Event-Datum</label><input type="date" wire:model="form.event_date" class="{{ $input }}" /></div>
                            <div class="md:col-span-2"><label class="{{ $label }}">Anlass</label><input type="text" wire:model="form.occasion" class="{{ $input }}" placeholder="Hochzeit, Firmenfeier …" /></div>
                            <div><label class="{{ $label }}">Budget €</label><input type="number" step="0.01" wire:model="form.budget" class="{{ $input }} text-right tabular-nums" /></div>
                            <div><label class="{{ $label }}">Gültig bis</label><input type="date" wire:model="form.valid_until" class="{{ $input }}" /></div>
                            <div class="md:col-span-2"><label class="{{ $label }}">Location</label><input type="text" wire:model="form.location" class="{{ $input }}" /></div>
                            <div class="md:col-span-2"><label class="{{ $label }}">Diät / Allergien</label><input type="text" wire:model="form.diet_requirement" class="{{ $input }}" /></div>
                        </div>
                        <div class="mt-3"><label class="{{ $label }}">Briefing</label><textarea rows="4" wire:model="form.brief" class="{{ $input }}"></textarea></div>
                    </x-foodalchemist::modal-section>
                </div>

                {{-- ═══ Tab: BOARD — Kapitel-Baum (Status + Inhalt + Preis je Kapitel), aufklappbar zu Positionen + Coverage ═══ --}}
                <div x-show="tab === 'board'" x-cloak class="pt-4 space-y-3" data-angebot-panel="board" data-angebot-anker="board">
                    @php($ampelDot = ['gruen' => 'bg-emerald-500', 'gelb' => 'bg-amber-400', 'rot' => 'bg-rose-500', 'unbekannt' => 'bg-gray-300'])
                    @php($ampelText = ['gruen' => 'text-emerald-700', 'gelb' => 'text-amber-700', 'rot' => 'text-rose-700', 'unbekannt' => 'text-gray-400'])
                    @php($befundAmpel = ['erfuellt' => 'text-emerald-600', 'teilerfuellt' => 'text-amber-600', 'verletzt' => 'text-rose-600', 'info' => 'text-sky-600'])
                    @php($fortDot = ['offen' => 'bg-gray-300', 'in_arbeit' => 'bg-amber-400', 'fertig' => 'bg-emerald-500'])
                    @php($fortLabel = ['offen' => 'Offen', 'in_arbeit' => 'In Arbeit', 'fertig' => 'Fertig'])
                    @php($board = $kapitelBoard ?? [])
                    @php($byId = collect($board)->keyBy('kapitel_id'))
                    @php($ahnen = function ($kid) use ($byId) { $ids = []; $cur = data_get($byId->get($kid), 'parent_id'); $g = 0; while ($cur !== null && $g++ < 20) { $ids[] = (int) $cur; $cur = data_get($byId->get($cur), 'parent_id'); } return $ids; })
                    @php($alleIds = collect($board)->pluck('kapitel_id')->map(fn ($i) => (int) $i)->all())
                    <div x-data="{ auf: {} }">
                        <div class="flex items-center justify-end gap-4 pb-1.5 text-[11px]">
                            <button type="button" @click="auf = Object.fromEntries(@js($alleIds).map(i => [i, true]))" class="text-gray-500 hover:text-violet-600 inline-flex items-center gap-1" title="Alle Äste aufklappen"><i class="ti ti-chevrons-down" style="font-size:13px"></i>Alle auf</button>
                            <button type="button" @click="auf = {}" class="text-gray-500 hover:text-violet-600 inline-flex items-center gap-1" title="Auf Oberkapitel zuklappen"><i class="ti ti-chevrons-up" style="font-size:13px"></i>Alle zu</button>
                        </div>
                        <div class="{{ $card }} divide-y divide-black/5 overflow-hidden">
                            @forelse($board as $kap)
                                @php($we = $kap['wareneinsatz'])
                                @php($agg = $kap['aggregat'])
                                @php($ahnenIds = $ahnen($kap['kapitel_id']))
                                <div wire:key="board-{{ $kap['kapitel_id'] }}" x-show="{{ empty($ahnenIds) ? 'true' : '[' . implode(',', $ahnenIds) . '].filter(a => auf[a]).length === ' . count($ahnenIds) }}" x-cloak class="even:bg-black/[0.015]">
                                    <div class="flex items-center gap-1.5 py-1 px-3 cursor-pointer hover:bg-violet-500/[0.04]" @click="auf = {...auf, {{ $kap['kapitel_id'] }}: !auf[{{ $kap['kapitel_id'] }}]}" style="padding-left: {{ 12 + ($kap['depth'] - 1) * 16 }}px">
                                        <i class="ti ti-chevron-right text-gray-400 shrink-0 transition-transform" :class="auf[{{ $kap['kapitel_id'] }}] && 'rotate-90'" style="font-size:15px"></i>
                                        <span class="w-2 h-2 rounded-full shrink-0 {{ $fortDot[$kap['fortschritt']] ?? 'bg-gray-300' }}" title="Fortschritt: {{ $fortLabel[$kap['fortschritt']] ?? 'Offen' }}"></span>
                                        <span class="text-sm font-medium {{ $kap['is_struktur'] ? 'text-gray-500' : 'text-gray-800' }} min-w-0 break-words">{{ $kap['titel'] }}</span>
                                        @if($kap['is_struktur'])<span class="text-[10px] uppercase tracking-wide text-violet-400/80 border border-violet-400/30 rounded px-1.5 shrink-0">Sektion</span>@elseif($kap['pricing_mode'])<span class="text-[10px] uppercase tracking-wide text-gray-400 shrink-0">{{ $kap['pricing_mode'] }}</span>@endif
                                        <div class="ml-auto flex items-center gap-2.5 text-[11px] tabular-nums shrink-0" @click.stop>
                                            @unless($kap['is_struktur'])
                                            <span class="{{ $pill }} {{ $kap['hat_ziele'] ? $variantPill['primary'] : $variantPill['secondary'] }}" title="Ziele/Dimensionen gesetzt">Z</span>
                                            <span class="{{ $pill }} {{ $kap['positionen_count'] > 0 ? $variantPill['info'] : $variantPill['secondary'] }}" title="Positionen">{{ $kap['positionen_count'] }}</span>
                                            <span class="{{ $pill }} {{ $kap['bepreist'] ? $variantPill['success'] : ($kap['hat_inhalt'] ? $variantPill['warning'] : $variantPill['secondary']) }}" title="{{ $kap['bepreist'] ? 'bepreist' : ($kap['hat_inhalt'] ? 'angelegt/ohne Preis' : 'leer') }}">€</span>
                                            @endunless
                                            @php($istRollup = count($kap['positionen']) === 0 && (($agg['vk_pro_person'] ?? 0) > 0 || ($agg['pauschal'] ?? 0) > 0))
                                            @if(($agg['ek_per_person'] ?? 0) > 0)<span class="text-gray-400" title="Wareneinsatz €/Gast">EK {{ number_format((float) $agg['ek_per_person'], 2, ',', '.') }}</span>@endif
                                            @if(($agg['vk_pro_person'] ?? 0) > 0)<span class="font-semibold text-gray-800" title="{{ $istRollup ? 'VK = Summe der Unterkapitel' : 'VK €/Gast' }}">@if($istRollup)<span class="text-gray-400 font-normal" title="Summe der Unterkapitel">Σ&nbsp;</span>@endif{{ number_format((float) $agg['vk_pro_person'], 2, ',', '.') }} €/G</span>@endif
                                            <span class="inline-flex items-center gap-1 {{ $ampelText[$we['status']] ?? 'text-gray-400' }}" title="WE {{ $we['ist_pct'] !== null ? number_format((float) $we['ist_pct'], 1, ',', '.') . ' %' : 'unbekannt' }} · Ziel {{ number_format((float) $we['ziel_pct'], 1, ',', '.') }} %">
                                                <span class="inline-block h-2 w-2 rounded-full {{ $ampelDot[$we['status']] ?? 'bg-gray-300' }}"></span>{{ $we['ist_pct'] !== null ? number_format((float) $we['ist_pct'], 1, ',', '.') . ' %' : '—' }}
                                            </span>
                                            <select wire:change="kapitelFortschritt({{ $kap['kapitel_id'] }}, $event.target.value)" title="Fortschritt setzen"
                                                    class="text-[11px] rounded border border-black/10 bg-transparent py-0.5 pl-1.5 pr-5 text-gray-500 hover:border-violet-300 focus:outline-none cursor-pointer">
                                                <option value="offen" @selected($kap['fortschritt'] === 'offen')>Offen</option>
                                                <option value="in_arbeit" @selected($kap['fortschritt'] === 'in_arbeit')>In Arbeit</option>
                                                <option value="fertig" @selected($kap['fortschritt'] === 'fertig')>Fertig</option>
                                            </select>
                                            <button type="button" wire:click="kapitelWaehle({{ $kap['kapitel_id'] }})" @click="$dispatch('angebot-goto', { tab: 'aufbau' })" class="text-violet-500 hover:text-violet-700" title="Kapitel öffnen &amp; weiterplanen">Planen</button>
                                        </div>
                                    </div>
                                    <div x-show="auf[{{ $kap['kapitel_id'] }}]" x-cloak class="pb-2 pr-3 space-y-0.5" style="padding-left: {{ 34 + ($kap['depth'] - 1) * 16 }}px">
                                        @forelse($kap['positionen'] as $p)
                                            <div class="flex items-center gap-2 py-0.5 text-xs">
                                                <span class="shrink-0 rounded px-1.5 py-0.5 text-[9px] uppercase tracking-wide {{ $p['art'] === 'paket' ? 'bg-violet-500/10 text-violet-700' : 'bg-sky-500/10 text-sky-700' }}">{{ $p['art'] === 'paket' ? 'Paket' : 'Einzel' }}</span>
                                                <span class="truncate text-gray-800">{{ $p['label'] }}</span>
                                                <div class="ml-auto flex items-center gap-3 tabular-nums shrink-0">
                                                    @if(($p['ek'] ?? 0) > 0)<span class="text-gray-400">EK {{ number_format((float) $p['ek'], 2, ',', '.') }}</span>@endif
                                                    @if(($p['vk'] ?? 0) > 0)<span class="font-semibold text-gray-700">{{ number_format((float) $p['vk'], 2, ',', '.') }} {{ ($p['preis_einheit'] ?? 'gast') === 'gast' ? '€/G' : '€/Pos' }}</span>@else<span class="text-amber-600">kein VK</span>@endif
                                                    @if(($p['we_pct'] ?? null) !== null)<span class="text-gray-400" title="Wareneinsatz dieser Position">{{ number_format((float) $p['we_pct'], 1, ',', '.') }} %</span>@endif
                                                </div>
                                            </div>
                                        @empty
                                            @if((($agg['vk_pro_person'] ?? 0) > 0) || (($agg['pauschal'] ?? 0) > 0))
                                                <p class="text-[11px] text-gray-400 py-0.5">Keine eigenen Positionen — der Preis ist die <span class="text-gray-500">Summe der Unterkapitel</span>.</p>
                                            @else
                                                <p class="text-[11px] text-gray-400 py-0.5">Noch keine bepreisten Positionen — im Aufbau-Tab / in der Leitstelle anlegen.</p>
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
                </div>

                {{-- ═══ Tab: AUFBAU — Kapitel-Editor (Konsumententitel · Hinführung/KI · Schreibstil · Bild/Galerie ·
                     Preis-Modus · Textkapitel + Inhalt-Picker Concept/Paket/Format/Gericht + Block-Liste) ═══ --}}
                <div x-show="tab === 'aufbau'" x-cloak class="pt-3 space-y-3" data-angebot-panel="aufbau" data-angebot-anker="aufbau">
                @if($kapitel)
                    <div class="flex gap-4 items-start" data-angebot-aufbau-2col>
                    <div class="flex-1 min-w-0 space-y-3" data-angebot-aufbau-links>
                    {{-- Kapitel-Kopf --}}
                    <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="kaphdr-{{ $kapitel->id }}">
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
                            <div><label class="{{ $label }}">Kapitel (intern)</label><input type="text" wire:model.blur="kapitelForm.title" wire:change="kapitelSpeichern" class="{{ $input }}" /></div>
                            <div class="md:col-span-2"><label class="{{ $label }}">Konsumententitel</label><input type="text" wire:model.blur="kapitelForm.consumer_title" wire:change="kapitelSpeichern" class="{{ $input }}" placeholder="Marketing-Titel (Kundenausgabe)" /></div>
                            <div><label class="{{ $label }}">Preis-Modus</label>
                                <select wire:model.live="kapitelForm.price_mode" wire:change="kapitelSpeichern" class="{{ $input }}"><option value="auto">auto (Σ Inhalt)</option><option value="manuell">manuell</option></select>
                            </div>
                            <div><label class="{{ $label }}">Pax (Kapitel)</label><input type="number" min="0" wire:model.blur="kapitelForm.personen" wire:change="kapitelSpeichern" class="{{ $input }} text-right tabular-nums" placeholder="erbt Angebot ({{ $angebot->personen ?: '—' }})" title="Eigene Gästezahl dieses Kapitels — leer = erbt die Angebots-Pax" /></div>
                        </div>
                        <label class="flex items-start gap-2 text-xs text-gray-500 cursor-pointer">
                            <input type="checkbox" wire:model.live="kapitelForm.is_struktur" wire:change="kapitelSpeichern" class="mt-0.5 accent-violet-500" />
                            <span>Textkapitel / Sektion — <span class="text-gray-400">kein eigenes Food (Intro, Überschrift, Format-Sektion). Food-Kennzahlen kommen nur aus Unterkapiteln.</span></span>
                        </label>

                        {{-- Kapitel-Bild (Präsentation) + Galerie --}}
                        <div data-angebot-kapitel-image>
                            <label class="{{ $label }}">Kapitel-Bild (Präsentation)</label>
                            <div class="flex items-center gap-3 flex-wrap">
                                @if($kapitelImageUrl ?? null)
                                    <img src="{{ $kapitelImageUrl }}" alt="" class="h-12 w-20 object-cover rounded border border-black/10">
                                    <button type="button" wire:click="kapitelImageEntfernen" class="text-rose-600 text-[11px] underline" data-angebot-kapitel-image-remove>entfernen</button>
                                @endif
                                <input type="file" wire:model="kapitelImageUpload" accept="image/*" class="text-[11px]" data-angebot-kapitel-image-upload>
                                <div wire:loading wire:target="kapitelImageUpload" class="text-[11px] text-gray-400">lädt …</div>
                            </div>
                            @if($kapitelImageFehler ?? null)<div class="text-[11px] text-rose-600 mt-1">{{ $kapitelImageFehler }}</div>@endif
                            @error('kapitelImageUpload')<div class="text-[11px] text-rose-600 mt-1">{{ $message }}</div>@enderror
                            <p class="text-[11px] text-gray-400 mt-1">Ohne eigenes Bild nutzt das Kapitel-Band automatisch das Titelbild des Konzepts.</p>

                            <div class="mt-3" data-angebot-kapitel-gallery>
                                <label class="{{ $label }}">Weitere Bilder (optional)</label>
                                <div class="flex items-center gap-3 flex-wrap">
                                    @foreach($kapitelGallery ?? [] as $gi)
                                        <div class="relative">
                                            <img src="{{ $gi['url'] }}" alt="" class="h-12 w-20 object-cover rounded border border-black/10">
                                            <button type="button" wire:click="kapitelGalerieBildEntfernen({{ $gi['id'] }})"
                                                class="absolute -top-1.5 -right-1.5 h-4 w-4 rounded-full bg-rose-600 text-white text-[10px] leading-none flex items-center justify-center"
                                                title="Bild entfernen">×</button>
                                        </div>
                                    @endforeach
                                    <input type="file" wire:model="kapitelGalleryUpload" accept="image/*" multiple class="text-[11px]" data-angebot-kapitel-gallery-upload>
                                    <div wire:loading wire:target="kapitelGalleryUpload" class="text-[11px] text-gray-400">lädt …</div>
                                </div>
                                @error('kapitelGalleryUpload.*')<div class="text-[11px] text-rose-600 mt-1">{{ $message }}</div>@enderror
                                <p class="text-[11px] text-gray-400 mt-1">Mehrere Bilder fürs Kapitel-Band — überschreibt die Concept-Bilder.</p>
                            </div>
                        </div>

                        {{-- Hinführung (Kundentext des Kapitels) + KI-Text --}}
                        <div>
                            <div class="flex items-center justify-between">
                                <label class="{{ $label }}">Hinführung (Kundentext des Kapitels)</label>
                                <button type="button" wire:click="kiKapitelText" wire:loading.attr="disabled" wire:target="kiKapitelText"
                                        title="Hinführung aus Kapitel-Inhalt (Wording-Kette), Angebots-Einleitung und Marken-Stimme" data-angebot-ki-kapiteltext
                                        class="{{ $btnAi }}">
                                    <span wire:loading.remove wire:target="kiKapitelText">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') KI-Text</span>
                                    <span wire:loading wire:target="kiKapitelText">schreibt …</span>
                                </button>
                            </div>
                            <textarea wire:model.blur="kapitelForm.description" wire:change="kapitelSpeichern" rows="2"
                                      class="{{ $input }} resize-none min-h-[3.5rem]"
                                      placeholder="Kurzer Kundentext, der ins Kapitel einführt — „KI-Text" schlägt einen vor"></textarea>
                            {{-- KI-Vorschau (inline, geteilter Zustand kiTextZiel/kiTextVorschau) --}}
                            @if(($kiTextZiel ?? null) === 'kapitel')
                                @if(($kiTextVorschau ?? null) !== null)
                                    @php($kapTextVorhanden = trim((string) ($kapitelForm['description'] ?? '')) !== '')
                                    <div class="mt-2 rounded-xl border border-violet-300/60 bg-violet-500/5 p-3 space-y-2" data-angebot-ki-vorschau>
                                        <p class="{{ $label }} !mb-0">KI-Vorschlag — noch nicht übernommen
                                            @if(($kiTextConfidence ?? null) !== null) · Konfidenz {{ number_format($kiTextConfidence * 100, 0) }} %@endif
                                        </p>
                                        <p class="text-xs text-gray-700 whitespace-pre-line">{{ $kiTextVorschau }}</p>
                                        @if($kapTextVorhanden)
                                            <p class="text-[11px] text-amber-600">Im Feld steht schon ein Text — „Ersetzen" schreibt ihn über (endgültig erst beim Speichern).</p>
                                        @endif
                                        <div class="flex gap-2">
                                            <button type="button" wire:click="kiTextUebernehmen" class="{{ $btnPrimary }}">{{ $kapTextVorhanden ? 'Ersetzen' : 'Übernehmen' }}</button>
                                            <button type="button" wire:click="kiTextVerwerfen" class="{{ $btnGhost }}">Verwerfen</button>
                                        </div>
                                    </div>
                                @endif
                                @if(($kiTextHinweis ?? null) !== null)
                                    <p class="text-[11px] text-amber-600 mt-1" data-angebot-ki-hinweis>{{ $kiTextHinweis }}</p>
                                @endif
                            @endif
                        </div>

                        {{-- Schreibstil PRO KAPITEL + Kapitel-Wording neu betexten --}}
                        <div class="flex items-end gap-2 pt-2 border-t border-black/5" data-angebot-kapitel-stil>
                            <div class="flex-1 max-w-xs">
                                <label class="{{ $label }}">Schreibstil (Kapitel)</label>
                                <select wire:model.live="kapitelForm.writing_style_id" wire:change="kapitelSpeichern" class="{{ $input }}" data-angebot-kapitel-schreibstil>
                                    <option value="">Standard (aus den Concepten)</option>
                                    @foreach($schreibstile ?? [] as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                                </select>
                            </div>
                            <button type="button" wire:click="kapitelWordingGenerieren" wire:loading.attr="disabled" wire:target="kapitelWordingGenerieren"
                                    @disabled(($kapitelForm['writing_style_id'] ?? null) === null || ($kapitelForm['writing_style_id'] ?? '') === '')
                                    title="Betextet alle Konzepte dieses Kapitels im gewählten Schreibstil neu (angebots-lokaler Snapshot; das Concept bleibt unangetastet)"
                                    class="{{ $btnAi }} shrink-0" data-angebot-kapitel-wording>
                                <span wire:loading.remove wire:target="kapitelWordingGenerieren">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5') Kapitel-Wording</span>
                                <span wire:loading wire:target="kapitelWordingGenerieren">betextet …</span>
                            </button>
                        </div>
                        @error('kapitelWording')<p class="text-[11px] text-rose-500 mt-1" data-angebot-kapitel-fehler>{{ $message }}</p>@enderror
                    </div>

                    {{-- Block-Liste (unter dem Kapitel-Kopf) --}}
                    <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" data-angebot-inhalt>
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <h3 class="font-medium tracking-tight text-gray-900">Inhalt <span class="text-gray-500 text-xs">({{ $kapitel->blocks->count() }})</span></h3>
                            <div class="flex items-center gap-2" x-data="{ presets: false }">
                                @if(count($markiert ?? []) >= 2)
                                    <button type="button" wire:click="wahlGruppeBilden" class="{{ $btnGhostXs }} text-amber-600">Wahl-Gruppe ({{ count($markiert) }})</button>
                                @endif
                                <button type="button" wire:click="blockBasis('text')" class="{{ $btnGhostXs }}">+ Text</button>
                                <button type="button" wire:click="blockBasis('spacer')" class="{{ $btnGhostXs }}">+ Leerzeile</button>
                                <div class="relative">
                                    <button type="button" @click="presets = !presets" class="{{ $btnGhost }}">+ Header / Preis</button>
                                    <div x-show="presets" x-cloak @click.outside="presets = false" class="absolute right-0 mt-1 w-56 max-h-80 overflow-y-auto z-20 {{ $card }} p-1 text-xs">
                                        <button type="button" wire:click="blockBasis('header_frei')" @click="presets=false" class="block w-full text-left px-2 py-1 rounded hover:bg-violet-500/10">— Freier Header</button>
                                        <button type="button" wire:click="blockBasis('header_frei_preis')" @click="presets=false" class="block w-full text-left px-2 py-1 rounded hover:bg-violet-500/10">€ Header + Preis</button>
                                        @foreach($headerPresets ?? [] as $gruppe => $items)
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

                        <div class="space-y-1" x-data="{ dragBlockId: null }">
                            @forelse($kapitel->blocks as $block)
                                <div wire:key="block-{{ $block->id }}"
                                     @dragover.prevent @drop.prevent="if (dragBlockId && dragBlockId !== {{ $block->id }}) { $wire.blockVerschiebenAuf(dragBlockId, {{ $block->id }}); } dragBlockId = null"
                                     :class="dragBlockId === {{ $block->id }} ? 'opacity-40' : (dragBlockId ? 'ring-1 ring-violet-300/60' : '')"
                                     class="rounded-lg border {{ $block->variant_group_id ? 'border-amber-400/60' : 'border-black/5' }} px-2 py-1 {{ $block->visible ? '' : 'opacity-60' }}"
                                     style="margin-left: {{ $block->level * 20 }}px">
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="flex items-center shrink-0">
                                            <span class="cursor-grab active:cursor-grabbing text-gray-400 hover:text-violet-500 select-none mr-0.5" draggable="true"
                                                  @dragstart="dragBlockId = {{ $block->id }}; $event.dataTransfer.setData('text/plain', String({{ $block->id }})); $event.dataTransfer.effectAllowed = 'move'"
                                                  @dragend="dragBlockId = null" title="ziehen zum Sortieren" data-block-drag>⠿</span>
                                            <span class="flex flex-col -my-0.5">
                                                <button type="button" wire:click="blockHoch({{ $block->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▲</button>
                                                <button type="button" wire:click="blockRunter({{ $block->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▼</button>
                                            </span>
                                        </span>
                                        @if($block->type === 'concept_ref')
                                            <input type="checkbox" wire:click="markiere({{ $block->id }})" @checked(in_array($block->id, $markiert ?? [])) title="Für Wahl-Gruppe markieren" class="shrink-0" />
                                        @else
                                            <span class="w-3 shrink-0"></span>
                                        @endif
                                        <span class="flex-1 min-w-0 truncate">
                                            @switch($block->type)
                                                @case('concept_ref')
                                                    <span class="{{ $pill }} {{ $variantPill['primary'] }} mr-1">Concept</span>{{ $block->concept?->name ?? '—' }}
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
                                        @if($block->type === 'concept_ref' && $block->concept_id)
                                            <a href="{{ route('foodalchemist.concepter.index', ['edit' => $block->concept_id]) }}" target="_blank" class="shrink-0 text-gray-500 hover:text-violet-500" title="im Concepter öffnen ↗" data-angebot-block-concepter>@svg('heroicon-o-arrow-top-right-on-square', 'w-3.5 h-3.5 inline-block align-middle')</a>
                                        @endif
                                        <button type="button" wire:click="blockSichtbar({{ $block->id }})" class="shrink-0 text-[10px] {{ $block->visible ? 'text-gray-500' : 'text-amber-500' }}" title="sichtbar/intern">@if($block->visible)@svg('heroicon-o-eye', 'w-3.5 h-3.5 inline-block align-middle')@else intern @endif</button>
                                        @if($block->type !== 'spacer')
                                            <button type="button" wire:click="blockBearbeiten({{ $block->id }})" class="shrink-0 text-gray-500 hover:text-violet-500" title="bearbeiten / Notiz">@svg('heroicon-o-pencil', 'w-3.5 h-3.5 inline-block align-middle')</button>
                                        @endif
                                        <button type="button" wire:click="blockRaus({{ $block->id }})" class="shrink-0 text-gray-500 hover:text-red-500" title="entfernen">✕</button>
                                    </div>

                                    {{-- Live-Menü-Vorschau (aufgelöste gerichtZeilen) je concept_ref-Block --}}
                                    @if($block->type === 'concept_ref' && ! empty($blockMenus[$block->id] ?? []))
                                        <div class="mt-1.5 ml-6 rounded-lg bg-violet-500/[0.035] border border-black/5 px-3 py-2 space-y-1" data-angebot-block-vorschau>
                                            @foreach($blockMenus[$block->id] as $g)
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
                                                @elseif($slotKey !== null && ($editSlotKey ?? null) === $slotKey)
                                                    <div class="flex items-center gap-1" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px" data-angebot-slot-editor>
                                                        <input type="text" wire:model="editSlotWording" wire:keydown.enter="slotWordingSpeichern" wire:keydown.escape="slotWordingAbbrechen"
                                                               class="{{ $input }} !py-0.5 !text-[11px] flex-1" placeholder="Anzeigename (Kunde) — leer = Wording-Kette" data-angebot-slot-input />
                                                        <button type="button" wire:click="slotWordingSpeichern" class="{{ $pill }} {{ $variantPill['primary'] }} shrink-0" title="Speichern">OK</button>
                                                        <button type="button" wire:click="slotWordingAbbrechen" class="text-gray-400 hover:text-gray-600 shrink-0 text-xs px-1" title="Abbrechen">×</button>
                                                    </div>
                                                @else
                                                    <div class="group/dish flex items-center gap-1 text-[11px] {{ ($g['source'] ?? null) === 'name' ? 'text-amber-600 italic' : 'text-gray-600' }}" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px">
                                                        <span class="text-gray-300 shrink-0">·</span>
                                                        <span class="break-words">{{ $g['text'] }}</span>
                                                        @if(($g['source'] ?? null) === 'name')<span class="text-[9px] text-amber-500 shrink-0">Wording fehlt</span>@endif
                                                        @if(($g['preis'] ?? null) !== null)<span class="ml-auto text-[10px] text-gray-500 tabular-nums shrink-0">{{ number_format((float) $g['preis'], 2, ',', '.') }} €/P</span>@endif
                                                        @if($istEditierbar)
                                                            <button type="button" wire:click="slotWordingBearbeiten({{ $block->id }}, {{ $g['slot_id'] }}, @js(($g['source'] ?? null) === 'name' ? '' : $g['text']))"
                                                                    class="ml-1 shrink-0 text-gray-300 hover:text-violet-500 opacity-0 group-hover/dish:opacity-100 transition-opacity" title="Anzeigename bearbeiten" data-angebot-slot-edit>@svg('heroicon-o-pencil', 'w-3 h-3 inline-block align-middle')</button>
                                                        @endif
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @endif

                                    @if(($editBlockId ?? null) === $block->id)
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
                                                <input type="text" wire:model="blockForm.wording" class="{{ $input }}" placeholder="Anzeigename (Kunde) — leer = Wording-Kette (Konzept → Standard → Name)" data-angebot-block-wording />
                                            @endif
                                            @if($block->type === 'recipe_ref')
                                                <input type="text" wire:model="blockForm.wording" class="{{ $input }}" placeholder="Anzeigename (Kunde) — leer = Wording-Kette (Standard → Name)" data-angebot-block-wording />
                                                <select wire:model="blockForm.price_basis" class="{{ $input }} w-40" title="Preis-Achse für dieses Gericht"><option value="person">pro Position (×Pax)</option><option value="pauschal">Pauschal</option></select>
                                            @endif
                                            @if($block->type === 'text')
                                                <textarea wire:model="blockForm.customer_text" rows="3" class="{{ $input }}" placeholder="Marketing-Text (kundensichtbar)"></textarea>
                                            @else
                                                <div class="flex gap-1.5 items-start">
                                                    <textarea wire:model="blockForm.customer_text" rows="2" class="{{ $input }}" placeholder="Beschreibungstext / Untertitel (kundensichtbar, optional)"></textarea>
                                                    @if($block->type === 'concept_ref')
                                                        <button type="button" wire:click="kiKundentext" class="{{ $btnAi }} shrink-0 mt-0.5" title="verkäuferischer Beschreibungstext zu diesem Concept" data-angebot-ki-kundentext>@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline-block align-middle')</button>
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
                                <div class="py-4 text-center space-y-1">
                                    <p class="text-xs text-gray-500">Noch kein Inhalt. Rechts im Katalog ein Concept, Paket, Format oder Gericht einfügen — oder Header/Text/Preis-Block hinzufügen.</p>
                                    <p class="text-[11px] text-violet-600/80">Oder KI-befüllen: die <span class="font-medium">Voll-Kaskade</span> (Leitstelle) erzeugt je Kapitel automatisch ein Konzept — es landet direkt hier im Inhalt.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>{{-- /Inhalt --}}
                    </div>{{-- /linke Spalte --}}

                    {{-- Persistenter Katalog: Concept · Paket · Format · Gericht. „+" bucht ins gewählte Kapitel. --}}
                    <x-foodalchemist::katalog-picker marker="angebot" switch="katalogModus" :modes="[
                        ['key' => 'concept', 'label' => 'Concept', 'active' => ($pickerModus ?? 'concept') === 'concept'],
                        ['key' => 'paket', 'label' => 'Paket', 'active' => ($pickerModus ?? 'concept') === 'paket'],
                        ['key' => 'format', 'label' => 'Format', 'active' => ($pickerModus ?? 'concept') === 'format'],
                        ['key' => 'gericht', 'label' => 'Gericht', 'active' => ($pickerModus ?? 'concept') === 'gericht'],
                    ]">
                        @if(($pickerModus ?? 'concept') === 'concept')
                            <input type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen …" class="{{ $input }} w-full mb-2 shrink-0" data-angebot-katalog-concept />
                            @php($facettenAktiv = collect($conceptFacetten ?? [])->filter(fn ($v) => $v !== null)->isNotEmpty())
                            <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-angebot-concept-facetten>
                                <select wire:model.live="conceptFacetten.eventtyp" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-facet-eventtyp>
                                    <option value="">Alle Eventtypen</option>
                                    @foreach($facetteEventtypen ?? [] as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                </select>
                                <select wire:model.live="conceptFacetten.servierform" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-facet-servierform>
                                    <option value="">Alle Servierformen</option>
                                    @foreach($facetteServierformen ?? [] as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                </select>
                                <select wire:model.live="conceptFacetten.einsatzmoment" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-facet-einsatzmoment>
                                    <option value="">Alle Einsatzmomente</option>
                                    @foreach($facetteMomente ?? [] as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                                </select>
                                <select wire:model.live="conceptFacetten.season" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-facet-season>
                                    <option value="">Alle Saisons</option>
                                    @foreach($facetteSaisons ?? [] as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($conceptKandidaten ?? [] as $ck)
                                    <x-foodalchemist::katalog-row wire:key="ack-{{ $ck->id }}" wire:click="conceptHinzu({{ $ck->id }})" :title="$ck->name" :price="$ck->price_per_person_cache !== null ? number_format((float) $ck->price_per_person_cache, 2, ',', '.') . ' €' : null">{{ $ck->name }}</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="text-[11px] text-gray-500 px-2 py-2">{{ ($conceptSuche ?? '') !== '' || $facettenAktiv ? 'Keine Concepts für diese Auswahl.' : 'Noch keine Concepts angelegt.' }}</p>
                                @endforelse
                            </div>
                        @elseif(($pickerModus ?? 'concept') === 'paket')
                            <input type="search" wire:model.live.debounce.300ms="paketSuche" placeholder="Paket suchen …" class="{{ $input }} w-full mb-2 shrink-0" data-angebot-katalog-paket />
                            @php($paketFacettenAktiv = collect($paketFacetten ?? [])->filter(fn ($v) => $v !== null)->isNotEmpty())
                            <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-angebot-paket-facetten>
                                <select wire:model.live="paketFacetten.eventtyp" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-paket-facet-eventtyp>
                                    <option value="">Alle Eventtypen</option>
                                    @foreach($facetteEventtypen ?? [] as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                </select>
                                <select wire:model.live="paketFacetten.servierform" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-paket-facet-servierform>
                                    <option value="">Alle Servierformen</option>
                                    @foreach($facetteServierformen ?? [] as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                </select>
                                <select wire:model.live="paketFacetten.einsatzmoment" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-paket-facet-einsatzmoment>
                                    <option value="">Alle Einsatzmomente</option>
                                    @foreach($facetteMomente ?? [] as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                                </select>
                                <select wire:model.live="paketFacetten.season" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-paket-facet-season>
                                    <option value="">Alle Saisons</option>
                                    @foreach($facetteSaisons ?? [] as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($paketKandidaten ?? [] as $pk)
                                    <x-foodalchemist::katalog-row wire:key="apk-{{ $pk->id }}" wire:click="paketHinzu({{ $pk->id }})" :title="$pk->consumer_name ?: $pk->name" :price="$pk->price_per_person_cache !== null ? number_format((float) $pk->price_per_person_cache, 2, ',', '.') . ' €' : null">{{ $pk->consumer_name ?: $pk->name }}</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="text-[11px] text-gray-500 px-2 py-2">{{ ($paketSuche ?? '') !== '' || $paketFacettenAktiv ? 'Keine Pakete für diese Auswahl.' : 'Noch keine Pakete angelegt.' }}</p>
                                @endforelse
                            </div>
                        @elseif(($pickerModus ?? 'concept') === 'format')
                            <input type="search" wire:model.live.debounce.300ms="formatSuche" placeholder="Format suchen …" class="{{ $input }} w-full mb-2 shrink-0" data-angebot-katalog-format />
                            @error('formatKapitel')<p class="text-[11px] text-rose-500 px-1 mb-1 shrink-0">{{ $message }}</p>@enderror
                            <p class="text-[10px] text-gray-500 mb-1 shrink-0">Bucht ein Format als eigenes Kapitel (Editionen live).</p>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($formatKandidaten ?? [] as $fk)
                                    <x-foodalchemist::katalog-row wire:key="afmt-{{ $fk->id }}" wire:click="formatEinfuegen({{ $fk->id }})" :title="$fk->consumer_name ?: $fk->name">{{ $fk->name }}@if($fk->origin === 'kunde')<span class="text-[9px] text-gray-400 ml-1">(Kunde-IP)</span>@endif</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="text-[11px] text-gray-500 px-2 py-2">Keine Formate vorhanden.</p>
                                @endforelse
                            </div>
                        @else
                            {{-- Gericht (recipe_ref): Suche + Hauptgruppe/Untergruppe --}}
                            <input type="search" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Gericht suchen …" class="{{ $input }} w-full mb-2 shrink-0" data-angebot-katalog-gericht />
                            <div class="grid grid-cols-2 gap-1 mb-2 shrink-0" data-angebot-gericht-facetten>
                                <select wire:model.live="gerichtHauptgruppe" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-gericht-hg>
                                    <option value="">Alle Hauptgruppen</option>
                                    @foreach($gerichtHauptgruppen ?? [] as $hg)<option value="{{ $hg->id }}">{{ $hg->label ?? $hg->name }}</option>@endforeach
                                </select>
                                <select wire:model.live="gerichtDishClass" class="{{ $input }} !py-0.5 !text-[11px]" data-angebot-gericht-klasse @disabled(($gerichtHauptgruppe ?? null) === null)>
                                    <option value="">Alle Untergruppen</option>
                                    @foreach($gerichtUntergruppen ?? [] as $ug)<option value="{{ $ug->id }}">{{ $ug->label }}</option>@endforeach
                                </select>
                            </div>
                            <div class="flex-1 overflow-y-auto space-y-0.5">
                                @forelse($gerichtKandidaten ?? [] as $gk)
                                    <x-foodalchemist::katalog-row wire:key="agk-{{ $gk->id }}" wire:click="gerichtHinzu({{ $gk->id }})" :title="$gk->name" :price="$gk->sales_net !== null ? number_format((float) $gk->sales_net, 2, ',', '.') . ' €' : null">{{ $gk->name }}</x-foodalchemist::katalog-row>
                                @empty
                                    <p class="text-[11px] text-gray-500 px-2 py-2">{{ ($gerichtSuche ?? '') !== '' ? 'Keine Gerichte für diese Auswahl.' : 'Gericht suchen oder Hauptgruppe wählen.' }}</p>
                                @endforelse
                            </div>
                        @endif
                    </x-foodalchemist::katalog-picker>
                    </div>{{-- /2col Aufbau --}}
                @else
                    <div class="{{ $card }} p-8 text-center text-sm text-gray-500">Links im Kapitelbaum ein Kapitel wählen, um seinen Aufbau zu bearbeiten — oder „Neues Kapitel …" anlegen.</div>
                @endif
                </div>{{-- /Aufbau-Tab --}}

                {{-- ═══ Tab: KALKULATION (Zuschlagskalkulation-Partial B3 + Preis-Modus + Mengen) ═══ --}}
                <div x-show="tab === 'kalkulation'" x-cloak class="pt-4 space-y-4" data-angebot-panel="kalkulation">
                    {{-- Per-Kapitel-Aufschlüsselung (Σ Kapitel-Pax × €/P) — Kern der Angebots-Kalkulation. --}}
                    @if($kalkulation && ! ($kalkulation['leer'] ?? true) && count($kalkulation['kapitel'] ?? []))
                    <x-foodalchemist::modal-section title="Aufschlüsselung je Kapitel">
                        <div class="flex items-center gap-2 pb-1 text-[10px] uppercase tracking-wide text-gray-400">
                            <span class="flex-1">Kapitel</span><span class="w-16 text-right">Pax</span><span class="w-24 text-right">€/Person</span><span class="w-28 text-right">Gesamt</span>
                        </div>
                        @foreach($kalkulation['kapitel'] as $kb)
                            <div wire:key="kalkkap-{{ $kb['id'] }}" class="flex items-center gap-2 py-1 text-xs border-t border-black/5">
                                <span class="flex-1 min-w-0 truncate text-gray-800">{{ $kb['titel'] }}</span>
                                <span class="w-16 text-right tabular-nums text-gray-600">{{ $kb['pax'] ?: '—' }}@if($kb['eigene_pax'] ?? false)<span class="text-violet-500" title="eigene Kapitel-Pax">*</span>@endif</span>
                                <span class="w-24 text-right tabular-nums text-gray-600">
                                    @if($kb['ist_format'] && ($kb['format_price_mode'] ?? null) === 'alternativen' && ($kb['preis_range'] ?? null))
                                        {{ $kb['preis_range']['min'] !== null ? number_format((float) $kb['preis_range']['min'], 2, ',', '.') : '—' }}–{{ $kb['preis_range']['max'] !== null ? number_format((float) $kb['preis_range']['max'], 2, ',', '.') : '—' }} €
                                    @elseif($kb['vk_pro_person'] !== null)
                                        {{ number_format((float) $kb['vk_pro_person'], 2, ',', '.') }} €
                                    @else — @endif
                                </span>
                                <span class="w-28 text-right tabular-nums font-medium text-gray-800">{{ number_format((float) ($kb['gesamt'] ?? 0), 2, ',', '.') }} €</span>
                            </div>
                        @endforeach
                        <div class="flex items-center gap-2 pt-1.5 mt-1 border-t-2 border-violet-500/30 text-sm font-semibold">
                            <span class="flex-1 text-gray-900">Gesamt</span>
                            <span class="w-16 text-right tabular-nums text-gray-400 text-[11px]">Ø {{ $kalkulation['pax'] ?: '—' }}</span>
                            <span class="w-24 text-right tabular-nums text-gray-600 text-[11px]">{{ number_format((float) $kalkulation['vk_pro_person'], 2, ',', '.') }} €/P</span>
                            <span class="w-28 text-right tabular-nums text-gray-900">{{ number_format((float) $kalkulation['gesamt_vk'], 2, ',', '.') }} €</span>
                        </div>
                        <p class="text-[10px] text-gray-400 pt-1">* eigene Kapitel-Pax — sonst erbt das Kapitel die Angebots-Pax ({{ $angebot->personen ?: '—' }}). Kopf-„€/Person" = Gesamt ÷ Angebots-Pax.</p>
                    </x-foodalchemist::modal-section>
                    @endif

                    {{-- B3: Vollkosten-/Zuschlagskalkulation über das gesamte Angebot × Pax ($auftragsKalkulation von B1). --}}
                    @includeIf('foodalchemist::livewire.angebote.partials.zuschlagskalkulation')

                    {{-- Preis-Modus (auto/fixiert) + Begründung --}}
                    <x-foodalchemist::modal-section title="Preis-Steuerung">
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="{{ $label }}">Preis-Modus</label>
                                <select wire:model="form.price_mode" class="{{ $input }}"><option value="auto">Auto (Pax × Aufbau)</option><option value="fixed">Fixiert</option></select></div>
                            @if(in_array($form['price_mode'] ?? 'auto', ['fixed', 'manuell'], true))
                                <div><label class="{{ $label }}">Gesamtpreis € (manuell)</label>
                                    <input type="number" step="0.01" wire:model="form.total_price" class="{{ $input }} text-right tabular-nums" /></div>
                                <div class="col-span-2"><label class="{{ $label }}">Begründung</label>
                                    <div class="flex gap-2"><input type="text" wire:model="form.price_override_reason" class="{{ $input }}" placeholder="Warum weicht der Angebotspreis ab?" />
                                    <button type="button" wire:click="speichern" class="{{ $btnGhostXs }} text-violet-600 shrink-0">Fixpreis übernehmen</button></div></div>
                            @else
                                <div class="flex items-end"><button type="button" wire:click="speichern" class="{{ $btnGhostXs }} text-violet-600">Auto-Preis übernehmen</button></div>
                            @endif
                        </div>
                    </x-foodalchemist::modal-section>

                    {{-- Mengen-Hochrechnung für die Pax --}}
                    @if($kalkulation && ! ($kalkulation['leer'] ?? true) && ($kalkulation['pax'] ?? 0) > 0 && count($kalkulation['mengen'] ?? []))
                    <x-foodalchemist::modal-section title="Mengen für {{ $kalkulation['pax'] }} Pax">
                        <div class="space-y-0.5 max-h-64 overflow-y-auto">
                            @foreach($kalkulation['mengen'] as $m)
                                <div wire:key="mng-{{ $loop->index }}" class="flex items-center justify-between gap-2 text-[11px]">
                                    <span class="truncate text-gray-600">{{ $m['gericht'] ?? '—' }}</span>
                                    <span class="tabular-nums text-gray-600 shrink-0">{{ $m['gesamt_menge'] !== null ? rtrim(rtrim(number_format($m['gesamt_menge'],2,',','.'),'0'),',').' '.($m['unit'] ?? '') : '—' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </x-foodalchemist::modal-section>
                    @endif
                </div>

                {{-- ═══ Tab: KUNDE & BUSINESS-CASE ═══ --}}
                <div x-show="tab === 'kunde'" x-cloak class="pt-4 space-y-4" data-angebot-panel="kunde">
                    <x-foodalchemist::modal-section title="Kunde (CRM)">
                        <x-foodalchemist::crm-kunde-picker
                            :ausgabe="$angebot" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
                    </x-foodalchemist::modal-section>

                    <x-foodalchemist::modal-section title="Business-Case (Canvas)">
                        @include('foodalchemist::livewire.canvas.partials.board')
                    </x-foodalchemist::modal-section>
                </div>

                {{-- ═══ Tab: BRANDING & PRÄSENTATION (pro Angebot) ═══ --}}
                <div x-show="tab === 'branding'" x-cloak class="pt-4 space-y-3" data-angebot-panel="branding"
                     x-data="{ brand: @entangle('brandingForm.brand_color'), band: @entangle('brandingForm.band_color'), footer: @entangle('brandingForm.footer_text') }">
                    <div class="relative overflow-hidden {{ $card }} p-5 space-y-4">
                        <div class="{{ $cardAccent }}"></div>

                        @if($brandingFehler ?? null)
                            <div class="rounded-lg bg-rose-500/10 border border-rose-500/30 px-2.5 py-1.5 text-[11px] text-rose-700" data-branding-fehler>{{ $brandingFehler }}</div>
                        @endif
                        @if($brandingGespeichert ?? false)
                            <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/25 px-2.5 py-1.5 text-[11px] text-emerald-700">✓ Gespeichert — fließt ins Dokument-PDF.</div>
                        @endif

                        {{-- Live-Vorschau: Kopf-Band (Bandfarbe + Logo) · Fuß-Linie (Marken-Farbe) --}}
                        <div>
                            <p class="{{ $label }} mb-1">Vorschau</p>
                            <div class="rounded-lg overflow-hidden border border-black/10">
                                <div class="flex items-center justify-between gap-2 px-3 h-9 text-white text-[11px] uppercase tracking-wide" :style="`background:${band || brand}`">
                                    <span class="truncate">{{ $angebot->name }}</span>
                                    @if($angebot->logo_path)<img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($angebot->logo_context_file_id, $angebot->logo_path) }}" alt="Logo" class="max-h-5 max-w-[90px] object-contain shrink-0" />@endif
                                </div>
                                <div class="px-3 py-3 text-[11px] text-gray-600" :style="`border-top:3px solid ${brand}`">
                                    <span x-text="footer || 'Erstellt mit Food Alchemist'"></span>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="{{ $label }}">Marken-Farbe</label>
                                <div class="flex items-center gap-2 mt-1">
                                    <input type="color" x-model="brand" class="h-9 w-12 rounded border border-black/10 bg-transparent cursor-pointer p-0.5" data-brand-color />
                                    <input type="text" x-model="brand" class="{{ $input }} w-32 font-mono" placeholder="#6d28d9" />
                                </div>
                                <p class="text-[10px] text-gray-500 mt-1">Rahmen, Linien, Badges im PDF.</p>
                            </div>
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

                        <div>
                            <label class="{{ $label }}">Footer-Text</label>
                            <input type="text" x-model="footer" class="{{ $input }}" placeholder="Erstellt mit Food Alchemist" />
                        </div>

                        {{-- Logo + Cover --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2 border-t border-black/5">
                            <div>
                                <label class="{{ $label }}">Logo</label>
                                @if($angebot->logo_path)
                                    <div class="flex items-center gap-2 mt-1 mb-1">
                                        <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($angebot->logo_context_file_id, $angebot->logo_path) }}" alt="Logo" class="h-10 max-w-[120px] object-contain rounded border border-black/5 bg-white p-1" />
                                        <button type="button" wire:click="brandingLogoEntfernen" class="{{ $btnGhostXs }} text-red-600" data-logo-entfernen>entfernen</button>
                                    </div>
                                @endif
                                <input type="file" wire:model="logoUpload" accept="image/*" class="block w-full text-[11px] text-gray-600 file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:bg-violet-500/10 file:text-violet-700 file:text-[11px] cursor-pointer" data-logo-upload />
                                <div wire:loading wire:target="logoUpload" class="text-[10px] text-gray-500 mt-0.5">lädt …</div>
                                @error('logoUpload')<span class="text-[10px] text-rose-600">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="{{ $label }}">Cover-Bild</label>
                                @if($angebot->cover_image_path)
                                    <div class="flex items-center gap-2 mt-1 mb-1">
                                        <img src="{{ app(\Platform\FoodAlchemist\Services\FoodAlchemistMediaService::class)->url($angebot->cover_context_file_id, $angebot->cover_image_path) }}" alt="Cover" class="h-10 max-w-[120px] object-cover rounded border border-black/5" />
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
                            <a href="{{ route('foodalchemist.angebote.dokument', $angebot->id) }}?pdf=1" target="_blank" class="{{ $btnGhost }}" title="Branding im PDF gegenprüfen">→ Im Dokument (PDF) ansehen</a>
                        </div>
                    </div>

                    {{-- Präsentation — digitales Kundenbuch (Public-Link + Snapshot + Freigabe) --}}
                    <div class="mt-4 pt-4 border-t border-gray-200 space-y-3" data-angebot-praesentation>
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-sm font-semibold text-gray-900">Präsentation · digitales Angebot</h3>
                            <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'praesentations-designs']) }}" target="_blank" class="text-[11px] text-violet-600 hover:underline">Designs gestalten →</a>
                        </div>

                        @if($presentationHinweis ?? null)<div class="rounded-lg bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 text-xs px-3 py-2" data-angebot-praes-hinweis>{{ $presentationHinweis }}</div>@endif
                        @if($presentationFehler ?? null)<div class="rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-300 text-xs px-3 py-2" data-angebot-praes-fehler>{{ $presentationFehler }}</div>@endif

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block text-xs text-gray-600">Design
                                <select wire:model="presentationDesign" class="mt-1 block w-full text-sm border border-gray-300 rounded px-2 py-1" data-angebot-praes-design>
                                    @foreach($presentationDesignOptionen ?? [] as $opt)
                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block text-xs text-gray-600">Gültig bis <span class="text-rose-500">*</span>
                                <input type="date" wire:model.live="presentationGueltigBis" class="mt-1 block w-full text-sm border border-gray-300 rounded px-2 py-1" data-angebot-praes-gueltig>
                            </label>
                        </div>

                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-xs text-gray-700"><input type="checkbox" wire:model="presentationPreisAnzeige"> Preise pro Person zeigen</label>
                            <label class="flex items-center gap-2 text-xs text-gray-700"><input type="checkbox" wire:model="presentationDeklaration"> Allergen-Legende zeigen</label>
                            <label class="flex items-center gap-2 text-xs text-gray-700" title="Aus: beim erneuten Veröffentlichen bleiben die eingefrorenen Preise stehen — neue Speisen kommen mit aktuellem Preis rein. An: alle aktuellen VK ziehen. Erstveröffentlichung ist immer aktuell."><input type="checkbox" wire:model="presentationPreiseAktualisieren"> Preise aktualisieren</label>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <label class="block text-xs text-gray-600">CTA-Text (optional)
                                <input type="text" wire:model="presentationCtaText" placeholder="z.B. Jetzt anfragen" class="mt-1 block w-full text-sm border border-gray-300 rounded px-2 py-1">
                            </label>
                            <label class="block text-xs text-gray-600">CTA-Link (optional)
                                <input type="url" wire:model="presentationCtaLink" placeholder="https://…" class="mt-1 block w-full text-sm border border-gray-300 rounded px-2 py-1">
                            </label>
                        </div>

                        <div>
                            <label class="block text-xs text-gray-600">Eigener Link-Name (optional)
                                <input type="text" wire:model.live.debounce.400ms="presentationSlug" placeholder="z.B. broich-empfang-2027"
                                    class="mt-1 block w-full text-sm border border-gray-300 rounded px-2 py-1" data-angebot-praes-slug>
                            </label>
                            <p class="mt-1 text-[11px] text-gray-500">
                                Kundenlink:
                                <span class="font-mono break-all">{{ url('/p/angebot') }}/{{ trim((string) ($presentationSlug ?? '')) !== '' ? \Illuminate\Support\Str::slug($presentationSlug) : '⟨automatischer Code⟩' }}</span>
                                — wirkt nach „Veröffentlichen". Leer lassen = zufälliger Code.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <a href="{{ route('foodalchemist.angebote.praesentation', ['id' => $angebot->id, 'design' => $presentationDesign]) }}" target="_blank" class="{{ $btnGhost }}">Vorschau öffnen</a>
                            <button type="button" wire:click="veroeffentlichen"
                                wire:confirm="Diesen Stand als Kundenbuch veröffentlichen? Der Snapshot wird eingefroren."
                                class="{{ $btnPrimary }}" data-angebot-praes-publish @disabled(! ($presentationGueltigBis ?? null))>
                                {{ ($presentationInfo['enabled'] ?? false) ? 'Neu veröffentlichen' : 'Veröffentlichen' }}
                            </button>
                            @if($presentationInfo['enabled'] ?? false)
                                <button type="button" wire:click="zuruckziehen" wire:confirm="Veröffentlichung zurückziehen? Der Link liefert dann 404." class="{{ $btnGhost }}" data-angebot-praes-withdraw>Zurückziehen</button>
                            @endif
                        </div>
                        @unless($presentationGueltigBis ?? null)
                            <p class="text-[11px] text-amber-600">Zum Veröffentlichen ein „gültig bis"-Datum setzen (Pflicht).</p>
                        @endunless

                        @if($presentationLink ?? null)
                            <div class="rounded-lg bg-white/[0.04] border border-white/10 p-3 text-xs" x-data>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 rounded px-2 py-1 font-mono text-[11px] break-all select-all bg-black/30 border border-white/10 text-gray-200" data-angebot-praes-link>{{ $presentationLink }}</div>
                                    <button type="button" class="{{ $btnGhost }}" x-on:click="navigator.clipboard.writeText('{{ $presentationLink }}'); $el.textContent='Kopiert ✓'">Link kopieren</button>
                                </div>
                                <p class="text-[11px] text-gray-500 mt-1">
                                    Freigegeben am {{ $presentationInfo['published_at'] ?? '—' }} · gültig bis {{ $presentationInfo['expires_at'] ?? '—' }} ·
                                    {{ ($presentationInfo['live'] ?? false) ? 'aktiv' : 'inaktiv/abgelaufen' }}
                                </p>
                            </div>
                        @endif

                        {{-- Betriebs-Links — pro Betrieb ein eigener Link --}}
                        <div class="rounded-lg border border-violet-400/25 bg-violet-500/[0.06] p-3 space-y-3">
                            <div class="flex items-center gap-2">
                                <span class="inline-block w-2 h-2 rounded-full bg-violet-400"></span>
                                <h4 class="text-xs font-semibold text-violet-200">Betriebs-Links · eigener Link je Betrieb</h4>
                            </div>
                            <p class="text-[11px] text-gray-400">Ein zusätzlicher Kundenlink pro Betrieb — eingefroren mit den <strong class="text-gray-200">Preisen</strong> und der <strong class="text-gray-200">Vorlage</strong> dieses Betriebs, eigene Freigabe. Der Standard-Link oben bleibt bestehen.</p>

                            @forelse($betriebsLinks ?? [] as $bl)
                                <div class="rounded-lg bg-white/[0.04] border border-white/10 p-2 text-xs" x-data>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-gray-100">{{ $bl['outlet_name'] }}</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded {{ $bl['enabled'] ? 'bg-emerald-500/15 text-emerald-300' : 'bg-white/10 text-gray-400' }}">{{ $bl['enabled'] ? 'aktiv' : 'inaktiv' }}</span>
                                        <span class="ml-auto text-[10px] text-gray-500">Vorlage: {{ $bl['design'] }}</span>
                                    </div>
                                    <div class="flex items-center gap-2 mt-1">
                                        <div class="flex-1 rounded px-2 py-1 font-mono text-[11px] break-all select-all bg-black/30 border border-white/10 text-gray-200">{{ $bl['url'] }}</div>
                                        <button type="button" class="{{ $btnGhost }}" x-on:click="navigator.clipboard.writeText('{{ $bl['url'] }}'); $el.textContent='Kopiert ✓'">Kopieren</button>
                                        @if($bl['enabled'])
                                            <button type="button" wire:click="betriebZuruckziehen({{ $bl['outlet_id'] }})" wire:confirm="Diesen Betriebs-Link zurückziehen? Er liefert dann 404." class="{{ $btnGhost }}">Zurückziehen</button>
                                        @else
                                            <button type="button" wire:click="betriebWiederFreigeben({{ $bl['outlet_id'] }})" class="{{ $btnPrimary }}">Wieder freigeben</button>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="text-[11px] text-gray-500">Noch kein Betriebs-Link angelegt.</p>
                            @endforelse

                            @if(count($betriebsOptionen ?? []) > 0)
                                <div class="rounded-lg border border-dashed border-violet-400/30 bg-white/[0.02] p-2.5 space-y-2">
                                    <p class="text-[11px] font-medium text-violet-200">Weiteren Betrieb hinzufügen</p>
                                    <div class="flex flex-wrap items-end gap-2">
                                        <div>
                                            <label class="block text-[10px] text-gray-400">Betrieb</label>
                                            <select wire:model="outletPublishId" class="mt-1 block text-sm rounded px-2 py-1">
                                                <option value="">— wählen —</option>
                                                @foreach($betriebsOptionen as $o)
                                                    <option value="{{ $o['id'] }}">{{ $o['name'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[10px] text-gray-400">gültig bis (optional)</label>
                                            <input type="date" wire:model="outletPublishGueltigBis" class="mt-1 block text-sm rounded px-2 py-1">
                                        </div>
                                        <div>
                                            <label class="block text-[10px] text-gray-400">Vorlage (optional)</label>
                                            <select wire:model="outletPublishDesign" class="mt-1 block text-sm rounded px-2 py-1">
                                                <option value="">— Betriebs-Vorlage / wie Dokument —</option>
                                                @foreach($presentationDesignOptionen ?? [] as $opt)
                                                    <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-[10px] text-gray-400">Link-Name (optional)</label>
                                            <input type="text" wire:model="outletPublishSlug" placeholder="z.B. broich-nord-2027" class="mt-1 block text-sm rounded px-2 py-1">
                                        </div>
                                        <button type="button" wire:click="betriebVeroeffentlichen" class="{{ $btnPrimary }}">＋ Betrieb hinzufügen</button>
                                    </div>
                                    <p class="text-[10px] text-gray-500">Beliebig viele Betriebe möglich — je Betrieb ein eigener Link. Ohne eigenes Datum gilt das „gültig bis" des Standard-Links.</p>
                                </div>
                            @else
                                <p class="text-[11px] text-amber-300">Noch keine Betriebe angelegt — lege sie unter <em>Einstellungen › Betriebe</em> an.</p>
                            @endif
                        </div>
                    </div>
                </div>{{-- /Branding & Präsentation --}}

                </x-foodalchemist::editor-tabs>
            </div>{{-- /angcockpit --}}
        </div>{{-- /Mitte --}}
    </div>{{-- /2-Spalten-Cockpit --}}
    @endif
</x-foodalchemist::modal>
