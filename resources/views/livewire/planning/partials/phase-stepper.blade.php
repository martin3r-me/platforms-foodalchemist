{{-- R4.3 Phasen-Stepper (Trait ManagesPhase): Kontext → Struktur → Befüllung →
     Kalkulation → Freigabe. Erwartet $phaseAktuell (string). Scheitert die Freigabe an offenen
     Lücken, öffnet sich das Begründungsfeld (Begründung wird protokolliert). fa-pass: nur Tokens. --}}
@php
    $phasen = $this->phasenListe();
    $aktuellerIndex = array_search($phaseAktuell, array_keys($phasen), true);
@endphp

<div class="flex flex-col gap-2" data-phase-stepper data-phase-aktuell="{{ $phaseAktuell }}">
    <span class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Phase</span>
    <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-2">
        @foreach($phasen as $key => $lbl)
            @php
                $i = array_search($key, array_keys($phasen), true);
                $istAktuell = $key === $phaseAktuell;
                $istErledigt = ! $istAktuell && $i < (int) $aktuellerIndex;
            @endphp
            <li class="flex items-center gap-1.5">
                <button type="button" wire:click="phaseSetzen('{{ $key }}')"
                        class="inline-flex items-center gap-1.5 h-8 px-3 rounded-full border text-[length:var(--fa-text-sm)] font-medium transition-colors
                            {{ $istAktuell
                                ? 'bg-[var(--fa-accent-soft)] border-[var(--fa-accent)] text-[var(--fa-accent)]'
                                : ($istErledigt
                                    ? 'bg-[var(--fa-ok-soft)] border-transparent text-[var(--fa-ok)]'
                                    : 'bg-[var(--fa-surface)] border-[var(--fa-line-strong)] text-[var(--fa-ink-2)] hover:text-[var(--fa-ink)]') }}"
                        title="{{ $key === 'freigabe' ? 'Freigabe nur ohne rote Lücken im Soll-Ist-Abgleich, sonst mit Begründung' : $lbl }}"
                        @if($istAktuell) aria-current="step" @endif
                        data-phase-btn="{{ $key }}">
                    @if($istErledigt)@svg('heroicon-m-check', 'w-3.5 h-3.5')@else<span class="tabular-nums text-[var(--fa-ink-3)]">{{ $i + 1 }}</span>@endif
                    {{ $lbl }}
                </button>
                @if(! $loop->last)@svg('heroicon-m-chevron-right', 'w-4 h-4 text-[var(--fa-ink-3)]')@endif
            </li>
        @endforeach
    </ol>

    @if($phaseFehler)
        <x-fa::notice tone="crit" data-phase-fehler>{{ $phaseFehler }}</x-fa::notice>
    @endif
    @if($phaseOverrideOffen)
        <div class="flex flex-wrap items-center gap-2" data-phase-override>
            <x-fa::input wire:model="phaseOverrideNote" placeholder="Begründung für die Freigabe trotz Lücken (wird protokolliert) …" class="flex-1 min-w-[16rem]" aria-label="Begründung für die Freigabe" />
            <x-fa::button variant="danger" icon="heroicon-m-exclamation-triangle" wire:click="phaseSetzen('freigabe')">Trotzdem freigeben</x-fa::button>
        </div>
    @endif
</div>
