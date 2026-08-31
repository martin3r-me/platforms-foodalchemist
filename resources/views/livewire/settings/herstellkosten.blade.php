{{-- Phase 4: Herstellkosten — eigene Sektion (mehrstufige Zuschlagskalkulation + Fixkosten + Bezugsbasen) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4" data-settings-herstellkosten>
    <x-foodalchemist::save-bar :meldung="$meldung"
        hint="Zuschlagsschema, Fixkosten und Marge rollen erst nach dem Speichern auf HK2/VK aus." />
    @if($fehler)
        <div class="{{ $card }} p-3 border-red-500/20"><p class="text-xs text-red-600">{{ $fehler }}</p></div>
    @endif

    @php($schemaLock = ! $schemaEditierbar)

    {{-- Ebene 2: Seiten-Wähler Team ↔ Betrieb — steuert die GANZE Seite (lokal, nicht die globale Brille). --}}
    @if(count($betriebeOptionen) > 0)
        <div class="rounded-lg border p-3" style="border-color:#e9d5ff;background:#faf5ff;">
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-block w-2 h-2 rounded-full" style="background:#9333ea;"></span>
                <label class="text-xs font-semibold" style="color:#6b21a8;">Ansicht &amp; Kosten erfassen für</label>
                <select wire:model.live="outletId" class="{{ $input }} !w-64 !py-1">
                    <option value="">Team-Standard (gilt für alle Betriebe)</option>
                    @foreach($betriebeOptionen as $o)
                        <option value="{{ $o['id'] }}">Betrieb: {{ $o['name'] }}</option>
                    @endforeach
                </select>
                @if($scopeOutletName)
                    <button type="button" wire:click="aufTeamZuruecksetzen"
                        wire:confirm="Alle Kosten-Overrides von „{{ $scopeOutletName }}" entfernen — erbt danach wieder komplett vom Team?"
                        class="{{ $btnGhostXs }} text-gray-500 ml-auto">Auf Team-Standard zurücksetzen</button>
                @endif
            </div>
            @if($scopeOutletName)
                <p class="text-[11px] text-purple-800 mt-1.5">
                    Du bearbeitest <strong>{{ $scopeOutletName }}</strong>. Leere Felder <strong>erben</strong> das Team; die abweichende VK/Kalkulation dieses Betriebs greift on-the-fly.
                    Die <em>Lohnquelle</em> gilt teamweit.
                </p>
            @else
                <p class="text-[11px] text-gray-500 mt-1.5">Team-Standard — Basis für jeden Betrieb ohne eigene Werte. Betrieb wählen, um dessen Kosten abweichend zu erfassen.</p>
            @endif
        </div>
    @endif

    {{-- Doc 16 §10: mehrstufiges Kostenblock-Schema --}}
    @php($basisLabel = ['pct_mek' => 'auf Wareneinsatz (MEK)', 'pct_fek' => 'auf Fertigungslohn (FEK)', 'pct_hk' => 'auf Herstellkosten (HK)', 'eur_pro_portion' => '€ / Portion (direkt)', 'arbeitszeit' => '€ / h (Lohn)'])
    @php($basisPill = ['pct_mek' => $variantPill['info'], 'pct_fek' => $variantPill['warning'], 'pct_hk' => $variantPill['primary'], 'eur_pro_portion' => $variantPill['secondary'], 'arbeitszeit' => $variantPill['secondary']])
    <div class="{{ $card }} p-5 space-y-3" data-hk-schema>
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h3 class="font-medium tracking-tight text-gray-900">Mehrstufige Zuschlagskalkulation</h3>
                <p class="text-[11px] text-gray-500 mt-0.5"><strong>MEK + MGK + FEK + FGK = HK → +Verwaltung/Logistik = Selbstkosten (HK2) → × Marge = VK-Vorschlag.</strong> Gemeinkosten stehen auf <em>automatisch</em> — du trägst unten deine Fixkosten in € ein, der Zuschlag-% rechnet sich selbst (Σ Fixkosten ÷ Bezugsbasis). <em>manuell (%)</em> nur als Ausnahme.</p>
            </div>
            @unless($schemaLock)
                <button type="button" wire:click="alleAutomatisch" class="{{ $btnGhostXs }} text-violet-600 shrink-0" title="Setzt alle Gemeinkosten-Blöcke auf automatische Ableitung aus den Fixkosten.">@svg('heroicon-o-bolt', 'w-3.5 h-3.5 inline-block align-middle') Alle Gemeinkosten automatisch</button>
            @endunless
        </div>

        {{-- Ebene 2: eigenes Schema je Betrieb (aus = erbt das Team, Editor read-only). --}}
        @if($scopeOutletName)
            <label class="flex items-center gap-2 text-[11px] text-purple-800 rounded-lg px-2.5 py-1.5" style="background:#faf5ff;border:1px solid #e9d5ff;">
                <input type="checkbox" wire:model.live="eigenesSchema" class="rounded border-gray-300 text-violet-500 focus:ring-violet-500/30" />
                <span>Eigenes Zuschlagsschema + Bezugsbasen + Stundensatz für „{{ $scopeOutletName }}"
                    <span class="text-purple-500">— aus = erbt das Team-Schema (Felder gesperrt)</span></span>
            </label>
        @endif

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
                        <td class="{{ $td }} font-medium text-gray-900">{{ $b['label'] }}</td>
                        <td class="{{ $td }}"><span class="{{ $pill }} {{ $basisPill[$b['type']] ?? $variantPill['secondary'] }}">{{ $basisLabel[$b['type']] ?? $b['type'] }}</span></td>
                        <td class="{{ $td }} text-center"><input type="checkbox" wire:model="schema.{{ $i }}.active" @disabled($schemaLock) class="rounded border-gray-300 text-violet-500 focus:ring-violet-500/30 disabled:opacity-40" /></td>
                        <td class="{{ $td }}">
                            @if($istGk)
                                <select wire:model.live="schema.{{ $i }}.mode" @disabled($schemaLock) class="{{ $input }} !w-36 !py-1 disabled:opacity-50">
                                    <option value="abgeleitet">automatisch</option>
                                    <option value="manuell">manuell (%)</option>
                                </select>
                            @else
                                <span class="text-[11px] text-gray-500">direkt</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-right">
                            @if($istAbgeleitet)
                                @php($basisTyp = ['pct_mek' => 'mek', 'pct_fek' => 'fek', 'pct_hk' => 'hk'][$b['type']] ?? null)
                                @php($blockSumme = (float) ($fixSummen[$b['key']] ?? 0))
                                @php($blockBasis = (float) ($liveBasen[$basisTyp] ?? 0))
                                @php($basisFehlt = $blockSumme > 0 && $blockBasis <= 0)
                                <span class="tabular-nums text-violet-700 font-medium" title="automatisch: Σ Fixkosten/Monat ÷ Bezugsbasis × 100">{{ number_format((float) ($abgeleitet[$b['key']] ?? 0), 2, ',', '.') }} %</span>
                                @if($blockSumme > 0 && $blockBasis > 0)
                                    <span class="block text-[10px] text-gray-500 tabular-nums" title="Σ Fixkosten/Monat ÷ Bezugsbasis">{{ number_format($blockSumme, 0, ',', '.') }} € ÷ {{ number_format($blockBasis, 0, ',', '.') }} €</span>
                                @elseif($basisFehlt)
                                    <span class="block text-[10px] text-amber-600" title="Fixkosten erfasst, aber Bezugsbasis = 0 → Satz bleibt 0 %. Bezugsbasis unten eintragen.">@svg('heroicon-o-exclamation-triangle', 'w-3.5 h-3.5 inline-block align-middle') Bezugsbasis fehlt → 0&nbsp;%</span>
                                @else
                                    <span class="block text-[10px] text-gray-500">noch keine Fixkosten</span>
                                @endif
                            @else
                                @php($stundenBlockGesperrt = $scopeOutletName && $b['type'] === 'arbeitszeit')
                                @php($istLohnFallback = $b['type'] === 'arbeitszeit' && $laborSource === 'station_roles')
                                <input type="text" wire:model="schema.{{ $i }}.value" @disabled($schemaLock || $stundenBlockGesperrt) class="{{ $input }} !w-24 text-right tabular-nums disabled:opacity-50 {{ $istLohnFallback ? 'opacity-50' : '' }}" placeholder="0" />
                                <span class="text-[10px] text-gray-500">{{ $b['type'] === 'eur_pro_portion' ? '€' : ($b['type'] === 'arbeitszeit' ? '€/h' : '%') }}</span>
                                @if($istLohnFallback)<span class="block text-[10px] text-amber-600" title="Lohnquelle = „Rollen des Postens": der Lohn kommt aus den Rollen-Sätzen; dieser Satz greift nur als Rückfall, wenn ein Posten keine Rollendaten hat.">Fallback (Rollen des Postens aktiv)</span>@endif
                                @if($stundenBlockGesperrt)<span class="block text-[10px] text-purple-500">Stundensatz unten je Betrieb setzen</span>@endif
                            @endif
                        </td>
                        <td class="{{ $td }} text-right">
                            @unless($schemaLock)
                                <button type="button" wire:click="blockEntfernen({{ $i }})" wire:confirm="Kostenblock entfernen?" class="text-gray-500 hover:text-red-500" title="Block entfernen">@svg('heroicon-o-trash', 'w-3.5 h-3.5 inline-block align-middle')</button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                {{-- Neuer Block --}}
                @unless($schemaLock)
                <tr class="border-t-2 border-black/5">
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
                @else
                <tr class="border-t-2 border-black/5"><td colspan="6" class="px-3 py-2 text-[11px] text-purple-700" style="background:#faf5ff;">Erbt das Team-Schema. Zum Abweichen oben „Eigenes Zuschlagsschema …" aktivieren.</td></tr>
                @endunless
            </tbody>
        </table>

        <div class="flex items-center gap-3 pt-1 border-t border-black/5">
            <span class="w-40 text-xs text-gray-600">Marge (→ VK-Vorschlag)</span>
            <input type="text" wire:model="marge" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="{{ $scopeOutletName ? 'erbt: '.$teamWerte['marge'] : '15' }}" /> <span class="text-[11px] text-gray-500">% auf HK2 @if($scopeOutletName)<span class="text-purple-500">— leer = erbt vom Team</span>@endif</span>
        </div>
        <div class="flex items-center gap-3">
            <span class="w-40 text-xs text-gray-600">Ziel-Wareneinsatzquote</span>
            <input type="text" wire:model="zielWe" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="{{ $scopeOutletName ? 'erbt: '.$teamWerte['zielWe'] : '30' }}" /> <span class="text-[11px] text-gray-500">% Food-Cost-Ziel (gastro-üblich 28–35 %) — treibt Break-even + Signal „Wareneinsatz über Ziel"</span>
        </div>
        <div class="flex items-center gap-3">
            <span class="w-40 text-xs text-gray-600">Lohnnebenkosten-Zuschlag</span>
            <input type="text" wire:model="lnk" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="{{ $scopeOutletName ? 'erbt: '.$teamWerte['lnk'] : '0' }}" /> <span class="text-[11px] text-gray-500">% AG-/Sozialabgaben auf den Produktionslohn — rechnet den <strong>echten</strong> Personalkostensatz (statt nur Brutto-Lohn) in HK2</span>
        </div>
        @if($scopeOutletName)
            {{-- Ebene 2: Stundensatz als eigenes Betrieb-Feld (unabhängig vom Schema-Toggle). --}}
            <div class="flex items-center gap-3">
                <span class="w-40 text-xs text-gray-600">Stundensatz (Lohn)</span>
                <input type="text" wire:model="stundensatz" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="erbt: {{ $teamWerte['stundensatz'] }}" /> <span class="text-[11px] text-gray-500">€/h — flacher Produktionslohn <span class="text-purple-500">— leer = erbt vom Team</span></span>
            </div>
        @endif
        <div class="flex items-center gap-3">
            <span class="w-40 text-xs text-gray-600">Lohnquelle im Auftrag</span>
            <select wire:model="laborSource" class="{{ $input }} !w-52">
                @if($scopeOutletName)<option value="">erbt vom Team ({{ $teamWerte['laborSource'] === 'station_roles' ? 'Rollen des Postens' : 'flacher Stundensatz' }})</option>@endif
                <option value="team_flat">Flacher Stundensatz</option>
                <option value="station_roles">Rollen des Postens</option>
            </select>
            <span class="text-[11px] text-gray-500">@if($scopeOutletName)<span class="text-purple-500">Je Betrieb wählbar — die Rollen-Sätze selbst gelten teamweit.</span>@else Fehlende Posten- oder Rollendaten fallen sichtbar auf den Team-Satz zurück.@endif</span>
        </div>
    </div>

    {{-- M-K6/Doc 16 §10.2: Fixkosten + Bezugsbasen → abgeleitete Gemeinkosten-Sätze --}}
    <div class="{{ $card }} p-5 space-y-3" data-hk-fixkosten>
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <div>
                <h3 class="font-medium tracking-tight text-gray-900">Fixkosten (Gemeinkosten) → abgeleitete Sätze</h3>
                <p class="text-[11px] text-gray-500 mt-0.5">Nicht-produktbezogene Kosten (Logistik, Spüle, Lager, Verwaltung …). <strong>Zuschlag-% = Σ Fixkosten/Monat ÷ Bezugsbasis × 100</strong> für jeden Block im Modus „aus Fixkosten".</p>
            </div>
            @if(count($fixListe) === 0 && $outletId === null)
                <button type="button" wire:click="cateringBeispielwerte" class="{{ $btnGhostXs }} text-violet-600" title="Setzt gekennzeichnete, editierbare Beispielwerte samt Monatsbasen ein und berechnet die Kaskade.">@svg('heroicon-o-calculator', 'w-3.5 h-3.5 inline-block align-middle') Catering-Beispiel rechnen</button>
            @endif
        </div>

        {{-- Ebene 2: Fixkosten folgen dem Seiten-Scope (Wähler oben) — Betriebs-Zeilen ersetzen pro Block die Team-Zeilen. --}}
        @if($scopeOutletName)
            <div class="rounded-lg border p-3" style="border-color:#e9d5ff;background:#faf5ff;">
                <p class="text-[11px] text-purple-800">
                    Fixkosten für <strong>{{ $scopeOutletName }}</strong> — eigene Zeilen ersetzen bei der VK-Berechnung
                    <strong>pro Block</strong> die Team-Fixkosten; Blöcke ohne eigene Zeile <strong>erben</strong> das Team.
                    Die Σ unten zeigt den effektiven Wert (eigen + geerbt).
                </p>
            </div>
        @endif

        {{-- Bezugsbasen (monatlich) — mit Erklärung (Phase 4: war vorher undokumentiert) --}}
        <div class="rounded-lg bg-black/[0.03] p-3 space-y-2">
            <p class="text-[11px] text-gray-600">Bezugsbasen = die erwarteten <strong>Monatswerte</strong>, durch die die Fixkosten geteilt werden. Faustregel: Ø der letzten 3 Monate (Ist) oder Planwert. <em>0 = Block bleibt 0 %.</em></p>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                @foreach([
                    'mek' => ['Ø Wareneinsatz / Monat', 'Summe Einkaufswert der verarbeiteten Ware/Monat (€)'],
                    'fek' => ['Ø Fertigungslohn / Monat', 'Summe Küchen-/Produktionslöhne/Monat (€)'],
                    'hk' => ['Ø Herstellkosten / Monat', 'MEK + Löhne + direkte Kosten/Monat (€)'],
                ] as $k => [$lbl, $hint])
                    <div>
                        <label class="{{ $label }}">{{ $lbl }}</label>
                        <div class="flex items-center gap-1"><input type="text" wire:model.live.debounce.600ms="bezugsbasen.{{ $k }}" @disabled($schemaLock) class="{{ $input }} text-right tabular-nums disabled:opacity-50" placeholder="0" /> <span class="text-[11px] text-gray-500">€</span></div>
                        <p class="text-[10px] text-gray-500 mt-0.5">{{ $hint }}</p>
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
                        <td class="{{ $td }} text-gray-600">{{ collect($gkBloecke)->firstWhere('key', $f['block_key'])['label'] ?? $f['block_key'] }}</td>
                        <td class="{{ $td }} text-right tabular-nums">{{ number_format((float) $f['amount'], 2, ',', '.') }} €</td>
                        <td class="{{ $td }} text-gray-600">
                            {{ $f['periode'] === 'jaehrlich' ? 'jährlich' : 'monatlich' }}
                            @if($f['periode'] === 'jaehrlich')
                                <span class="text-[10px] text-gray-500">= {{ number_format((float) ($f['monatsbetrag'] ?? 0), 2, ',', '.') }} €/Mt</span>
                            @endif
                        </td>
                        <td class="{{ $td }} text-right"><button type="button" wire:click="fixLoeschen({{ $f['id'] }})" wire:confirm="Fixkosten-Zeile löschen?" class="text-gray-500 hover:text-red-500">@svg('heroicon-o-trash', 'w-3.5 h-3.5 inline-block align-middle')</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-4 text-center text-[11px] text-gray-500">@if($scopeOutletName)Noch keine eigenen Fixkosten für „{{ $scopeOutletName }}" — es gilt der Team-Standard. Unten eine eigene Zeile anlegen, um einen Block für diesen Betrieb zu überschreiben.@else Noch keine Fixkosten erfasst.@endif</td></tr>
                @endforelse
                {{-- Neue Zeile --}}
                <tr class="border-t-2 border-black/5">
                    <td class="{{ $td }}"><input type="text" wire:model="neuFix.label" wire:keydown.enter="fixHinzu" placeholder="z. B. Spülpersonal, LKW, Miete …" class="{{ $input }}" /></td>
                    <td class="{{ $td }}">
                        <select wire:model="neuFix.block_key" class="{{ $input }} !w-40 !py-1">
                            <option value="">— Block —</option>
                            @foreach($gkBloecke as $gk)<option value="{{ $gk['key'] }}">{{ $gk['label'] }}</option>@endforeach
                        </select>
                    </td>
                    <td class="{{ $td }} text-right"><input type="text" wire:model="neuFix.amount" wire:keydown.enter="fixHinzu" placeholder="0" class="{{ $input }} !w-24 text-right tabular-nums" /></td>
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
        <div class="flex flex-col gap-1 pt-2 border-t border-black/5">
            <div class="flex items-center justify-between">
                <span class="text-xs font-medium text-gray-900">Σ Fixkosten / Monat
                    @if($scopeOutletName && count($fixListe) === 0)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }} ml-1">komplett vom Team geerbt</span>
                    @elseif($scopeOutletName)
                        <span class="{{ $pill }} {{ $variantPill['info'] }} ml-1">eigen + geerbt</span>
                    @endif
                </span>
                <span class="tabular-nums text-sm font-semibold text-gray-900">{{ number_format((float) $fixMonatGesamt, 2, ',', '.') }} €</span>
            </div>
            @if($scopeOutletName && count($fixListe) === 0)
                <p class="text-[10px] text-purple-700">„{{ $scopeOutletName }}" hat noch keine eigenen Fixkosten → nutzt die Team-Fixkosten (leer = erbt, nicht 0). Unten eine Zeile anlegen, um einen Block für diesen Betrieb abweichend zu setzen.</p>
            @endif
            @if($fixMonatGesamt > 0)
                <div class="flex flex-wrap gap-1">
                    @foreach($fixSummen as $bk => $summe)
                        @if($summe > 0)
                            <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ collect($gkBloecke)->firstWhere('key', $bk)['label'] ?? $bk }}: {{ number_format((float) $summe, 2, ',', '.') }} €/Mt</span>
                        @endif
                    @endforeach
                </div>
                <p class="text-[10px] text-gray-500">Davon fließen pro Block ÷ Bezugsbasis die abgeleiteten Zuschlag-% (Modus „aus Fixkosten").</p>
            @endif
        </div>
    </div>

</div>
