{{-- #380: Angebote-Detail/Edit (am Concepter-DetailPanel orientiert) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div>
@if($angebot)
    <div class="p-4 space-y-4" wire:key="ang-detail-{{ $angebot->id }}">
        {{-- Kopf --}}
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <div class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">{{ $angebot->name }}</div>
                <span class="{{ $pill }} {{ $variantPill[$angebot->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $angebot->status->label() }}</span>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <a href="{{ route('foodalchemist.angebote.dokument', $angebot->id) }}" target="_blank" class="{{ $btnGhostXs }}" title="Versendbares Angebots-Dokument (Druck/PDF)">Dokument</a>
                <button type="button" wire:click="loeschen" wire:confirm="Angebot löschen?" class="{{ $btnGhostXs }} text-red-600 dark:text-red-400">Löschen</button>
            </div>
        </div>

        {{-- #380: Lifecycle-Workflow (anfrage → in Arbeit → Angebot → versendet → angenommen|abgelehnt) --}}
        <div class="flex flex-wrap items-center gap-1.5 pb-1 border-b border-black/5 dark:border-white/10">
            <span class="{{ $label }}">Workflow</span>
            <span class="{{ $pill }} {{ $variantPill[$angebot->status->badgeVariant()] ?? $variantPill['secondary'] }}">{{ $angebot->status->label() }}</span>
            @forelse($angebot->status->uebergaenge() as $next)
                <button type="button" wire:click="statusSetzen('{{ $next->value }}')" class="{{ $btnGhostXs }}">→ {{ $next->label() }}</button>
            @empty
                <span class="text-[11px] text-gray-400">— abgeschlossen —</span>
            @endforelse
        </div>

        {{-- Anfrage / Briefing --}}
        <div class="space-y-2">
            <span class="{{ $label }}">Anfrage</span>
            <div><label class="{{ $label }}">Name</label><input type="text" wire:model="form.name" class="{{ $input }}" /></div>
            <div><label class="{{ $label }}">Pax</label><input type="number" min="0" wire:model="form.personen" wire:change="speichern" class="{{ $input }} text-right tabular-nums" title="treibt den Auto-Gesamtpreis" /></div>
            <div><label class="{{ $label }}">Anlass</label><input type="text" wire:model="form.anlass" class="{{ $input }}" placeholder="Hochzeit, Firmenfeier …" /></div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="{{ $label }}">Event-Datum</label><input type="date" wire:model="form.event_date" class="{{ $input }}" /></div>
                <div><label class="{{ $label }}">Budget €</label><input type="number" step="0.01" wire:model="form.budget" class="{{ $input }} text-right tabular-nums" /></div>
            </div>
            <div><label class="{{ $label }}">Location</label><input type="text" wire:model="form.location" class="{{ $input }}" /></div>
            <div><label class="{{ $label }}">Diät / Allergien</label><input type="text" wire:model="form.diaet_vorgabe" class="{{ $input }}" /></div>
            <div><label class="{{ $label }}">Briefing</label><textarea rows="3" wire:model="form.brief" class="{{ $input }}"></textarea></div>
        </div>

        {{-- #383: Kalkulation (Pax × Menü) — aggregiert die Concepter-Engine --}}
        @if($kalkulation)
        <div class="space-y-2 pt-2 border-t border-black/5 dark:border-white/10">
            <span class="{{ $label }}">Kalkulation (Pax × Menü)</span>
            @if($kalkulation['leer'])
                <p class="text-[11px] text-gray-400">Noch kein Menü (unten „+ Menü"). Pax: {{ $kalkulation['pax'] ?: '—' }}.</p>
            @else
                <div class="grid grid-cols-2 gap-x-3">
                    <div class="{{ $row }}"><span class="{{ $dt }}">€/Person</span><span class="{{ $dd }} tabular-nums">{{ number_format($kalkulation['vk_pro_person'],2,',','.') }} €</span></div>
                    <div class="{{ $row }}"><span class="{{ $dt }}">Pax</span><span class="{{ $dd }} tabular-nums">{{ $kalkulation['pax'] ?: '—' }}</span></div>
                    <div class="{{ $row }}"><span class="{{ $dt }}">Wareneinsatz</span><span class="{{ $dd }} tabular-nums">{{ $kalkulation['wareneinsatz_pct'] !== null ? number_format($kalkulation['wareneinsatz_pct'],1,',','.').' %' : '—' }}</span></div>
                    <div class="{{ $row }}"><span class="{{ $dt }}">HK2/P</span><span class="{{ $dd }} tabular-nums">{{ number_format($kalkulation['hk2_pro_person'],2,',','.') }} €</span></div>
                </div>
                <div class="flex items-center justify-between rounded-lg bg-violet-500/5 px-3 py-2">
                    <span class="text-xs text-gray-500">Gesamt · {{ $kalkulation['preis_modus']==='auto' ? 'auto' : 'manuell' }}</span>
                    <span class="text-sm font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format($kalkulation['gesamt_vk'],2,',','.') }} €</span>
                </div>
                <div class="text-[11px] text-gray-400 text-right">Deckungsbeitrag {{ number_format($kalkulation['gesamt_db'],2,',','.') }} € · EK {{ number_format($kalkulation['gesamt_ek'],2,',','.') }} €</div>
            @endif
            <div class="grid grid-cols-2 gap-2">
                <div><label class="{{ $label }}">Preis-Modus</label>
                    <select wire:model="form.preis_modus" wire:change="speichern" class="{{ $input }}"><option value="auto">Auto (Pax × Menü)</option><option value="manuell">Manuell</option></select></div>
                <div><label class="{{ $label }}">Gültig bis</label><input type="date" wire:model="form.valid_until" class="{{ $input }}" /></div>
            </div>
            @if($kalkulation['preis_modus']==='manuell')
                <div><label class="{{ $label }}">Gesamtpreis € (manuell)</label>
                    <input type="number" step="0.01" wire:model="form.gesamtpreis" wire:change="speichern" class="{{ $input }} text-right tabular-nums" /></div>
            @endif
        </div>
        @endif

        <button type="button" wire:click="speichern" class="{{ $btnPrimary }} w-full justify-center">Speichern</button>

        {{-- Canvas: Angebot-Business-Case — auf Klick im Modal --}}
        <button type="button" @click="$dispatch('modal.open', { name: 'angebot-canvas' })"
                class="{{ $btnGhost }} w-full justify-center" wire:key="angcanvas-btn-{{ $angebot->id }}">
            Business-Case-Canvas
        </button>
        <x-foodalchemist::modal name="angebot-canvas" title="Angebot — Business Case (Canvas)" size="max-w-2xl">
            @include('foodalchemist::livewire.canvas.partials.board')
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'angebot-canvas' })" class="{{ $btnGhost }}">Schließen</button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- CRM-Verknüpfung (MVP: nur verlinken) --}}
        <div class="space-y-2 pt-3 border-t border-black/5 dark:border-white/10">
            <span class="{{ $label }}">Kunde (CRM)</span>
            @if(! $crmVerfuegbar)
                <p class="text-[11px] text-gray-400">CRM-Modul nicht verfügbar.</p>
            @else
                <div class="text-xs text-gray-600 dark:text-gray-300 space-y-0.5">
                    <div>Firma: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $angebot->crmCompany?->display_name ?? '—' }}</span></div>
                    <div>Kontakt: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $angebot->crmContact?->display_name ?? '—' }}</span></div>
                    @if($angebot->crm_company_id || $angebot->crm_contact_id)
                        <button type="button" wire:click="loeseKunde" class="{{ $btnGhostXs }} mt-1">Verknüpfung lösen</button>
                    @endif
                </div>
                <input type="search" wire:model.live.debounce.300ms="firmaSuche" placeholder="Firma suchen …" class="{{ $input }}" />
                @if($firmen->isNotEmpty())
                    <div class="space-y-0.5">
                        @foreach($firmen as $f)
                            <button type="button" wire:key="fi-{{ $f->id }}" wire:click="verknuepfeFirma({{ $f->id }})"
                                    class="w-full text-left px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">{{ $f->display_name }}</button>
                        @endforeach
                    </div>
                @endif
                <input type="search" wire:model.live.debounce.300ms="kontaktSuche" placeholder="Kontakt suchen …" class="{{ $input }}" />
                @if($kontakte->isNotEmpty())
                    <div class="space-y-0.5">
                        @foreach($kontakte as $k)
                            <button type="button" wire:key="ko-{{ $k->id }}" wire:click="verknuepfeKontakt({{ $k->id }})"
                                    class="w-full text-left px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10">{{ $k->display_name }}</button>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        {{-- Menü-Composer: angebots-lokale Menüs, gebaut im wiederverwendeten Concepter-Editor (#380) --}}
        <div class="space-y-1.5 pt-3 border-t border-black/5 dark:border-white/10">
            <div class="flex items-center justify-between">
                <span class="{{ $label }}">Menü (angebots-lokal)</span>
                <button type="button" wire:click="neuesMenue" class="{{ $btnGhostXs }}">+ Menü</button>
            </div>
            @forelse($angebot->concepts as $c)
                <div wire:key="amc-{{ $c->id }}" class="flex items-center gap-1.5 px-2 py-1 rounded-lg bg-black/[0.03] dark:bg-white/5 text-xs">
                    <button type="button" wire:click="bearbeiteMenue({{ $c->id }})"
                            class="flex-1 min-w-0 text-left truncate hover:text-violet-600 dark:hover:text-violet-400" title="Im Concepter-Editor bearbeiten">
                        {{ $c->name }} <span class="text-gray-400">· {{ $c->slots_count }} Pos.</span>
                    </button>
                    <button type="button" wire:click="uebernehmeMenue({{ $c->id }})" class="{{ $btnGhostXs }}"
                            title="In den Concepter-Katalog übernehmen (standardisieren)">übernehmen</button>
                    <button type="button" wire:click="entferneMenue({{ $c->id }})" wire:confirm="Menü entfernen?"
                            class="text-gray-400 hover:text-red-500 shrink-0">✕</button>
                </div>
            @empty
                <p class="text-[11px] text-gray-400">Noch kein Menü. „+ Menü" legt einen angebots-lokalen Entwurf an (im Concepter-Editor bearbeitbar); „übernehmen" standardisiert ihn in den Katalog.</p>
            @endforelse
        </div>

        {{-- #380 DoD-5: Katalog-Concepts referenzieren — Picker im Livewire-sicheren Modal
             (roomy, escaped das enge Panel; der x-teleport-Drawer brach die Live-Suche, weil
             Livewire-morph teleportierten Inhalt nicht zuverlässig aktualisiert). --}}
        <div class="space-y-1.5 pt-3 border-t border-black/5 dark:border-white/10">
            <div class="flex items-center justify-between">
                <span class="{{ $label }}">Aus Katalog (referenziert)</span>
                <button type="button" @click="$dispatch('modal.open', { name: 'angebot-katalog' })" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">+ Concept einbinden</button>
            </div>
            @forelse($angebot->referenzierteConcepts as $rc)
                <div wire:key="refc-{{ $rc->id }}" class="flex items-center gap-1.5 px-2 py-1 rounded-lg bg-black/[0.03] dark:bg-white/5 text-xs">
                    <span class="flex-1 min-w-0 truncate">{{ $rc->consumer_name ?: $rc->name }} <span class="text-gray-400">· {{ $rc->slots_count ?? 0 }} Pos.</span></span>
                    <button type="button" wire:click="entferneReferenz({{ $rc->id }})" class="text-gray-400 hover:text-red-500 shrink-0" title="Referenz lösen">✕</button>
                </div>
            @empty
                <p class="text-[11px] text-gray-400">Keine referenziert. „+ Concept einbinden" öffnet den Katalog-Filter (Portfolio wiederverwenden).</p>
            @endforelse
        </div>

        {{-- Katalog-Concept-Picker (Modal, Livewire-sicher) — Suche + Kategorie-Tree + Liste, „alles eingeben" --}}
        <x-foodalchemist::modal name="angebot-katalog" title="Katalog-Concept einbinden" size="max-w-3xl">
            <input type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen …" class="{{ $input }} w-full mb-3" />
            <div class="flex gap-3 min-h-[20rem]">
                <div class="w-44 shrink-0 overflow-y-auto border-r border-black/5 dark:border-white/10 pr-2 space-y-0.5 max-h-[26rem]">
                    <button type="button" wire:click="$set('conceptKategorie', null)"
                            class="w-full text-left text-xs px-2 py-1 rounded-lg {{ $conceptKategorie === null ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300' : 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5' }}">Alle Kategorien</button>
                    <x-foodalchemist::tree :initial-collapsed="collect($conceptKategorien)->where('has_children', true)->pluck('id')->all()">
                        @foreach($conceptKategorien as $kat)
                            <x-foodalchemist::tree-node :node-id="$kat['id']" :depth="$kat['depth']" :ancestors="$kat['ancestors'] ?? []"
                                :has-children="$kat['has_children'] ?? false" :active="$conceptKategorie === $kat['id']">
                                <button type="button" wire:click="$set('conceptKategorie', {{ $kat['id'] }})" class="flex-1 min-w-0 truncate text-left text-xs px-1 py-0.5">{{ $kat['name'] }}</button>
                            </x-foodalchemist::tree-node>
                        @endforeach
                    </x-foodalchemist::tree>
                </div>
                <div class="flex-1 min-w-0 overflow-y-auto space-y-0.5 max-h-[26rem]">
                    @if($katalogTreffer->isNotEmpty())
                        @foreach($katalogTreffer as $kt)
                            <button type="button" wire:key="acd-{{ $kt->id }}" wire:click="referenziereConcept({{ $kt->id }})"
                                    class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                <span class="truncate text-gray-900 dark:text-gray-100">+ {{ $kt->name }}</span>
                                <span class="text-gray-400 tabular-nums shrink-0">{{ $kt->preis_pro_person_cache !== null ? number_format((float) $kt->preis_pro_person_cache, 2, ',', '.') . ' €' : '' }}</span>
                            </button>
                        @endforeach
                    @elseif($conceptSuche !== '' || $conceptKategorie !== null)
                        <p class="text-[11px] text-gray-400 px-2 py-2">Keine Concepts für diese Auswahl.</p>
                    @else
                        <p class="text-[11px] text-gray-400 px-2 py-2">Kategorie wählen oder oben suchen.</p>
                    @endif
                </div>
            </div>
            <x-slot:footer>
                <button type="button" @click="$dispatch('modal.close', { name: 'angebot-katalog' })" class="{{ $btnGhost }}">Schließen</button>
            </x-slot:footer>
        </x-foodalchemist::modal>

        {{-- #383: Mengen-Hochrechnung für die Pax (Einkaufs-/Produktionssicht) --}}
        @if($kalkulation && ! $kalkulation['leer'] && $kalkulation['pax'] > 0 && count($kalkulation['mengen']))
        <div class="space-y-1 pt-3 border-t border-black/5 dark:border-white/10">
            <span class="{{ $label }}">Mengen für {{ $kalkulation['pax'] }} Pax</span>
            <div class="space-y-0.5 max-h-48 overflow-y-auto">
                @foreach($kalkulation['mengen'] as $m)
                    <div wire:key="mng-{{ $loop->index }}" class="flex items-center justify-between gap-2 text-[11px]">
                        <span class="truncate text-gray-600 dark:text-gray-300">{{ $m['gericht'] ?? '—' }}</span>
                        <span class="tabular-nums text-gray-500 shrink-0">{{ $m['gesamt_menge'] !== null ? rtrim(rtrim(number_format($m['gesamt_menge'],2,',','.'),'0'),',').' '.($m['unit'] ?? '') : '—' }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif
    </div>
@else
    <div class="p-6 text-center text-sm text-gray-400">Links ein Angebot wählen oder „+ Neue Anfrage".</div>
@endif
</div>
