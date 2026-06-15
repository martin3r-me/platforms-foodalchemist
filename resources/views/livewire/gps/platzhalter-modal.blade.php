{{-- D-5: 📐 Platzhalter verwalten — anlegen / umbenennen / löschen --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-foodalchemist::modal name="platzhalter-modal" title="📐 Platzhalter verwalten" size="max-w-xl">
    @if($fehler !== null)
        <p class="text-xs text-rose-600 dark:text-rose-400 mb-3" data-platzhalter-fehler>{{ $fehler }}</p>
    @endif

    <x-foodalchemist::modal-section title="Neuer Platzhalter">
        <div class="flex items-end gap-2">
            <div class="flex-1">
                <input type="text" wire:model="neuName" wire:keydown.enter.prevent="anlegen"
                       placeholder="z. B. Flüssigkeit/Fond, Aromat, Stärke …" class="{{ $input }}" data-platzhalter-neu />
                <p class="text-[11px] text-gray-400 mt-1">„(neutral)" wird automatisch angehängt. Abstrakt — kein Lieferantenartikel, vom Matcher ausgeschlossen.</p>
            </div>
            <button type="button" wire:click="anlegen" wire:loading.attr="disabled" class="{{ $btnPrimary }} shrink-0" data-platzhalter-anlegen>+ Anlegen</button>
        </div>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Vorhandene Platzhalter ({{ $platzhalter->count() }})">
        <div class="space-y-1" data-platzhalter-liste>
            @forelse($platzhalter as $ph)
                <div class="flex items-center gap-2 rounded-lg border border-black/5 dark:border-white/10 px-3 py-1.5" wire:key="ph-{{ $ph->id }}" data-platzhalter-row="{{ $ph->id }}">
                    @if($editId === $ph->id)
                        <input type="text" wire:model="editName" wire:keydown.enter.prevent="speichernEdit"
                               class="{{ $input }} flex-1" data-platzhalter-edit />
                        <button type="button" wire:click="speichernEdit" class="{{ $btnGhostXs }} text-emerald-600" title="Speichern">✓</button>
                        <button type="button" wire:click="abbrechenEdit" class="{{ $btnGhostXs }}" title="Abbrechen">✕</button>
                    @else
                        <span class="flex-1 text-xs text-gray-800 dark:text-gray-100">{{ $ph->name }}</span>
                        <span class="{{ $pill }} {{ $ph->in_zeilen > 0 ? $variantPill['info'] : $variantPill['secondary'] }}">{{ $ph->in_zeilen }}× genutzt</span>
                        <button type="button" wire:click="startEdit({{ $ph->id }}, @js($ph->name))" class="{{ $btnGhostXs }}" title="Umbenennen">✎</button>
                        <button type="button" wire:click="loeschen({{ $ph->id }})" wire:confirm="Diesen Platzhalter wirklich löschen?"
                                @disabled($ph->in_zeilen > 0) class="{{ $btnGhostXs }} text-rose-600 disabled:opacity-40"
                                title="{{ $ph->in_zeilen > 0 ? 'Wird genutzt — erst aus Rezepten entfernen' : 'Löschen' }}">🗑</button>
                    @endif
                </div>
            @empty
                <p class="text-[11px] text-gray-400">Noch keine Platzhalter. Lege oben den ersten an.</p>
            @endforelse
        </div>
    </x-foodalchemist::modal-section>

    <x-slot:footer>
        <button type="button" wire:click="$dispatch('modal.close', { name: 'platzhalter-modal' })" class="{{ $btnGhost }}">Schließen</button>
    </x-slot:footer>
</x-foodalchemist::modal>
