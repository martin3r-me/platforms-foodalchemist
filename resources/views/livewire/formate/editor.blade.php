{{-- Format-Modul (Phase B): Voll-Editor-Modal (Fullscreen-Dark) — Identität · Editionen · Marketing-Bilder · Notizen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($sekHead = 'text-[11px] font-semibold uppercase tracking-wider text-gray-400 mb-2')

<div>
    <x-foodalchemist::modal name="formate-editor" title="Format bearbeiten" :title-name="$format?->name" fullscreen dark-canvas>
        <x-slot:actions>
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
            @if($fehler)<span class="{{ $pill }} {{ $variantPill['danger'] }}">{{ $fehler }}</span>@endif
        </x-slot:actions>

        @if($format === null)
            <p class="text-sm text-gray-500 py-10 text-center">Nichts geladen.</p>
        @else
            <x-foodalchemist::editor-tabs marker="format" action="setTab" :active="$tab" :tabs="[
                'identitaet' => 'Identität',
                'editionen' => 'Editionen',
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

            {{-- ── Tab: EDITIONEN (Concepter 2.0: Oberkapitel → Unterkapitel) ────── --}}
            @if($tab === 'editionen')
                {{-- Oberkapitel = das Format (automatisch) --}}
                <div class="mb-3 px-3 py-2 rounded-lg bg-violet-500/10 border border-violet-400/20">
                    <span class="text-[11px] uppercase tracking-wider text-violet-300">Oberkapitel (automatisch)</span>
                    <p class="text-sm font-semibold text-gray-100">{{ $format->consumer_name ?: $format->name }}</p>
                    @if($format->claim)<p class="text-xs italic text-violet-200">„{{ $format->claim }}“</p>@endif
                </div>

                <h4 class="{{ $sekHead }}">Unterkapitel · Editionen ({{ $format->editions->count() }})</h4>
                <div class="space-y-3">
                    @forelse($format->editions as $e)
                        <div wire:key="ed-{{ $e->id }}" class="rounded-xl bg-white/5 border border-white/10 p-3">
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <span class="text-[11px] uppercase tracking-wider text-gray-400">Unterkapitel {{ $loop->iteration }} · {{ $e->name }}</span>
                                <div class="flex items-center gap-1 shrink-0">
                                    <a href="{{ route('foodalchemist.concepter.index', ['sel' => $e->id]) }}" target="_blank"
                                       class="{{ $btnGhostXs ?? $btnGhost }}" title="Gerichte/Sektionen im Concepter bearbeiten">Im Concepter ↗</a>
                                    <button type="button" wire:click="editionVerschieben({{ $e->id }}, -1)" @disabled($loop->first) class="{{ $btnGhostXs ?? $btnGhost }} disabled:opacity-30" title="nach oben">↑</button>
                                    <button type="button" wire:click="editionVerschieben({{ $e->id }}, 1)" @disabled($loop->last) class="{{ $btnGhostXs ?? $btnGhost }} disabled:opacity-30" title="nach unten">↓</button>
                                    <button type="button" wire:click="editionLoesen({{ $e->id }})" class="{{ $btnGhostXs ?? $btnGhost }} text-rose-400" title="lösen">✕</button>
                                </div>
                            </div>

                            {{-- Wording pro Unterkapitel (Foodbook-Kapitel-Parität) --}}
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2 mb-2">
                                <div>
                                    <label class="{{ $label }}">Konsumenten-Titel</label>
                                    <input type="text" value="{{ $e->consumer_name }}" placeholder="{{ $e->name }}"
                                           wire:change="editionWordingSpeichern({{ $e->id }}, 'consumer_name', $event.target.value)" class="{{ $input }}" />
                                </div>
                                <div>
                                    <label class="{{ $label }}">Claim</label>
                                    <input type="text" value="{{ $e->claim }}" placeholder="z. B. „Die neue Küche der Welt“"
                                           wire:change="editionWordingSpeichern({{ $e->id }}, 'claim', $event.target.value)" class="{{ $input }}" />
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="{{ $label }}">Hinführung</label>
                                <textarea rows="2" placeholder="Kunden-Hinführung zu dieser Edition …"
                                          wire:change="editionWordingSpeichern({{ $e->id }}, 'description', $event.target.value)" class="{{ $input }}">{{ $e->description }}</textarea>
                            </div>

                            {{-- Live-Vorschau: Sektionen + Gerichte (aus dem Concept, gleiche Auflösung wie im Foodbook) --}}
                            <div class="rounded-lg bg-black/20 p-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-[10px] uppercase tracking-wider text-gray-500">Menü-Vorschau</span>
                                    @if($e->price_per_person_cache !== null)<span class="text-[11px] text-gray-400 tabular-nums">{{ number_format((float) $e->price_per_person_cache, 2, ',', '.') }} € p. P.</span>@endif
                                </div>
                                @forelse($editionMenus[$e->id] ?? [] as $g)
                                    @if($g['type'] === 'header')
                                        <p class="text-xs font-semibold text-gray-200 mt-1">{{ $g['text'] }}</p>
                                    @elseif($g['type'] === 'paket')
                                        <p class="text-xs font-medium text-gray-300 mt-1" style="margin-left:8px">{{ $g['text'] }}</p>
                                    @else
                                        <p class="text-xs text-gray-400" style="margin-left:{{ 8 + ($g['einrueckung'] ?? 0) * 12 }}px">· {{ $g['text'] }}</p>
                                    @endif
                                @empty
                                    <p class="text-[11px] text-gray-500 mt-1">Noch keine Gerichte — „Im Concepter ↗" füllen.</p>
                                @endforelse
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Noch keine Editionen. Unten neu anlegen oder ein bestehendes Konzept zuordnen.</p>
                    @endforelse
                </div>

                {{-- Neue Edition inline (mit Auto-Sektions-Gerüst) --}}
                <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-white/10">Neue Edition anlegen</h4>
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="neueEditionName" wire:keydown.enter="neueEdition" placeholder="Name der Edition (z. B. FUTURE FLAVORS) …" class="{{ $input }}" />
                    <button type="button" wire:click="neueEdition" class="{{ $btnPrimary }} shrink-0">+ Edition (mit Gerüst)</button>
                </div>
                <p class="text-[11px] text-gray-500 mt-1">Legt automatisch die Sektionen {{ implode(' · ', \Platform\FoodAlchemist\Services\FormatService::SEKTIONS_GERUEST) }} an.</p>

                {{-- Bestehendes Konzept zuordnen --}}
                <h4 class="{{ $sekHead }} mt-4 pt-3 border-t border-white/10">Bestehendes Konzept zuordnen</h4>
                <input type="search" wire:model.live.debounce.300ms="editionSuche" placeholder="Konzept suchen …" class="{{ $input }} mb-2" />
                <div class="space-y-1 max-h-60 overflow-auto">
                    @forelse($kandidaten as $k)
                        <div wire:key="kand-{{ $k->id }}" class="flex items-center justify-between gap-2 px-3 py-1.5 rounded-lg bg-white/[0.03] hover:bg-white/[0.06]">
                            <span class="text-sm text-gray-200 truncate">{{ $k->name }}</span>
                            <button type="button" wire:click="editionZuordnen({{ $k->id }})" class="{{ $btnGhostXs ?? $btnGhost }}">+ Zuordnen</button>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400">Keine freien Konzepte gefunden.</p>
                    @endforelse
                </div>
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
