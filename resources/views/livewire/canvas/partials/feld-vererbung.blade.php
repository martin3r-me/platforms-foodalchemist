{{-- Spec 82 §7 · Statuszeile unter einem Skalar-Feld der Team-DNA: Herkunft („geerbt von …“), „verbindlich“ und im
     Oberteam der Verbindlich-Schalter. Leer, wenn das Board nicht die Team-DNA zeigt ($v === null und kein Schalter). --}}
@if($v || $verbMoeglich)
    <div class="-mt-1 flex flex-wrap items-center gap-2 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]" data-canvas-vererbung="{{ $f['key'] }}">
        @if($v && $v['gesperrt'])
            <x-fa::badge tone="accent" icon="heroicon-m-lock-closed">Verbindlich vom Oberteam {{ $v['quelle'] }}</x-fa::badge>
            <span>Dieses Feld gilt unverändert und lässt sich hier nicht ändern.</span>
        @elseif($v && $v['verbindlich'] && $v['eigen'])
            <x-fa::badge tone="accent" icon="heroicon-m-lock-closed">Verbindlich für alle Unter-Teams</x-fa::badge>
        @elseif($v && $v['quelle'] && ! $v['eigen'])
            <x-fa::badge>Geerbt von {{ $v['quelle'] }}</x-fa::badge>
            <span>Ein eigener Wert überschreibt ihn.</span>
        @endif
        @if($verbMoeglich)
            <label class="inline-flex items-center gap-1.5 cursor-pointer" data-canvas-verbindlich-schalter="{{ $f['key'] }}">
                <input type="checkbox" class="w-4 h-4 rounded accent-[var(--fa-accent)]" @checked(! empty($canvasVerbindlich[$f['key']]))
                       wire:click="verbindlichSchalten('{{ $f['key'] }}')" />
                {{ $canvasType === 'kunde_dna' ? 'Bleibt – unverhandelbar' : 'Für Unter-Teams verbindlich' }}
            </label>
        @endif
    </div>
@endif
