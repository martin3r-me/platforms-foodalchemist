{{-- x-fa::empty — Leerzustand: sagt, was hier erscheint und wie man es füllt. --}}
@props(['icon' => 'heroicon-o-inbox', 'title', 'compact' => false])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center text-center gap-2 ' . ($compact ? 'py-4' : 'py-10')]) }}>
    @svg($icon, ($compact ? 'w-6 h-6' : 'w-8 h-8') . ' text-[var(--fa-ink-3)]')
    <p class="text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">{{ $title }}</p>
    @if(trim($slot) !== '')<div class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[48ch]">{{ $slot }}</div>@endif
    @isset($action)<div class="mt-1">{{ $action }}</div>@endisset
</div>
