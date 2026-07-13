{{-- M1-07: Kalkulations-Defaults (GL-02) — Recompute (M4-03) liest dieselben Getter. HK → eigene Sektion „Herstellkosten" (Phase 4). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($meldung)
        <div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400">{{ $meldung }}</p></div>
    @endif

    {{-- Garverlust-Defaults --}}
    <div class="{{ $card }} p-5 space-y-3" data-kalk-garverlust>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Garverlust-Defaults</h3>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">In % je GP-Klasse (Warengruppe). Kaskade: Zutat-Wert → GP-Default → dieser Team-Default → 0. Leer = kein Default.</p>
        </div>
        <div class="flex items-center gap-3 py-1.5 border-b border-black/5 dark:border-white/5">
            <span class="w-72 shrink-0 text-xs font-medium text-gray-900 dark:text-gray-100">* Global (alle Klassen)</span>
            <input type="text" wire:model="garverlust.*" placeholder="—" class="{{ $input }} !w-24" /> <span class="text-[11px] text-gray-500 dark:text-gray-400">%</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
            @foreach($warengruppen as $wg)
                <div class="flex items-center gap-3 py-1" wire:key="gv-{{ $wg->code }}">
                    <span class="w-64 shrink-0 text-xs text-gray-600 dark:text-gray-300 truncate">{{ $wg->name }}</span>
                    <input type="text" wire:model="garverlust.{{ $wg->code }}" placeholder="—" class="{{ $input }} !w-20 !py-1" /> <span class="text-[11px] text-gray-500 dark:text-gray-400">%</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Putzverlust-Defaults (Phase 2 — gleiche Kaskade wie Garverlust) --}}
    <div class="{{ $card }} p-5 space-y-3" data-kalk-putzverlust>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Putzverlust-Defaults</h3>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">In % je GP-Klasse (Warengruppe). Kaskade: Zutat-Wert → GP-Default → dieser Team-Default → 0. Leer = kein Default.</p>
        </div>
        <div class="flex items-center gap-3 py-1.5 border-b border-black/5 dark:border-white/5">
            <span class="w-72 shrink-0 text-xs font-medium text-gray-900 dark:text-gray-100">* Global (alle Klassen)</span>
            <input type="text" wire:model="putzverlust.*" placeholder="—" class="{{ $input }} !w-24" /> <span class="text-[11px] text-gray-500 dark:text-gray-400">%</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
            @foreach($warengruppen as $wg)
                <div class="flex items-center gap-3 py-1" wire:key="pv-{{ $wg->code }}">
                    <span class="w-64 shrink-0 text-xs text-gray-600 dark:text-gray-300 truncate">{{ $wg->name }}</span>
                    <input type="text" wire:model="putzverlust.{{ $wg->code }}" placeholder="—" class="{{ $input }} !w-20 !py-1" /> <span class="text-[11px] text-gray-500 dark:text-gray-400">%</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- MwSt + Rundung --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="{{ $card }} p-5 space-y-3" data-kalk-mwst>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">MwSt-Defaults</h3>
            <div class="flex items-center gap-3"><span class="w-32 text-xs text-gray-600 dark:text-gray-300">Regulär</span>
                <input type="text" wire:model="mwst.regulaer" class="{{ $input }} !w-24" /> <span class="text-[11px] text-gray-500 dark:text-gray-400">%</span></div>
            <div class="flex items-center gap-3"><span class="w-32 text-xs text-gray-600 dark:text-gray-300">Ermäßigt</span>
                <input type="text" wire:model="mwst.ermaessigt" class="{{ $input }} !w-24" /> <span class="text-[11px] text-gray-500 dark:text-gray-400">%</span></div>
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
                <select wire:model="rundung.mode" class="{{ $input }} !w-44">
                    <option value="kaufmaennisch">kaufmännisch</option>
                    <option value="auf">immer aufrunden</option>
                    <option value="ab">immer abrunden</option>
                </select></div>
            <p class="text-[11px] text-gray-500 dark:text-gray-400">Achtung GL-02 I7: Die Rundungs-REIHENFOLGE (Nenner = gerundetes yield_kg) ist fix — hier nur Stellen/Modus.</p>
        </div>
    </div>

    <p class="text-[11px] text-gray-500 dark:text-gray-400">Herstellkosten (Zuschlagsschema, Fixkosten, Bezugsbasen, Marge) → eigene Sektion <strong>„Herstellkosten"</strong> in der Navigation.</p>

    <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
</div>
