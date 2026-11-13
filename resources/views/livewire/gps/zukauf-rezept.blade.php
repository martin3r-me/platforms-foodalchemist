{{-- Zukauf-Basisrezept am Grundprodukt: Link aufs vorhandene oder Knopf zum Anlegen. Nur bei Fertigware. --}}
<div data-gp-zukauf>
    @if($angeboten)
        @if($rezept !== null)
            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]" data-gp-zukauf-rezept>
                Zukauf-Basisrezept:
                <a href="{{ route('foodalchemist.recipes.index', ['rezept' => $rezept->id]) }}"
                   wire:click.prevent="$dispatch('recipe-modal.oeffnen', { id: {{ $rezept->id }} })"
                   class="text-[var(--fa-accent)] hover:underline" data-gp-zukauf-link>{{ $rezept->name }}</a>
            </p>
        @elseif($darf)
            <div class="flex flex-col gap-2">
                @if(! $formOffen)
                    <div>
                        <x-fa::button size="sm" variant="secondary" icon="heroicon-o-book-open" wire:click="formUmschalten" data-gp-zukauf-knopf
                            title="Basisrezept, das genau diese Ware führt und die Handgriffe beschreibt (Rüsten, Regenerieren, Portionieren). Rezepte, die das Grundprodukt nutzen, bleiben unverändert.">Als Zukauf-Basisrezept anlegen</x-fa::button>
                    </div>
                @else
                    <div class="flex flex-wrap items-end gap-2" data-gp-zukauf-form>
                        <x-fa::field label="Typ" for="gp-zukauf-typ" :hint="$typAbgeleitet === null ? 'Aus der Warengruppe nicht ableitbar (Regel basisrezept.zukauf.typ), bitte wählen.' : null">
                            <x-fa::select id="gp-zukauf-typ" size="sm" wire:model="typ" placeholder="Typ wählen" data-gp-zukauf-typ>
                                @foreach($typen as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                            </x-fa::select>
                        </x-fa::field>
                        <x-fa::field label="Bezeichnung" for="gp-zukauf-bez">
                            <x-fa::input id="gp-zukauf-bez" size="sm" wire:model="bezeichnung" data-gp-zukauf-bezeichnung />
                        </x-fa::field>
                        <x-fa::button size="sm" variant="primary" wire:click="anlegen" wire:loading.attr="disabled" wire:target="anlegen" data-gp-zukauf-anlegen>Anlegen</x-fa::button>
                        <x-fa::button size="sm" variant="ghost" wire:click="formUmschalten">Abbrechen</x-fa::button>
                    </div>
                    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Name: „Typ: Bezeichnung (Zukauf)“. Die Ware wird nicht nachgekocht, das Basisrezept beschreibt die Handgriffe. Danach öffnet der Editor für Rüst- und Regenerationszeit.</p>
                @endif
                @if($fehler !== null)
                    <x-fa::notice tone="crit" data-gp-zukauf-fehler>{{ $fehler }}</x-fa::notice>
                @endif
            </div>
        @endif
    @endif
</div>
