{{-- Umbau-Spec Phase 4b: Concepter-Facetten pflegen (F3–F6) --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<div class="space-y-6" data-settings-concepter-dimensionen>
    <div>
        <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Concepter-Dimensionen</h3>
        <p class="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">Facetten der Concepts: Einsatzmoment · Eventtyp · Saison · Servierform. Die Servierform ist das Scharnier zur Darreichungs-Auflösung der Gerichte (Slot → passende Variante). ✕ deaktivieren · 🗑 löschen (nur wenn ungenutzt; WaWi-Servierformen nur deaktivierbar).</p>
    </div>
    @if($fehler !== null)<p class="text-xs text-rose-600 dark:text-rose-400" data-dimensionen-fehler>{{ $fehler }}</p>@endif
    @if($meldung !== null)<p class="text-xs text-emerald-600 dark:text-emerald-400" data-dimensionen-meldung>{{ $meldung }}</p>@endif

    @foreach($listen as $key => $vokabular)
        <div data-dimension="{{ $key }}">
            <p class="{{ $dt }} mb-0.5">{{ $vokabular['label'] }} ({{ $vokabular['zeilen']->count() }})</p>
            <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1.5">{{ $vokabular['hint'] }}</p>

            <div class="flex flex-wrap gap-1">
                @foreach($vokabular['zeilen'] as $zeile)
                    <span wire:key="dim-{{ $key }}-{{ $zeile->id }}"
                          class="{{ $pill }} {{ $variantPill['secondary'] }} group {{ $zeile->is_inactive ? 'opacity-40 line-through' : '' }}"
                          title="{{ $key === 'servierformen' ? ($zeile->code . ($zeile->legacy_id !== null ? ' · WaWi-Master' : ' · FA-nativ')) : '' }}">
                        {{ $zeile->label ?? $zeile->name }}
                        <button type="button" wire:click="toggleInactive('{{ $key }}', {{ $zeile->id }})"
                                class="hidden group-hover:inline ml-0.5 {{ $zeile->is_inactive ? 'text-emerald-500' : 'text-rose-400' }}"
                                title="{{ $zeile->is_inactive ? 'aktivieren' : 'deaktivieren' }}">{{ $zeile->is_inactive ? '↻' : '✕' }}</button>
                        @if(! ($key === 'servierformen' && $zeile->legacy_id !== null))
                            <button type="button" wire:click="delete('{{ $key }}', {{ $zeile->id }})" wire:confirm="Diesen Eintrag löschen?"
                                    class="hidden group-hover:inline ml-0.5 text-rose-500" title="löschen (nur wenn ungenutzt)">🗑</button>
                        @endif
                    </span>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2 mt-1.5" data-dimension-anlegen="{{ $key }}">
                <input type="text" wire:model="neu.{{ $key }}" placeholder="Neu: Name" class="{{ $input }} !py-1 w-44"
                       wire:keydown.enter="create('{{ $key }}')" />
                <button type="button" wire:click="create('{{ $key }}')" class="{{ $btnGhostXs }} text-violet-600 dark:text-violet-400">+ Anlegen</button>
            </div>
        </div>
    @endforeach
</div>
