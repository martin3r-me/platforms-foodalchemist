{{--
    M0-08 / P-2: Sektion innerhalb von <x-foodalchemist::modal> —
    Sektions-Überschrift (Stammdaten / Verpackung & Mengen / Eigenschaften / …),
    alles auf einer Fläche, kein Wizard.
--}}
@props(['title'])

<section {{ $attributes->merge(['class' => 'pt-5 border-t border-black/5 dark:border-white/5 first:pt-0 first:border-t-0']) }} data-modal-zone="section">
    <h3 class="text-xs font-medium uppercase tracking-wider text-gray-400 mb-3">{{ $title }}</h3>
    {{ $slot }}
</section>
