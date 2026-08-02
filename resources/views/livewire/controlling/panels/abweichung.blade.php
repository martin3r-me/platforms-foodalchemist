{{-- Spec 32 · C4 — die echte Wareneinsatzquote und die Abweichung zur Rezeptur.
     Überall sonst im Modul steht der KALKULIERTE Wareneinsatz; hier steht der gemessene. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($eur = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', '.') . ' €')
@php($pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.') . ' %')

<div class="space-y-3" data-ctrl-abweichung>
    <div class="flex items-end justify-between gap-3 flex-wrap">
        <p class="text-[11px] text-gray-500 max-w-2xl">
            Einkaufsjournal gegen Verkaufsjournal — die einzige Stelle im Modul, an der die
            Wareneinsatzquote <strong>gemessen</strong> und nicht gerechnet wird.
        </p>
        <div class="flex items-end gap-2">
            <div>
                <label class="block text-[10px] text-gray-600 mb-1">von</label>
                <input type="date" wire:model.live="von" class="{{ $input }} !w-36" data-ctrl-abw-von />
            </div>
            <div>
                <label class="block text-[10px] text-gray-600 mb-1">bis</label>
                <input type="date" wire:model.live="bis" class="{{ $input }} !w-36" />
            </div>
            <button type="button" wire:click="vormonat" class="{{ $btnGhostXs }}">Vormonat</button>
        </div>
    </div>

    @if($a === null)
        <p class="text-xs text-gray-500">Kein Team zugeordnet.</p>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2">
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Umsatz</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ $eur($a['umsatz']) }}</div>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Einkauf</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ $eur($a['einkauf']) }}</div>
            </div>
            {{-- Der Leitwert: Ist gegen Ziel. Nur hier eine Ampel — nie zwei nebeneinander. --}}
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Wareneinsatz Ist</div>
                <div class="text-lg font-semibold tabular-nums {{ $a['ist_delta_pp'] === null ? 'text-gray-900' : ($a['ist_delta_pp'] > 0 ? 'text-rose-700' : 'text-emerald-700') }}">
                    {{ $pct($a['ist_pct']) }}
                </div>
                <div class="text-[10px] text-gray-500">Ziel {{ $pct($a['ziel_pct']) }}@if($a['ist_delta_pp'] !== null) · {{ $a['ist_delta_pp'] > 0 ? '+' : '' }}{{ number_format((float) $a['ist_delta_pp'], 1, ',', '.') }} pp @endif</div>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">laut Rezeptur</div>
                {{-- 0,00 € sähe aus wie ein Messwert. Ohne belastbare Datenlage steht hier
                     nichts — dieselbe Zurückhaltung wie bei der Abweichung daneben. --}}
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ $a['theoretisch'] > 0 ? $eur($a['theoretisch']) : '—' }}</div>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Abweichung</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900" data-ctrl-abw-wert>
                    {{ $a['abweichung_eur'] === null ? '—' : (($a['abweichung_eur'] > 0 ? '+' : '') . $eur($a['abweichung_eur'])) }}
                </div>
                <div class="text-[10px] text-gray-500">
                    {{ $a['abweichung_pp'] === null ? 'nicht belastbar' : (($a['abweichung_pp'] > 0 ? '+' : '') . number_format((float) $a['abweichung_pp'], 1, ',', '.') . ' pp vom Umsatz') }}
                </div>
            </div>
            <div class="rounded-lg bg-black/[0.03] px-3 py-2">
                <div class="{{ $label }}">Zuordnung</div>
                <div class="text-lg font-semibold tabular-nums text-gray-900">{{ $pct($a['abdeckung_pct']) }}</div>
                <div class="text-[10px] text-gray-500">des Umsatzes hängt an einem Gericht</div>
            </div>
        </div>

        @if($a['hinweis'])
            <p class="text-[11px] text-amber-700" data-ctrl-abw-hinweis>{{ $a['hinweis'] }}</p>
        @elseif($a['abweichung_eur'] !== null)
            <p class="text-[11px] text-gray-500">
                @if($a['abweichung_eur'] > 0)
                    Es wurde mehr eingekauft, als die Rezepturen für den verkauften Absatz hergeben —
                    übliche Ursachen sind Verschnitt, Verderb, Überproduktion oder Lageraufbau.
                @else
                    Es wurde weniger eingekauft als rechnerisch nötig — meist Lagerabbau oder eine
                    zu hoch angesetzte Rezeptmenge.
                @endif
                Ohne Inventur bleibt das eine Perioden-Rechnung; über lange Zeiträume ist der Wert belastbarer.
            </p>
        @endif
    @endif
</div>
