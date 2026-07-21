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
                            <span class="text-[10px] text-gray-500">{{ $f->customer ?? 'ohne Kunde' }} · {{ $f->kapitel_count }} Kapitel · <span class="text-violet-500/80">{{ \Platform\FoodAlchemist\Services\PhaseService::LABELS[$f->phase] ?? $f->phase }}</span></span>
                        </button>
                    @empty
                        <p class="px-2 py-3 text-[11px] text-gray-500">Noch keine Foodbooks.</p>
                    @endforelse
                </div>

                {{-- Kapitel-Baum des gewählten Foodbooks --}}
                @if($fb)
                    <div class="pt-2 border-t border-black/5 space-y-0.5">
                        {{-- UX 2026-07-21: Rücksprung auf den übergeordneten Foodbook-Kopf (Stammdaten/Briefing/Canvas/Gerüst) --}}
                        <button type="button" wire:click="kopfAnzeigen"
                                class="w-full text-left text-xs px-2 py-1 rounded-lg {{ $selectedKapitelId === null ? $aktiv : $hover }}"
                                data-fb-kopf>📋 Foodbook-Kopf · Übersicht</button>
                        <div class="flex items-center gap-1">
                            <input type="text" wire:model="neuesKapitelTitel" wire:keydown.enter="kapitelNeu" placeholder="Neues Kapitel …" class="{{ $input }} py-0.5" />
                            <button type="button" wire:click="kapitelNeu" class="{{ $btnGhostXs }}" title="Top-Kapitel">+</button>
                        </div>
                        @foreach($kapitelTree as $kt)
                            <div wire:key="kt-{{ $kt['id'] }}" class="group flex items-center gap-1" style="padding-left: {{ $kt['depth'] * 12 }}px">
                                <button type="button" wire:click="kapitelWaehle({{ $kt['id'] }})"
                                        class="flex-1 min-w-0 text-left truncate text-xs px-2 py-0.5 rounded-lg {{ $selectedKapitelId === $kt['id'] ? $aktiv : $hover }}">{{ $kt['title'] }}</button>
                                <button type="button" wire:click="kapitelHoch({{ $kt['id'] }})" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-500 hover:text-violet-500 text-[10px]" title="hoch">▲</button>
                                <button type="button" wire:click="kapitelRunter({{ $kt['id'] }})" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-500 hover:text-violet-500 text-[10px]" title="runter">▼</button>
                                <button type="button" wire:click="kapitelNeu({{ $kt['id'] }})" class="shrink-0 text-violet-400 hover:text-violet-600 text-xs px-1 leading-none" title="Unterkapitel anlegen">＋</button>
                                <button type="button" wire:click="kapitelLoeschen({{ $kt['id'] }})" wire:confirm="Kapitel löschen?" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-500 hover:text-red-500 text-[11px]" title="löschen">✕</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-ui-page-sidebar>
    </x-slot>

    <x-slot name="activity">
        <x-ui-page-sidebar title="Portfolio (pro Person)" width="w-80" :maxWidth="520" storeKey="activityOpen" side="right">
            @if($fb && $gesamt)
                <div class="p-4 space-y-3">
                    <div class="text-center py-2">
                        <div class="text-2xl font-semibold text-gray-900 tabular-nums">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</div>
                        <div class="{{ $label }}">pro Person · EK {{ number_format($gesamt['ek_per_person'], 2, ',', '.') }} €</div>
                        <div class="text-[11px] text-gray-500 mt-1">Portfolio — person-unabhängig. Pax + Gesamtpreis liegen im Angebot.</div>
                    </div>
                    @if($kapitel && $kapitelAgg)
                        <div class="pt-2 border-t border-black/5 text-xs space-y-1">
                            <div class="{{ $label }}">Kapitel „{{ $kapitel->title }}"</div>
                            <div class="flex justify-between"><span class="text-gray-600">€/Person</span><span class="tabular-nums">{{ number_format($kapitelAgg['vk_pro_person'], 2, ',', '.') }} €</span></div>
                            <div class="flex justify-between"><span class="text-gray-600">EK/Person</span><span class="tabular-nums">{{ number_format($kapitelAgg['ek_per_person'], 2, ',', '.') }} €</span></div>
                            @if($kapitelAgg['food_cost_percent'] !== null)<div class="flex justify-between"><span class="text-gray-600">Wareneinsatz</span><span class="tabular-nums">{{ number_format($kapitelAgg['food_cost_percent'], 1, ',', '.') }} %</span></div>@endif
                        </div>
                    @endif
                </div>
            @else
                <div class="p-6 text-center text-sm text-gray-500">Foodbook auswählen.</div>
            @endif
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if($fb)
            @if($selectedKapitelId === null)
            {{-- ═══════════════ FOODBOOK-KOPF (übergeordnete Ebene) ═══════════════ --}}
            {{-- Foodbook-Stammdaten --}}
            <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="fbhdr-{{ $fb->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="md:col-span-2"><label class="{{ $label }}">Bezeichnung</label><input type="text" wire:model="form.label" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Kunde</label><input type="text" wire:model="form.customer" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">@foreach(['draft' => 'Entwurf', 'active' => 'Aktiv', 'versendet' => 'Versendet', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                    </div>
                </div>

                {{-- R4.3: Phasen-Statusmaschine (ergänzt den Sichtbarkeits-Status, ersetzt ihn nicht) --}}
                @include('foodalchemist::livewire.planning.partials.phase-stepper', ['phaseAktuell' => $fb->phase ?? 'kontext'])
                {{-- #369: CRM-Kunde-Link (MVP, nur verlinken) — ergänzt das Freitext-Feld „Kunde" --}}
                <div class="space-y-2 pt-1 border-t border-black/5">
                    <span class="{{ $label }}">Kunde (CRM)</span>
                    @if(! $crmVerfuegbar)
                        <p class="text-[11px] text-gray-500">CRM-Modul nicht verfügbar — Freitext-Feld „Kunde" oben nutzen.</p>
                    @else
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
                            <div>Firma: <span class="font-medium text-gray-900">{{ $fb->crmCompany?->display_name ?? '—' }}</span></div>
                            <div>Kontakt: <span class="font-medium text-gray-900">{{ $fb->crmContact?->display_name ?? '—' }}</span></div>
                            @if($fb->crm_company_id || $fb->crm_contact_id)
                                <button type="button" wire:click="loeseKunde" class="{{ $btnGhostXs }}">Verknüpfung lösen</button>
                            @endif
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                            <div>
                                <input type="search" wire:model.live.debounce.300ms="firmaSuche" placeholder="Firma suchen …" class="{{ $input }}" />
                                @if($firmen->isNotEmpty())
                                    <div class="space-y-0.5 mt-1">
                                        @foreach($firmen as $f)
                                            <button type="button" wire:key="fbfi-{{ $f->id }}" wire:click="verknuepfeFirma({{ $f->id }})" class="w-full text-left px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">{{ $f->display_name }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <div>
                                <input type="search" wire:model.live.debounce.300ms="kontaktSuche" placeholder="Kontakt suchen …" class="{{ $input }}" />
                                @if($kontakte->isNotEmpty())
                                    <div class="space-y-0.5 mt-1">
                                        @foreach($kontakte as $k)
                                            <button type="button" wire:key="fbko-{{ $k->id }}" wire:click="verknuepfeKontakt({{ $k->id }})" class="w-full text-left px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">{{ $k->display_name }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <label class="{{ $label }}">Briefing / Einleitung (Kundentext)</label>
                        <button type="button" disabled title="KI-Befüllung folgt — speist sich aus Kunde, Briefing und den enthaltenen Concepts (M11-08, LLM offen)" class="{{ $btnGhostXs }} opacity-50 cursor-not-allowed">✨ KI-Text (folgt)</button>
                    </div>
                    <textarea wire:model="form.description" rows="3" class="{{ $input }}" placeholder="Briefing / Einleitungstext fürs Angebot — später KI-befüllbar aus Kunde + Concepts"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                    {{-- #501 (2026-07-13): standalone interne Ansicht entfernt — die Kunden-Vorschau
                         lebt im Editor als „🍽 Menü"-Toggle (rechts), das versendbare Dokument im „Dokument"-Link. --}}
                    {{-- Ein Einstieg genügt: interne Sicht (EK/VK/W%) ⇄ Kundensicht wird IM Dokument umgeschaltet (2026-07-14). --}}
                    <a href="{{ route('foodalchemist.foodbooks.dokument', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Dokument (Druck/PDF) — im Dokument zwischen Kunden- und interner Sicht (Marge) umschaltbar">Dokument</a>
                    <a href="{{ route('foodalchemist.foodbooks.praesentation', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Externe Kunden-Präsentation (Web-Seite, Preise pro Person, ohne Interna)">Präsentation</a>
                    <button type="button" wire:click="loeschen({{ $fb->id }})" wire:confirm="Foodbook löschen?" class="{{ $btnGhost }} text-red-600">Löschen</button>
                </div>
            </div>

            {{-- #389/Canvas: Foodbook-Leitidee — auf Klick im Modal (Dominique 2026-06-17) --}}
            <button type="button" @click="$dispatch('modal.open', { name: 'fb-leitidee' })"
                    class="{{ $btnGhost }} w-full justify-center" wire:key="fbcanvas-btn-{{ $fb->id }}">
                Leitidee-Canvas — was muss rein · welche Konzepte · was es erfüllen muss
            </button>
            <x-foodalchemist::modal name="fb-leitidee" title="Foodbook-Leitidee (Canvas)" size="max-w-3xl">
                @include('foodalchemist::livewire.canvas.partials.board')
                <x-slot:footer>
                    <button type="button" @click="$dispatch('modal.close', { name: 'fb-leitidee' })" class="{{ $btnGhost }}">Schließen</button>
                </x-slot:footer>
            </x-foodalchemist::modal>

            {{-- R4.1: Planungs-Gerüst — messbarer Soll-Rahmen (Mengen · Preise · Quoten · Dramaturgie) neben der Freitext-Leitidee --}}
            <button type="button" @click="$dispatch('modal.open', { name: 'fb-geruest' })"
                    class="{{ $btnGhost }} w-full justify-center" wire:key="fbframe-btn-{{ $fb->id }}" data-fb-geruest-btn>
                Planungs-Gerüst — Soll-Mengen · Preisrahmen · Diät-Quoten · Dramaturgie
            </button>
            <x-foodalchemist::modal name="fb-geruest" title="Planungs-Gerüst (Soll-Rahmen)" size="max-w-4xl">
                @include('foodalchemist::livewire.planning.partials.frame-board')
                <x-slot:footer>
                    <button type="button" @click="$dispatch('modal.close', { name: 'fb-geruest' })" class="{{ $btnGhost }}">Schließen</button>
                </x-slot:footer>
            </x-foodalchemist::modal>

            {{-- R4.2: Soll/Ist-Coverage live beim Befüllen — Lücken-Klick öffnet den VK-Browser gefiltert --}}
            @if(($coverage ?? null) !== null && $coverage['hat_geruest'])
                @include('foodalchemist::livewire.planning.partials.coverage-panel', ['coverageFillRoute' => route('foodalchemist.verkauf.index')])

                {{-- R6.1: Gerüst-Pfad des Konzept-Generators — baut ein Draft-Konzept aus echten VK-Gerichten --}}
                <div class="space-y-1.5">
                    <button type="button" wire:click="konzeptAusGeruest" class="{{ $btnGhost }} w-full justify-center" wire:loading.attr="disabled" data-konzept-aus-geruest>
                        <span wire:loading.remove wire:target="konzeptAusGeruest">✨ Konzept aus diesem Gerüst generieren</span>
                        <span wire:loading wire:target="konzeptAusGeruest">Wähle Gerichte …</span>
                    </button>
                    @if($generatorFehler)
                        <div class="rounded-lg bg-rose-500/10 border border-rose-500/30 px-2.5 py-1.5 text-[11px] text-rose-700">{{ $generatorFehler }}</div>
                    @endif
                    @if($generatorErgebnis)
                        <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/25 px-2.5 py-2 space-y-1 text-[11px]" data-generator-ergebnis>
                            <div class="font-medium text-gray-800">„{{ $generatorErgebnis['concept_name'] }}“ (Draft) · Kohäsion {{ $generatorErgebnis['kohaesion_score'] ?? '—' }} · Coverage {{ $generatorErgebnis['coverage_gesamt'] ?? '—' }}</div>
                            @foreach($generatorErgebnis['protokoll'] as $p)
                                <div class="{{ $p['status'] === 'leer' ? 'text-amber-600' : 'text-gray-600' }}">{{ $p['slot'] }}: {{ $p['status'] === 'leer' ? 'LEER — ' . $p['begruendung'] : collect($p['gerichte'])->pluck('name')->implode(', ') }}</div>
                            @endforeach
                            <a href="{{ route('foodalchemist.concepts.index') }}?c={{ $generatorErgebnis['concept_id'] }}" class="{{ $btnGhostXs }} text-violet-600">→ im Concepter öffnen</a>
                        </div>
                    @endif
                </div>
            @endif

            {{-- UX 2026-07-21: Menü-Vorschau (Kundensicht, ganzes Foodbook) — einklappbar, gehört zur Foodbook-Kopf-Ebene.
                 Früher Teil eines Bearbeiten⇄Menü-Toggles; das Bearbeiten (Kapitel+Blöcke) lebt jetzt in der Kapitel-Ansicht. --}}
            <div x-data="{ fbMenue: false }" class="space-y-2">
            <button type="button" @click="fbMenue = !fbMenue" class="{{ $btnGhost }} w-full justify-center" data-fb-menue-toggle>
                <span x-text="fbMenue ? '▲ Menü-Vorschau ausblenden' : '🍽 Menü-Vorschau (Kundensicht) — ganzes Foodbook'"></span>
            </button>

            {{-- ═══ MENÜ-VORSCHAU (Kundensicht, read-only) ═══ --}}
            <div x-show="fbMenue" x-cloak class="relative overflow-hidden {{ $card }} p-6 space-y-5" data-fb-menue-vorschau>
                <div class="{{ $cardAccent }}"></div>
                <div class="flex items-baseline justify-between border-b border-black/5 pb-3">
                    <div>
                        <h2 class="text-lg font-semibold tracking-tight text-gray-900">{{ $fb->label }}</h2>
                        @if($menue['customer'] ?? null)<p class="text-xs text-gray-500">{{ $menue['customer'] }}@if(($menue['kontakt'] ?? null) && $menue['kontakt'] !== $menue['customer']) · {{ $menue['kontakt'] }}@endif</p>@endif
                    </div>
                    @if(($menue['gesamt']['vk_pro_person'] ?? 0) > 0)<span class="text-sm font-semibold text-emerald-600 tabular-nums">{{ number_format((float) $menue['gesamt']['vk_pro_person'], 2, ',', '.') }} €/P</span>@endif
                </div>
                @forelse($menue['kapitel'] ?? [] as $k)
                    <section style="margin-left: {{ $k['depth'] * 16 }}px">
                        <div class="flex items-baseline gap-2 border-b border-black/5 pb-1 mb-2">
                            <h3 class="text-sm font-semibold text-violet-700">{{ $k['title'] }}</h3>
                            @if($k['vk_pro_person'] > 0)<span class="ml-auto text-[11px] text-gray-500 tabular-nums">{{ number_format((float) $k['vk_pro_person'], 2, ',', '.') }} €/P</span>@endif
                        </div>
                        @forelse($k['bloecke'] as $b)
                            <div class="py-0.5">
                                <p class="text-sm {{ $b['ist_header'] ? 'font-semibold text-gray-700 mt-2' : 'text-gray-900' }}">{{ $b['label'] }}</p>
                                @if($b['untertitel'] ?? null)<p class="text-[11px] text-gray-500 italic">{{ $b['untertitel'] }}</p>@endif
                                @foreach($b['gerichte'] ?? [] as $g)
                                    @if($g['type'] === 'paket' || $g['type'] === 'header')
                                        <p class="text-xs font-semibold text-gray-600 ml-3 mt-1">{{ $g['text'] }}</p>
                                    @else
                                        @php($gfb = ($g['recipe_id'] ?? null) ? ($feedbackAgg[$g['recipe_id']] ?? null) : null)
                                        <p class="text-xs text-gray-600 {{ $g['source'] === 'name' ? 'italic text-amber-600' : '' }}" style="margin-left:{{ 12 + $g['einrueckung'] * 12 }}px">{{ $g['text'] }}@if($g['source'] === 'name')<span class="ml-1 text-[10px]">· Wording fehlt</span>@endif@if($gfb && $gfb['count'] > 0)<span class="ml-1.5 text-[10px] {{ ($gfb['avg'] ?? 0) >= 4 ? 'text-emerald-600' : (($gfb['avg'] ?? 0) >= 3 ? 'text-amber-600' : 'text-red-500') }}" title="{{ $gfb['count'] }} Feedback-Einträge">★ {{ $gfb['avg'] !== null ? number_format((float) $gfb['avg'], 1, ',', '.') : '–' }}</span>@endif</p>
                                    @endif
                                @endforeach
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">—</p>
                        @endforelse
                    </section>
                @empty
                    <p class="text-xs text-gray-500 py-6 text-center">Noch keine Kapitel — links anlegen und Concepts einfügen.</p>
                @endforelse
                <p class="text-[11px] text-gray-500 pt-2 border-t border-black/5">Gericht-Namen aus der Wording-Kette: Foodbook-Override → Konzept-Wording → VK-Standard → interner Name. Amber = kein Wording gepflegt.</p>
            </div>

            </div>{{-- /x-data fbMenue (Menü-Vorschau, Foodbook-Kopf) --}}

            @else
            {{-- ═══════════════ KAPITEL (den „einzelnen Strukturen" — nur die Speisen) ═══════════════ --}}
            @if($kapitel)
                {{-- Kapitel-Kopf --}}
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="kaphdr-{{ $kapitel->id }}">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div><label class="{{ $label }}">Kapitel (intern)</label><input type="text" wire:model.blur="kapitelForm.title" wire:change="kapitelSpeichern" class="{{ $input }}" /></div>
                        <div class="md:col-span-2"><label class="{{ $label }}">Konsumententitel</label><input type="text" wire:model.blur="kapitelForm.consumer_title" wire:change="kapitelSpeichern" class="{{ $input }}" placeholder="Marketing-Titel (PDF)" /></div>
                        <div><label class="{{ $label }}">Preis-Modus</label>
                            <select wire:model.live="kapitelForm.price_mode" wire:change="kapitelSpeichern" class="{{ $input }}"><option value="auto">auto (Σ Inhalt)</option><option value="manuell">manuell</option></select>
                        </div>
                    </div>
                </div>

                {{-- Block-Liste --}}
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="font-medium tracking-tight text-gray-900">Inhalt <span class="text-gray-500 text-xs">({{ $kapitel->blocks->count() }})</span></h3>
                        <div class="flex items-center gap-2" x-data="{ presets: false }">
                            @if(count($markiert) >= 2)
                                <button type="button" wire:click="wahlGruppeBilden" class="{{ $btnGhostXs }} text-amber-600">Wahl-Gruppe ({{ count($markiert) }})</button>
                            @endif
                            <button type="button" @click="$dispatch('modal.open', { name: 'fb-concept' })" class="{{ $btnPrimary }}">+ Concept einfügen</button>
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


                    <div class="space-y-1">
                        @forelse($kapitel->blocks as $block)
                            <div wire:key="block-{{ $block->id }}"
                                 class="rounded-lg border {{ $block->variant_group_id ? 'border-amber-400/60' : 'border-black/5' }} px-2 py-1 {{ $block->visible ? '' : 'opacity-60' }}"
                                 style="margin-left: {{ $block->level * 20 }}px">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="flex flex-col -my-0.5 shrink-0">
                                        <button type="button" wire:click="blockHoch({{ $block->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▲</button>
                                        <button type="button" wire:click="blockRunter({{ $block->id }})" class="text-gray-500 hover:text-violet-500 leading-none">▼</button>
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
                                                <span class="text-gray-500 tabular-nums">{{ $block->concept?->price_per_person_cache !== null ? '· ' . number_format((float) $block->concept->price_per_person_cache, 2, ',', '.') . ' €/P' : '' }}</span>
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
                                            @case('image') <span class="text-gray-600">🖼 Bild</span> @break
                                            @default <span class="italic">{{ \Illuminate\Support\Str::limit($block->customer_text ?? '(Text)', 80) }}</span>
                                        @endswitch
                                    </span>
                                    @if($block->variant_group_id)<button type="button" wire:click="wahlGruppeAufheben({{ $block->id }})" class="{{ $pill }} {{ $variantPill['warning'] }} shrink-0" title="aus Wahl-Gruppe">Wahl #{{ $block->variant_group_id }}</button>@endif
                                    <button type="button" wire:click="blockEbene({{ $block->id }}, -1)" class="text-gray-500 hover:text-violet-500 shrink-0" title="ausrücken">←</button>
                                    <button type="button" wire:click="blockEbene({{ $block->id }}, 1)" class="text-gray-500 hover:text-violet-500 shrink-0" title="einrücken">→</button>
                                    <button type="button" wire:click="blockSichtbar({{ $block->id }})" class="shrink-0 text-[10px] {{ $block->visible ? 'text-gray-500' : 'text-amber-500' }}" title="sichtbar/intern">{{ $block->visible ? '👁' : 'intern' }}</button>
                                    @if($block->type !== 'spacer')
                                        <button type="button" wire:click="blockBearbeiten({{ $block->id }})" class="shrink-0 text-gray-500 hover:text-violet-500" title="bearbeiten / Notiz">✎</button>
                                    @endif
                                    <button type="button" wire:click="blockRaus({{ $block->id }})" class="shrink-0 text-gray-500 hover:text-red-500" title="entfernen">✕</button>
                                </div>

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
                                        @if($block->type === 'text')
                                            <textarea wire:model="blockForm.customer_text" rows="3" class="{{ $input }}" placeholder="Marketing-Text (kundensichtbar)"></textarea>
                                        @else
                                            <div class="flex gap-1.5 items-start">
                                                <textarea wire:model="blockForm.customer_text" rows="2" class="{{ $input }}" placeholder="Beschreibungstext / Untertitel (kundensichtbar, optional)"></textarea>
                                                @if($block->type === 'concept_ref')
                                                    <button type="button" wire:click="kiKundentext" class="{{ $btnGhostXs }} text-violet-600 shrink-0 mt-0.5" title="vk.marketing: verkäuferischer Beschreibungstext zu diesem Concept" data-fb-ki-kundentext>✨</button>
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
                            <p class="text-xs text-gray-500 py-4 text-center">Noch kein Inhalt. Oben „+ Concept einfügen" oder Header/Text/Preis-Block hinzufügen.</p>
                        @endforelse
                    </div>

                    {{-- FB: Concept-Einfüge-Picker (Modal, Livewire-sicher). Angebot bleibt unberührt (hat eigenen Concepter-Editor).
                         Concepter-Such-Wissen: Suche + collapsible Kategorie-Tree + Concept-Liste; bleibt offen für Mehrfach-Einfügen.
                         Modal statt x-teleport-Drawer: Teleport entkoppelt das DOM vom Livewire-Morph → wire:model/click toter. --}}
                    <x-foodalchemist::modal name="fb-concept" title="Concept einfügen" size="max-w-3xl">
                        <input type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen …" class="{{ $input }} w-full mb-3" />
                        <div class="flex gap-3 min-h-[20rem]">
                            {{-- Kategorie-Tree (collapsible, wie Concepter-Browser) --}}
                            <div class="w-44 shrink-0 overflow-y-auto border-r border-black/5 pr-2 space-y-0.5 max-h-[26rem]">
                                <button type="button" wire:click="$set('conceptKategorie', null)"
                                        class="w-full text-left text-xs px-2 py-1 rounded-lg {{ $conceptKategorie === null ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700' : 'text-gray-600 hover:bg-black/[0.03]' }}">Alle Kategorien</button>
                                <x-foodalchemist::tree :initial-collapsed="collect($conceptKategorien)->where('has_children', true)->pluck('id')->all()">
                                    @foreach($conceptKategorien as $kat)
                                        <x-foodalchemist::tree-node :node-id="$kat['id']" :depth="$kat['depth']" :ancestors="$kat['ancestors'] ?? []"
                                            :has-children="$kat['has_children'] ?? false" :active="$conceptKategorie === $kat['id']">
                                            <button type="button" wire:click="$set('conceptKategorie', {{ $kat['id'] }})" class="flex-1 min-w-0 truncate text-left text-xs px-1 py-0.5">{{ $kat['name'] }}</button>
                                        </x-foodalchemist::tree-node>
                                    @endforeach
                                </x-foodalchemist::tree>
                            </div>
                            {{-- Concept-Liste --}}
                            <div class="flex-1 min-w-0 overflow-y-auto space-y-0.5 max-h-[26rem]">
                                @if($conceptKandidaten->isNotEmpty())
                                    @foreach($conceptKandidaten as $ck)
                                        <button type="button" wire:key="dck-{{ $ck->id }}" wire:click="conceptHinzu({{ $ck->id }})"
                                                class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                            <span class="truncate text-gray-900">+ {{ $ck->name }}</span>
                                            <span class="text-gray-500 tabular-nums shrink-0">{{ $ck->price_per_person_cache !== null ? number_format((float) $ck->price_per_person_cache, 2, ',', '.') . ' €' : '' }}</span>
                                        </button>
                                    @endforeach
                                @elseif($conceptSuche !== '' || $conceptKategorie !== null)
                                    <p class="text-[11px] text-gray-500 px-2 py-2">Keine Concepts für diese Auswahl.</p>
                                @else
                                    <p class="text-[11px] text-gray-500 px-2 py-2">Kategorie wählen oder oben suchen.</p>
                                @endif
                            </div>
                        </div>
                        <x-slot:footer>
                            <span class="text-[10px] text-gray-500 mr-auto">Eingefügte Concepts erscheinen links im Inhalt. Bleibt offen für mehrere.</span>
                            <button type="button" @click="$dispatch('modal.close', { name: 'fb-concept' })" class="{{ $btnGhost }}">Schließen</button>
                        </x-slot:footer>
                    </x-foodalchemist::modal>
                </div>
            @else
                <div class="{{ $card }} p-8 text-center text-sm text-gray-500">Links ein Kapitel wählen oder anlegen.</div>
            @endif
            @endif{{-- /Foodbook-Kopf vs. Kapitel-Ansicht --}}
        @else
            <div class="{{ $card }} p-10 text-center text-sm text-gray-500">
                Links ein Foodbook wählen oder „+ Neues Foodbook". Das Foodbook bündelt fertige <strong>Concepts</strong> zu einem <strong>person-unabhängigen Portfolio</strong> (Kapitel, €/Person) — Pax &amp; Gesamtpreis liegen im <strong>Angebot</strong>, Einzel-Gerichte im Concepter.
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
