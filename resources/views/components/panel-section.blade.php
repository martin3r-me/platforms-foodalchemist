{{--
    FA einklappbare Panel-Sektion — Disclosure-Zeile: Icon links, Titel (sentence-case)
    + optionaler Count/Sub, Chevron rechts, Hairline-Trenner oben, Hover-Highlight.
    Kapselt das dreifach duplizierte toggleSektion-Muster der Detail-Panels.

    wire:click läuft gegen die umschließende Livewire-Komponente — deren toggleSektion()
    muss den target-Key in ihrer Whitelist führen.

    Props: title, target, open, count, sub, icon (heroicon-o-…).
    Slots:
      default  → Body, rendert nur wenn :open (eingerückt unter dem Titel).
      actions  → rechts neben dem Kopf (z. B. „Netz").
      preview  → statt Body, wenn NICHT offen (kompakte Zusammenfassung).
--}}
@props(['title', 'target', 'open' => false, 'count' => null, 'sub' => null, 'icon' => null])

<div {{ $attributes->merge(['class' => 'border-t border-black/5']) }}>
    <div class="flex items-center gap-1">
        <button type="button" wire:click="toggleSektion('{{ $target }}')"
                class="group flex-1 flex items-center gap-2.5 py-2.5 text-left transition-colors">
            @if($icon)<span class="text-gray-400 group-hover:text-violet-500 transition-colors shrink-0">@svg($icon, 'w-4 h-4')</span>@endif
            <span class="text-[13px] text-gray-800">{{ $title }}</span>
            @if($count !== null)<span class="text-[11px] text-gray-400 tabular-nums">{{ $count }}</span>@endif
            @if($sub !== null)<span class="text-[11px] text-gray-400">{{ $sub }}</span>@endif
            <span class="ml-auto text-gray-300 group-hover:text-gray-500 transition-colors shrink-0">@svg($open ? 'heroicon-o-chevron-down' : 'heroicon-o-chevron-right', 'w-4 h-4')</span>
        </button>
        @isset($actions)<div class="shrink-0">{{ $actions }}</div>@endisset
    </div>
    @if($open)
        <div class="pb-3 pl-[26px]">{{ $slot }}</div>
    @elseif(isset($preview))
        <div class="pb-3 pl-[26px]">{{ $preview }}</div>
    @endif
</div>
