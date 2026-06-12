{{-- M6-04: VK-Editor (D-6 §4.2–4.5) — Anlage aus Basisrezept + Sektionen-Edit --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

{{-- R5 (Dominique): VK-Editor nimmt wie der Basis-Editor den ganzen Bildschirm --}}
<x-foodalchemist::modal name="vk-modal" title="{{ $rezept !== null ? 'Verkaufsrezept bearbeiten' : 'Neues Verkaufsrezept' }}" size="max-w-[100rem]">
    @if($rezept !== null)
        <x-slot:actions>
            <button type="button" wire:click="speichern" class="{{ $btnPrimary }}" data-vk-speichern>Speichern</button>
            <button type="button" wire:click="$dispatch('zutaten-editor.oeffnen', { id: {{ $rezept->id }} })" class="{{ $btnGhostXs }}" data-vk-zutaten>Komponenten bearbeiten</button>
        </x-slot:actions>
    @endif

    @if($fehler !== null)
        <p class="text-xs text-rose-600 dark:text-rose-400" data-vk-fehler>{{ $fehler }}</p>
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
                                class="block w-full text-left px-2 py-1 rounded text-xs {{ $basisId === $b->id ? 'bg-violet-500/10 text-violet-700 dark:text-violet-300' : 'text-gray-700 dark:text-gray-200 hover:bg-black/[0.03] dark:hover:bg-white/5' }}"
                                data-vk-basis-treffer="{{ $b->id }}">
                            {{ $b->name }} <span class="text-[11px] text-gray-400">{{ $b->yield_kg !== null ? number_format((float) $b->yield_kg, 2, ',', '.') . ' kg' : '' }} {{ $b->ek_total_eur !== null ? '· EK ' . number_format((float) $b->ek_total_eur, 2, ',', '.') . ' €' : '' }}</span>
                        </button>
                    @endforeach
                </div>
                <button type="button" wire:click="anlegen" class="{{ $btnPrimary }}" data-vk-anlegen>Anlegen</button>
                <p class="text-[10px] text-gray-400">Die ganze Charge des Basisrezepts wird als erste Komponente übernommen (Menge = Yield) — danach Komponenten & VK-Daten pflegen.</p>
            </div>
        </x-foodalchemist::modal-section>
    @else
        <x-foodalchemist::modal-section title="Stammdaten">
            {{-- M9-01i: ✨-Vorschläge in die Form-Felder (Save = Accept) --}}
            <x-slot:actions>
                <button type="button" wire:click="ki('wording')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="vk.wording: kanonischer Marketing-Name, stil-neutral" data-ki-wording>✨ Wording</button>
                <button type="button" wire:click="ki('marketing')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="vk.marketing: verkäuferischer Foodbook-Text" data-ki-marketing>✨ Marketing</button>
            </x-slot:actions>
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">Name*</label>
                    <input type="text" wire:model="form.name" class="{{ $input }}" data-vk-name />
                </div>
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">VK-Wording (kanonischer Marketing-Name, stil-neutral)</label>
                    <input type="text" wire:model="form.vk_wording_standard" class="{{ $input }}" data-vk-wording />
                    <p class="text-[10px] text-gray-400 mt-0.5">Schreibstile (Foodbook, M10) transformieren später diesen Standard in Brand-Voice-Varianten.</p>
                </div>
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">Marketing-Text (Foodbook)</label>
                    <textarea wire:model="form.marketing_text" rows="2" class="{{ $input }}" data-vk-marketing-text></textarea>
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

        {{-- M9-01a/b: Zutaten INLINE (P-8-Kern, VK-Kontext = Rollen-Spalte) + 🎭 + KPI-Leiste --}}
        <x-foodalchemist::modal-section title="Zutaten ({{ $rezept->ingredients->count() }})">
            <x-slot:actions>
                <button type="button" wire:click="ai_rollen" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="ai_verteile_rollen — Gesamt-Gericht-Sicht (V-21)" data-vk-editor-rollen>🎭 Rollen verteilen</button>
            </x-slot:actions>

            @if($rollenVorschlag !== null)
                <div class="mb-2 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 text-xs" data-vk-editor-rollen-vorschlag>
                    <p class="text-gray-900 dark:text-gray-100">🎭 Rollen-Verteilung <span class="text-[11px] text-gray-400">· {{ round($rollenVorschlag['confidence'] * 100) }} %</span></p>
                    @if($rollenVorschlag['rollen'] === [])
                        <p class="text-[11px] text-gray-400 mt-0.5">Kein gültiger Vorschlag (Vokabular: aroma_treiber · komponente · beilage · garnitur).</p>
                    @else
                        <div class="mt-1 space-y-0.5">
                            @foreach($rollenVorschlag['rollen'] as $zeileId => $rolle)
                                @php($zeile = $rezept->ingredients->firstWhere('id', $zeileId))
                                <p class="text-[11px] text-gray-600 dark:text-gray-300" wire:key="vkmr-{{ $zeileId }}">{{ $zeile?->referencedRecipe?->name ?? $zeile?->gp?->name ?? $zeile?->display_name ?? "Zeile {$zeileId}" }} → <span class="font-medium">{{ $rolle }}</span></p>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex gap-1.5 mt-1.5">
                        @if($rollenVorschlag['rollen'] !== [])
                            <button type="button" wire:click="accept_rollen" class="{{ $btnGhostXs }} text-emerald-600" data-vk-rollen-accept>Übernehmen</button>
                        @endif
                        <button type="button" wire:click="reject_rollen" class="{{ $btnGhostXs }}">Verwerfen</button>
                    </div>
                </div>
            @endif

            <livewire:foodalchemist.recipes.ingredient-editor :recipe-id="$recipeId" :eingebettet="true" wire:key="vk-zutaten-{{ $recipeId }}-v{{ $zutatenVersion }}" />

            <div class="mt-3 grid grid-cols-2 md:grid-cols-5 gap-2" data-vk-editor-kpis>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">Yield</span>
                    <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $rezept->yield_kg !== null ? number_format((float) $rezept->yield_kg, 3, ',', '.') . ' kg' : '—' }}</p>
                </div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">EK gesamt</span>
                    <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $rezept->ek_total_eur !== null ? number_format((float) $rezept->ek_total_eur, 2, ',', '.') . ' €' : '—' }}</p>
                </div>
                <div class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2">
                    <span class="text-[10px] font-medium uppercase tracking-wider text-orange-600 dark:text-orange-400">EK / kg</span>
                    <p class="text-xs font-bold text-orange-700 dark:text-orange-300">{{ $rezept->ek_per_kg_eur !== null ? number_format((float) $rezept->ek_per_kg_eur, 2, ',', '.') . ' €/kg' : '—' }}</p>
                </div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">Mit Preis</span>
                    <p class="text-xs font-semibold text-gray-900 dark:text-gray-100">{{ $rezept->ek_n_ingredients_priced ?? 0 }}/{{ $rezept->ek_n_ingredients_total ?? 0 }}</p>
                </div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2">
                    <span class="{{ $dt }}">Allergen-Konf.</span>
                    <p class="text-xs font-semibold {{ ['high' => 'text-green-600', 'medium' => 'text-amber-500', 'low' => 'text-rose-500'][$rezept->allergene_konfidenz] ?? 'text-gray-400' }}">{{ strtoupper($rezept->allergene_konfidenz) }}</p>
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
                <p class="text-[11px] text-gray-400 mt-2" data-vk-vorschau>Vorschlag aus Klasse: {{ number_format($cockpit['vk']['vorschlag']['vk_netto'], 2, ',', '.') }} € netto · {{ $cockpit['vk']['vorschlag']['formel'] }}</p>
            @endif
        </x-foodalchemist::modal-section>

        {{-- M9-01c: Allergene · Zusatzstoffe · Diät (geteiltes R6-Partial) --}}
        <x-foodalchemist::modal-section title="Deklaration">
            @include('foodalchemist::livewire.recipes.partials.deklaration', ['rezept' => $rezept])
        </x-foodalchemist::modal-section>

        {{-- M9-01d: Nährwerte (GL-08-Aggregate — pro 100 g + pro Stück) --}}
        <x-foodalchemist::modal-section title="Nährwerte">
            @if($rezept->nutri_kcal_per_100g === null)
                <p class="text-[11px] text-gray-400" data-vk-naehrwerte-leer>Noch nicht aggregiert — läuft mit dem nächsten Zutaten-Speichern (GL-08).</p>
            @else
                <table class="{{ $table }}" data-vk-naehrwerte>
                    <thead><tr class="text-left">
                        <th class="{{ $th }}">Nährwert</th>
                        <th class="{{ $th }} text-right">pro 100 g</th>
                        <th class="{{ $th }} text-right">pro Stück {{ $gProStueck !== null ? '(≈ ' . number_format($gProStueck, 0, ',', '.') . ' g)' : '' }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach([
                            ['Brennwert', $rezept->nutri_kcal_per_100g, 'kcal', 0],
                            ['Eiweiß', $rezept->nutri_protein_g_per_100g, 'g', 1],
                            ['Fett', $rezept->nutri_fat_g_per_100g, 'g', 1],
                            ['Kohlenhydrate', $rezept->nutri_carbs_g_per_100g, 'g', 1],
                            ['Salz', $rezept->nutri_salt_g_per_100g, 'g', 2],
                        ] as [$lbl, $wert, $einheit, $dez])
                            <tr class="{{ $tr }}" wire:key="vkn-{{ $lbl }}">
                                <td class="{{ $td }} {{ $lbl === 'Brennwert' ? 'font-medium text-gray-900 dark:text-gray-100' : '' }}">{{ $lbl }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $wert !== null ? number_format((float) $wert, $dez, ',', '.') . ' ' . $einheit : '—' }}</td>
                                <td class="{{ $td }} text-right tabular-nums">{{ $wert !== null && $gProStueck !== null ? number_format((float) $wert * $gProStueck / 100, $dez, ',', '.') . ' ' . $einheit : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="text-[10px] text-gray-400 mt-1">
                    Konfidenz: <span class="font-medium {{ ['high' => 'text-green-600', 'medium' => 'text-amber-500', 'low' => 'text-rose-500'][$rezept->nutri_konfidenz] ?? '' }}">{{ strtoupper($rezept->nutri_konfidenz ?? '—') }}</span>
                    · {{ $rezept->nutri_n_ingredients_mapped ?? 0 }}/{{ $rezept->nutri_n_ingredients_total ?? 0 }} Zutaten mit Nährwert-Daten
                    {{ $rezept->nutri_aggregiert_am !== null ? '· aggregiert ' . $rezept->nutri_aggregiert_am->format('Y-m-d H:i') : '' }}
                    — Garverlust/Putzverlust werden NICHT angewendet (BLS-Rohwerte); Stück-Zutaten ohne g/ml-Basis tragen nichts bei.
                </p>
            @endif
        </x-foodalchemist::modal-section>

        {{-- M9-01e: Spezifikation (Bio-/Regional-Anteil, Gramm-gewichtet über GP-Tags) --}}
        <x-foodalchemist::modal-section title="Spezifikation">
            <div class="grid grid-cols-2 gap-3" data-vk-spezifikation>
                <div>
                    <span class="{{ $dt }}">Bio-Anteil</span>
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $anteile['bio'] !== null ? number_format($anteile['bio'], 1, ',', '.') . ' %' : '—' }}</p>
                </div>
                <div>
                    <span class="{{ $dt }}">Regional (DE)</span>
                    <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $anteile['regional'] !== null && $anteile['regional'] > 0 ? number_format($anteile['regional'], 1, ',', '.') . ' %' : '—' }}</p>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Container & Service">
            <x-slot:actions>
                <button type="button" wire:click="ki('behaelter')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="vk.behaelter: warm/kalt + Anzahl fürs Catering" data-ki-behaelter>✨ Behälter</button>
                <button type="button" wire:click="ki('vehikel')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="vk.servier_vehikel: worauf wird angerichtet" data-ki-vehikel>✨ Servier-Vorschlag</button>
            </x-slot:actions>
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
            <x-slot:actions>
                <button type="button" wire:click="kiRegeneration" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="vk.regeneration: ein Programm je Komponente (Vorschlag, Übernahme je Zeile)" data-ki-regeneration>✨ Regeneration</button>
            </x-slot:actions>
            @if($regenVorschlaege !== [])
                <div class="mb-2 rounded-lg bg-violet-500/10 border border-violet-500/30 px-3 py-2 space-y-1" data-regen-vorschlaege>
                    <p class="text-[11px] font-medium text-violet-700 dark:text-violet-300">✨ Programm-Vorschläge — je Zeile übernehmen:</p>
                    @foreach($regenVorschlaege as $idx => $rv)
                        <div class="flex items-center justify-between gap-2 text-[11px] text-gray-600 dark:text-gray-300" wire:key="rvz-{{ $idx }}">
                            <span class="min-w-0 truncate">{{ $rv['komponente_label'] }}{{ $rv['temp_c'] !== null ? ' · ' . $rv['temp_c'] . ' °C' : '' }}{{ $rv['dauer_min'] !== null ? ' · ' . $rv['dauer_min'] . ' min' : '' }}{{ $rv['kerntemp_c'] !== null ? ' · KT ' . $rv['kerntemp_c'] . ' °C' : '' }}</span>
                            <button type="button" wire:click="regenVorschlagUebernehmen({{ $idx }})" class="{{ $btnGhostXs }} text-emerald-600 shrink-0" data-regen-uebernehmen>+ Übernehmen</button>
                        </div>
                    @endforeach
                </div>
            @endif
            <div class="space-y-1.5" data-vk-regen>
                @foreach($regenZeilen as $z)
                    <div wire:key="rg-{{ $z->id }}" class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200" data-regen-zeile="{{ $z->id }}">
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

        {{-- M9-01f: Eigenschaften (+ ✨ recipe.eigenschaften/geschmack) --}}
        <x-foodalchemist::modal-section title="Eigenschaften">
            <x-slot:actions>
                <button type="button" wire:click="ki('eigenschaften')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" data-ki-eigenschaften>✨ Eigenschaften</button>
            </x-slot:actions>
            <div class="grid grid-cols-2 gap-3" data-vk-eigenschaften>
                <div>
                    <label class="block {{ $label }} mb-1">Arbeitszeit (min)</label>
                    <input type="number" min="0" wire:model="form.arbeitszeit_min" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Temperatur</label>
                    <input type="text" wire:model="form.temperatur" placeholder="z. B. 75 °C Kerntemperatur · gekühlt" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Funktion</label>
                    <input type="text" wire:model="form.funktion" placeholder="z. B. Hauptgang, Fingerfood" class="{{ $input }}" />
                </div>
                <div>
                    <label class="block {{ $label }} mb-1">Fertigungstiefe</label>
                    <select wire:model="form.fertigungstiefe" class="{{ $input }}">
                        <option value="">— unbestimmt —</option>
                        <option value="from_scratch">from scratch</option>
                        <option value="teilfertig">teilfertig</option>
                        <option value="convenience">Convenience</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block {{ $label }} mb-1">KI-Beschreibung (3–5 Sätze nüchtern, §8.3)</label>
                    <textarea wire:model="form.beschreibung" rows="3" class="{{ $input }}" data-vk-beschreibung></textarea>
                </div>
            </div>
        </x-foodalchemist::modal-section>

        {{-- M9-01g: Plating & Service (Teller-Aufbau, Mengenverteilung — keine Produktion) --}}
        <x-foodalchemist::modal-section title="Plating &amp; Service">
            <x-slot:actions>
                <button type="button" wire:click="ki('plating')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400" title="vk.plating: Hybrid-Plating-Anweisung" data-ki-plating>✨ Plating</button>
            </x-slot:actions>
            <div x-data data-vk-plating>
                <div class="flex items-center justify-between mb-1">
                    <span class="text-[10px] text-gray-400">Markdown — ## für Phasen, nummerierte Schritte</span>
                    @include('foodalchemist::livewire.recipes.partials.md-toolbar', ['ziel' => 'vk-plating-text'])
                </div>
                <textarea wire:model="form.plating_text" id="vk-plating-text" rows="7" class="{{ $input }} font-mono text-[11px]" data-vk-plating-text></textarea>
            </div>
        </x-foodalchemist::modal-section>

        {{-- M9-01h: Notizen (§9.1 — manuelle Insel) --}}
        <x-foodalchemist::modal-section title="Notizen (§9.1 — bleibt bei jedem KI-Sync erhalten)">
            <textarea wire:model="form.notizen_manual" rows="3" class="{{ $input }}" data-vk-notizen></textarea>
        </x-foodalchemist::modal-section>

        <x-foodalchemist::modal-section title="Verwendungsnachweise (Kunde × Marketing-Name)">
            <div class="space-y-1.5" data-vk-kunden>
                @foreach($kunden as $k)
                    <div wire:key="kn-{{ $k->id }}" class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200" data-kunde-zeile="{{ $k->id }}">
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
