{{--
    Umsortier-Bedienelemente: Drag-Griff (⠿) + ▲▼ als zuverlässige Alternative.
    Erwartet (via @include): $id, $upMethod, $downMethod, $first, $last.
    Voraussetzung: Container mit Alpine-Scope `x-data="{ dragId: null }"` + Drop-Handler an der Zeile.
--}}
<span class="inline-block cursor-grab active:cursor-grabbing text-gray-400 hover:text-violet-500 select-none align-middle mr-0.5"
      draggable="true"
      @dragstart="dragId = {{ $id }}; $event.dataTransfer.effectAllowed = 'move'"
      @dragend="dragId = null"
      title="ziehen zum Umsortieren">⠿</span>
<span class="inline-flex flex-col align-middle leading-none">
    <button type="button" wire:click="{{ $upMethod }}({{ $id }})" @disabled($first ?? false)
            class="text-[9px] leading-none {{ ($first ?? false) ? 'text-gray-300 cursor-not-allowed' : 'text-gray-500 hover:text-violet-500' }}" title="hoch">▲</button>
    <button type="button" wire:click="{{ $downMethod }}({{ $id }})" @disabled($last ?? false)
            class="text-[9px] leading-none {{ ($last ?? false) ? 'text-gray-300 cursor-not-allowed' : 'text-gray-500 hover:text-violet-500' }}" title="runter">▼</button>
</span>
