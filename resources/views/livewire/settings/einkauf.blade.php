{{-- M1-05: Lead-LA-Strategie (V-27) — M1-06 ergänzt die Stamm-Lieferanten-Matrix --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($meldung)
        <div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-xs text-emerald-600 dark:text-emerald-400">{{ $meldung }}</p></div>
    @endif

    <div class="{{ $card }} p-5 space-y-4" data-einkauf-strategie>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Lead-LA-Strategie</h3>
            <p class="text-[11px] text-gray-400 mt-0.5">Entscheidet, welcher Lieferantenartikel je GP kalkulationsführend wird (V-27, speist die GL-03-Kette ab M3-06). Gilt nur für dein Team.</p>
        </div>

        <div class="space-y-2">
            @foreach($strategien as $s)
                <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer transition-all duration-150 {{ $strategie === $s->value ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : 'hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <input type="radio" wire:model.live="strategie" value="{{ $s->value }}" class="mt-0.5" />
                    <span>
                        <span class="block text-xs font-medium text-gray-900 dark:text-gray-100">{{ $s->label() }}</span>
                        <span class="block text-[11px] text-gray-400 mt-0.5">{{ $s->beschreibung() }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @if($strategie === 'prioritaets_kette')
            <div class="pt-3 border-t border-black/5 dark:border-white/5 space-y-2" data-prio-kette>
                <div class="{{ $label }}">Prioritäts-Kette (oben = höchste Priorität)</div>
                @forelse($prioritaeten as $i => $supplierId)
                    <div class="flex items-center gap-2" wire:key="prio-{{ $supplierId }}">
                        <span class="text-[11px] text-gray-400 w-5">{{ $i + 1 }}.</span>
                        <span class="text-xs text-gray-700 dark:text-gray-300 flex-1">{{ $lieferantenNamen[$supplierId] ?? "Lieferant #{$supplierId}" }}</span>
                        <button type="button" wire:click="prioHoch({{ $i }})" class="{{ $btnGhostXs }}" @if($i === 0) disabled @endif>↑</button>
                        <button type="button" wire:click="prioEntfernen({{ $i }})" class="{{ $btnGhostXs }} text-red-500">×</button>
                    </div>
                @empty
                    <p class="text-[11px] text-gray-400">Noch keine Lieferanten in der Kette.</p>
                @endforelse
                <div class="flex gap-2">
                    <select wire:model="neuerPrioLieferant" class="{{ $input }} !w-72">
                        <option value="">Lieferant wählen…</option>
                        @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="prioHinzu" class="{{ $btnGhostXs }}">+ zur Kette</button>
                </div>
            </div>
        @endif

        {{-- Phase 3: Strategie je Warengruppe (überschreibt die globale oben) --}}
        <div class="pt-3 border-t border-black/5 dark:border-white/5 space-y-2" data-strategie-per-wg>
            <div class="{{ $label }}">Strategie je Warengruppe (optional — überschreibt die globale)</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1">
                @foreach($warengruppen as $wg)
                    <div class="flex items-center gap-2 py-0.5" wire:key="strat-wg-{{ $wg->code }}">
                        <span class="flex-1 min-w-0 truncate text-xs text-gray-600 dark:text-gray-300">{{ $wg->name }}</span>
                        <select wire:model="strategiePerWg.{{ $wg->code }}" class="{{ $input }} !w-48 !py-0.5 !text-[11px]">
                            <option value="">— globale Strategie —</option>
                            @foreach($strategien as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach
                        </select>
                    </div>
                @endforeach
            </div>
        </div>

        <label class="flex items-center gap-2 pt-3 border-t border-black/5 dark:border-white/5 text-xs text-gray-700 dark:text-gray-300 cursor-pointer">
            <input type="checkbox" wire:model="ausweichKette" class="rounded border-gray-300" />
            Ausweich-Kette anzeigen (im GP-Detail: wer würde Lead, wenn der aktuelle ausfällt)
        </label>

        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
    </div>

    {{-- M1-06: Stamm-Lieferanten-Matrix (Lieferant × Warengruppe) --}}
    <div class="{{ $card }} p-5 space-y-1" data-stamm-matrix>
        <div class="mb-3">
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Stamm-Lieferanten-Matrix</h3>
            <p class="text-[11px] text-gray-400 mt-0.5">Je Warengruppe (+ global) — gewinnt bei Strategie „Stamm-Lieferant zuerst" (GL-03/V-27). Geerbte Einträge des Eltern-Teams sind fixiert.</p>
        </div>
        @if($fehler)
            <p class="text-xs text-red-600 dark:text-red-400 pb-2">{{ $fehler }}</p>
        @endif

        @foreach(collect([['', 'Global (alle Warengruppen)']])->concat($warengruppen->map(fn ($wg) => [$wg->code, $wg->code . ' ' . $wg->name])) as [$code, $titel])
            <div wire:key="stamm-zeile-{{ $code ?: 'global' }}" class="flex items-center gap-3 py-2 border-t border-black/5 dark:border-white/5 first:border-t-0">
                <span class="w-72 shrink-0 text-xs {{ $code === '' ? 'font-medium text-gray-900 dark:text-gray-100' : 'text-gray-600 dark:text-gray-300' }}">{{ $titel }}</span>
                <div class="flex-1 min-w-0 flex flex-wrap items-center gap-1.5">
                    @foreach($matrix->get($code, collect()) as $eintrag)
                        @php($eigen = \Platform\FoodAlchemist\Support\Curate::canCurate(auth()->user(), $eintrag))
                        <span wire:key="stamm-{{ $eintrag->id }}" class="inline-flex items-center gap-1 pl-2.5 {{ $eigen ? 'pr-1' : 'pr-2.5' }} py-0.5 rounded-full text-[11px] bg-violet-500/10 text-violet-700 dark:text-violet-300"
                              @unless($eigen) title="Geerbt vom Eltern-Team (D1)" @endunless>
                            {{ $eintrag->supplier?->name ?? ('#' . $eintrag->supplier_id) }}
                            @if($eigen)
                                <button type="button" wire:click="stammEntfernen({{ $eintrag->supplier_id }}, '{{ $code }}')"
                                        class="w-4 h-4 inline-flex items-center justify-center rounded-full text-violet-400 hover:text-red-500 hover:bg-red-500/10 transition-colors duration-150">×</button>
                            @endif
                        </span>
                    @endforeach
                    <select wire:model="stammNeu.{{ $code ?: '' }}" wire:change="stammSetzen('{{ $code }}')"
                            class="{{ $input }} !w-44 !py-0.5 !text-[11px]">
                        <option value="">+ Stamm…</option>
                        @foreach($lieferanten as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                    </select>
                </div>
            </div>
        @endforeach
    </div>
</div>
