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

    {{-- M-K1/Doc 16: Herstellkosten — Kostenblock-Schema --}}
    @php($einheit = ['pct_we' => '% auf Wareneinsatz', 'pct_hk' => '% auf lfd. HK', 'eur_pro_portion' => '€ / Portion', 'arbeitszeit' => '€ / h'])
    <div class="{{ $card }} p-5 space-y-3" data-kalk-hk2>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Herstellkosten — Kostenblöcke (HK2)</h3>
            <p class="text-[11px] text-gray-400 mt-0.5"><strong>HK2 = Wareneinsatz + Σ aktive Blöcke</strong> (Lohn aus Arbeitszeit × Stundensatz, Verpackung, Schwund, Lager, Gemeinkosten). <strong>VK-Vorschlag = HK2 × (1 + Marge)</strong>. Speist die Kalkulations-Übersicht und die Cockpits.</p>
        </div>

        <table class="{{ $table }}">
            <thead><tr>
                <th class="{{ $th }} text-left">Block</th>
                <th class="{{ $th }} text-left">Einheit</th>
                <th class="{{ $th }} text-center">aktiv</th>
                <th class="{{ $th }} text-right">Wert</th>
            </tr></thead>
            <tbody>
                @foreach($schema as $i => $b)
                    <tr wire:key="kblock-{{ $b['key'] }}" class="{{ $tr }}">
                        <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">{{ $b['label'] }}</td>
                        <td class="{{ $td }} text-gray-400 text-[11px]">{{ $einheit[$b['typ']] ?? $b['typ'] }}</td>
                        <td class="{{ $td }} text-center"><input type="checkbox" wire:model="schema.{{ $i }}.aktiv" class="rounded border-gray-300 text-violet-500 focus:ring-violet-500/30" /></td>
                        <td class="{{ $td }} text-right"><input type="text" wire:model="schema.{{ $i }}.wert" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="0" /></td>
                    </tr>
                @endforeach
                <tr class="{{ $tr }}">
                    <td class="{{ $td }} font-medium text-gray-900 dark:text-gray-100">Gemeinkosten</td>
                    <td class="{{ $td }} text-gray-400 text-[11px]">% auf lfd. HK</td>
                    <td class="{{ $td }} text-center text-gray-300">immer</td>
                    <td class="{{ $td }} text-right"><input type="text" wire:model="hk2Zuschlag" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="0" /></td>
                </tr>
            </tbody>
        </table>

        <div class="flex items-center gap-3 pt-1 border-t border-black/5 dark:border-white/10">
            <span class="w-40 text-xs text-gray-600 dark:text-gray-300">Marge (→ VK-Vorschlag)</span>
            <input type="text" wire:model="marge" class="{{ $input }} !w-24 text-right tabular-nums" placeholder="15" /> <span class="text-[11px] text-gray-400">% auf HK2</span>
        </div>
        <p class="text-[11px] text-gray-400">Lohn-Wert = €/h (greift auf die Arbeitszeit je Rezept; Rollup Gericht→Paket→Concept). Schwund-Default 0 % — der Wareneinsatz ist über GL-02 bereits verlustkorrigiert (Doppelzählung vermeiden, D-K3).</p>
    </div>

    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
</div>
