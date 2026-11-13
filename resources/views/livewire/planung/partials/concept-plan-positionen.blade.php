{{-- Leitstelle, Plan-Panel: Menü-Aufbau mit Gericht-Vorgaben (Trait PlanGerichtVorgaben).
     Belegte Position: zeigt das Gericht. Leere Position: Feld für die Vorgabe — mit Vorgabe entsteht beim
     Erstellen genau dieses Gericht, ohne Vorgabe erfindet die KI. Schreibt beim Verlassen des Felds. --}}
@php $positionen = $this->planPositionen(); @endphp
<div class="mt-4 flex flex-col gap-1.5" data-plan-positionen>
    <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Menü-Aufbau
        <span class="font-normal text-[var(--fa-ink-3)]">· {{ count($positionen) }} {{ count($positionen) === 1 ? 'Position' : 'Positionen' }}. Mit Vorgabe entsteht genau dieses Gericht, ohne Vorgabe erfindet die KI.</span>
    </p>
    @if($positionen !== [])
        <ol class="flex flex-col divide-y divide-[var(--fa-line)] rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]">
            @foreach($positionen as $pos)
                <li wire:key="plan-pos-{{ $pos['id'] }}" class="flex flex-wrap items-center gap-2 px-3 py-1.5 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]" data-plan-speise>
                    <span class="w-5 text-right text-[var(--fa-ink-3)] tabular-nums">{{ $loop->iteration }}.</span>
                    <span class="w-32 shrink-0 truncate text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ $pos['rolle'] !== '' ? $pos['rolle'] : ($pos['titel'] !== '' ? $pos['titel'] : 'Position') }}</span>
                    @if($pos['leer'])
                        <x-fa::input size="sm" class="flex-1 min-w-[14rem]" value="{{ $pos['vorgabe'] }}" :disabled="$laeuft"
                            wire:change="planVorgabeSpeichern({{ $pos['id'] }}, $event.target.value)"
                            placeholder="Gericht vorgeben, sonst erfindet die KI" aria-label="Gericht-Vorgabe für Position {{ $loop->iteration }}" data-plan-vorgabe />
                        @if($pos['vorgabe'] !== '')<x-fa::badge tone="accent">Vorgabe</x-fa::badge>@endif
                    @else
                        <span class="flex-1 min-w-0 break-words font-medium">{{ $pos['gericht'] ?? 'belegt' }}</span>
                        <x-fa::badge tone="ok">steht</x-fa::badge>
                    @endif
                    @if($pos['pflicht'])<x-fa::badge tone="neutral">Pflicht</x-fa::badge>@endif
                </li>
            @endforeach
        </ol>
    @endif
    <div class="flex flex-wrap items-center gap-2">
        <x-fa::input size="sm" class="flex-1 min-w-[14rem] max-w-xl" wire:model="planNeueVorgabe" wire:keydown.enter.prevent="planPositionMitVorgabe" :disabled="$laeuft"
            placeholder="Weiteres Gericht vorgeben, zum Beispiel Graved Karotte | Dillcreme | Brot-Crumble" aria-label="Weitere Position mit Gericht-Vorgabe" data-plan-vorgabe-neu />
        <x-fa::button size="sm" variant="secondary" icon="heroicon-m-plus" wire:click="planPositionMitVorgabe" :disabled="$laeuft" data-plan-vorgabe-hinzu>Position hinzufügen</x-fa::button>
    </div>
</div>
