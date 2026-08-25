{{-- Format-Modul (Phase B): Voll-Editor-Modal (Fullscreen-Dark) — Identität · Editionen · Marketing-Bilder · Notizen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($sekHead = 'text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2')

<div>
    <x-foodalchemist::modal name="formate-editor" title="Format bearbeiten" :title-name="$format?->name" fullscreen dark-canvas>
        <x-slot:actions>
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
            @if($format)
                <a href="{{ route('foodalchemist.formate.dokument', ['id' => $format->id]) }}" target="_blank"
                   class="{{ $btnGhost }}" title="Schöne Kunden-Ausgabe (Druck/PDF)">@svg('heroicon-o-printer', 'w-4 h-4') Druck/Karte</a>
                <a href="{{ route('foodalchemist.formate.report', ['id' => $format->id, 'profil' => 'voll']) }}" target="_blank"
                   class="{{ $btnGhost }}" title="Technischer Report mit Profilen + Filtern (Editionen → Gericht→Basisrezept→GP→LA-Kaskade)">@svg('heroicon-o-document-text', 'w-4 h-4') Report</a>
            @endif
            @if($fehler)<span class="{{ $pill }} {{ $variantPill['danger'] }}">{{ $fehler }}</span>@endif
        </x-slot:actions>

        @if($format === null)
            <p class="text-sm text-gray-500 py-10 text-center">Nichts geladen.</p>
        @else
            <x-foodalchemist::editor-tabs marker="format" action="setTab" :active="$tab" :tabs="[
                'identitaet' => 'Identität',
                'editionen' => 'Aufbau',
                'kalkulation' => 'Kalkulation',
                'bilder' => 'Marketing-Bilder',
                'notizen' => 'Notizen',
            ]" />

            {{-- ── Tab: IDENTITÄT ──────────────────────────────────── --}}
            @if($tab === 'identitaet')
                <h4 class="{{ $sekHead }}">Identität</h4>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                    <div class="md:col-span-3">
                        <label class="{{ $label }}">Bezeichnung (intern)</label>
                        <input type="text" wire:model="form.name" class="{{ $input }}" placeholder="z. B. „CHEFS.CORNER – WORLD ON A PLATE“" />
                    </div>
                    <div class="md:col-span-3">
                        <label class="{{ $label }}">Konsumentenbezeichnung</label>
                        <input type="text" wire:model="form.consumer_name" class="{{ $input }}" />
                    </div>
                    <div class="md:col-span-3">
                        <label class="{{ $label }}">Claim / Tagline</label>
                        <input type="text" wire:model="form.claim" class="{{ $input }}" placeholder="z. B. „WORLD ON A PLATE“" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="{{ $label }}">Herkunft</label>
                        <select wire:model="form.origin" class="{{ $input }}">
                            <option value="">—</option>
                            <option value="eigen">Eigen</option>
                            <option value="gruppe">Gruppe</option>
                            <option value="kunde">Kunde (IP-geschützt)</option>
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">
                            <option value="draft">Entwurf</option>
                            <option value="active">Aktiv</option>
                            <option value="archiviert">Archiv</option>
                        </select>
                    </div>
                    <div class="md:col-span-3" x-data x-show="$wire.form.origin === 'kunde'" x-cloak>
                        <label class="{{ $label }}">Kunde (Besitzer)</label>
                        <input type="text" wire:model="form.customer" class="{{ $input }}" placeholder="Kunden-IP — nur für diesen Kunden verwendbar" />
                    </div>
                </div>

                {{-- F1: Dimensionen (Facetten) — dieselben wie am Concept, aus den Einstellungen gepflegt. --}}
                <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-black/5">Dimensionen</h4>
                <p class="text-[11px] text-gray-500 mb-2">Dieselben Concept-Dimensionen (in den Einstellungen gepflegt) — für Filter + Einordnung des Formats.</p>
                <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                    <div class="md:col-span-3">
                        <label class="{{ $label }}">Servierform</label>
                        <select wire:model="form.serving_form_id" wire:change="speichern" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                        </select>
                    </div>
                    <div class="md:col-span-3">
                        <label class="{{ $label }}">Eventtyp</label>
                        <select wire:model="form.event_type_id" wire:change="speichern" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="flex flex-wrap items-start gap-x-4 gap-y-2 mt-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $label }} !mb-0 mr-1">Einsatzmoment</span>
                        @foreach($einsatzmomente as $em)
                            <button type="button" wire:click="toggleFacette('einsatzmoment_ids', {{ $em->id }})"
                                class="{{ $pill }} {{ in_array($em->id, $form['einsatzmoment_ids'] ?? []) ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $em->name }}</button>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="{{ $label }} !mb-0 mr-1">Saison</span>
                        @foreach($saisons as $sa)
                            <button type="button" wire:click="toggleFacette('saison_ids', {{ $sa->id }})"
                                class="{{ $pill }} {{ in_array($sa->id, $form['saison_ids'] ?? []) ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $sa->name }}</button>
                        @endforeach
                    </div>
                    @if($zielgruppen->isNotEmpty())
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="{{ $label }} !mb-0 mr-1">Zielgruppe</span>
                            @foreach($zielgruppen as $zg)
                                <button type="button" wire:click="toggleFacette('target_group_ids', {{ $zg->id }})"
                                    class="{{ $pill }} {{ in_array($zg->id, $form['target_group_ids'] ?? []) ? $variantPill['primary'] : $variantPill['secondary'] }}">{{ $zg->name }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-black/5">Marken-Story</h4>
                <textarea wire:model="form.story" rows="6" class="{{ $input }}" placeholder="Die Marketing-Story des Formats (Kunden-/Präsentationstext)…"></textarea>
            @endif

            {{-- ── Tab: AUFBAU (F2, „Conceptor eine Ebene höher": Concept-Referenzen + Struktur-Blöcke) ──
                 F6: zweispaltig wie der Conceptor — LINKS die Positionen, RECHTS ein Picker-Rail mit den
                 Reitern „Concepts | Pakete" (jeweils Suche + Filter + Liste mit „+ Einfügen"). --}}
            @if($tab === 'editionen')
                {{-- Oberkapitel = das Format (automatisch, volle Breite) --}}
                <div class="mb-3 px-3 py-2 rounded-lg bg-violet-500/10 border border-violet-400/20">
                    <span class="text-[11px] uppercase tracking-wider text-violet-300">Oberkapitel (automatisch)</span>
                    <p class="text-sm font-semibold text-gray-100">{{ $format->consumer_name ?: $format->name }}</p>
                    @if($format->claim)<p class="text-xs italic text-violet-200">„{{ $format->claim }}“</p>@endif
                </div>

                @php($conceptSlots = $aufbauSlots->where('type', 'concept'))
                <div class="flex flex-col md:flex-row gap-4 items-start">
                    {{-- ═══ LINKS: Positionen ═══ --}}
                    <div class="flex-1 min-w-0 w-full">
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="{{ $sekHead }} !mb-0">Aufbau · {{ $conceptSlots->count() }} {{ $conceptSlots->count() === 1 ? 'Edition' : 'Editionen' }} · {{ $aufbauSlots->count() }} {{ $aufbauSlots->count() === 1 ? 'Position' : 'Positionen' }}</h4>
                            @if($einfuegenNachId !== null)
                                <span class="inline-flex items-center gap-1 text-[11px] text-violet-300">
                                    📍 Einfügen unter markierter Zeile
                                    <button type="button" wire:click="einfuegenZiel(null)" class="underline decoration-dotted hover:text-violet-100">ans Ende</button>
                                </span>
                            @endif
                        </div>

                        <div class="space-y-3 mt-2">
                            @forelse($aufbauSlots as $s)
                                @php($istZiel = $einfuegenNachId === $s->id)
                                @if($s->type === 'concept')
                                    @php($c = $s->concept)
                                    @php($istPaketSlot = ($c?->kind ?? null) === 'paket')
                                    <div wire:key="slot-{{ $s->id }}" class="rounded-xl bg-white/5 border {{ $istZiel ? 'border-violet-400' : 'border-white/10' }} p-3">
                                        <div class="flex items-center justify-between gap-2 mb-2">
                                            <span class="inline-flex items-center gap-1.5 text-[11px] uppercase tracking-wider text-gray-400 min-w-0">
                                                @if($istPaketSlot)<span class="{{ $pill }} {{ $variantPill['info'] }} shrink-0 normal-case tracking-normal">@svg('heroicon-o-archive-box', 'w-3 h-3 inline-block align-middle') Paket</span>@endif
                                                <span class="truncate">Position {{ $loop->iteration }} · {{ $c?->name ?? '— (Concept entfernt)' }}</span>
                                            </span>
                                            <div class="flex items-center gap-1 shrink-0">
                                                @if($c)
                                                    <a href="{{ route('foodalchemist.concepter.index', ['edit' => $c->id]) }}" target="_blank"
                                                       class="{{ $btnGhostXs ?? $btnGhost }}" title="Direkt im Concepter-Editor öffnen (Gerichte/Sektionen bearbeiten)">Im Concepter ↗</a>
                                                @endif
                                                <button type="button" wire:click="einfuegenZiel({{ $s->id }})" class="{{ $btnGhostXs ?? $btnGhost }} {{ $istZiel ? 'text-violet-300' : '' }}" title="{{ $istZiel ? 'Einfügeziel aktiv (Klick = abwählen)' : 'Hier einfügen — die nächste neue Position landet unter dieser Zeile' }}">📍</button>
                                                <button type="button" wire:click="slotHochRunter({{ $s->id }}, -1)" @disabled($loop->first) class="{{ $btnGhostXs ?? $btnGhost }} disabled:opacity-30" title="nach oben">↑</button>
                                                <button type="button" wire:click="slotHochRunter({{ $s->id }}, 1)" @disabled($loop->last) class="{{ $btnGhostXs ?? $btnGhost }} disabled:opacity-30" title="nach unten">↓</button>
                                                <button type="button" wire:click="slotEntfernen({{ $s->id }})" class="{{ $btnGhostXs ?? $btnGhost }} text-rose-400" title="entfernen">✕</button>
                                            </div>
                                        </div>

                                        @if($c)
                                            {{-- Wording des referenzierten Concepts (geteilt — editiert das Concept selbst) --}}
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">
                                                <div>
                                                    <label class="{{ $label }}">Konsumenten-Titel</label>
                                                    <input type="text" value="{{ $c->consumer_name }}" placeholder="{{ $c->name }}"
                                                           wire:change="conceptWordingSpeichern({{ $c->id }}, 'consumer_name', $event.target.value)" class="{{ $input }}" />
                                                </div>
                                                <div>
                                                    <label class="{{ $label }}">Claim</label>
                                                    <input type="text" value="{{ $c->claim }}" placeholder="z. B. „Die neue Küche der Welt“"
                                                           wire:change="conceptWordingSpeichern({{ $c->id }}, 'claim', $event.target.value)" class="{{ $input }}" />
                                                </div>
                                            </div>
                                            <div class="mb-2">
                                                <label class="{{ $label }}">Hinführung</label>
                                                <textarea rows="2" placeholder="Kunden-Hinführung zu dieser Edition …"
                                                          wire:change="conceptWordingSpeichern({{ $c->id }}, 'description', $event.target.value)" class="{{ $input }}">{{ $c->description }}</textarea>
                                            </div>

                                            {{-- Live-Vorschau: Sektionen + Gerichte (aus dem Concept, gleiche Auflösung wie im Foodbook).
                                                 #7: Header fett, Paket-Zeilen eingerückt + Badge, Gerichte gedämpft (Concepter-Menü-Parität). --}}
                                            <div class="rounded-lg bg-black/20 p-2">
                                                <div class="flex items-center justify-between">
                                                    <span class="text-[10px] uppercase tracking-wider text-gray-500">Menü-Vorschau</span>
                                                    @if($c->price_per_person_cache !== null)<span class="text-[11px] text-gray-400 tabular-nums">{{ number_format((float) $c->price_per_person_cache, 2, ',', '.') }} € p. P.</span>@endif
                                                </div>
                                                <div class="mt-1 space-y-0.5">
                                                    @forelse($editionMenus[$s->id] ?? [] as $g)
                                                        @if($g['type'] === 'header')
                                                            <p class="text-xs font-semibold text-gray-100 mt-1.5 first:mt-0" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 14 }}px">{{ $g['text'] }}</p>
                                                        @elseif($g['type'] === 'paket')
                                                            <p class="text-[11px] font-medium text-violet-200 mt-1 flex items-center gap-1" style="margin-left:{{ ($g['einrueckung'] ?? 0) * 14 }}px">
                                                                <span class="{{ $pill }} {{ $variantPill['info'] }} normal-case">Paket</span>{{ $g['text'] }}
                                                                @if(($g['preis'] ?? null) !== null)<span class="ml-auto text-gray-400 tabular-nums">{{ number_format((float) $g['preis'], 2, ',', '.') }} €</span>@endif
                                                            </p>
                                                        @else
                                                            <p class="text-xs text-gray-400/90 leading-snug" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 14 }}px">· {{ $g['text'] }}</p>
                                                        @endif
                                                    @empty
                                                        <p class="text-[11px] text-gray-500 mt-1">Noch keine Gerichte — „Im Concepter ↗" füllen.</p>
                                                    @endforelse
                                                </div>
                                            </div>
                                        @else
                                            <p class="text-[11px] text-rose-300">Referenziertes Concept nicht mehr sichtbar — Position entfernen.</p>
                                        @endif
                                    </div>
                                @else
                                    {{-- Struktur-Block: header / text / spacer --}}
                                    <div wire:key="slot-{{ $s->id }}" class="rounded-xl bg-violet-500/[0.06] border {{ $istZiel ? 'border-violet-400' : 'border-white/10' }} p-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex-1 min-w-0">
                                                @if($s->type === 'header')
                                                    <label class="{{ $label }}">Überschrift</label>
                                                    <input type="text" value="{{ $s->title }}" placeholder="Überschrift …"
                                                           wire:change="blockSpeichern({{ $s->id }}, 'title', $event.target.value)" class="{{ $input }} font-medium" />
                                                @elseif($s->type === 'text')
                                                    <label class="{{ $label }}">Freitext</label>
                                                    <textarea rows="2" placeholder="Freitext …"
                                                              wire:change="blockSpeichern({{ $s->id }}, 'text_content', $event.target.value)" class="{{ $input }}">{{ $s->text_content }}</textarea>
                                                @else
                                                    <label class="{{ $label }}">Leerzeile</label>
                                                    <select wire:change="blockSpeichern({{ $s->id }}, 'height', $event.target.value)" class="{{ $input }} w-40">
                                                        @foreach(['klein' => 'Klein', 'mittel' => 'Mittel', 'gross' => 'Groß'] as $h => $txt)
                                                            <option value="{{ $h }}" @selected(($s->height ?? 'mittel') === $h)>{{ $txt }}</option>
                                                        @endforeach
                                                    </select>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-1 shrink-0 pt-4">
                                                <button type="button" wire:click="einfuegenZiel({{ $s->id }})" class="{{ $btnGhostXs ?? $btnGhost }} {{ $istZiel ? 'text-violet-300' : '' }}" title="{{ $istZiel ? 'Einfügeziel aktiv (Klick = abwählen)' : 'Hier einfügen' }}">📍</button>
                                                <button type="button" wire:click="slotHochRunter({{ $s->id }}, -1)" @disabled($loop->first) class="{{ $btnGhostXs ?? $btnGhost }} disabled:opacity-30" title="nach oben">↑</button>
                                                <button type="button" wire:click="slotHochRunter({{ $s->id }}, 1)" @disabled($loop->last) class="{{ $btnGhostXs ?? $btnGhost }} disabled:opacity-30" title="nach unten">↓</button>
                                                <button type="button" wire:click="slotEntfernen({{ $s->id }})" class="{{ $btnGhostXs ?? $btnGhost }} text-rose-400" title="entfernen">✕</button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @empty
                                <p class="text-xs text-gray-400">Noch kein Aufbau. Rechts eine neue Edition anlegen, ein Concept/Paket einfügen oder einen Struktur-Block setzen.</p>
                            @endforelse
                        </div>
                    </div>

                    {{-- ═══ RECHTS: Picker-Rail (Conceptor-Stil) ═══ --}}
                    <aside class="w-full md:w-80 lg:w-96 shrink-0 rounded-xl bg-white/5 border border-white/10 p-3 space-y-3 md:sticky md:top-0 self-start">
                        {{-- Neue Edition (mit Auto-Sektions-Gerüst) --}}
                        <div>
                            <h4 class="{{ $sekHead }}">Neue Edition</h4>
                            <div class="flex items-center gap-1.5">
                                <input type="text" wire:model="neueEditionName" wire:keydown.enter="neueEdition" placeholder="Name (z. B. FUTURE FLAVORS) …" class="{{ $input }}" />
                                <button type="button" wire:click="neueEdition" class="{{ $btnPrimary }} shrink-0 !px-2.5 !py-1.5 !text-[11px]" title="Legt automatisch die Sektionen {{ implode(' · ', \Platform\FoodAlchemist\Services\FormatService::SEKTIONS_GERUEST) }} an">+ Gerüst</button>
                            </div>
                        </div>

                        {{-- Struktur-Blöcke --}}
                        <div class="pt-2 border-t border-white/10">
                            <h4 class="{{ $sekHead }}">Struktur</h4>
                            <div class="flex flex-wrap items-center gap-1.5">
                                <button type="button" wire:click="blockHinzu('header')" class="{{ $btnGhostXs ?? $btnGhost }}">+ Header</button>
                                <button type="button" wire:click="blockHinzu('text')" class="{{ $btnGhostXs ?? $btnGhost }}">+ Text</button>
                                <button type="button" wire:click="blockHinzu('spacer')" class="{{ $btnGhostXs ?? $btnGhost }}">+ Leerzeile</button>
                            </div>
                        </div>

                        {{-- Picker: Concept ODER Paket booken --}}
                        <div class="pt-2 border-t border-white/10">
                            <div class="flex gap-1 mb-2" data-formate-picker-tabs>
                                <button type="button" wire:click="setPickerTab('concept')" class="{{ $pill }} {{ $pickerTab === 'concept' ? $variantPill['primary'] : $variantPill['secondary'] }}">Concepts</button>
                                <button type="button" wire:click="setPickerTab('paket')" class="{{ $pill }} {{ $pickerTab === 'paket' ? $variantPill['primary'] : $variantPill['secondary'] }}">Pakete</button>
                            </div>

                            @if($pickerTab === 'paket')
                                <input type="search" wire:model.live.debounce.300ms="paketSuche" placeholder="Paket suchen …" class="{{ $input }} mb-1.5" />
                                <select wire:model.live="pickerKlasse" class="{{ $input }} mb-1.5" data-formate-picker-klasse>
                                    <option value="">Alle Klassen</option>
                                    @foreach($pickerPaketKlassen as $kl)<option value="{{ $kl }}">{{ $kl }}</option>@endforeach
                                </select>
                            @else
                                <input type="search" wire:model.live.debounce.300ms="editionSuche" placeholder="Concept suchen …" class="{{ $input }} mb-1.5" />
                                <select wire:model.live="pickerKlasse" class="{{ $input }} mb-1.5" data-formate-picker-klasse>
                                    <option value="">Alle Klassen</option>
                                    @foreach($pickerKlassen as $kl)<option value="{{ $kl }}">{{ $kl }}</option>@endforeach
                                </select>
                            @endif

                            {{-- Facetten-Filter als Dropdowns (kompakt statt Chip-Wand — Dominique 2026-08-25) --}}
                            <div class="grid grid-cols-2 gap-1.5 mb-2" data-formate-picker-facetten>
                                @if($servierformen->isNotEmpty())
                                    <select wire:model.live="pickerServierform" class="{{ $input }} !text-xs" title="Servierform">
                                        <option value="">Servierform</option>
                                        @foreach($servierformen as $sf)<option value="{{ $sf->id }}">{{ $sf->label }}</option>@endforeach
                                    </select>
                                @endif
                                @if($eventtypen->isNotEmpty())
                                    <select wire:model.live="pickerEventtyp" class="{{ $input }} !text-xs" title="Eventtyp">
                                        <option value="">Eventtyp</option>
                                        @foreach($eventtypen as $et)<option value="{{ $et->id }}">{{ $et->name }}</option>@endforeach
                                    </select>
                                @endif
                                @if($einsatzmomente->isNotEmpty())
                                    <select wire:model.live="pickerMoment" class="{{ $input }} !text-xs" title="Einsatzmoment">
                                        <option value="">Einsatzmoment</option>
                                        @foreach($einsatzmomente as $em)<option value="{{ $em->id }}">{{ $em->name }}</option>@endforeach
                                    </select>
                                @endif
                                @if($saisons->isNotEmpty())
                                    <select wire:model.live="pickerSaison" class="{{ $input }} !text-xs" title="Saison">
                                        <option value="">Saison</option>
                                        @foreach($saisons as $sa)<option value="{{ $sa->id }}">{{ $sa->name }}</option>@endforeach
                                    </select>
                                @endif
                            </div>

                            {{-- Kandidaten-Liste (je Reiter) --}}
                            @if($pickerTab === 'paket')
                                <div class="space-y-1 max-h-[46vh] overflow-auto -mx-1 px-1" data-formate-paket-liste>
                                    @forelse($paketKandidaten as $k)
                                        <div wire:key="pkand-{{ $k->id }}" class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg bg-white/[0.03] hover:bg-white/[0.06]">
                                            <span class="min-w-0 flex-1 truncate text-sm text-gray-200" title="{{ $k->name }}">
                                                <span class="{{ $pill }} {{ $variantPill['info'] }} mr-1">PK</span>{{ $k->consumer_name ?: $k->name }}
                                            </span>
                                            @if($k->price_per_person_cache !== null)<span class="shrink-0 text-[10px] text-gray-500 tabular-nums">{{ number_format((float) $k->price_per_person_cache, 2, ',', '.') }} €</span>@endif
                                            <button type="button" wire:click="conceptEinfuegen({{ $k->id }})" class="{{ $btnGhostXs ?? $btnGhost }} shrink-0">+ Einfügen</button>
                                        </div>
                                    @empty
                                        <p class="text-xs text-gray-400 px-1">Keine aktiven Pakete gefunden.</p>
                                    @endforelse
                                </div>
                            @else
                                <div class="space-y-1 max-h-[46vh] overflow-auto -mx-1 px-1" data-formate-concept-liste>
                                    @forelse($kandidaten as $k)
                                        <div wire:key="kand-{{ $k->id }}" class="flex items-center justify-between gap-2 px-2.5 py-1.5 rounded-lg bg-white/[0.03] hover:bg-white/[0.06]">
                                            <span class="min-w-0 flex-1 truncate text-sm text-gray-200" title="{{ $k->name }}">{{ $k->consumer_name ?: $k->name }}</span>
                                            @if($k->price_per_person_cache !== null)<span class="shrink-0 text-[10px] text-gray-500 tabular-nums">{{ number_format((float) $k->price_per_person_cache, 2, ',', '.') }} €</span>@endif
                                            <button type="button" wire:click="conceptEinfuegen({{ $k->id }})" class="{{ $btnGhostXs ?? $btnGhost }} shrink-0">+ Einfügen</button>
                                        </div>
                                    @empty
                                        <p class="text-xs text-gray-400 px-1">Keine aktiven Konzepte gefunden.</p>
                                    @endforelse
                                </div>
                            @endif
                        </div>
                    </aside>
                </div>
            @endif

            {{-- ── Tab: KALKULATION (F4 — wie performen die Editionen?) ── --}}
            @if($tab === 'kalkulation')
                <p class="text-[11px] text-gray-500 mb-2">Wie performen die Editionen (Concepts)? Read-only aus den Concept-Kalkulationen — €/Person, Wareneinsatz, W%. Preise pflegst du im jeweiligen Concept.</p>

                {{-- Format-Rollup --}}
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                    @php($rollup = [
                        ['Editionen', $kalkSumme['n']],
                        ['Preisspanne €/P', ($kalkSumme['min'] !== null ? number_format($kalkSumme['min'], 2, ',', '.') . '–' . number_format($kalkSumme['max'], 2, ',', '.') . ' €' : '—')],
                        ['Ø €/P', ($kalkSumme['avg'] !== null ? number_format($kalkSumme['avg'], 2, ',', '.') . ' €' : '—')],
                        ['Ø W%', ($kalkSumme['avg_w'] !== null ? number_format($kalkSumme['avg_w'], 1, ',', '.') . ' %' : '—')],
                    ])
                    @foreach($rollup as [$lbl, $val])
                        <div class="rounded-xl bg-white/5 border border-white/10 px-3 py-2">
                            <p class="text-[10px] uppercase tracking-wider text-gray-500">{{ $lbl }}</p>
                            <p class="text-lg font-semibold text-gray-100 tabular-nums">{{ $val }}</p>
                        </div>
                    @endforeach
                </div>

                <table class="{{ $table ?? 'w-full text-sm' }}">
                    <thead>
                        <tr class="text-left text-[11px] uppercase tracking-wider text-gray-500 border-b border-white/10">
                            <th class="py-1.5 pr-2 w-full">Edition</th>
                            <th class="py-1.5 px-2 text-right whitespace-nowrap">€/Person</th>
                            <th class="py-1.5 px-2 text-right whitespace-nowrap">EK/Person</th>
                            <th class="py-1.5 pl-2 text-right whitespace-nowrap">W%</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($kalkZeilen as $z)
                            <tr class="border-b border-white/5">
                                <td class="py-1.5 pr-2 text-gray-200 truncate">{{ $z['name'] }}</td>
                                <td class="py-1.5 px-2 text-right tabular-nums text-emerald-300">{{ $z['vk'] !== null ? number_format($z['vk'], 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="py-1.5 px-2 text-right tabular-nums text-gray-400">{{ $z['ek'] !== null ? number_format($z['ek'], 2, ',', '.') . ' €' : '—' }}</td>
                                <td class="py-1.5 pl-2 text-right tabular-nums {{ ($z['w'] !== null && $z['w'] > 35) ? 'text-rose-400' : 'text-gray-300' }}">{{ $z['w'] !== null ? number_format($z['w'], 1, ',', '.') . ' %' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-xs text-gray-500">Noch keine Editionen — im Aufbau-Tab Konzepte einfügen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif

            {{-- ── Tab: MARKETING-BILDER ───────────────────────────── --}}
            @if($tab === 'bilder')
                <h4 class="{{ $sekHead }}">Bild hochladen</h4>
                <input type="file" wire:model="bildUpload" accept="image/*"
                       class="block w-full text-sm text-gray-300 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:bg-violet-500/20 file:text-violet-200 file:cursor-pointer" />
                <div wire:loading wire:target="bildUpload" class="text-xs text-violet-300 mt-1">Lade hoch …</div>

                <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-white/10">Bildwelt ({{ $format->images->count() }})</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    @forelse($format->images as $img)
                        <div wire:key="img-{{ $img->id }}" class="rounded-xl overflow-hidden border {{ $img->is_hero ? 'border-violet-400' : 'border-white/10' }} bg-white/5">
                            <div class="relative">
                                <img src="{{ $img->url() }}" alt="{{ $img->caption }}" class="w-full h-32 object-cover" />
                                @if($img->is_hero)<span class="absolute top-1 left-1 {{ $pill }} {{ $variantPill['primary'] }}">Hero</span>@endif
                            </div>
                            <div class="p-2 space-y-1">
                                <input type="text" value="{{ $img->caption }}" wire:change="bildCaption({{ $img->id }}, $event.target.value)"
                                       placeholder="Bildunterschrift …" class="{{ $input }} text-xs" />
                                <div class="flex items-center justify-between gap-1">
                                    @unless($img->is_hero)
                                        <button type="button" wire:click="heroSetzen({{ $img->id }})" class="{{ $btnGhostXs ?? $btnGhost }}">Als Hero</button>
                                    @else
                                        <span class="text-[11px] text-violet-300">Marken-Hero</span>
                                    @endunless
                                    <button type="button" wire:click="bildLoeschen({{ $img->id }})" wire:confirm="Bild löschen?" class="{{ $btnGhostXs ?? $btnGhost }} text-rose-400">Löschen</button>
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 col-span-full">Noch keine Bilder. Oben eins hochladen — das erste wird automatisch Hero.</p>
                    @endforelse
                </div>
            @endif

            {{-- ── Tab: NOTIZEN ────────────────────────────────────── --}}
            @if($tab === 'notizen')
                <h4 class="{{ $sekHead }}">Interne Notiz</h4>
                <textarea wire:model="form.note" rows="8" class="{{ $input }}" placeholder="Interne Notizen zum Format …"></textarea>
            @endif
        @endif
    </x-foodalchemist::modal>
</div>
