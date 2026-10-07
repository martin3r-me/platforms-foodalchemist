{{--
    Der Kind-Ast einer Filter-Spalte: eine feine Führungslinie verankert die Kind-Ebene am Elternteil.
    Nutzung: x-foodalchemist::filter-ast data-sub-liste → Slot: filter-row level="child"
--}}
<div {{ $attributes->merge(['class' => 'ml-3 my-1 pl-2 border-l border-[var(--fa-line-strong)] flex flex-col gap-0.5']) }} data-fa-filter-ast>
    {{ $slot }}
</div>
