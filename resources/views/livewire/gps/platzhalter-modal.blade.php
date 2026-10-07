{{-- D-5: Platzhalter verwalten (anlegen, umbenennen, löschen). fa-pass Welle 2: Bausteine <x-fa::…>. --}}
<x-foodalchemist::modal name="platzhalter-modal" title="Platzhalter verwalten" size="max-w-xl">
    @if($fehler !== null)
        <x-fa::notice tone="crit" class="mb-3" data-platzhalter-fehler>{{ $fehler }}</x-fa::notice>
    @endif

    <x-foodalchemist::modal-section title="Neuer Platzhalter">
        <div class="flex items-start gap-2">
            <x-fa::field class="flex-1" for="platzhalter-neu" hint="„(neutral)“ wird angehängt. Ein Platzhalter hat keinen Lieferantenartikel und wird bei der Zuordnung übersprungen.">
                <x-fa::input id="platzhalter-neu" wire:model="neuName" wire:keydown.enter.prevent="anlegen"
                    placeholder="z. B. Flüssigkeit/Fond, Aromat, Stärke" aria-label="Name des neuen Platzhalters" data-platzhalter-neu />
            </x-fa::field>
            <x-fa::button variant="primary" icon="heroicon-m-plus" class="shrink-0" wire:click="anlegen" wire:loading.attr="disabled" data-platzhalter-anlegen>Platzhalter anlegen</x-fa::button>
        </div>
    </x-foodalchemist::modal-section>

    <x-foodalchemist::modal-section title="Vorhandene Platzhalter ({{ $platzhalter->count() }})">
        <div class="flex flex-col gap-1" data-platzhalter-liste>
            @forelse($platzhalter as $ph)
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-[var(--fa-radius-control)] border border-[var(--fa-line)]" wire:key="ph-{{ $ph->id }}" data-platzhalter-row="{{ $ph->id }}">
                    @if($editId === $ph->id)
                        <x-fa::input size="sm" class="flex-1" wire:model="editName" wire:keydown.enter.prevent="speichernEdit" aria-label="Neuer Name" data-platzhalter-edit />
                        <x-fa::button size="sm" wire:click="abbrechenEdit">Abbrechen</x-fa::button>
                        <x-fa::button size="sm" variant="primary" wire:click="speichernEdit">Namen speichern</x-fa::button>
                    @else
                        <span class="flex-1 min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">{{ $ph->name }}</span>
                        <x-fa::badge :tone="$ph->in_zeilen > 0 ? 'info' : 'neutral'" class="shrink-0">{{ $ph->in_zeilen }}× genutzt</x-fa::badge>
                        <x-fa::icon-button size="sm" icon="heroicon-o-pencil" label="Umbenennen" wire:click="startEdit({{ $ph->id }}, @js($ph->name))" />
                        <x-fa::icon-button size="sm" tone="danger" icon="heroicon-o-trash"
                            :label="$ph->in_zeilen > 0 ? 'Wird genutzt, erst aus den Rezepten entfernen' : 'Löschen'"
                            wire:click="loeschen({{ $ph->id }})" wire:confirm="Diesen Platzhalter wirklich löschen?"
                            :disabled="$ph->in_zeilen > 0" class="disabled:opacity-40 disabled:pointer-events-none" />
                    @endif
                </div>
            @empty
                <x-fa::empty compact icon="heroicon-o-square-2-stack" title="Noch keine Platzhalter">Oben einen Namen eingeben und anlegen.</x-fa::empty>
            @endforelse
        </div>
    </x-foodalchemist::modal-section>

    <x-slot:footer>
        <x-fa::button variant="ghost" wire:click="$dispatch('modal.close', { name: 'platzhalter-modal' })">Schließen</x-fa::button>
    </x-slot:footer>
</x-foodalchemist::modal>
