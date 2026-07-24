{{-- Spec 19 E6.3: eine Skizzen-Zeile (frei/Bestand · entwurf/verworfen).
     Erwartet: $idee (FoodAlchemistDishIdea), $imPaket (bool). Style-Vars ($btnGhostXs) via @include vererbt. --}}
<div class="flex items-center gap-2 rounded bg-black/[0.02] px-2 py-1 {{ $idee->status === 'verworfen' ? 'opacity-50' : '' }}"
     wire:key="idee-{{ $idee->id }}" data-skizze="{{ $idee->id }}">
    @unless($imPaket)
        <input type="checkbox" value="{{ $idee->id }}" wire:model.live="ideeAuswahl"
               class="rounded border-gray-300 shrink-0" @if($idee->status === 'verworfen') disabled @endif>
    @endunless
    <span class="flex-1 min-w-0 truncate text-xs text-gray-700">
        {{ $idee->title }}
        <span class="text-[10px] {{ $idee->sales_recipe_id ? 'text-emerald-500' : 'text-gray-400' }}">· {{ $idee->sales_recipe_id ? 'Bestand' : 'frei' }}</span>
        @if($idee->status === 'verworfen')<span class="text-[10px] text-rose-500">· verworfen</span>@endif
    </span>
    @if($imPaket)
        <button type="button" wire:click="ausPaketLoesen({{ $idee->id }})" class="{{ $btnGhostXs }} text-gray-400 shrink-0" title="aus Paket lösen">lösen</button>
    @endif
    @if($idee->status === 'verworfen')
        <button type="button" wire:click="ideeReaktivieren({{ $idee->id }})" class="{{ $btnGhostXs }} text-emerald-600 shrink-0" title="reaktivieren">↺</button>
    @else
        <button type="button" wire:click="ideeVerwerfen({{ $idee->id }})" class="{{ $btnGhostXs }} text-gray-400 shrink-0" title="verwerfen">✕</button>
    @endif
</div>
