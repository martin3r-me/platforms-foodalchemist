{{--
    M0-08 / P-2: Sektion innerhalb von <x-foodalchemist::modal> —
    Sektions-Überschrift (Stammdaten / Verpackung & Mengen / Eigenschaften / …),
    alles auf einer Fläche, kein Wizard.
--}}
@props(['title'])

<section {{ $attributes->merge(['class' => 'pt-5 border-t border-black/5 dark:border-white/5 first:pt-0 first:border-t-0']) }} data-modal-zone="section">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-medium uppercase tracking-wider text-gray-400">{{ $title }}</h3>
        @isset($actions)
            <div class="flex items-center gap-1.5" data-section-actions>{{ $actions }}</div>
        @endisset
    </div>
    {{ $slot }}
</section>
