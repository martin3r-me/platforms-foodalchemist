{{-- M1-07: Kalkulations-Defaults (GL-02) — RecomputeService (M4-03) liest dieselben Getter --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($meldung)
        <div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400">{{ $meldung }}</p></div>
    @endif

    {{-- Garverlust-Defaults --}}
    <div class="{{ $card }} p-5 space-y-3" data-kalk-garverlust>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Garverlust-Defaults</h3>
            <p class="text-[11px] text-gray-400 mt-0.5">In % je GP-Klasse (Warengruppe). Greift, wenn weder Zutat noch GP einen eigenen Wert hat (GL-02-Kaskade). Leer = kein Default.</p>
        </div>
        <div class="flex items-center gap-3 py-1.5 border-b border-black/5 dark:border-white/5">
            <span class="w-72 shrink-0 text-xs font-medium text-gray-900 dark:text-gray-100">* Global (alle Klassen)</span>
            <input type="text" wire:model="garverlust.*" placeholder="—" class="{{ $input }} !w-24" /> <span class="text-[11px] text-gray-400">%</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
            @foreach($warengruppen as $wg)
                <div class="flex items-center gap-3 py-1" wire:key="gv-{{ $wg->code }}">
                    <span class="w-64 shrink-0 text-xs text-gray-600 dark:text-gray-300 truncate">{{ $wg->code }} {{ $wg->name }}</span>
                    <input type="text" wire:model="garverlust.{{ $wg->code }}" placeholder="—" class="{{ $input }} !w-20 !py-1" /> <span class="text-[11px] text-gray-400">%</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- MwSt + Rundung --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="{{ $card }} p-5 space-y-3" data-kalk-mwst>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">MwSt-Defaults</h3>
            <div class="flex items-center gap-3"><span class="w-32 text-xs text-gray-600 dark:text-gray-300">Regulär</span>
                <input type="text" wire:model="mwst.regulaer" class="{{ $input }} !w-24" /> <span class="text-[11px] text-gray-400">%</span></div>
            <div class="flex items-center gap-3"><span class="w-32 text-xs text-gray-600 dark:text-gray-300">Ermäßigt</span>
                <input type="text" wire:model="mwst.ermaessigt" class="{{ $input }} !w-24" /> <span class="text-[11px] text-gray-400">%</span></div>
            <div class="flex items-center gap-3"><span class="w-32 text-xs text-gray-600 dark:text-gray-300">Default-Satz</span>
                <select wire:model="mwst.default_satz" class="{{ $input }} !w-40">
                    <option value="ermaessigt">ermäßigt (Speisen)</option>
                    <option value="regulaer">regulär</option>
                </select></div>
        </div>
        <div class="{{ $card }} p-5 space-y-3" data-kalk-rundung>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Rundungsregeln</h3>
            <div class="flex items-center gap-3"><span class="w-40 text-xs text-gray-600 dark:text-gray-300">Nachkommastellen</span>
                <input type="number" min="0" max="4" wire:model="rundung.nachkommastellen" class="{{ $input }} !w-20" /></div>
            <div class="flex items-center gap-3"><span class="w-40 text-xs text-gray-600 dark:text-gray-300">Modus</span>
                <select wire:model="rundung.modus" class="{{ $input }} !w-44">
                    <option value="kaufmaennisch">kaufmännisch</option>
                    <option value="auf">immer aufrunden</option>
                    <option value="ab">immer abrunden</option>
                </select></div>
            <p class="text-[11px] text-gray-400">Achtung GL-02 I7: Die Rundungs-REIHENFOLGE (Nenner = gerundetes yield_kg) ist fix — hier nur Stellen/Modus.</p>
        </div>
    </div>

    {{-- Doc 16 §10: mehrstufiges Kostenblock-Schema --}}
    @php($basisLabel = ['pct_mek' => 'auf Wareneinsatz (MEK)', 'pct_fek' => 'auf Fertigungslohn (FEK)', 'pct_hk' => 'auf Herstellkosten (HK)', 'eur_pro_portion' => '€ / Portion (direkt)', 'arbeitszeit' => '€ / h (Lohn)'])
    @php($basisPill = ['pct_mek' => $variantPill['info'], 'pct_fek' => $variantPill['warning'], 'pct_hk' => $variantPill['primary'], 'eur_pro_portion' => $variantPill['secondary'], 'arbeitszeit' => $variantPill['secondary']])
    <div class="{{ $card }} p-5 space-y-3" data-kalk-hk2>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Herstellkosten — mehrstufige Zuschlagskalkulation</h3>
            <p class="text-[11px] text-gray-400 mt-0.5"><strong>MEK + MGK + FEK + FGK = HK → +Verwaltung/Logistik = Selbstkosten (HK2) → × Marge = VK-Vorschlag.</strong> Gemeinkosten-Blöcke entweder <em>manuell</em> oder <em>abgeleitet</em> aus den Fixkosten unten.</p>
        </div>

        <table class="{{ $table }}">
            <thead><tr>
                <th class="{{ $th }} text-left">Block</th>
                <th class="{{ $th }} text-left">Basis</th>
                <th class="{{ $th }} text-center">aktiv</th>
                <th class="{{ $th }} text-left">Modus</th>
                <th class="{{ $th }} text-right">Satz / Wert</th>
            </tr></thead>
            <tbody>
                @foreach($schema as $i => $b)
                    @php($istGk = in_array($b['typ'], ['pct_mek', 'pct_fek', 'pct_hk'], true))
                    @php($istAbgeleitet = $istGk && ($b['modus'] ?? 'manuell') === 'abgeleitet')
                    <tr wire:key="kblock-{{ $b['key'] }}" class="{{ $tr }}">
                        <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $b['label'] }}</td>
                        <td class="{{ $td }}"><span class="{{ $pill }} {{ $basisPill[$b['typ']] ?? $variantPill['secondary'] }}">{{ $basisLabel[$b['typ']] ?? $b['typ'] }}</span></td>
                        <td class="{{ $td }} text-center"><input type="checkbox" wire:model="schema.{{ $i }}.aktiv" class="rounded border-gray-300 text-violet-500 focus:ring-violet-500/30" /></td>
                        <td class="{{ $td }}">
                            @if($istGk)
                                <select wire:model.live="schema.{{ $i }}.modus" class="{{ $input }} !w-32 !py-1">
                                    <option value="manuell">manuell</option>
                                    <option value="abgeleitet">aus Fixkosten</option>
                                </select>
                            @else
                                <span class="text-[11px] text-gray-400">direkt</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-right">
                            @if($istAbgeleitet)
                                <span class="tabular-nums text-violet-700 dark:text-violet-300 font-medium" title="abgeleitet aus Fixkosten ÷ Bezugsbasis">{{ number_format((float) ($abgeleitet[$b['key']] ?? 0), 2, ',', '.') }} %</span>
                            @else
                                <input type="text" wire:model="schema.{{ $i }}.wert" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="0" />
                                <span class="text-[10px] text-gray-400">{{ $b['typ'] === 'eur_pro_portion' ? '€' : ($b['typ'] === 'arbeitszeit' ? '€/h' : '%') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="flex items-center gap-3 pt-1 border-t border-black/5 dark:border-white/10">
            <span class="w-40 text-xs text-gray-600 dark:text-gray-300">Marge (→ VK-Vorschlag)</span>
            <input type="text" wire:model="marge" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="15" /> <span class="text-[11px] text-gray-400">% auf HK2</span>
        </div>
    </div>

    {{-- M-K6/Doc 16 §10.2: Fixkosten + Bezugsbasen → abgeleitete Gemeinkosten-Sätze --}}
    <div class="{{ $card }} p-5 space-y-3" data-kalk-fixkosten>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Fixkosten (Gemeinkosten) → abgeleitete Sätze</h3>
            <p class="text-[11px] text-gray-400 mt-0.5">Nicht-produktbezogene Kosten (Logistik, Spüle, Lager, Verwaltung …). <strong>Zuschlag-% = Σ Fixkosten/Monat ÷ Bezugsbasis × 100</strong> für jeden Block im Modus „aus Fixkosten".</p>
        </div>

        {{-- Bezugsbasen (monatlich) --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @foreach(['mek' => 'Ø Wareneinsatz / Monat (MEK-Basis)', 'fek' => 'Ø Fertigungslohn / Monat (FEK-Basis)', 'hk' => 'Ø Herstellkosten / Monat (HK-Basis)'] as $k => $lbl)
                <div>
                    <label class="{{ $label }}">{{ $lbl }}</label>
                    <input type="text" wire:model="bezugsbasen.{{ $k }}" class="{{ $input }} text-right tabular-nums" placeholder="0" /> <span class="text-[11px] text-gray-400">€</span>
                </div>
            @endforeach
        </div>

        {{-- Fixkosten-Liste --}}
        <table class="{{ $table }}">
            <thead><tr>
                <th class="{{ $th }} text-left w-full">Bezeichnung</th>
                <th class="{{ $th }} text-left">Block</th>
                <th class="{{ $th }} text-right">Betrag</th>
                <th class="{{ $th }} text-left">Periode</th>
                <th class="{{ $th }}"></th>
            </tr></thead>
            <tbody>
                @forelse($fixListe as $f)
                    <tr wire:key="fix-{{ $f['id'] }}" class="{{ $tr }}">
                        <td class="{{ $td }}">{{ $f['bezeichnung'] }}</td>
                        <td class="{{ $td }} text-gray-500">{{ collect($gkBloecke)->firstWhere('key', $f['block_key'])['label'] ?? $f['block_key'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $f['betrag'], 2, ',', '.') }} €</td>
                        <td class="{{ $td }} text-gray-500">{{ $f['periode'] === 'jaehrlich' ? 'jährlich' : 'monatlich' }}</td>
                        <td class="{{ $td }} text-right"><button type="button" wire:click="fixLoeschen({{ $f['id'] }})" wire:confirm="Fixkosten-Zeile löschen?" class="text-gray-400 hover:text-red-500">✕</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-center text-[11px] text-gray-400">Noch keine Fixkosten erfasst.</td></tr>
                @endforelse
                {{-- Neue Zeile --}}
                <tr class="border-t-2 border-black/5 dark:border-white/10">
                    <td class="{{ $td }}"><input type="text" wire:model="neuFix.bezeichnung" wire:keydown.enter="fixHinzu" placeholder="z. B. Spülpersonal, LKW, Miete …" class="{{ $input }}" /></td>
                    <td class="{{ $td }}">
                        <select wire:model="neuFix.block_key" class="{{ $input }} !w-40 !py-1">
                            <option value="">— Block —</option>
                            @foreach($gkBloecke as $gk)<option value="{{ $gk['key'] }}">{{ $gk['label'] }}</option>@endforeach
                        </select>
                    </td>
                    <td class="{{ $td }} text-right"><input type="text" wire:model="neuFix.betrag" wire:keydown.enter="fixHinzu" placeholder="0" class="{{ $input }} !w-24 text-right tabular-nums" /></td>
                    <td class="{{ $td }}">
                        <select wire:model="neuFix.periode" class="{{ $input }} !w-32 !py-1">
                            <option value="monatlich">monatlich</option>
                            <option value="jaehrlich">jährlich</option>
                        </select>
                    </td>
                    <td class="{{ $td }} text-right"><button type="button" wire:click="fixHinzu" class="{{ $btnGhostXs }} text-emerald-600">+ Add</button></td>
                </tr>
            </tbody>
        </table>
    </div>

    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
</div>
