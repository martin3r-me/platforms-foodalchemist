{{-- Angebote-Editor (Fullscreen-Dark, pro Angebot) — Anfrage · Menü & Kalkulation · Kunde & Business-Case. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="angebot-editor" fullscreen dark-canvas title="Angebot bearbeiten"
    :title-name="$angebot->name ?? null">
    <x-slot:actions>
        @if($angebot)
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-angebot-speichern>Speichern</button>
            {{-- Workflow-Übergänge --}}
            @foreach($angebot->status->uebergaenge() as $next)
                <button type="button" wire:click="statusSetzen('{{ $next->value }}')" class="{{ $btnGhostXs }}" data-angebot-status="{{ $next->value }}">→ {{ $next->label() }}</button>
            @endforeach
            <span class="text-gray-300">|</span>
            <a href="{{ route('foodalchemist.angebote.dokument', $angebot->id) }}" target="_blank" class="{{ $btnGhostXs }}" title="Versendbares Angebots-Dokument (Druck/PDF)">Dokument</a>
            {{-- Stufe 3 — Angebot → Produktion (concept × Pax → Produktionsauftrag am Event-Tag). --}}
            <button type="button" wire:click="anProduktion" class="{{ $btnGhostXs }}"
                    title="Angebot in die Produktion übergeben — danach im Tagesplan planbar" data-angebot-produktion>→ Produktion</button>
            <button type="button" wire:click="loeschen" wire:confirm="Angebot löschen?" class="{{ $btnGhostXs }} text-red-600" data-angebot-loeschen>Löschen</button>
        @endif
    </x-slot:actions>

    @if($angebot)
        <x-slot:kpiHeader>
            @php($k = $kalkulation)
            @php($voll = $k && ! $k['leer'])
            <x-foodalchemist::kpi-tiles marker="angebot-kpis" :tiles="[
                ['kpi' => 'vkpp', 'label' => '€ / Person', 'tone' => 'accent',
                 'value' => $voll ? number_format((float) $k['vk_pro_person'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'pax', 'label' => 'Pax', 'value' => (string) ($k['pax'] ?? ($angebot->personen ?: '—'))],
                ['kpi' => 'gesamt', 'label' => 'Gesamt VK',
                 'value' => $voll ? number_format((float) $k['gesamt_vk'], 2, ',', '.') . ' €' : '—'],
                ['kpi' => 'we', 'label' => 'Wareneinsatz',
                 'value' => ($voll && $k['wareneinsatz_pct'] !== null) ? number_format((float) $k['wareneinsatz_pct'], 1, ',', '.') . ' %' : '—'],
                ['kpi' => 'status', 'label' => 'Status', 'value' => $angebot->status->label()],
            ]" />
        </x-slot:kpiHeader>
    @endif

    @if($angebot === null)
        <p class="pt-4 text-[12px] text-gray-500">Kein Angebot geladen.</p>
    @else
    <x-foodalchemist::editor-tabs marker="angebot" wire-key="angebot-tabs-{{ $angebot->id }}" :init="'anfrage'"
        :tabs="['anfrage' => 'Anfrage', 'menue' => 'Menü & Kalkulation', 'geruest' => 'Gerüst', 'kunde' => 'Kunde & Business-Case']">

        {{-- ═══ Tab: ANFRAGE ═══ --}}
        <div x-show="tab === 'anfrage'" x-cloak class="pt-4">
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

        {{-- ═══ Tab: MENÜ & KALKULATION ═══ --}}
        <div x-show="tab === 'menue'" x-cloak class="pt-4 space-y-4">
            @if($kalkulation)
            <x-foodalchemist::modal-section title="Kalkulation (Pax × Menü)">
                @if($kalkulation['leer'])
                    <p class="text-[11px] text-gray-500">Noch kein Menü (unten „+ Menü"). Pax: {{ $kalkulation['pax'] ?: '—' }}.</p>
                @else
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-x-3">
                        <div class="{{ $row }}"><span class="{{ $dt }}">€/Person</span><span class="{{ $dd }} tabular-nums">{{ number_format($kalkulation['vk_pro_person'],2,',','.') }} €</span></div>
                        <div class="{{ $row }}"><span class="{{ $dt }}">Pax</span><span class="{{ $dd }} tabular-nums">{{ $kalkulation['pax'] ?: '—' }}</span></div>
                        <div class="{{ $row }}"><span class="{{ $dt }}">Wareneinsatz</span><span class="{{ $dd }} tabular-nums">{{ $kalkulation['wareneinsatz_pct'] !== null ? number_format($kalkulation['wareneinsatz_pct'],1,',','.').' %' : '—' }}</span></div>
                        <div class="{{ $row }}"><span class="{{ $dt }}">HK2/P</span><span class="{{ $dd }} tabular-nums">{{ number_format($kalkulation['hk2_pro_person'],2,',','.') }} €</span></div>
                    </div>
                    <div class="flex items-center justify-between rounded-lg bg-violet-500/10 px-3 py-2 mt-2">
                        <span class="text-xs text-gray-600">Gesamt · {{ $kalkulation['price_mode']==='auto' ? 'auto' : 'fixiert' }}</span>
                        <span class="text-sm font-semibold tabular-nums text-gray-900">{{ number_format($kalkulation['gesamt_vk'],2,',','.') }} €</span>
                    </div>
                    <div class="text-[11px] text-gray-500 text-right mt-1">Deckungsbeitrag {{ number_format($kalkulation['gesamt_db'],2,',','.') }} € · EK {{ number_format($kalkulation['gesamt_ek'],2,',','.') }} €</div>
                    @if($kalkulation['pax'] > 0)
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-3 text-xs">
                            <div><span class="block text-[10px] text-gray-500">Mindestpreis</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['mindestpreis'],2,',','.') }} €</span></div>
                            <div><span class="block text-[10px] text-gray-500">Zielpreis</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['zielpreis'],2,',','.') }} €</span></div>
                            <div><span class="block text-[10px] text-gray-500">Zielpreis / Person</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['zielpreis_pro_person'],2,',','.') }} €</span></div>
                            <div><span class="block text-[10px] text-gray-500">Aktive Personenzeit</span><span class="font-medium tabular-nums">{{ number_format($kalkulation['aktive_personenminuten'] / 60,2,',','.') }} h</span></div>
                        </div>
                        @if($kalkulation['unwirtschaftlich'])
                            <div class="mt-3 rounded-lg border border-amber-400/50 bg-amber-500/10 px-3 py-2 text-xs text-amber-700">
                                Der Angebotspreis liegt {{ number_format($kalkulation['zielabweichung'],2,',','.') }} € unter dem Zielpreis. Der Preis wurde nicht automatisch erhöht.
                            </div>
                        @endif
                        @if(count($kalkulation['warnungen']))
                            <p class="mt-2 text-[10px] text-amber-700">{{ implode(' · ', $kalkulation['warnungen']) }}</p>
                        @endif
                    @endif
                @endif
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <div><label class="{{ $label }}">Preis-Modus</label>
                        <select wire:model="form.price_mode" class="{{ $input }}"><option value="auto">Auto (Pax × Menü)</option><option value="fixed">Fixiert</option></select></div>
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
            @endif

            {{-- Menü-Composer: angebots-lokale Menüs (im Concepter-Editor gebaut) --}}
            <x-foodalchemist::modal-section title="Menü (angebots-lokal)">
                <x-slot:actions>
                    <button type="button" wire:click="neuesMenue" class="{{ $btnGhostXs }}" data-angebot-neu-menue>+ Menü</button>
                    {{-- E2 (Spec 40): Voll-Kaskade — je Slot ein Menü-Konzept, ans Angebot referenziert; Prüfung in der Leitstelle. --}}
                    <button type="button" wire:click="vollKaskadeStarten" wire:loading.attr="disabled" wire:target="vollKaskadeStarten"
                            class="{{ $btnGhostXs }} text-violet-600" data-angebot-vollkaskade>
                        <span wire:loading.remove wire:target="vollKaskadeStarten">@svg('heroicon-o-bolt', 'w-3.5 h-3.5 inline align-text-bottom') Voll-Kaskade (KI)</span>
                        <span wire:loading wire:target="vollKaskadeStarten">Starte …</span>
                    </button>
                </x-slot:actions>
                <div class="space-y-1.5">
                    @forelse($angebot->concepts as $c)
                        <div wire:key="amc-{{ $c->id }}" class="flex items-center gap-1.5 px-2 py-1 rounded-lg bg-black/[0.06] text-xs">
                            <button type="button" wire:click="bearbeiteMenue({{ $c->id }})"
                                    class="flex-1 min-w-0 text-left truncate hover:text-violet-600" title="Im Concepter-Editor bearbeiten">
                                {{ $c->name }} <span class="text-gray-500">· {{ $c->slots_count }} Pos.</span>
                            </button>
                            <button type="button" wire:click="uebernehmeMenue({{ $c->id }})" class="{{ $btnGhostXs }}" title="In den Concepter-Katalog übernehmen (standardisieren)">übernehmen</button>
                            <button type="button" wire:click="entferneMenue({{ $c->id }})" wire:confirm="Menü entfernen?" class="text-gray-500 hover:text-red-500 shrink-0">✕</button>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-500">Noch kein Menü. „+ Menü" legt einen angebots-lokalen Entwurf an (im Concepter-Editor bearbeitbar); „übernehmen" standardisiert ihn in den Katalog.</p>
                    @endforelse
                </div>
            </x-foodalchemist::modal-section>

            {{-- Katalog-Concepts referenzieren — INLINE-Picker (Concepter-/Foodbook-Muster, kein Modal) --}}
            <div x-data="{ einf: false }">
            <x-foodalchemist::modal-section title="Aus Katalog (referenziert)">
                <x-slot:actions>
                    <button type="button" @click="einf = !einf" class="{{ $btnGhostXs }} text-violet-600" :class="einf ? '!bg-violet-500/20 !border-violet-500/40' : ''" data-angebot-katalog-open>+ Concept einbinden</button>
                </x-slot:actions>
                <div class="space-y-1.5">
                    @forelse($angebot->referencedConcepts as $rc)
                        <div wire:key="refc-{{ $rc->id }}" class="flex items-center gap-1.5 px-2 py-1 rounded-lg bg-black/[0.06] text-xs">
                            <span class="flex-1 min-w-0 truncate">{{ $rc->consumer_name ?: $rc->name }} <span class="text-gray-500">· {{ $rc->slots_count ?? 0 }} Pos.</span></span>
                            <button type="button" wire:click="entferneReferenz({{ $rc->id }})" class="text-gray-500 hover:text-red-500 shrink-0" title="Referenz lösen">✕</button>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-500">Keine referenziert. „+ Concept einbinden" öffnet den Katalog-Filter (Portfolio wiederverwenden).</p>
                    @endforelse
                </div>

                {{-- Inline-Picker: Suche + Concepter-Dimensionen + Kandidatenliste; „+" referenziert direkt, bleibt offen --}}
                <div x-show="einf" x-cloak class="mt-3 border-t border-white/10 pt-3" data-angebot-katalog-picker>
                    <input type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen … (aus der Liste mit + einbinden)" class="{{ $input }} w-full mb-2" />
                    @php($facettenAktiv = collect($conceptFacetten)->filter(fn ($v) => $v !== null)->isNotEmpty())
                    <div class="flex gap-3">
                        <div class="w-52 shrink-0 overflow-y-auto border-r border-white/10 pr-2 space-y-2 max-h-72">
                            <button type="button" wire:click="resetConceptFacetten"
                                    class="w-full text-left text-[11px] px-2 py-1 rounded-lg {{ ! $facettenAktiv ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700' : 'text-gray-500 hover:bg-black/[0.06]' }}">Alle Dimensionen</button>
                            @if($facetteEventtypen->isNotEmpty())
                                <div class="space-y-1"><span class="{{ $label }}">Eventtyp</span>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($facetteEventtypen as $et)
                                            <button type="button" wire:key="afc-ev-{{ $et->id }}" wire:click="toggleConceptFacet('eventtyp', {{ $et->id }})" class="{{ $pill }} {{ $conceptFacetten['eventtyp'] === $et->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $et->name }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if($facetteServierformen->isNotEmpty())
                                <div class="space-y-1"><span class="{{ $label }}">Servierform</span>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($facetteServierformen as $sf)
                                            <button type="button" wire:key="afc-sf-{{ $sf->id }}" wire:click="toggleConceptFacet('servierform', {{ $sf->id }})" class="{{ $pill }} {{ $conceptFacetten['servierform'] === $sf->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sf->label }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if($facetteMomente->isNotEmpty())
                                <div class="space-y-1"><span class="{{ $label }}">Einsatzmoment</span>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($facetteMomente as $em)
                                            <button type="button" wire:key="afc-em-{{ $em->id }}" wire:click="toggleConceptFacet('einsatzmoment', {{ $em->id }})" class="{{ $pill }} {{ $conceptFacetten['einsatzmoment'] === $em->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $em->name }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            @if($facetteSaisons->isNotEmpty())
                                <div class="space-y-1"><span class="{{ $label }}">Saison</span>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($facetteSaisons as $sa)
                                            <button type="button" wire:key="afc-sa-{{ $sa->id }}" wire:click="toggleConceptFacet('season', {{ $sa->id }})" class="{{ $pill }} {{ $conceptFacetten['season'] === $sa->id ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sa->name }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="flex-1 min-w-0 overflow-y-auto space-y-0.5 max-h-72">
                            @if($katalogTreffer->isNotEmpty())
                                @foreach($katalogTreffer as $kt)
                                    <button type="button" wire:key="acd-{{ $kt->id }}" wire:click="referenziereConcept({{ $kt->id }})"
                                            class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                        <span class="truncate text-gray-900">+ {{ $kt->name }}</span>
                                        <span class="text-gray-500 tabular-nums shrink-0">{{ $kt->price_per_person_cache !== null ? number_format((float) $kt->price_per_person_cache, 2, ',', '.') . ' €' : '' }}</span>
                                    </button>
                                @endforeach
                            @elseif($conceptSuche !== '' || $facettenAktiv)
                                <p class="text-[11px] text-gray-500 px-2 py-2">Keine Concepts für diese Auswahl.</p>
                            @else
                                <p class="text-[11px] text-gray-500 px-2 py-2">Noch keine Concepts angelegt.</p>
                            @endif
                        </div>
                    </div>
                </div>
            </x-foodalchemist::modal-section>
            </div>

            {{-- Mengen-Hochrechnung für die Pax --}}
            @if($kalkulation && ! $kalkulation['leer'] && $kalkulation['pax'] > 0 && count($kalkulation['mengen']))
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

        {{-- ═══ Tab: GERÜST (UX-Ausbau: Slots VOR der Voll-Kaskade prüfen/bauen) ═══ --}}
        <div x-show="tab === 'geruest'" x-cloak class="pt-4 space-y-4">
            <x-foodalchemist::modal-section title="Planungs-Gerüst (Slots für die Voll-Kaskade)">
                <p class="text-[11px] text-gray-500 mb-2">Lege die Slots fest (z. B. Vorspeise · Hauptgang · Dessert) — die Voll-Kaskade erzeugt je Slot ein Menü-Konzept und referenziert es ans Angebot. Ohne Gerüst strukturiert die Voll-Kaskade automatisch aus Anlass/Gäste.</p>
                <div class="flex gap-2 mb-2">
                    <input type="text" wire:model="neuerSlot" wire:keydown.enter="geruestSlotNeu" placeholder="Slot-Label (z. B. Hauptgang) …" class="{{ $input }}" data-angebot-slot-input />
                    <button type="button" wire:click="geruestSlotNeu" class="{{ $btnGhostXs }} shrink-0" data-angebot-slot-neu>+ Slot</button>
                    <button type="button" wire:click="geruestKickoff" wire:loading.attr="disabled" wire:target="geruestKickoff" class="{{ $btnGhostXs }} shrink-0 text-violet-600" data-angebot-geruest-kickoff>
                        <span wire:loading.remove wire:target="geruestKickoff">@svg('heroicon-o-sparkles', 'w-3.5 h-3.5 inline align-text-bottom') KI-Vorschlag</span>
                        <span wire:loading wire:target="geruestKickoff">…</span>
                    </button>
                </div>
                <div class="space-y-1.5">
                    @forelse($geruestSlots as $slot)
                        <div wire:key="ang-slot-{{ $slot->id }}" class="flex items-center gap-2 px-2 py-1 rounded-lg bg-black/[0.05] text-xs">
                            <span class="flex-1 truncate text-gray-800">{{ $slot->label }}</span>
                            @if($slot->target_count)<span class="text-gray-500">×{{ $slot->target_count }}</span>@endif
                            @if($slot->price_anchor !== null)<span class="text-gray-500 tabular-nums">{{ number_format((float) $slot->price_anchor, 2, ',', '.') }} €</span>@endif
                            <button type="button" wire:click="geruestSlotLoeschen({{ $slot->id }})" class="text-gray-500 hover:text-red-500 shrink-0" title="Slot löschen">✕</button>
                        </div>
                    @empty
                        <p class="text-[11px] text-gray-500">Noch keine Slots. „+ Slot" oder „KI-Vorschlag" anlegen — dann Voll-Kaskade (Menü-Tab).</p>
                    @endforelse
                </div>
            </x-foodalchemist::modal-section>
        </div>

        {{-- ═══ Tab: KUNDE & BUSINESS-CASE ═══ --}}
        <div x-show="tab === 'kunde'" x-cloak class="pt-4 space-y-4">
            <x-foodalchemist::modal-section title="Kunde (CRM)">
                <x-foodalchemist::crm-kunde-picker
                    :ausgabe="$angebot" :crm-verfuegbar="$crmVerfuegbar" :firmen="$firmen" :kontakte="$kontakte" />
            </x-foodalchemist::modal-section>

            <x-foodalchemist::modal-section title="Business-Case (Canvas)">
                @include('foodalchemist::livewire.canvas.partials.board')
            </x-foodalchemist::modal-section>
        </div>
    </x-foodalchemist::editor-tabs>

    {{-- Katalog-Picker liegt jetzt inline im „Aus Katalog"-Abschnitt (Concepter-/Foodbook-Muster). --}}
    @endif
</x-foodalchemist::modal>
