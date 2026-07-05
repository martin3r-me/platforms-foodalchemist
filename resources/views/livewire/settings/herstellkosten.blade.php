{{-- Phase 4: Herstellkosten — eigene Sektion (mehrstufige Zuschlagskalkulation + Fixkosten + Bezugsbasen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4" data-settings-herstellkosten>
    @if($meldung)
        <div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400">{{ $meldung }}</p></div>
    @endif
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600 dark:text-red-400">{{ $fehler }}</p></div>
    @endif

    {{-- Doc 16 §10: mehrstufiges Kostenblock-Schema --}}
    @php($basisLabel = ['pct_mek' => 'auf Wareneinsatz (MEK)', 'pct_fek' => 'auf Fertigungslohn (FEK)', 'pct_hk' => 'auf Herstellkosten (HK)', 'eur_pro_portion' => '€ / Portion (direkt)', 'arbeitszeit' => '€ / h (Lohn)'])
    @php($basisPill = ['pct_mek' => $variantPill['info'], 'pct_fek' => $variantPill['warning'], 'pct_hk' => $variantPill['primary'], 'eur_pro_portion' => $variantPill['secondary'], 'arbeitszeit' => $variantPill['secondary']])
    <div class="{{ $card }} p-5 space-y-3" data-hk-schema>
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Mehrstufige Zuschlagskalkulation</h3>
                <p class="text-[11px] text-gray-400 mt-0.5"><strong>MEK + MGK + FEK + FGK = HK → +Verwaltung/Logistik = Selbstkosten (HK2) → × Marge = VK-Vorschlag.</strong> Gemeinkosten stehen auf <em>automatisch</em> — du trägst unten deine Fixkosten in € ein, der Zuschlag-% rechnet sich selbst (Σ Fixkosten ÷ Bezugsbasis). <em>manuell (%)</em> nur als Ausnahme.</p>
            </div>
            <button type="button" wire:click="alleAutomatisch" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400 shrink-0" title="Setzt alle Gemeinkosten-Blöcke auf automatische Ableitung aus den Fixkosten.">⚡ Alle Gemeinkosten automatisch</button>
        </div>

        <table class="{{ $table }}">
            <thead><tr>
                <th class="{{ $th }} text-left">Block</th>
                <th class="{{ $th }} text-left">Basis</th>
                <th class="{{ $th }} text-center">aktiv</th>
                <th class="{{ $th }} text-left">Modus</th>
                <th class="{{ $th }} text-right">Satz / Wert</th>
                <th class="{{ $th }}"></th>
            </tr></thead>
            <tbody>
                @foreach($schema as $i => $b)
                    @php($istGk = in_array($b['type'], ['pct_mek', 'pct_fek', 'pct_hk'], true))
                    @php($istAbgeleitet = $istGk && ($b['mode'] ?? 'manuell') === 'abgeleitet')
                    <tr wire:key="kblock-{{ $b['key'] }}" class="{{ $tr }}">
                        <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $b['label'] }}</td>
                        <td class="{{ $td }}"><span class="{{ $pill }} {{ $basisPill[$b['type']] ?? $variantPill['secondary'] }}">{{ $basisLabel[$b['type']] ?? $b['type'] }}</span></td>
                        <td class="{{ $td }} text-center"><input type="checkbox" wire:model="schema.{{ $i }}.aktiv" class="rounded border-gray-300 text-violet-500 focus:ring-violet-500/30" /></td>
                        <td class="{{ $td }}">
                            @if($istGk)
                                <select wire:model.live="schema.{{ $i }}.modus" class="{{ $input }} !w-36 !py-1">
                                    <option value="abgeleitet">automatisch</option>
                                    <option value="manuell">manuell (%)</option>
                                </select>
                            @else
                                <span class="text-[11px] text-gray-400">direkt</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-right">
                            @if($istAbgeleitet)
                                @php($basisTyp = ['pct_mek' => 'mek', 'pct_fek' => 'fek', 'pct_hk' => 'hk'][$b['type']] ?? null)
                                @php($blockSumme = (float) ($fixSummen[$b['key']] ?? 0))
                                @php($blockBasis = (float) ($liveBasen[$basisTyp] ?? 0))
                                @php($basisFehlt = $blockSumme > 0 && $blockBasis <= 0)
                                <span class="tabular-nums text-violet-700 dark:text-violet-300 font-medium" title="automatisch: Σ Fixkosten/Monat ÷ Bezugsbasis × 100">{{ number_format((float) ($abgeleitet[$b['key']] ?? 0), 2, ',', '.') }} %</span>
                                @if($blockSumme > 0 && $blockBasis > 0)
                                    <span class="block text-[10px] text-gray-400 tabular-nums" title="Σ Fixkosten/Monat ÷ Bezugsbasis">{{ number_format($blockSumme, 0, ',', '.') }} € ÷ {{ number_format($blockBasis, 0, ',', '.') }} €</span>
                                @elseif($basisFehlt)
                                    <span class="block text-[10px] text-amber-600 dark:text-amber-400" title="Fixkosten erfasst, aber Bezugsbasis = 0 → Satz bleibt 0 %. Bezugsbasis unten eintragen.">⚠ Bezugsbasis fehlt → 0&nbsp;%</span>
                                @else
                                    <span class="block text-[10px] text-gray-400">noch keine Fixkosten</span>
                                @endif
                            @else
                                <input type="text" wire:model="schema.{{ $i }}.wert" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="0" />
                                <span class="text-[10px] text-gray-400">{{ $b['type'] === 'eur_pro_portion' ? '€' : ($b['type'] === 'arbeitszeit' ? '€/h' : '%') }}</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-right">
                            <button type="button" wire:click="blockEntfernen({{ $i }})" wire:confirm="Kostenblock entfernen?" class="text-gray-400 hover:text-red-500" title="Block entfernen">✕</button>
                        </td>
                    </tr>
                @endforeach
                {{-- Neuer Block --}}
                <tr class="border-t-2 border-black/5 dark:border-white/10">
                    <td class="{{ $td }}"><input type="text" wire:model="neuBlock.label" wire:keydown.enter="blockHinzu" placeholder="Neuer Block (z. B. Energie)" class="{{ $input }} !py-1" /></td>
                    <td class="{{ $td }}" colspan="3">
                        <select wire:model="neuBlock.type" class="{{ $input }} !w-56 !py-1">
                            <option value="pct_mek">% auf Wareneinsatz (MEK)</option>
                            <option value="pct_fek">% auf Fertigungslohn (FEK)</option>
                            <option value="pct_hk">% auf Herstellkosten (HK)</option>
                            <option value="eur_pro_portion">€ / Portion (direkt)</option>
                            <option value="arbeitszeit">€ / h (Lohn)</option>
                        </select>
                    </td>
                    <td class="{{ $td }} text-right" colspan="2"><button type="button" wire:click="blockHinzu" class="{{ $btnGhostXs }} text-emerald-600">+ Block</button></td>
                </tr>
            </tbody>
        </table>

        <div class="flex items-center gap-3 pt-1 border-t border-black/5 dark:border-white/10">
            <span class="w-40 text-xs text-gray-600 dark:text-gray-300">Marge (→ VK-Vorschlag)</span>
            <input type="text" wire:model="marge" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="15" /> <span class="text-[11px] text-gray-400">% auf HK2</span>
        </div>
        <div class="flex items-center gap-3">
            <span class="w-40 text-xs text-gray-600 dark:text-gray-300">Ziel-Wareneinsatzquote</span>
            <input type="text" wire:model="zielWe" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="30" /> <span class="text-[11px] text-gray-400">% Food-Cost-Ziel (gastro-üblich 28–35 %) — treibt Break-even + Signal „Wareneinsatz über Ziel"</span>
        </div>
        <div class="flex items-center gap-3">
            <span class="w-40 text-xs text-gray-600 dark:text-gray-300">Lohnnebenkosten-Zuschlag</span>
            <input type="text" wire:model="lnk" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="0" /> <span class="text-[11px] text-gray-400">% AG-/Sozialabgaben auf den Produktionslohn — rechnet den <strong>echten</strong> Personalkostensatz (statt nur Brutto-Lohn) in HK2</span>
        </div>
    </div>

    {{-- M-K6/Doc 16 §10.2: Fixkosten + Bezugsbasen → abgeleitete Gemeinkosten-Sätze --}}
    <div class="{{ $card }} p-5 space-y-3" data-hk-fixkosten>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Fixkosten (Gemeinkosten) → abgeleitete Sätze</h3>
            <p class="text-[11px] text-gray-400 mt-0.5">Nicht-produktbezogene Kosten (Logistik, Spüle, Lager, Verwaltung …). <strong>Zuschlag-% = Σ Fixkosten/Monat ÷ Bezugsbasis × 100</strong> für jeden Block im Modus „aus Fixkosten".</p>
        </div>

        {{-- Bezugsbasen (monatlich) — mit Erklärung (Phase 4: war vorher undokumentiert) --}}
        <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 p-3 space-y-2">
            <p class="text-[11px] text-gray-500 dark:text-gray-400">Bezugsbasen = die erwarteten <strong>Monatswerte</strong>, durch die die Fixkosten geteilt werden. Faustregel: Ø der letzten 3 Monate (Ist) oder Planwert. <em>0 = Block bleibt 0 %.</em></p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                @foreach([
                    'mek' => ['Ø Wareneinsatz / Monat', 'Summe Einkaufswert der verarbeiteten Ware/Monat (€)'],
                    'fek' => ['Ø Fertigungslohn / Monat', 'Summe Küchen-/Produktionslöhne/Monat (€)'],
                    'hk' => ['Ø Herstellkosten / Monat', 'MEK + Löhne + direkte Kosten/Monat (€)'],
                ] as $k => [$lbl, $hint])
                    <div>
                        <label class="{{ $label }}">{{ $lbl }}</label>
                        <div class="flex items-center gap-1"><input type="text" wire:model.live.debounce.600ms="bezugsbasen.{{ $k }}" class="{{ $input }} text-right tabular-nums" placeholder="0" /> <span class="text-[11px] text-gray-400">€</span></div>
                        <p class="text-[10px] text-gray-400 mt-0.5">{{ $hint }}</p>
                    </div>
                @endforeach
            </div>
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
                        <td class="{{ $td }}">{{ $f['label'] }}</td>
                        <td class="{{ $td }} text-gray-500">{{ collect($gkBloecke)->firstWhere('key', $f['block_key'])['label'] ?? $f['block_key'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $f['betrag'], 2, ',', '.') }} €</td>
                        <td class="{{ $td }} text-gray-500">
                            {{ $f['periode'] === 'jaehrlich' ? 'jährlich' : 'monatlich' }}
                            @if($f['periode'] === 'jaehrlich')
                                <span class="text-[10px] text-gray-400">= {{ number_format((float) ($f['monatsbetrag'] ?? 0), 2, ',', '.') }} €/Mt</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-right"><button type="button" wire:click="fixLoeschen({{ $f['id'] }})" wire:confirm="Fixkosten-Zeile löschen?" class="text-gray-400 hover:text-red-500">✕</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-center text-[11px] text-gray-400">Noch keine Fixkosten erfasst.</td></tr>
                @endforelse
                {{-- Neue Zeile --}}
                <tr class="border-t-2 border-black/5 dark:border-white/10">
                    <td class="{{ $td }}"><input type="text" wire:model="neuFix.label" wire:keydown.enter="fixHinzu" placeholder="z. B. Spülpersonal, LKW, Miete …" class="{{ $input }}" /></td>
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

        {{-- #379+: Σ Fixkosten/Monat (Controlling-Zahl) — gesamt + je Block, jährlich bereits auf /Monat normalisiert --}}
        @php($fixMonatGesamt = array_sum($fixSummen ?? []))
        <div class="flex flex-col gap-1 pt-2 border-t border-black/5 dark:border-white/10">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-900 dark:text-gray-100">Σ Fixkosten / Monat</span>
                <span class="tabular-nums text-sm font-semibold text-gray-900 dark:text-gray-100">{{ number_format((float) $fixMonatGesamt, 2, ',', '.') }} €</span>
            </div>
            @if($fixMonatGesamt > 0)
                <div class="flex flex-wrap gap-1">
                    @foreach($fixSummen as $bk => $summe)
                        @if($summe > 0)
                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ collect($gkBloecke)->firstWhere('key', $bk)['label'] ?? $bk }}: {{ number_format((float) $summe, 2, ',', '.') }} €/Mt</span>
                        @endif
                    @endforeach
                </div>
                <p class="text-[10px] text-gray-400">Davon fließen pro Block ÷ Bezugsbasis die abgeleiteten Zuschlag-% (Modus „aus Fixkosten").</p>
            @endif
        </div>
    </div>

    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
</div>
