{{-- Reiter Basisrezept: Zukauf-Ware der Planung (Herkunfts-GP + Typ) oder „Zukauf-Ware wählen“. --}}
@php($leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]')
<div class="mb-3" data-planung-zukauf>
    @if($gp !== null)
        <x-fa::section variant="plain" title="Zukauf-Basisrezept" icon="heroicon-o-shopping-bag" data-planung-zukauf-ware>
            <p class="text-[length:var(--fa-text-md)] text-[var(--fa-ink)]">Ware: <span class="font-medium" data-planung-zukauf-gp>{{ $gp->name }}</span></p>
            <p class="{{ $leise }}">Die Ware wird nicht nachgekocht. Das Basisrezept führt genau diese Ware und beschreibt die Handgriffe (Rüsten, Regenerieren, Portionieren). Rüst- und Regenerationszeit danach im Basisrezept-Editor pflegen.</p>
            @if($rezept !== null)
                <x-fa::notice tone="info" data-planung-zukauf-vorhanden>Schon angelegt:
                    <a href="#" wire:click.prevent="$dispatch('recipe-modal.oeffnen', { id: {{ $rezept->id }} })" class="text-[var(--fa-accent)] hover:underline">{{ $rezept->name }}</a></x-fa::notice>
            @else
                <div class="mt-2 max-w-xs">
                    <x-fa::field label="Typ" for="planung-zukauf-typ" :hint="$typVorschlag === null ? 'Aus der Warengruppe nicht ableitbar (Regel basisrezept.zukauf.typ), bitte wählen.' : 'Vorschlag aus der Warengruppe: ' . $typVorschlag">
                        <x-fa::select id="planung-zukauf-typ" size="sm" wire:model.live="typ" placeholder="Typ wählen" data-planung-zukauf-typ>
                            @foreach($typen as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
                        </x-fa::select>
                    </x-fa::field>
                </div>
            @endif
        </x-fa::section>
    @else
        <div class="flex flex-col gap-2">
            <div>
                <x-fa::button size="sm" variant="ghost" icon="heroicon-o-shopping-bag" wire:click="sucheUmschalten" data-planung-zukauf-waehlen
                    title="Basisrezept für gekaufte Ware: führt genau diese Ware und beschreibt die Handgriffe, statt sie nachzukochen.">Zukauf-Ware wählen</x-fa::button>
            </div>
            @if($suchen)
                <div class="max-w-md flex flex-col gap-1">
                    <x-fa::input size="sm" wire:model.live.debounce.300ms="suche" placeholder="Grundprodukt suchen …" data-planung-zukauf-suche />
                    @foreach($treffer as $t)
                        <button type="button" wire:key="zk-{{ $t->id }}" wire:click="waehle({{ $t->id }})"
                                class="text-left px-2 py-1 rounded-[var(--fa-radius-control)] text-[length:var(--fa-text-md)] text-[var(--fa-ink)] hover:bg-[var(--fa-hover)]" data-planung-zukauf-treffer>{{ $t->name }}</button>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
    @if($vorhanden !== null)
        <x-fa::notice tone="info" data-planung-zukauf-vorhanden>Zu dieser Ware gibt es schon das Zukauf-Basisrezept
            <a href="#" wire:click.prevent="$dispatch('recipe-modal.oeffnen', { id: {{ $vorhanden['id'] }} })" class="text-[var(--fa-accent)] hover:underline">{{ $vorhanden['name'] }}</a>.</x-fa::notice>
    @endif
    @if($fehler !== null)
        <x-fa::notice tone="crit" data-planung-zukauf-fehler>{{ $fehler }}</x-fa::notice>
    @endif
</div>
