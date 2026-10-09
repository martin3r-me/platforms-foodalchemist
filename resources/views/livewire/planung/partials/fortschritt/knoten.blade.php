{{-- Spec 80 D2: ein Baumknoten (rekursiv). Ebene klein über dem Namen, Zähler rechts (Fehler vor offen). --}}
<ul class="flex flex-col gap-px {{ $tiefe > 0 ? 'pl-3' : '' }}">
    @foreach($knoten as $k)
        @php $aktiv = $fortschrittKnoten === $k['key']; @endphp
        <li wire:key="fk-{{ $k['key'] }}">
            @if(! empty($k['struktur']))
                {{-- Spec 80 F4: Titel/Freitext/Leerzeile an ihrer Stelle — ruhig, nicht klickbar. --}}
                <div class="grid grid-cols-1 px-2 py-1 text-[var(--fa-ink-3)]" data-fortschritt-struktur="{{ $k['key'] }}">
                    <span class="text-[length:var(--fa-text-sm)] uppercase tracking-wide">{{ $k['art'] }}</span>
                    <span class="truncate text-[length:var(--fa-text-md)] italic" title="{{ $k['label'] }}">{{ $k['label'] }}</span>
                </div>
            @else
            <button type="button" wire:click="waehleKnoten('{{ $k['key'] }}')"
                class="grid w-full grid-cols-[minmax(0,1fr)_auto] gap-x-2 rounded-[var(--fa-radius-control)] px-2 py-1.5 text-left {{ $aktiv ? 'bg-[var(--fa-accent-soft)] text-[var(--fa-accent)]' : 'text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]' }}"
                @if($aktiv) aria-current="true" @endif data-fortschritt-knoten="{{ $k['key'] }}">
                <span class="text-[length:var(--fa-text-sm)] uppercase tracking-wide text-[var(--fa-ink-3)]">{{ $k['art'] }}</span>
                <span class="row-span-2 self-center text-[length:var(--fa-text-sm)] tabular-nums {{ $k['fehler'] > 0 ? 'text-[var(--fa-crit)]' : 'text-[var(--fa-ink-3)]' }}">{{ $k['fehler'] > 0 ? $k['fehler'] . ' Fehler' : $k['offen'] . ' offen' }}</span>
                <span class="truncate text-[length:var(--fa-text-md)]" title="{{ $k['label'] }}">{{ $k['label'] }}</span>
            </button>
            @endif
            @if(! empty($k['kinder']))
                @include('foodalchemist::livewire.planung.partials.fortschritt.knoten', ['knoten' => $k['kinder'], 'tiefe' => $tiefe + 1])
            @endif
        </li>
    @endforeach
</ul>
