{{-- M6-04: VK-Editor (D-6 §4.2–4.5) — Anlage aus Basisrezept + Sektionen-Edit --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- R5 (Dominique): VK-Editor nimmt wie der Basis-Editor den ganzen Bildschirm --}}
<x-foodalchemist::modal name="vk-modal" title="{{ $rezept !== null ? 'Verkaufsrezept bearbeiten' : 'Neues Verkaufsrezept' }}" :fullscreen="true">
    @if($rezept !== null)
        <x-slot:actions>
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-vk-speichern>Speichern</button>
            <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-zutaten>Komponenten bearbeiten</button>
        </x-slot:actions>
    @endif

    @if($fehler !== null)
        <p class="text-sm text-rose-600 dark:text-rose-400" data-vk-fehler>{{ $fehler }}</p>
    @endif

    @if($rezept === null)
        {{-- Anlage-Modus (DoD: VK aus Basisrezept manuell) --}}
        <x-foodalchemist::modal-section title="VK aus Basisrezept anlegen">
            <div class="space-y-3" data-vk-anlage>
                <div>
                    <label class="block {{ $label }} mb-1">Name* (Pipe-Syntax §4.4: »HG: Hauptkomponente | Komponente | …«)</label>
                    <input type="text" wire:model="neuName" class="{{ $input }}" placeholder="HG: Rinderfilet | Rotwein-Jus | Kartoffelgratin" data-vk-neu-name />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Basisrezept als erste Komponente</label>
                    <input type="search" wire:model.live.debounce.300ms="basisSuche" class="{{ $input }}" placeholder="Basisrezept suchen …" data-vk-basis-suche />
                    @foreach($basisTreffer as $b)
                        <button type="button" wire:key="bt-{{ $b->id }}" wire:click="$set('basisId', {{ $b->id }})"
                                class="block w-full text-left px-2 py-1 rounded text-sm {{ $basisId === $b->id ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}"
                                data-vk-basis-treffer="{{ $b->id }}">
                            {{ $b->name }} <span class="text-xs text-gray-400">{{ $b->yield_kg !== null ? number_format((float) $b->yield_kg, 2, ',', '.') . ' kg' : '' }} {{ $b->ek_total_eur !== null ? '· EK ' . number_format((float) $b->ek_total_eur, 2, ',', '.') . ' €' : '' }}</span>
                        </button>
                    @endforeach
                </div>
                <button type="button" wire:click="anlegen" class="{{ $btnPrimary }}" data-vk-anlegen>Anlegen</button>
                <p class="text-[10px] text-gray-400">Die ganze Charge des Basisrezepts wird als erste Komponente übernommen (Menge = Yield) — danach Komponenten & VK-Daten pflegen.</p>
            </div>
        </x-foodalchemist::modal-section>
    @else
        <x-foodalchemist::modal-section title="Stammdaten">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">Name*</label>
                    <input type="text" wire:model="form.name" class="{{ $input }}" data-vk-name />
                </div>
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">VK-Wording (kanonischer Marketing-Name, stil-neutral)</label>
                    <input type="text" wire:model="form.vk_wording_standard" class="{{ $input }}" data-vk-wording />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Geschmack</label>
                    <select wire:model="form.geschmacksrichtung" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach(['suess' => 'süß', 'herzhaft' => 'herzhaft', 'neutral' => 'neutral'] as $wert => $lbl)
                            <option value="{{ $wert }}">{{ $lbl }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Klassifikation">
            <div class="grid grid-cols-2 gap-3" data-vk-klassifikation>
                <div>
                    <label class="block {{ $label }} mb-1">Speisen-Hauptgruppe</label>
                    <select wire:model.live="hauptgruppeId" class="{{ $input }}" data-vk-hg>
                        <option value="">—</option>
                        @foreach($hauptgruppen as $hg)
                            <option value="{{ $hg->id }}">[{{ $hg->code }}] {{ $hg->bezeichnung }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Speisen-Klasse (Diätform)</label>
                    <select wire:model="form.speisen_klasse_id" class="{{ $input }}" data-vk-klasse @if($klassen->isEmpty()) disabled @endif>
                        <option value="">—</option>
                        @foreach($klassen as $k)
                            <option value="{{ $k->id }}">{{ $k->bezeichnung }} ({{ $k->diaetform }})</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Verkaufseinheit">
            <div class="grid grid-cols-3 gap-3" data-vk-einheit-block>
                <div>
                    <label class="block {{ $label }} mb-1">Einheit</label>
                    <select wire:model="form.vk_einheit_vocab_id" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach($einheiten as $e)
                            <option value="{{ $e->id }}">{{ $e->display_de ?? $e->slug }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Anzahl Einheiten (primär)</label>
                    <input type="number" step="0.1" min="0" wire:model="form.vk_anzahl_einheiten" class="{{ $input }}" data-vk-anzahl />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">g/Einheit (leer = aus Yield)</label>
                    <input type="number" step="1" min="0" wire:model="form.vk_menge_pro_einheit_g" class="{{ $input }}"
                           placeholder="{{ $cockpit['verkauft_als']['g_pro_einheit'] ?? '' }}" data-vk-g-einheit />
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Verkaufs-Block (Live-Marge)">
            <div class="grid grid-cols-3 gap-3" data-vk-verkaufsblock>
                <div>
                    <label class="block {{ $label }} mb-1">Aufschlagsklasse</label>
                    <select wire:model="form.aufschlagsklasse_id" class="{{ $input }}" data-vk-ak>
                        <option value="">—</option>
                        @foreach($aufschlagsklassen as $ak)
                            <option value="{{ $ak->id }}">{{ $ak->code }} ({{ rtrim(rtrim(number_format((float) $ak->rohaufschlag_pct, 1, '.', ''), '0'), '.') }} %){{ $ak->formel_typ === 'deckungsbeitrag' ? ' — Formel nicht definiert (W-1)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">MwSt-Satz %</label>
                    <input type="number" step="0.1" min="0" wire:model="form.mwst_satz" class="{{ $input }}" data-vk-mwst />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">VK netto manuell € (leer = aus Klasse)</label>
                    <input type="number" step="0.01" min="0" wire:model="form.vk_netto" class="{{ $input }}"
                           placeholder="{{ $cockpit['vk']['vorschlag']['vk_netto'] ?? '' }}" data-vk-netto-manuell />
                </div>
            </div>
            @if($cockpit !== null && $cockpit['vk']['vorschlag'] !== null)
                <p class="text-xs text-gray-400 mt-2" data-vk-vorschau>Vorschlag aus Klasse: {{ number_format($cockpit['vk']['vorschlag']['vk_netto'], 2, ',', '.') }} € netto · {{ $cockpit['vk']['vorschlag']['formel'] }}</p>
            @endif
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Container & Service">
            <div class="grid grid-cols-2 gap-3" data-vk-container>
                <div>
                    <label class="block {{ $label }} mb-1">Behälter warm</label>
                    <div class="flex gap-2">
                        <select wire:model="form.behaelter_warm_vocab_id" class="{{ $input }} flex-1">
                            <option value="">—</option>
                            @foreach($behaelter as $b)
                                <option value="{{ $b->id }}" @if($b->is_inactive && $form['behaelter_warm_vocab_id'] != $b->id) hidden @endif>{{ $b->name }}{{ $b->gruppe ? ' · ' . $b->gruppe : '' }}{{ $b->is_inactive ? ' (inaktiv)' : '' }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="0" wire:model="form.behaelter_warm_anzahl" class="{{ $input }} w-16" placeholder="n" />
                    </div>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Behälter kalt</label>
                    <div class="flex gap-2">
                        <select wire:model="form.behaelter_kalt_vocab_id" class="{{ $input }} flex-1">
                            <option value="">—</option>
                            @foreach($behaelter as $b)
                                <option value="{{ $b->id }}" @if($b->is_inactive && $form['behaelter_kalt_vocab_id'] != $b->id) hidden @endif>{{ $b->name }}{{ $b->gruppe ? ' · ' . $b->gruppe : '' }}{{ $b->is_inactive ? ' (inaktiv)' : '' }}</option>
                            @endforeach
                        </select>
                        <input type="number" min="0" wire:model="form.behaelter_kalt_anzahl" class="{{ $input }} w-16" placeholder="n" />
                    </div>
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Servier-Vehikel</label>
                    <select wire:model="form.servier_vehikel_vocab_id" class="{{ $input }}">
                        <option value="">—</option>
                        @foreach($vehikel as $v)
                            <option value="{{ $v->id }}" @if($v->is_inactive && $form['servier_vehikel_vocab_id'] != $v->id) hidden @endif>{{ $v->name }}{{ $v->gruppe ? ' · ' . $v->gruppe : '' }}{{ $v->is_inactive ? ' (inaktiv)' : '' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Regeneration (je Komponente, V-19)">
            <div class="space-y-1.5" data-vk-regen>
                @foreach($regenZeilen as $z)
                    <div wire:key="rg-{{ $z->id }}" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" data-regen-zeile="{{ $z->id }}">
                        <span class="flex-1 truncate">
                            <span class="font-medium">{{ $z->komponente_label }}</span>
                            <span class="text-gray-400">· {{ $z->geraet ?? 'kalt servieren' }}{{ $z->temp_c !== null ? " · {$z->temp_c} °C" : '' }}{{ $z->dauer_min !== null ? " · {$z->dauer_min} min" : '' }}{{ $z->kerntemp_c !== null ? " · KT {$z->kerntemp_c} °C" : '' }}{{ $z->hinweis ? " · {$z->hinweis}" : '' }}</span>
                        </span>
                        <button type="button" wire:click="regenSchieben({{ $z->id }}, -1)" class="{{ $btnGhostXs }}" title="hoch">↑</button>
                        <button type="button" wire:click="regenSchieben({{ $z->id }}, 1)" class="{{ $btnGhostXs }}" title="runter">↓</button>
                        <button type="button" wire:click="regenBearbeiten({{ $z->id }})" class="{{ $btnGhostXs }}">Edit</button>
                        <button type="button" wire:click="regenLoeschen({{ $z->id }})" class="{{ $btnGhostXs }} text-rose-500">✕</button>
                    </div>
                @endforeach
                <div class="grid grid-cols-6 gap-2 pt-1" data-regen-form>
                    <input type="text" wire:model="regenForm.komponente_label" class="{{ $input }} col-span-2" placeholder="Komponente (z. B. Gesamt)" />
                    <select wire:model="regenForm.geraet_vocab_id" class="{{ $input }}">
                        <option value="">kalt</option>
                        @foreach($geraete as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach
                    </select>
                    <input type="number" wire:model="regenForm.temp_c" class="{{ $input }}" placeholder="°C" />
                    <input type="number" wire:model="regenForm.dauer_min" class="{{ $input }}" placeholder="min" />
                    <input type="number" wire:model="regenForm.kerntemp_c" class="{{ $input }}" placeholder="KT °C" />
                    <input type="text" wire:model="regenForm.hinweis" class="{{ $input }} col-span-5" placeholder="Hinweis (z. B. abgedeckt, nach 8 min schwenken)" />
                    <button type="button" wire:click="regenSpeichern" class="{{ $btnGhostXs }}" data-regen-speichern>{{ $regenEditId !== null ? 'Aktualisieren' : '+ Zeile' }}</button>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Verwendungsnachweise (Kunde × Marketing-Name)">
            <div class="space-y-1.5" data-vk-kunden>
                @foreach($kunden as $k)
                    <div wire:key="kn-{{ $k->id }}" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200" data-kunde-zeile="{{ $k->id }}">
                        <span class="flex-1 truncate"><span class="font-medium">{{ $k->customer_name }}</span> <span class="text-gray-400">· {{ $k->marketing_name }}</span></span>
                        <button type="button" wire:click="kundeLoeschen({{ $k->id }})" class="{{ $btnGhostXs }} text-rose-500">✕</button>
                    </div>
                @endforeach
                <div class="grid grid-cols-5 gap-2 pt-1">
                    <input type="text" wire:model="kundeName" class="{{ $input }} col-span-2" placeholder="Kunde" data-kunde-name />
                    <input type="text" wire:model="kundeMarketing" class="{{ $input }} col-span-2" placeholder="Marketing-Name beim Kunden" data-kunde-marketing />
                    <button type="button" wire:click="kundeHinzufuegen" class="{{ $btnGhostXs }}" data-kunde-hinzufuegen>+ Nachweis</button>
                </div>
            </div>
        </x-foodalchemist::modal-section>
    @endif
</x-foodalchemist::modal>
