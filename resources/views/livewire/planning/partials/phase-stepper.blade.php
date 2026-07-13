{{-- R4.3 Phasen-Stepper (Trait ManagesPhase): Kontext → Struktur → Befüllung →
     Kalkulation → Freigabe. Erwartet $phaseAktuell (string). Freigabe-Gate-Fehler
     öffnet das Override-Feld (Begründung wird protokolliert). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))
@php($phasen = $this->phasenListe())
@php($aktuellerIndex = array_search($phaseAktuell, array_keys($phasen), true))

<div class="space-y-1.5" data-phase-stepper data-phase-aktuell="{{ $phaseAktuell }}">
    <div class="flex items-center gap-1 flex-wrap">
        <span class="text-[10px] text-gray-400 uppercase tracking-wider mr-1">Phase</span>
        @foreach($phasen as $key => $lbl)
            @php($i = array_search($key, array_keys($phasen), true))
            <button type="button" wire:click="phaseSetzen('{{ $key }}')"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] border transition-colors
                        {{ $key === $phaseAktuell
                            ? 'bg-violet-500/15 border-violet-500/40 text-violet-700 dark:text-violet-300 font-medium'
                            : ($i < (int) $aktuellerIndex
                                ? 'bg-emerald-500/10 border-emerald-500/25 text-emerald-700 dark:text-emerald-400'
                                : 'bg-black/[0.03] dark:bg-white/5 border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300') }}"
                    title="{{ $key === 'freigabe' ? 'Gate: nur ohne rote Coverage-Ampeln (Override mit Begründung möglich)' : $lbl }}"
                    data-phase-btn="{{ $key }}">
                {{ $lbl }}
            </button>
            @if(! $loop->last)<span class="text-gray-300 dark:text-gray-600 text-[10px]">›</span>@endif
        @endforeach
    </div>

    @if($phaseFehler)
        <div class="rounded-lg bg-rose-500/10 border border-rose-500/30 px-3 py-1.5 text-[11px] text-rose-700 dark:text-rose-300" data-phase-fehler>{{ $phaseFehler }}</div>
    @endif
    @if($phaseOverrideOffen)
        <div class="flex items-center gap-2" data-phase-override>
            <input type="text" wire:model="phaseOverrideNote" placeholder="Override-Begründung (wird protokolliert) …" class="{{ $input }} flex-1" />
            <button type="button" wire:click="phaseSetzen('freigabe')" class="{{ $btnGhostXs }} text-rose-600 dark:text-rose-400">Trotzdem freigeben</button>
        </div>
    @endif
</div>
