{{--
    Wiederverwendbarer, ausklappbarer Baum (Basisrezepte-Look) — der EINE Taxonomie-Baum-Stil
    fuers ganze Modul (Konzept-Taxonomie, Rezept-Taxonomie, VK-Taxonomie, Filter-Sidebars).

    Haelt den Auf-/Zuklapp-State (Alpine, kein Server-Roundtrip) fuer alle enthaltenen
    x-foodalchemist::tree-node. Die Knoten kommen als FLACHE, vorsortierte Liste mit
    depth / ancestors / has_children (Format: ConceptService::categoriesFlat()). Der Host
    schleift je Knoten eine tree-node ein und legt Label-Button + Aktionen in deren Slot.

    initialCollapsed: IDs, die zu Beginn eingeklappt sind (z. B. alle Eltern -> nur Wurzeln
    sichtbar). Default []: alles aufgeklappt.
--}}
@props([
    'heading' => null,
    'initialCollapsed' => [],
])

<div
    x-data="{
        collapsed: {{ \Illuminate\Support\Js::from(array_values(array_map('intval', (array) $initialCollapsed))) }},
        toggle(id) { this.collapsed = this.collapsed.includes(id) ? this.collapsed.filter(x => x !== id) : [...this.collapsed, id] },
        isCollapsed(id) { return this.collapsed.includes(id) },
        hiddenBy(ancestors) { return ancestors.some(a => this.collapsed.includes(a)) },
    }"
    {{ $attributes->merge(['class' => 'space-y-0.5']) }}
>
    @if($heading !== null)
        <span class="text-[10px] font-medium uppercase tracking-wider text-gray-500 block px-1 pb-0.5">{{ $heading }}</span>
    @endif
    {{ $slot }}
</div>
