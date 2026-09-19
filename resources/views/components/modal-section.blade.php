{{--
    M0-08 / P-2: Sektion innerhalb von <x-foodalchemist::modal> —
    Sektions-Überschrift (Stammdaten / Verpackung & Mengen / Eigenschaften / …),
    alles auf einer Fläche, kein Wizard.

    UX-Umbau 2026-07-03: Sektion ist eine frosted Card (sectionCard-Token) statt
    borderless Trennlinie — die borderless Inputs (bg-black/[0.03]) liegen damit auf
    Weiß und lesen sich als Kontrast, nicht als Grau-auf-Grau. Self-Spacing via
    mt-4/first:mt-0 (kollidiert nicht mit parent space-y-4 — gleicher margin-top-Wert).

    Cockpit-Optik (Paket K, Breite-Fix 2026-09-19): optionales `icon`-Prop schaltet eine
    grössere, button-label-grosse Überschrift (Icon + Gewicht 600) statt des winzigen
    Uppercase-Mikro-Labels — rein additiv, ohne `icon` bleibt die Ausgabe für alle
    bestehenden Aufrufer (GP-Modal, Suppliers, VK-Modal, …) byte-identisch.
--}}
@props(['title', 'icon' => null])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<section {{ $attributes->merge(['class' => $sectionCard . ' mt-4 first:mt-0 min-w-0']) }} data-modal-zone="section">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        @if($icon)
            <h3 class="flex items-center gap-1.5 text-[13px] font-semibold text-gray-900 min-w-0">
                @svg($icon, 'w-4 h-4 text-violet-600 shrink-0')
                <span class="min-w-0">{{ $title }}</span>
            </h3>
        @else
            <h3 class="text-[11px] font-medium uppercase tracking-wider text-gray-500 min-w-0">{{ $title }}</h3>
        @endif
        @isset($actions)
            <div class="flex flex-wrap items-center justify-end gap-1.5 min-w-0" data-section-actions>{{ $actions }}</div>
        @endisset
    </div>
    {{ $slot }}
</section>
