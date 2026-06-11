{{-- M1-05: Lead-LA-Strategie (V-27) — M1-06 ergänzt die Stamm-Lieferanten-Matrix --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-4">
    @if($meldung)
        <div class="{{ $card }} p-3 border-emerald-500/20"><p class="text-sm text-emerald-600 dark:text-emerald-400">{{ $meldung }}</p></div>
    @endif

    <div class="{{ $card }} p-5 space-y-4" data-einkauf-strategie>
        <div>
            <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Lead-LA-Strategie</h3>
            <p class="text-xs text-gray-400 mt-0.5">Entscheidet, welcher Lieferantenartikel je GP kalkulationsführend wird (V-27, speist die GL-03-Kette ab M3-06). Gilt nur für dein Team.</p>
        </div>

        <div class="space-y-2">
            @foreach($strategien as $s)
                <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer transition-all duration-150 {{ $strategie === $s->value ? 'bg-gradient-to-r from-violet-500/10 to-indigo-500/10' : 'hover:bg-black/[0.03] dark:hover:bg-white/5' }}">
                    <input type="radio" wire:model.live="strategie" value="{{ $s->value }}" class="mt-0.5" />
                    <span>
                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $s->label() }}</span>
                        <span class="block text-xs text-gray-400 mt-0.5">{{ $s->beschreibung() }}</span>
                    </span>
                </label>
            @endforeach
        </div>

        @if($strategie === 'prioritaets_kette')
            <div class="pt-3 border-t border-black/5 dark:border-white/5 space-y-2" data-prio-kette>
                <div class="{{ $label }}">Prioritäts-Kette (oben = höchste Priorität)</div>
                @forelse($prioritaeten as $i => $supplierId)
                    <div class="flex items-center gap-2" wire:key="prio-{{ $supplierId }}">
                        <span class="text-xs text-gray-400 w-5">{{ $i + 1 }}.</span>
                        <span class="text-sm text-gray-700 dark:text-gray-300 flex-1">{{ $lieferantenNamen[$supplierId] ?? "Lieferant #{$supplierId}" }}</span>
                        <button type="button" wire:click="prioHoch({{ $i }})" class="{{ $btnGhostXs }}" @if($i === 0) disabled @endif>↑</button>
                        <button type="button" wire:click="prioEntfernen({{ $i }})" class="{{ $btnGhostXs }} text-red-500">×</button>
                    </div>
                @empty
                    <p class="text-xs text-gray-400">Noch keine Lieferanten in der Kette.</p>
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

        <label class="flex items-center gap-2 pt-3 border-t border-black/5 dark:border-white/5 text-sm text-gray-700 dark:text-gray-300 cursor-pointer">
            <input type="checkbox" wire:model="ausweichKette" class="rounded border-gray-300" />
            Ausweich-Kette anzeigen (im GP-Detail: wer würde Lead, wenn der aktuelle ausfällt)
        </label>

        <button type="button" wire:click="speichern" class="{{ $btnPrimary }}">Speichern</button>
    </div>
</div>
