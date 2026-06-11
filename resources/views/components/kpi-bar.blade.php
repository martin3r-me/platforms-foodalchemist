{{--
    M0-13 / P-1-Header: dichte KPI-Leiste — „120 Lieferanten · 6.930 GPs · 9.803 LAs · 1.407 Rezepte".
    Platzierung Actionbar vs. Navbar ist offene Martin-Frage (11_UI_PATTERNS „Offene Punkte") —
    bis dahin im Content-Header der Browser-Screens. NULL-Werte (z. B. Rezepte vor M4) werden
    ausgelassen.
--}}
@props(['kpis' => []])

@php
    $ui = \Platform\FoodAlchemist\Support\Ui::maps();
    $teile = collect([
        'lieferanten' => 'Lieferanten',
        'gps' => 'GPs',
        'las' => 'LAs',
        'rezepte' => 'Rezepte',
    ])->map(fn ($label, $key) => isset($kpis[$key])
        ? number_format($kpis[$key], 0, ',', '.') . ' ' . $label
        : null
    )->filter();
@endphp

<div {{ $attributes->merge(['class' => $ui['label']]) }} data-kpi-bar>
    {{ $teile->implode(' · ') }}
</div>
