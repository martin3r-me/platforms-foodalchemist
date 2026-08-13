{{--
    M0-08 / P-2: Sektion innerhalb von <x-foodalchemist::modal> —
    Sektions-Überschrift (Stammdaten / Verpackung & Mengen / Eigenschaften / …),
    alles auf einer Fläche, kein Wizard.

    UX-Umbau 2026-07-03: Sektion ist eine frosted Card (sectionCard-Token) statt
    borderless Trennlinie — die borderless Inputs (bg-black/[0.03]) liegen damit auf
    Weiß und lesen sich als Kontrast, nicht als Grau-auf-Grau. Self-Spacing via
    mt-4/first:mt-0 (kollidiert nicht mit parent space-y-4 — gleicher margin-top-Wert).
--}}
@props(['title'])
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<section {{ $attributes->merge(['class' => $sectionCard . ' mt-4 first:mt-0 min-w-0']) }} data-modal-zone="section">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <h3 class="text-[11px] font-medium uppercase tracking-wider text-gray-500 min-w-0">{{ $title }}</h3>
        @isset($actions)
            <div class="flex flex-wrap items-center justify-end gap-1.5 min-w-0" data-section-actions>{{ $actions }}</div>
        @endisset
    </div>
    {{ $slot }}
</section>
