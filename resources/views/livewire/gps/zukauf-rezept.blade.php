{{-- Zukauf-Basisrezept am Grundprodukt: Link aufs vorhandene oder Knopf „planen“ (öffnet die Planung). Nicht bei Non-Food. --}}
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
                <div>
                    <x-fa::button size="sm" variant="secondary" icon="heroicon-o-book-open" wire:click="planen" data-gp-zukauf-knopf
                        title="Öffnet die Planung (Reiter Basisrezept): Basisrezept, das genau diese Ware führt und die Handgriffe beschreibt (Rüsten, Regenerieren, Portionieren). Rezepte, die das Grundprodukt nutzen, bleiben unverändert.">Zukauf-Basisrezept planen</x-fa::button>
                </div>
                @if($fehler !== null)
                    <x-fa::notice tone="crit" data-gp-zukauf-fehler>{{ $fehler }}</x-fa::notice>
                @endif
            </div>
        @endif
    @endif
</div>
