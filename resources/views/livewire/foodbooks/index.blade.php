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
            {{-- ═══════════════ FOODBOOK-KOPF — Planungs-Cockpit (Tabs) ═══════════════ --}}
            {{-- Tab-Zustand hält Alpine über Livewire-Morphs hinweg (stabiler wire:key), Muster wie Concepter-Editor.
                 Kalkulations-Leiste bleibt die rechte activity-Sidebar. Phase 1: reiner Reuse, Modals raus. --}}
            <div wire:key="fbcockpit-{{ $fb->id }}" x-data="{ tab: 'briefing' }" class="space-y-4">

                {{-- Tab-Leiste + Foodbook-Aktionen — Speichern/Dokument/Präsentation/Löschen gelten fürs GANZE
                     Foodbook, daher auf Tab-Ebene (aus allen Tabs erreichbar), nicht im Briefing-Tab vergraben. --}}
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap gap-1" role="tablist">
                        @foreach(['briefing' => '📋 Briefing', 'planung' => '🎯 Planung', 'kreativ' => '🎨 Kreativ', 'trend' => '📈 Trend', 'branding' => '🏷 Branding/CI', 'vorschau' => '🍽 Vorschau'] as $tk => $tl)
                            <button type="button" @click="tab = @js($tk)" :class="tab === @js($tk) ? '{{ $aktiv }}' : '{{ $hover }}'"
                                    class="px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-fb-tab="{{ $tk }}">{{ $tl }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2" data-fb-aktionen>
                        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                        {{-- Ein Einstieg genügt: interne Sicht (EK/VK/W%) ⇄ Kundensicht wird IM Dokument umgeschaltet (2026-07-14). --}}
                        <a href="{{ route('foodalchemist.foodbooks.dokument', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Dokument (Druck/PDF) — im Dokument zwischen Kunden- und interner Sicht (Marge) umschaltbar">Dokument</a>
                        <a href="{{ route('foodalchemist.foodbooks.praesentation', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Externe Kunden-Präsentation (Web-Seite, Preise pro Person, ohne Interna)">Präsentation</a>
                        <button type="button" wire:click="loeschen({{ $fb->id }})" wire:confirm="Foodbook löschen?" class="{{ $btnGhost }} text-red-600">Löschen</button>
                    </div>
                </div>

                {{-- ═══ Tab: BRIEFING (Stammdaten · Kunde · Leitidee) ═══ --}}
                <div x-show="tab === 'briefing'" x-cloak class="space-y-3" data-fb-panel="briefing">
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

                {{-- Phase 5: Kickoff-Wizard — minimale Rückfrage → KI schlägt das Planungs-Gerüst vor.
                     Doktrin: Vorschlag, nicht Zwang. LLM läuft über den Core-Contract; kein Provider → UI-Fehler. --}}
                <div class="space-y-2 pt-2 border-t border-black/5" data-kickoff x-data="{ auf: false }">
                    <div class="flex items-center justify-between">
                        <span class="{{ $label }} !mb-0">✨ Kickoff — Gerüst-Vorschlag aus Brief</span>
                        <button type="button" @click="auf = !auf" class="{{ $btnGhostXs }}" x-text="auf ? 'Zuklappen' : 'Öffnen'"></button>
                    </div>
                    <div x-show="auf" x-cloak class="space-y-2">
                        <p class="text-[11px] text-gray-500">Kurz-Brief + Segment/DNA → die KI schlägt Kapitel-Slots vor. Danach im Planung-Tab prüfen und „Struktur anwenden".</p>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                            <div><label class="{{ $label }}">Anlass</label><input type="text" wire:model="kickoff.anlass" class="{{ $input }}" placeholder="z. B. Sommer-Gala" /></div>
                            <div><label class="{{ $label }}">Gäste</label><input type="number" min="1" wire:model="kickoff.personen" class="{{ $input }}" placeholder="{{ $fb->personen ?? '—' }}" /></div>
                            <div><label class="{{ $label }}">Saison</label><input type="text" wire:model="kickoff.saison" class="{{ $input }}" placeholder="z. B. Spätsommer" /></div>
                            <div><label class="{{ $label }}">Service-Form</label><input type="text" wire:model="kickoff.service_form" class="{{ $input }}" placeholder="z. B. Buffet / Menü" /></div>
                            <div><label class="{{ $label }}">Budget € p. P.</label><input type="number" step="0.01" min="0" wire:model="kickoff.budget" class="{{ $input }}" /></div>
                        </div>
                        <button type="button" wire:click="frameAusBriefVorschlagen" class="{{ $btnPrimary }} w-full justify-center" wire:loading.attr="disabled" wire:target="frameAusBriefVorschlagen" data-kickoff-go>
                            <span wire:loading.remove wire:target="frameAusBriefVorschlagen">✨ Gerüst vorschlagen</span>
                            <span wire:loading wire:target="frameAusBriefVorschlagen">KI baut das Gerüst …</span>
                        </button>
                        @if($kickoffFehler)
                            <p class="text-[11px] text-red-600" data-kickoff-fehler>{{ $kickoffFehler }}</p>
                        @endif
                        @if($kickoffErgebnis)
                            <p class="text-[11px] text-emerald-600" data-kickoff-ok>
                                Gerüst-Vorschlag steht: {{ $kickoffErgebnis['slots'] }} Slots @if($kickoffErgebnis['confidence'] !== null)· Konfidenz {{ number_format((float) $kickoffErgebnis['confidence'], 2) }} @endif.
                                Im <button type="button" @click="tab = 'planung'" class="underline font-medium">Planung-Tab</button> prüfen, dann „Struktur anwenden".
                            </p>
                        @endif
                    </div>
                </div>

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
                    <textarea wire:model="form.description" rows="3"
                              x-data
                              x-effect="$wire.form; $el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                              @input="$el.style.height='auto'; $el.style.height=$el.scrollHeight+'px'"
                              class="{{ $input }} resize-none overflow-hidden min-h-[4.5rem]" placeholder="Briefing / Einleitungstext fürs Angebot — später KI-befüllbar aus Kunde + Concepts"></textarea>
                </div>
            </div>

                {{-- Foodbook-Leitidee (Canvas) — inline statt Modal (Dominique 2026-07-21) --}}
                <div class="relative overflow-hidden {{ $card }} p-5" wire:key="fbcanvas-{{ $fb->id }}">
                    <div class="{{ $cardAccent }}"></div>
                    <p class="{{ $label }} mb-2">Leitidee-Canvas — was muss rein · welche Konzepte · was es erfüllen muss</p>
                    @include('foodalchemist::livewire.canvas.partials.board')
                </div>
                </div>{{-- /Briefing --}}

                {{-- ═══ Tab: PLANUNG (Struktur = Slots · Coverage) ═══ --}}
                <div x-show="tab === 'planung'" x-cloak class="space-y-3" data-fb-panel="planung">
                    {{-- R4.1: Planungs-Gerüst — Soll-Rahmen (Slots = Kapitel-Struktur, Mengen · Preise · Quoten · Dramaturgie) --}}
                    <div class="relative overflow-hidden {{ $card }} p-5" wire:key="fbframe-{{ $fb->id }}">
                        <div class="{{ $cardAccent }}"></div>
                        @include('foodalchemist::livewire.planning.partials.frame-board')
                    </div>

                    {{-- Phase 3a: Gerüst = Struktur — Slots als Kapitel materialisieren (Slot = Kapitel, Dominiques Kopplung). Idempotent. --}}
                    <div class="space-y-1.5">
                        <button type="button" wire:click="strukturAnwenden" class="{{ $btnGhost }} w-full justify-center" wire:loading.attr="disabled" data-struktur-anwenden>
                            <span wire:loading.remove wire:target="strukturAnwenden">⟐ Struktur anwenden — Slots als Kapitel anlegen</span>
                            <span wire:loading wire:target="strukturAnwenden">Lege Kapitel an …</span>
                        </button>
                        @if($strukturErgebnis !== null)
                            @if($strukturErgebnis['kein_geruest'])
                                <div class="rounded-lg bg-amber-500/10 border border-amber-500/30 px-2.5 py-1.5 text-[11px] text-amber-700">Noch kein Planungs-Gerüst mit Slots — oben erst Slots anlegen.</div>
                            @else
                                <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/25 px-2.5 py-2 text-[11px] text-gray-700" data-struktur-ergebnis>{{ $strukturErgebnis['angelegt'] }} Kapitel angelegt · {{ $strukturErgebnis['uebersprungen'] }} bereits vorhanden. Sie erscheinen links im Baum; Coverage matcht jetzt robust per Kapitel.</div>
                            @endif
                        @endif
                    </div>

                    {{-- R4.2: Soll/Ist-Coverage live beim Befüllen — Lücken-Klick öffnet den VK-Browser gefiltert --}}
                    @if(($coverage ?? null) !== null && $coverage['hat_geruest'])
                        @include('foodalchemist::livewire.planning.partials.coverage-panel', ['coverageFillRoute' => route('foodalchemist.verkauf.index')])

                        {{-- Phase 3 (Weg B): per-Slot-Vorschläge → abstimmen → übernehmen. Ersetzt den Monolith
                             „Konzept aus Gerüst". Voraussetzung: „Struktur anwenden" (Slot hat ein Kapitel). --}}
                        @if(!empty($frameSlots))
                            <div class="space-y-2" data-slot-vorschlaege>
                                <p class="{{ $label }}">Vorschläge je Slot — aus Bestand + Wissen, du stimmst ab</p>
                                @foreach($frameSlots as $fs)
                                    <div class="rounded-lg border border-black/5 px-3 py-2" wire:key="slotvor-{{ $fs['id'] }}">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-medium text-gray-800 truncate">{{ $fs['label'] }}
                                                @if($fs['chapter_id'])<span class="text-[10px] text-emerald-600">· Kapitel ✓</span>@else<span class="text-[10px] text-amber-600">· erst „Struktur anwenden"</span>@endif
                                            </span>
                                            <button type="button" wire:click="vorschlaegeFuerSlot({{ $fs['id'] }})" class="{{ $btnGhostXs }} text-violet-600 shrink-0" wire:loading.attr="disabled" wire:target="vorschlaegeFuerSlot" @disabled(!$fs['chapter_id'])>✨ Vorschläge</button>
                                        </div>
                                        @if(!empty($slotVorschlaege[$fs['id']] ?? []))
                                            <div class="mt-1.5 space-y-1">
                                                @foreach($slotVorschlaege[$fs['id']] as $v)
                                                    <div class="flex items-center gap-2 rounded bg-black/[0.02] px-2 py-1" wire:key="v-{{ $fs['id'] }}-{{ $v['id'] }}">
                                                        <span class="flex-1 min-w-0 truncate text-xs text-gray-700">{{ $v['name'] }}<span class="text-[10px] text-gray-400"> · {{ $v['diet_form'] ?? '—' }}@if($v['sales_net'] !== null) · {{ number_format((float) $v['sales_net'], 2, ',', '.') }} €@endif</span></span>
                                                        <button type="button" wire:click="uebernehmeGericht({{ $fs['id'] }}, {{ $v['id'] }})" class="{{ $btnGhostXs }} text-emerald-600 shrink-0" title="übernehmen">✓</button>
                                                        <button type="button" wire:click="verwerfeGericht({{ $fs['id'] }}, {{ $v['id'] }})" class="{{ $btnGhostXs }} text-gray-400 shrink-0" title="verwerfen">✕</button>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </div>{{-- /Planung --}}

                {{-- ═══ Tab: KREATIV (Kunde-DNA · später Geschmack/Tonalität) ═══ --}}
                <div x-show="tab === 'kreativ'" x-cloak class="space-y-3" data-fb-panel="kreativ">
                    {{-- Ebene 2 der DNA-Kette: Kunde-DNA am CRM-Kunden (Nested-Livewire, Re-Mount via key bei Kunden-Wechsel) --}}
                    <livewire:foodalchemist.foodbooks.kunde-dna-panel :company-id="$fb->crm_company_id" :key="'kdna-'.($fb->crm_company_id ?? 'none')" />
                    {{-- Foodbook-Tonalität (Schreibstil-Override) — führt über die Default-Schreibstile
                         aus Team- + Kunde-DNA (CanvasService::cascadeKontext), Phase 4. --}}
                    <div class="relative overflow-hidden {{ $card }} p-5 space-y-2" data-fb-tonalitaet>
                        <div class="{{ $cardAccent }}"></div>
                        <label class="{{ $label }}">Tonalität dieses Foodbooks</label>
                        <select wire:change="tonalitaetSetzen($event.target.value)" class="{{ $input }}" data-fb-tonalitaet-select>
                            <option value="">— Default aus der DNA-Kette (Team → Kunde)</option>
                            @foreach($schreibstile as $s)
                                <option value="{{ $s->id }}" {{ ($fb->writing_style_id ?? null) == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-gray-500">Steuert, wie die KI die neutralen Gerichte in die Markenstimme übersetzt. Der gewählte Stil <span class="font-medium">überschreibt</span> die Default-Schreibstile aus Team- und Kunde-DNA; leer = Default-Kaskade. Stile pflegst du in den Einstellungen → Schreibstile.</p>
                        @if($schreibstile->isEmpty())
                            <p class="text-[11px] text-amber-600">Noch keine Schreibstile angelegt — Einstellungen → Schreibstile.</p>
                        @endif
                        <p class="text-[11px] text-gray-400 pt-1 border-t border-black/5">Geschmackswelten werden je <span class="font-medium">Konzept</span> gepflegt (Konzept-Brief), nicht am Foodbook — das Foodbook komponiert die Konzepte.</p>
                    </div>
                </div>{{-- /Kreativ --}}

                {{-- ═══ Tab: TREND (Wissensschrank-Pull) — Phase 4 ═══ --}}
                <div x-show="tab === 'trend'" x-cloak class="space-y-3" data-fb-panel="trend">
                    <div class="relative overflow-hidden {{ $card }} p-5 space-y-2.5">
                        <div class="{{ $cardAccent }}"></div>
                        <div class="flex items-center justify-between gap-2">
                            <p class="{{ $label }}">Trends aus dem Wissensschrank — Inspiration für die Planung</p>
                            <a href="{{ route('foodalchemist.knowledge.index') }}" target="_blank" class="{{ $btnGhostXs }} text-violet-600 shrink-0">→ Wissen</a>
                        </div>
                        @forelse($trendDocs ?? [] as $d)
                            <div class="rounded-lg border border-black/5 px-3 py-2" wire:key="trend-{{ $d['slug'] }}">
                                <p class="text-xs font-medium text-gray-800">{{ $d['title'] }}</p>
                                @if(($d['frontmatter']['thema'] ?? null) || ($d['frontmatter']['relevanz'] ?? null))
                                    <p class="text-[11px] text-gray-500">{{ $d['frontmatter']['thema'] ?? '' }}@if($d['frontmatter']['relevanz'] ?? null) · Relevanz {{ $d['frontmatter']['relevanz'] }}@endif</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">Noch keine Trend-Dokumente im Wissensschrank. Das Trend-Scouting speist sie wöchentlich ein — dann erscheinen sie hier als Anker für Konzept & Vorschläge.</p>
                        @endforelse
                    </div>
                </div>{{-- /Trend --}}

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
                                    @if($fb->logo_path)<img src="{{ \Storage::disk('public')->url($fb->logo_path) }}" alt="Logo" class="max-h-5 max-w-[90px] object-contain shrink-0" />@endif
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
                                        <img src="{{ \Storage::disk('public')->url($fb->logo_path) }}" alt="Logo" class="h-10 max-w-[120px] object-contain rounded border border-black/5 bg-white p-1" />
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
                                        <img src="{{ \Storage::disk('public')->url($fb->cover_image_path) }}" alt="Cover" class="h-10 max-w-[120px] object-cover rounded border border-black/5" />
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

                {{-- Menü-Vorschau (Kundensicht, ganzes Foodbook) = eigener Output-Tab (read-only, Foodbook-Kopf-Ebene).
                     Früher ein einklappbarer Balken unter allen Tabs — jetzt eine eigene Fläche (ein Tab = eine Fläche). --}}
                <div x-show="tab === 'vorschau'" x-cloak class="space-y-3" data-fb-panel="vorschau">
            {{-- ═══ MENÜ-VORSCHAU (Kundensicht, read-only) ═══ --}}
            <div class="relative overflow-hidden {{ $card }} p-6 space-y-5" data-fb-menue-vorschau>
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
                            @php($istKonzept = in_array($b['type'], ['concept_ref', 'recipe_ref'], true))
                            <div class="py-0.5">
                                <p class="text-sm {{ $b['ist_header'] ? 'font-semibold text-gray-700 mt-2' : ($istKonzept ? 'font-semibold text-gray-900 mt-2' : 'text-gray-900') }}">{{ $b['label'] }}</p>
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
            </div>{{-- /Menü-Vorschau-Karte --}}
                </div>{{-- /Vorschau-Tab --}}

            </div>{{-- /fbcockpit --}}

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
