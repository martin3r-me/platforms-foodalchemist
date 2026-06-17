{{-- M11-03 / Doc 15 §9.3: Foodbook-Editor — stellt Concepts zu einem Kunden-Angebot zusammen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($aktiv = 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10 text-violet-700 dark:text-violet-300')
@php($hover = 'text-gray-600 dark:text-gray-300 hover:bg-black/[0.03] dark:hover:bg-white/5')

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Foodbook / Portfolio" icon="heroicon-o-book-open" />
    </x-slot:navbar>

    <x-slot name="sidebar">
        <x-ui-page-sidebar title="Foodbooks" width="w-80">
            <div class="p-3 space-y-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Foodbook / Kunde suchen …" class="{{ $input }}" />
                <button type="button" wire:click="neu" class="{{ $btnPrimary }} w-full justify-center">+ Neues Foodbook</button>
                <div class="space-y-0.5 -mx-1">
                    @forelse($foodbooks as $f)
                        <button type="button" wire:key="fb-{{ $f->id }}" wire:click="waehle({{ $f->id }})"
                                class="w-full text-left px-2 py-1 rounded-lg text-xs {{ $selectedId === $f->id ? $aktiv : $hover }}">
                            <span class="truncate block">{{ $f->bezeichnung }}</span>
                            <span class="text-[10px] text-gray-400">{{ $f->kunde ?? 'ohne Kunde' }} · {{ $f->kapitel_count }} Kapitel</span>
                        </button>
                    @empty
                        <p class="px-2 py-3 text-[11px] text-gray-400">Noch keine Foodbooks.</p>
                    @endforelse
                </div>

                {{-- Kapitel-Baum des gewählten Foodbooks --}}
                @if($fb)
                    <div class="pt-2 border-t border-black/5 dark:border-white/10 space-y-0.5">
                        <div class="flex items-center gap-1">
                            <input type="text" wire:model="neuesKapitelTitel" wire:keydown.enter="kapitelNeu" placeholder="Neues Kapitel …" class="{{ $input }} py-0.5" />
                            <button type="button" wire:click="kapitelNeu" class="{{ $btnGhostXs }}" title="Top-Kapitel">+</button>
                        </div>
                        @foreach($kapitelTree as $kt)
                            <div wire:key="kt-{{ $kt['id'] }}" class="group flex items-center gap-1" style="padding-left: {{ $kt['depth'] * 12 }}px">
                                <button type="button" wire:click="kapitelWaehle({{ $kt['id'] }})"
                                        class="flex-1 min-w-0 text-left truncate text-xs px-2 py-0.5 rounded-lg {{ $selectedKapitelId === $kt['id'] ? $aktiv : $hover }}">{{ $kt['titel'] }}</button>
                                <button type="button" wire:click="kapitelHoch({{ $kt['id'] }})" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[10px]" title="hoch">▲</button>
                                <button type="button" wire:click="kapitelRunter({{ $kt['id'] }})" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-violet-500 text-[10px]" title="runter">▼</button>
                                <button type="button" wire:click="kapitelNeu({{ $kt['id'] }})" class="shrink-0 text-violet-400 hover:text-violet-600 text-xs px-1 leading-none" title="Unterkapitel anlegen">＋</button>
                                <button type="button" wire:click="kapitelLoeschen({{ $kt['id'] }})" wire:confirm="Kapitel löschen?" class="shrink-0 opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-500 text-[11px]" title="löschen">✕</button>
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
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 tabular-nums">{{ number_format($gesamt['vk_pro_person'], 2, ',', '.') }} €</div>
                        <div class="{{ $label }}">pro Person · EK {{ number_format($gesamt['ek_pro_person'], 2, ',', '.') }} €</div>
                        <div class="text-[11px] text-gray-400 mt-1">Portfolio — person-unabhängig. Pax + Gesamtpreis liegen im Angebot.</div>
                    </div>
                    @if($kapitel && $kapitelAgg)
                        <div class="pt-2 border-t border-black/5 dark:border-white/10 text-xs space-y-1">
                            <div class="{{ $label }}">Kapitel „{{ $kapitel->titel }}"</div>
                            <div class="flex justify-between"><span class="text-gray-500">€/Person</span><span class="tabular-nums">{{ number_format($kapitelAgg['vk_pro_person'], 2, ',', '.') }} €</span></div>
                            <div class="flex justify-between"><span class="text-gray-500">EK/Person</span><span class="tabular-nums">{{ number_format($kapitelAgg['ek_pro_person'], 2, ',', '.') }} €</span></div>
                            @if($kapitelAgg['wareneinsatz_prozent'] !== null)<div class="flex justify-between"><span class="text-gray-500">Wareneinsatz</span><span class="tabular-nums">{{ number_format($kapitelAgg['wareneinsatz_prozent'], 1, ',', '.') }} %</span></div>@endif
                        </div>
                    @endif
                </div>
            @else
                <div class="p-6 text-center text-sm text-gray-400">Foodbook auswählen.</div>
            @endif
        </x-ui-page-sidebar>
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        @if($fb)
            {{-- Foodbook-Stammdaten --}}
            <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="fbhdr-{{ $fb->id }}">
                <div class="{{ $cardAccent }}"></div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="md:col-span-2"><label class="{{ $label }}">Bezeichnung</label><input type="text" wire:model="form.bezeichnung" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Kunde</label><input type="text" wire:model="form.kunde" class="{{ $input }}" /></div>
                    <div><label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">@foreach(['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'versendet' => 'Versendet', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                    </div>
                </div>
                {{-- #369: CRM-Kunde-Link (MVP, nur verlinken) — ergänzt das Freitext-Feld „Kunde" --}}
                <div class="space-y-2 pt-1 border-t border-black/5 dark:border-white/10">
                    <span class="{{ $label }}">Kunde (CRM)</span>
                    @if(! $crmVerfuegbar)
                        <p class="text-[11px] text-gray-400">CRM-Modul nicht verfügbar — Freitext-Feld „Kunde" oben nutzen.</p>
                    @else
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-300">
                            <div>Firma: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $fb->crmCompany?->display_name ?? '—' }}</span></div>
                            <div>Kontakt: <span class="font-medium text-gray-900 dark:text-gray-100">{{ $fb->crmContact?->display_name ?? '—' }}</span></div>
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
                    <textarea wire:model="form.beschreibung" rows="3" class="{{ $input }}" placeholder="Briefing / Einleitungstext fürs Angebot — später KI-befüllbar aus Kunde + Concepts"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
                    <a href="{{ route('foodalchemist.foodbooks.dokument', $fb->id) }}" target="_blank" class="{{ $btnGhost }}" title="Versendbares Foodbook-Dokument (Druck/PDF)">Dokument</a>
                    <button type="button" wire:click="loeschen({{ $fb->id }})" wire:confirm="Foodbook löschen?" class="{{ $btnGhost }} text-red-600 dark:text-red-400">Löschen</button>
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

            @if($kapitel)
                {{-- Kapitel-Kopf --}}
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3" wire:key="kaphdr-{{ $kapitel->id }}">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div><label class="{{ $label }}">Kapitel (intern)</label><input type="text" wire:model.blur="kapitelForm.titel" wire:change="kapitelSpeichern" class="{{ $input }}" /></div>
                        <div class="md:col-span-2"><label class="{{ $label }}">Konsumententitel</label><input type="text" wire:model.blur="kapitelForm.konsumententitel" wire:change="kapitelSpeichern" class="{{ $input }}" placeholder="Marketing-Titel (PDF)" /></div>
                        <div><label class="{{ $label }}">Preis-Modus</label>
                            <select wire:model.live="kapitelForm.preis_modus" wire:change="kapitelSpeichern" class="{{ $input }}"><option value="auto">auto (Σ Inhalt)</option><option value="manuell">manuell</option></select>
                        </div>
                    </div>
                </div>

                {{-- Block-Liste --}}
                <div class="relative overflow-hidden {{ $card }} p-5 space-y-3">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Inhalt <span class="text-gray-400 text-xs">({{ $kapitel->blocks->count() }})</span></h3>
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
                                                    wire:click="presetHinzu(@js($p['type']), @js($p['slug']), @js($p['label']), @js($p['preis_basis'] ?? null), {{ ($p['sichtbar'] ?? true) ? 'true' : 'false' }})"
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
                                 class="rounded-lg border {{ $block->variant_group_id ? 'border-amber-400/60' : 'border-black/5 dark:border-white/10' }} px-2 py-1 {{ $block->sichtbar ? '' : 'opacity-60' }}"
                                 style="margin-left: {{ $block->ebene * 20 }}px">
                                <div class="flex items-center gap-2 text-xs">
                                    <span class="flex flex-col -my-0.5 shrink-0">
                                        <button type="button" wire:click="blockHoch({{ $block->id }})" class="text-gray-400 hover:text-violet-500 leading-none">▲</button>
                                        <button type="button" wire:click="blockRunter({{ $block->id }})" class="text-gray-400 hover:text-violet-500 leading-none">▼</button>
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
                                                <span class="text-gray-400 tabular-nums">{{ $block->concept?->preis_pro_person_cache !== null ? '· ' . number_format((float) $block->concept->preis_pro_person_cache, 2, ',', '.') . ' €/P' : '' }}</span>
                                                @break
                                            @case('header_neutral') @case('header_frei')
                                                <span class="font-semibold">{{ $block->bezeichnung ?: '(Header)' }}</span>
                                                @break
                                            @case('header_frei_preis')
                                                <span class="font-semibold">{{ $block->bezeichnung ?: '(Header)' }}</span>
                                                <span class="text-gray-500">· {{ $block->preis_basis === 'staffel' ? 'Staffel' : number_format((float) ($block->preis_wert ?? 0), 2, ',', '.') . ' € ' . ($block->preis_basis === 'pauschal' ? 'pauschal' : '/P') }}</span>
                                                @break
                                            @case('spacer') <span class="italic text-gray-400">Leerzeile ({{ $block->hoehe ?? 'mittel' }})</span> @break
                                            @case('image') <span class="text-gray-500">🖼 Bild</span> @break
                                            @default <span class="italic">{{ \Illuminate\Support\Str::limit($block->kundentext ?? '(Text)', 80) }}</span>
                                        @endswitch
                                    </span>
                                    @if($block->variant_group_id)<button type="button" wire:click="wahlGruppeAufheben({{ $block->id }})" class="{{ $pill }} {{ $variantPill['warning'] }} shrink-0" title="aus Wahl-Gruppe">Wahl #{{ $block->variant_group_id }}</button>@endif
                                    <button type="button" wire:click="blockEbene({{ $block->id }}, -1)" class="text-gray-400 hover:text-violet-500 shrink-0" title="ausrücken">←</button>
                                    <button type="button" wire:click="blockEbene({{ $block->id }}, 1)" class="text-gray-400 hover:text-violet-500 shrink-0" title="einrücken">→</button>
                                    <button type="button" wire:click="blockSichtbar({{ $block->id }})" class="shrink-0 text-[10px] {{ $block->sichtbar ? 'text-gray-400' : 'text-amber-500' }}" title="sichtbar/intern">{{ $block->sichtbar ? '👁' : 'intern' }}</button>
                                    @if($block->type !== 'spacer')
                                        <button type="button" wire:click="blockBearbeiten({{ $block->id }})" class="shrink-0 text-gray-400 hover:text-violet-500" title="bearbeiten / Notiz">✎</button>
                                    @endif
                                    <button type="button" wire:click="blockRaus({{ $block->id }})" class="shrink-0 text-gray-400 hover:text-red-500" title="entfernen">✕</button>
                                </div>

                                @if($editBlockId === $block->id)
                                    <div class="mt-2 space-y-2 pl-6">
                                        @if(in_array($block->type, ['header_neutral', 'header_frei', 'header_frei_preis']))
                                            <input type="text" wire:model="blockForm.bezeichnung" placeholder="Header-Text" class="{{ $input }}" />
                                        @endif
                                        @if($block->type === 'header_frei_preis')
                                            <div class="flex gap-2">
                                                <select wire:model="blockForm.preis_basis" class="{{ $input }} w-32"><option value="person">pro Person</option><option value="pauschal">Pauschal</option><option value="staffel">Staffel</option></select>
                                                <input type="number" step="0.01" wire:model="blockForm.preis_wert" class="{{ $input }} w-28 text-right tabular-nums" placeholder="0,00 €" />
                                            </div>
                                        @endif
                                        @if($block->type === 'text')
                                            <textarea wire:model="blockForm.kundentext" rows="3" class="{{ $input }}" placeholder="Marketing-Text (kundensichtbar)"></textarea>
                                        @else
                                            <input type="text" wire:model="blockForm.kundentext" class="{{ $input }}" placeholder="Kundentext / Untertitel (optional)" />
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
                            <p class="text-xs text-gray-400 py-4 text-center">Noch kein Inhalt. Oben „+ Concept einfügen" oder Header/Text/Preis-Block hinzufügen.</p>
                        @endforelse
                    </div>

                    {{-- FB: Concept-Einfüge-Picker (Modal, Livewire-sicher). Angebot bleibt unberührt (hat eigenen Concepter-Editor).
                         Concepter-Such-Wissen: Suche + collapsible Kategorie-Tree + Concept-Liste; bleibt offen für Mehrfach-Einfügen.
                         Modal statt x-teleport-Drawer: Teleport entkoppelt das DOM vom Livewire-Morph → wire:model/click toter. --}}
                    <x-foodalchemist::modal name="fb-concept" title="Concept einfügen" size="max-w-3xl">
                        <input type="search" wire:model.live.debounce.300ms="conceptSuche" placeholder="Concept suchen …" class="{{ $input }} w-full mb-3" />
                        <div class="flex gap-3 min-h-[20rem]">
                            {{-- Kategorie-Tree (collapsible, wie Concepter-Browser) --}}
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
                            {{-- Concept-Liste --}}
                            <div class="flex-1 min-w-0 overflow-y-auto space-y-0.5 max-h-[26rem]">
                                @if($conceptKandidaten->isNotEmpty())
                                    @foreach($conceptKandidaten as $ck)
                                        <button type="button" wire:key="dck-{{ $ck->id }}" wire:click="conceptHinzu({{ $ck->id }})"
                                                class="w-full flex items-center justify-between gap-2 px-2 py-1.5 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                            <span class="truncate text-gray-900 dark:text-gray-100">+ {{ $ck->name }}</span>
                                            <span class="text-gray-400 tabular-nums shrink-0">{{ $ck->preis_pro_person_cache !== null ? number_format((float) $ck->preis_pro_person_cache, 2, ',', '.') . ' €' : '' }}</span>
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
                            <span class="text-[10px] text-gray-400 mr-auto">Eingefügte Concepts erscheinen links im Inhalt. Bleibt offen für mehrere.</span>
                            <button type="button" @click="$dispatch('modal.close', { name: 'fb-concept' })" class="{{ $btnGhost }}">Schließen</button>
                        </x-slot:footer>
                    </x-foodalchemist::modal>
                </div>
            @else
                <div class="{{ $card }} p-8 text-center text-sm text-gray-400">Links ein Kapitel wählen oder anlegen.</div>
            @endif
        @else
            <div class="{{ $card }} p-10 text-center text-sm text-gray-400">
                Links ein Foodbook wählen oder „+ Neues Foodbook". Das Foodbook bündelt fertige <strong>Concepts</strong> zu einem <strong>person-unabhängigen Portfolio</strong> (Kapitel, €/Person) — Pax &amp; Gesamtpreis liegen im <strong>Angebot</strong>, Einzel-Gerichte im Concepter.
            </div>
        @endif
    </x-ui-page-container>
</x-ui-page>
