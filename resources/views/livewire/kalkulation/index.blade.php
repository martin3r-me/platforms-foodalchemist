{{-- #379+ (Dominique 2026-06-16): Kalkulations-Werkstatt = Controlling-Zentrum.
     Stehende Kosten (Stundensatz · Fixkosten wie Strom/Nebenkosten · Aufschlagsätze · Marge)
     an EINEM Ort pflegen → rollen auf alle Kalkulationen aus. Reine Kalkulation: Concepter. --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Kalkulations-Werkstatt" icon="heroicon-o-calculator" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Kalkulations-Werkstatt'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        {{-- Cockpit: die rolled-up Kosten-Wahrheit, die auf alle Kalkulationen ausrollt --}}
        <div class="relative overflow-hidden {{ $card }} px-5 py-4">
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h3 class="font-medium tracking-tight text-gray-900 dark:text-gray-100">Controlling-Zentrum — stehende Kosten</h3>
                    <p class="text-[11px] text-gray-400 max-w-2xl">Pflege deine dauerhaften Kosten an <strong>einem Ort</strong> — Stundensatz/Personal, Fixkosten (Strom, Nebenkosten, Logistik …), Aufschlagsätze und Marge. Sie rollen automatisch auf HK2, VK-Vorschlag und das „Marge unter Ziel"-Signal in <strong>jedem</strong> Gericht &amp; Concept aus.</p>
                </div>
                <a href="{{ route('foodalchemist.concepter.index') }}" class="{{ $btnGhost }}" wire:navigate>Kalkulation im Concepter →</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 mt-3">
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2"><div class="{{ $label }}">Zielmarge</div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $regeln['marge_pct'], 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2"><div class="{{ $label }}">Ziel-Wareneinsatz</div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $zielWe, 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2"><div class="{{ $label }}">Stundensatz</div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $regeln['stundensatz'], 2, ',', '.') }} €</div></div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2"><div class="{{ $label }}">HK2-Zuschlag (eff.)</div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $zuschlag, 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2"><div class="{{ $label }}">Fixkosten / Monat</div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ number_format((float) $fixkostenMonat, 0, ',', '.') }} €</div></div>
                <div class="rounded-lg bg-black/[0.03] dark:bg-white/5 px-3 py-2" title="Σ Fixkosten/Monat ÷ Deckungsbeitragsquote (1 − Ziel-Wareneinsatz). Monatsumsatz, ab dem die Fixkosten gedeckt sind."><div class="{{ $label }}">Break-even / Monat</div><div class="text-lg font-semibold tabular-nums text-gray-900 dark:text-gray-100">{{ $fixkostenMonat > 0 ? number_format((float) $breakEven, 0, ',', '.') . ' €' : '—' }}</div></div>
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-[11px] text-gray-400">
                <span><span class="text-gray-500 dark:text-gray-300 font-medium">MwSt</span> regulär {{ rtrim(rtrim(number_format((float) $mwst['regulaer'], 1, ',', '.'), '0'), ',') }} % · ermäßigt {{ rtrim(rtrim(number_format((float) $mwst['ermaessigt'], 1, ',', '.'), '0'), ',') }} % · Standard {{ $mwst['default_satz'] === 'regulaer' ? 'regulär' : 'ermäßigt' }}</span>
                <span class="text-gray-300 dark:text-gray-600">·</span>
                <span>{{ count($regeln['schema']) }} aktive Zuschlagsblöcke</span>
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'kalkulation']) }}" class="text-violet-600 dark:text-violet-400 hover:underline" wire:navigate>MwSt / Verlust-Defaults pflegen →</a>
            </div>
            @if(count($regeln['schema']))
                <div class="flex flex-wrap gap-1 mt-2">
                    @foreach($regeln['schema'] as $b)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $b['label'] }}: {{ rtrim(rtrim(number_format((float) ($b['value'] ?? 0), 2, ',', '.'), '0'), ',') }}{{ str_starts_with((string) $b['type'], 'pct') ? ' %' : ($b['type'] === 'arbeitszeit' ? ' €/h' : ' €') }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Eingebetteter Kosten-Editor (vormals Einstellungen → Herstellkosten): Zuschlagsschema,
             Fixkosten + Bezugsbasen, Marge. Speichern dispatched 'kosten-aktualisiert' → Kacheln oben ziehen nach. --}}
        @livewire('foodalchemist.settings.herstellkosten')

        <p class="text-[11px] text-gray-400 px-1 pt-1">
            Die gerichts- und mengenbezogene Kalkulation (HK1 → HK2 → VK-Vorschlag → Deckungsbeitrag) läuft im
            <a href="{{ route('foodalchemist.concepter.index') }}" class="text-violet-600 dark:text-violet-400 hover:underline" wire:navigate>Concepter</a>
            und je Einzelgericht in den
            <a href="{{ route('foodalchemist.verkauf.index') }}" class="text-violet-600 dark:text-violet-400 hover:underline" wire:navigate>Gerichten</a>.
            Diese Werkstatt liefert die Regeln dafür.
        </p>
    </x-ui-page-container>
</x-ui-page>
