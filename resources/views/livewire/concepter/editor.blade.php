{{-- M10R-3 / Doc 15 §10.4: Voll-Editor-Modal (VK-Stil) — Kopf + Tabs (Aufbau/Nährwerte/Allergene/Kalkulation/Notizen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($item = $concept ?? $paket)
@php($titel = $item?->name ?? 'Editor')
@php($tabAktiv = 'border-violet-500 text-violet-700 dark:text-violet-300')
@php($tabIdle = 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300')
@php($konfPill = ['high' => $variantPill['success'], 'medium' => $variantPill['warning'], 'low' => $variantPill['danger'], 'unknown' => $variantPill['secondary']])

<div>
    <x-foodalchemist::modal name="concepter-editor" :title="$titel" fullscreen>
        <x-slot:actions>
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
            @if($concept && ! $concept->is_vorlage)
                <button type="button" wire:click="alsVorlage" class="{{ $btnGhost }}">Als Vorlage speichern</button>
            @endif
            @if($fehler)<span class="{{ $pill }} {{ $variantPill['danger'] }}">{{ $fehler }}</span>@endif
            @if($bewertung)
                @php($scorePill = $bewertung['score'] >= 80 ? $variantPill['success'] : ($bewertung['score'] >= 50 ? $variantPill['warning'] : $variantPill['danger']))
                <span class="{{ $pill }} {{ $scorePill }}" title="Menü-Bewertung (Anteil bestandener Checks)">Score {{ $bewertung['score'] }}</span>
            @endif
        </x-slot:actions>

        @if($item === null)
            <p class="text-sm text-gray-400 py-10 text-center">Nichts geladen.</p>
        @else
            {{-- ── Kopf ──────────────────────────────────────────────────── --}}
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <div class="md:col-span-2">
                    <label class="{{ $label }}">Bezeichnung (intern)</label>
                    <input type="text" wire:model="form.name" class="{{ $input }}" />
                </div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">Konsumentenbezeichnung</label>
                    <input type="text" wire:model="form.konsumenten_name" class="{{ $input }}" placeholder="z. B. „Sommerliche Vorspeisen-Auswahl"" />
                </div>
                <div>
                    <label class="{{ $label }}">Klasse</label>
                    <input type="text" wire:model="form.klasse" list="concepter-klassen" class="{{ $input }}" placeholder="frei/wählbar" />
                    <datalist id="concepter-klassen">@foreach($klassen as $k)<option value="{{ $k }}"></option>@endforeach</datalist>
                </div>
                <div>
                    <label class="{{ $label }}">Niveau</label>
                    <select wire:model="form.niveau" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['klassisch' => 'klassisch', 'gehoben' => 'gehoben', 'haute' => 'haute'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </div>

                @if($concept)
                    <div>
                        <label class="{{ $label }}">Anlass</label>
                        <input type="text" wire:model="form.anlass" class="{{ $input }}" placeholder="z. B. Sommerfest" />
                    </div>
                    <div>
                        <label class="{{ $label }}">Kategorie</label>
                        <select wire:model="form.category_id" class="{{ $input }}">
                            <option value="">— ohne —</option>
                            @foreach($kategorienFlat as $kat)<option value="{{ $kat['id'] }}">{{ $kat['label'] }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Status</label>
                        <select wire:model="form.status" class="{{ $input }}">
                            @foreach(['draft' => 'Entwurf', 'aktiv' => 'Aktiv', 'archiviert' => 'Archiviert'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="{{ $label }}">Geschmack</label>
                        <select wire:model="form.geschmacksrichtung" class="{{ $input }}">
                            <option value="">—</option>
                            @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                @else
                    <div>
                        <label class="{{ $label }}">Rolle</label>
                        <input type="text" wire:model="form.rolle" list="concepter-rollen" class="{{ $input }}" placeholder="z. B. Vorspeise" />
                        <datalist id="concepter-rollen">@foreach($rollen as $r)<option value="{{ $r }}"></option>@endforeach</datalist>
                    </div>
                @endif
            </div>

            {{-- ── Tab-Nav ───────────────────────────────────────────────── --}}
            <div class="flex gap-4 border-b border-black/5 dark:border-white/10 mt-1">
                @foreach(['aufbau' => 'Aufbau', 'naehrwerte' => 'Nährwerte', 'allergene' => 'Allergene & Diät', 'kalkulation' => 'Kalkulation', 'notizen' => 'Notizen'] as $k => $l)
                    <button type="button" wire:click="setTab('{{ $k }}')"
                            class="px-1 py-2 text-xs font-medium border-b-2 -mb-px transition-colors {{ $tab === $k ? $tabAktiv : $tabIdle }}">{{ $l }}</button>
                @endforeach
            </div>

            {{-- ── Tab: AUFBAU ───────────────────────────────────────────── --}}
            @if($tab === 'aufbau')
                @if($concept)
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">Positionen</h3>
                        <div class="flex items-center gap-2">
                            <input type="text" wire:model="neuerSlotRolle" wire:keydown.enter="slotHinzu" placeholder="Rolle, z. B. Vorspeise" class="{{ $input }} w-48" />
                            <button type="button" wire:click="slotHinzu" class="{{ $btnGhost }}">+ Position</button>
                        </div>
                    </div>
                    <div class="space-y-2">
                        @forelse($concept->slots as $slot)
                            <div wire:key="eslot-{{ $slot->id }}" class="rounded-xl border border-black/5 dark:border-white/10 p-3 space-y-2">
                                <div class="flex items-center gap-2">
                                    <span class="flex flex-col -my-0.5 shrink-0">
                                        <button type="button" wire:click="slotHoch({{ $slot->id }})" class="text-gray-400 hover:text-violet-500 leading-none" title="hoch">▲</button>
                                        <button type="button" wire:click="slotRunter({{ $slot->id }})" class="text-gray-400 hover:text-violet-500 leading-none" title="runter">▼</button>
                                    </span>
                                    <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.rolle" wire:change="slotSpeichern({{ $slot->id }})" class="{{ $input }} w-40" placeholder="Rolle" />
                                    <input type="text" wire:model.blur="slotForm.{{ $slot->id }}.titel" wire:change="slotSpeichern({{ $slot->id }})" class="{{ $input }} flex-1" placeholder="Titel (optional)" />
                                    <button type="button" wire:click="slotRaus({{ $slot->id }})" class="text-gray-400 hover:text-red-500 px-2" title="Position entfernen">✕</button>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if($slot->paket_id && $slot->paket)
                                        <span class="{{ $pill }} {{ $variantPill['info'] }}">Paket</span>
                                        <span class="text-sm font-medium">{{ $slot->paket->name }}</span>
                                        <span class="text-gray-400 text-xs tabular-nums">{{ $slot->paket->preis_pro_person !== null ? number_format((float) $slot->paket->preis_pro_person, 2, ',', '.') . ' €' : '—' }}</span>
                                    @elseif($slot->vk_recipe_id && $slot->gericht)
                                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">festes Gericht</span>
                                        <span class="text-sm font-medium">{{ $slot->gericht->name }}</span>
                                    @else
                                        <span class="text-xs text-gray-400">leer — Paket wählen oder festes Gericht setzen</span>
                                    @endif
                                    @if($slot->paket_id || $slot->vk_recipe_id)
                                        <button type="button" wire:click="slotLeeren({{ $slot->id }})" class="text-[11px] text-gray-400 hover:text-red-500">leeren</button>
                                    @endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <select x-on:change="$wire.fuellePaket({{ $slot->id }}, $event.target.value); $event.target.value=''" class="{{ $input }} w-56">
                                        <option value="">↹ Paket (Rolle {{ $slot->rolle ?: '–' }}) …</option>
                                        @foreach(($tauschbar[$slot->id] ?? []) as $b)
                                            <option value="{{ $b->id }}">{{ $b->name }}{{ $b->preis_pro_person !== null ? ' (' . number_format((float) $b->preis_pro_person, 2, ',', '.') . ' €)' : '' }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="gerichtPicker({{ $slot->id }})" class="{{ $btnGhostXs }}">festes Gericht …</button>
                                    <button type="button" wire:click="neuesPaketImSlot({{ $slot->id }})" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="Inline ein neues Paket schnüren">+ neues Paket</button>
                                </div>
                                @if($fillSlotId === $slot->id)
                                    <div class="space-y-1 pl-1">
                                        <input type="search" wire:model.live.debounce.300ms="gerichtSuche" placeholder="Gericht suchen …" class="{{ $input }}" />
                                        @if($gerichtSuche !== '' && $kandidaten->isNotEmpty())
                                            <div class="space-y-0.5 max-h-48 overflow-y-auto">
                                                @foreach($kandidaten as $kand)
                                                    <button type="button" wire:key="ek-{{ $slot->id }}-{{ $kand->id }}" wire:click="fuelleGericht({{ $slot->id }}, {{ $kand->id }})" class="w-full flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                                        <span class="truncate">{{ $kand->name }}</span>
                                                        <span class="text-gray-400 tabular-nums shrink-0">{{ $kand->vk_netto !== null ? number_format((float) $kand->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 py-4 text-center">Noch keine Positionen. Oben eine Rolle eintragen und „+ Position".</p>
                        @endforelse
                    </div>
                @else
                    {{-- Paket: Gerichte schnüren --}}
                    <div class="flex items-center justify-between">
                        <h3 class="font-medium text-gray-900 dark:text-gray-100">Gerichte im Paket</h3>
                        <span class="text-[11px] text-gray-400">Nur Gerichte (VK) — keine Basisrezepte.</span>
                    </div>
                    <div class="space-y-1">
                        @forelse($paket->gerichte as $pg)
                            <div wire:key="epg-{{ $pg->id }}" class="flex items-center gap-2 rounded-lg border border-black/5 dark:border-white/10 px-3 py-1.5">
                                <span class="flex flex-col -my-0.5 shrink-0">
                                    <button type="button" wire:click="gerichtHoch({{ $pg->id }})" class="text-gray-400 hover:text-violet-500 leading-none">▲</button>
                                    <button type="button" wire:click="gerichtRunter({{ $pg->id }})" class="text-gray-400 hover:text-violet-500 leading-none">▼</button>
                                </span>
                                <span class="flex-1 min-w-0 truncate text-sm">{{ $pg->gericht?->name ?? '—' }}</span>
                                <span class="text-[10px] text-gray-400">Menge/Person</span>
                                <input type="number" step="0.01" min="0" wire:model.blur="" value="{{ $pg->menge }}" wire:change="gerichtMengeSpeichern({{ $pg->id }}, $event.target.value)" class="{{ $input }} w-24 text-right tabular-nums" />
                                <span class="text-gray-400 text-xs tabular-nums w-16 text-right">{{ $pg->gericht?->vk_netto !== null ? number_format((float) $pg->gericht->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                <button type="button" wire:click="gerichtRaus({{ $pg->vk_recipe_id }})" class="text-gray-400 hover:text-red-500 px-1" title="entfernen">✕</button>
                            </div>
                        @empty
                            <p class="text-xs text-gray-400 py-4 text-center">Noch keine Gerichte. Unten suchen und hinzufügen.</p>
                        @endforelse
                    </div>
                    <div class="space-y-1 pt-1">
                        <input type="search" wire:model.live.debounce.300ms="paketGerichtSuche" placeholder="Gericht suchen und hinzufügen …" class="{{ $input }}" />
                        @if($paketGerichtSuche !== '' && $paketKandidaten->isNotEmpty())
                            <div class="space-y-0.5 max-h-48 overflow-y-auto">
                                @foreach($paketKandidaten as $kand)
                                    <button type="button" wire:key="epk-{{ $kand->id }}" wire:click="gerichtHinzu({{ $kand->id }})" class="w-full flex items-center justify-between gap-2 px-2 py-1 rounded-lg text-xs hover:bg-violet-500/10 text-left">
                                        <span class="truncate">{{ $kand->name }}</span>
                                        <span class="text-gray-400 tabular-nums shrink-0">{{ $kand->vk_netto !== null ? number_format((float) $kand->vk_netto, 2, ',', '.') . ' €' : '' }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            @endif

            {{-- ── Tab: NÄHRWERTE ────────────────────────────────────────── --}}
            @if($tab === 'naehrwerte')
                @if($aggregat && $aggregat['naehrwerte']['kcal'] !== null)
                    <div class="flex items-center justify-between">
                        <span class="{{ $label }}">Nährwerte / Person (aus den Gerichten · Portionsgramm)</span>
                        <span class="{{ $pill }} {{ $konfPill[$aggregat['naehrwerte']['konfidenz']] ?? $variantPill['secondary'] }}">Konf. {{ $aggregat['naehrwerte']['konfidenz'] }}</span>
                    </div>
                    <div class="grid grid-cols-5 gap-2">
                        @foreach(['kcal' => 'kcal', 'protein_g' => 'Eiweiß (g)', 'fett_g' => 'Fett (g)', 'kh_g' => 'KH (g)', 'salz_g' => 'Salz (g)'] as $k => $l)
                            <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2 text-center">
                                <p class="text-base font-semibold tabular-nums">{{ $aggregat['naehrwerte'][$k] !== null ? number_format((float) $aggregat['naehrwerte'][$k], $k === 'kcal' ? 0 : 1, ',', '.') : '—' }}</p>
                                <p class="text-[10px] text-gray-400 uppercase">{{ $l }}</p>
                            </div>
                        @endforeach
                    </div>
                    @unless($aggregat['naehrwerte']['vollstaendig'])
                        <p class="text-[11px] text-amber-600 dark:text-amber-400">⚠ Nur {{ $aggregat['naehrwerte']['n_mit_naehrwerten'] }}/{{ $aggregat['naehrwerte']['n_gerichte'] }} Gerichte haben Nährwert + Portionsgramm — Werte sind eine Untergrenze.</p>
                    @endunless
                @else
                    <p class="text-sm text-gray-400 py-6 text-center">Keine Nährwerte — den Gerichten fehlen Werte oder Portionsgramm.</p>
                @endif
            @endif

            {{-- ── Tab: ALLERGENE & DIÄT ─────────────────────────────────── --}}
            @if($tab === 'allergene')
                @if($aggregat && $aggregat['allergene']['n_gerichte'] > 0)
                    <span class="{{ $label }}">Aggregiert aus {{ $aggregat['allergene']['n_gerichte'] }} Gerichten (kein manuelles Gruppieren)</span>
                    <div class="flex flex-wrap gap-1.5">
                        @if($aggregat['allergene']['is_vegan'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegan</span>
                        @elseif($aggregat['allergene']['is_vegetarian'])<span class="{{ $pill }} {{ $variantPill['success'] }}">vegetarisch</span>@endif
                        @if($aggregat['allergene']['is_gluten_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">glutenfrei</span>@endif
                        @if($aggregat['allergene']['is_lactose_free'])<span class="{{ $pill }} {{ $variantPill['info'] }}">laktosefrei</span>@endif
                        @if($aggregat['allergene']['is_halal'])<span class="{{ $pill }} {{ $variantPill['info'] }}">halal</span>@endif
                        @if($aggregat['allergene']['contains_pork'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Schwein</span>@endif
                        @if($aggregat['allergene']['contains_beef'])<span class="{{ $pill }} {{ $variantPill['warning'] }}">enthält Rind</span>@endif
                        <span class="{{ $pill }} {{ $konfPill[$aggregat['allergene']['konfidenz']] ?? $variantPill['secondary'] }}">Konf. {{ $aggregat['allergene']['konfidenz'] }}</span>
                    </div>
                @else
                    <p class="text-sm text-gray-400 py-6 text-center">Noch keine Gerichte für den Allergen-Rollup.</p>
                @endif
            @endif

            {{-- ── Tab: KALKULATION ──────────────────────────────────────── --}}
            @if($tab === 'kalkulation')
                @if($concept && $cockpit)
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <div class="rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2">
                            <span class="text-[10px] uppercase tracking-wider text-violet-600 dark:text-violet-400">€/Person</span>
                            <p class="text-base font-bold text-violet-700 dark:text-violet-300 tabular-nums">{{ number_format($cockpit['preis_pro_person'], 2, ',', '.') }} €</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                            <span class="{{ $dt }}">EK/Person</span>
                            <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? number_format((float) $aggregat['ek_pro_person'], 2, ',', '.') . ' €' : '—' }}</p>
                        </div>
                        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                            <span class="{{ $dt }}">Arbeitszeit</span>
                            <p class="text-xs font-semibold tabular-nums">{{ $aggregat !== null ? $aggregat['arbeitszeit_min'] . ' min' : '—' }}</p>
                        </div>
                        <div>
                            <label class="{{ $label }}">Zielpreis €/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.zielpreis_pro_person" class="{{ $input }} text-right tabular-nums" />
                        </div>
                    </div>
                    <div class="pt-2">
                        <button type="button" wire:click="zielpreisToggle" class="{{ $btnGhost }} {{ $zielModus ? 'text-violet-600 dark:text-violet-400' : '' }}">🎯 Zielpreis-Konfigurator</button>
                    </div>
                    @if($zielModus)
                        <div class="rounded-xl border border-violet-500/30 bg-violet-500/5 p-3 space-y-2">
                            <div class="flex items-end gap-2 flex-wrap">
                                <div>
                                    <label class="{{ $label }}">Komm auf €/Person</label>
                                    <input type="number" step="0.01" min="0" wire:model="zielPreis" wire:keydown.enter="zielpreisBerechnen" class="{{ $input }} w-32 text-right tabular-nums" placeholder="z. B. 36,00" />
                                </div>
                                <button type="button" wire:click="zielpreisBerechnen" class="{{ $btnPrimary }}">Vorschlag</button>
                                <span class="text-[11px] text-gray-400">Tauscht Pakete derselben Rolle; feste Gerichte = Fixkosten.</span>
                            </div>
                            @if($zielVorschlag)
                                <div class="text-xs space-y-1 pt-1 border-t border-violet-500/20">
                                    <div class="flex flex-wrap gap-x-6 gap-y-1">
                                        <span><span class="{{ $label }}">Aktuell</span> {{ number_format($zielVorschlag['aktuell'], 2, ',', '.') }} €</span>
                                        <span><span class="{{ $label }}">Vorschlag</span> <span class="font-semibold">{{ number_format($zielVorschlag['preis'], 2, ',', '.') }} €</span></span>
                                        <span><span class="{{ $label }}">Tausche</span> {{ $zielVorschlag['aenderungen'] }}</span>
                                    </div>
                                    <div class="flex gap-2 pt-1">
                                        <button type="button" wire:click="zielpreisUebernehmen" @disabled($zielVorschlag['aenderungen'] === 0) class="{{ $btnPrimary }}">Übernehmen</button>
                                        <button type="button" wire:click="$set('zielVorschlag', null)" class="{{ $btnGhost }}">Verwerfen</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                @elseif($paket)
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div>
                            <label class="{{ $label }}">Preis-Modus</label>
                            <select wire:model.live="form.preis_modus" class="{{ $input }}">
                                <option value="manuell">manuell (Buffet)</option>
                                <option value="auto">auto (Σ Gerichte)</option>
                            </select>
                        </div>
                        <div>
                            <label class="{{ $label }}">€/Person</label>
                            <input type="number" step="0.01" min="0" wire:model="form.preis_pro_person" @disabled($form['preis_modus'] === 'auto') class="{{ $input }} text-right tabular-nums" />
                        </div>
                        <div>
                            <label class="{{ $label }}">EK/Person</label>
                            <input type="number" step="0.0001" min="0" wire:model="form.ek_pro_person" @disabled($form['preis_modus'] === 'auto') class="{{ $input }} text-right tabular-nums" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Wareneinsatz %</label>
                            <input type="number" step="0.1" min="0" wire:model="form.wareneinsatz_prozent" @disabled($form['preis_modus'] === 'auto') class="{{ $input }} text-right tabular-nums" />
                        </div>
                    </div>
                    @if($form['preis_modus'] === 'auto')
                        <button type="button" wire:click="neuBerechnen" class="{{ $btnGhost }}">↻ Aus Gerichten neu berechnen</button>
                    @endif
                @endif
            @endif

            {{-- ── Tab: NOTIZEN ──────────────────────────────────────────── --}}
            @if($tab === 'notizen')
                @if($concept)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">Schreibstil (Wording)</label>
                            <select wire:model="form.schreibstil_id" class="{{ $input }}">
                                <option value="">— neutral —</option>
                                @foreach($schreibstile as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                            </select>
                            <p class="text-[10px] text-gray-400 mt-0.5">Foodbook kann den Stil je Kunde überschreiben. Text-Veredelung folgt mit LLM-Key.</p>
                        </div>
                        <div>
                            <label class="{{ $label }}">Diät-Vorgabe (KI-Brief)</label>
                            <input type="text" wire:model="form.diaet_vorgabe" class="{{ $input }}" placeholder="z. B. „je Gang ≥1 vegan"" />
                        </div>
                        <div>
                            <label class="{{ $label }}">Struktur-Vorgabe</label>
                            <input type="text" wire:model="form.struktur_vorgabe" class="{{ $input }}" placeholder="z. B. „3-Gang" / „Buffet: Salat+HG+Dessert"" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="{{ $label }}">Saison</label>
                                <input type="text" wire:model="form.saison" class="{{ $input }}" />
                            </div>
                            <div>
                                <label class="{{ $label }}">Zielgruppe/Sektor</label>
                                <input type="text" wire:model="form.zielgruppe" class="{{ $input }}" />
                            </div>
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Brief / Kontext (KI-Eingabe)</label>
                            <textarea wire:model="form.brief" rows="2" class="{{ $input }}" placeholder="Freitext-Brief für die KI-Komposition (LLM-Key folgt)"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Konsumenten-Zusatztext</label>
                            <textarea wire:model="form.zusatztext" rows="2" class="{{ $input }}"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $label }}">Interne Notiz</label>
                            <textarea wire:model="form.note" rows="2" class="{{ $input }}"></textarea>
                        </div>
                    </div>
                @else
                    <div class="space-y-3">
                        <div>
                            <label class="{{ $label }}">Beschreibung</label>
                            <textarea wire:model="form.beschreibung" rows="2" class="{{ $input }}"></textarea>
                        </div>
                        <div>
                            <label class="{{ $label }}">Interne Notiz</label>
                            <textarea wire:model="form.note" rows="2" class="{{ $input }}"></textarea>
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </x-foodalchemist::modal>
</div>
