{{-- #502 (Dominique 2026-07-13): eigener „Was-wäre-wenn"-Preissimulations-Screen.
     Der Regel-Editor (Zuschläge/Fixkosten/Marge) ist zurück unter Einstellungen → Herstellkosten;
     hier bleibt nur die Simulation + die Kennzahlen als Kontext (Werkstatt aufgelöst). --}}
@php(extract(\Platform\FoodAlchemist\Support\Ui::maps()))

<x-ui-page>
    <x-slot:navbar>
        <x-ui-page-navbar title="Preissimulation" icon="heroicon-o-arrows-right-left" />
    </x-slot:navbar>

    <x-slot name="actionbar">
        <x-ui-page-actionbar :breadcrumbs="[
            ['label' => 'Food Alchemist', 'href' => route('foodalchemist.dashboard'), 'icon' => 'cube'],
            ['label' => 'Preissimulation'],
        ]" />
    </x-slot>

    <x-ui-page-container padding="px-6 pb-6" spacing="space-y-4">
        {{-- Kennzahlen-Kontext (read-only): die rolled-up Kosten-Regeln, gegen die simuliert wird.
             Gepflegt werden sie in den Einstellungen → Herstellkosten. --}}
        <div class="relative overflow-hidden {{ $card }} px-5 py-4">
            <div class="{{ $cardAccent }}"></div>
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div>
                    <h3 class="font-medium tracking-tight text-gray-900">Kalkulations-Kennzahlen</h3>
                    <p class="text-[11px] text-gray-500 max-w-2xl">Die aktuellen, ausgerollten Kosten-Regeln — Grundlage jeder Kalkulation und dieser Simulation. Bearbeitet werden sie unter <strong>Einstellungen → Herstellkosten</strong>.</p>
                </div>
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'herstellkosten']) }}" class="{{ $btnGhost }}" wire:navigate>Regeln in den Einstellungen pflegen →</a>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-2 mt-3">
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Zielmarge</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $regeln['marge_pct'], 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Ziel-Wareneinsatz</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $zielWe, 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Stundensatz</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $regeln['stundensatz'], 2, ',', '.') }} €</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">HK2-Zuschlag (eff.)</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $zuschlag, 1, ',', '.') }} %</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2"><div class="{{ $label }}">Fixkosten / Monat</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ number_format((float) $fixkostenMonat, 0, ',', '.') }} €</div></div>
                <div class="rounded-lg bg-black/[0.03] px-3 py-2" title="Σ Fixkosten/Monat ÷ Deckungsbeitragsquote (1 − Ziel-Wareneinsatz). Monatsumsatz, ab dem die Fixkosten gedeckt sind."><div class="{{ $label }}">Break-even / Monat</div><div class="text-lg font-semibold tabular-nums text-gray-900">{{ $fixkostenMonat > 0 ? number_format((float) $breakEven, 0, ',', '.') . ' €' : '—' }}</div></div>
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-[11px] text-gray-500">
                <span><span class="text-gray-600 font-medium">MwSt</span> regulär {{ rtrim(rtrim(number_format((float) $mwst['regulaer'], 1, ',', '.'), '0'), ',') }} % · ermäßigt {{ rtrim(rtrim(number_format((float) $mwst['ermaessigt'], 1, ',', '.'), '0'), ',') }} % · Standard {{ $mwst['default_satz'] === 'regulaer' ? 'regulär' : 'ermäßigt' }}</span>
                <span class="text-gray-300">·</span>
                <span>{{ count($regeln['schema']) }} aktive Zuschlagsblöcke</span>
                <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'kalkulation']) }}" class="text-violet-600 hover:underline" wire:navigate>MwSt / Verlust-Defaults pflegen →</a>
            </div>
            @if(count($regeln['schema']))
                <div class="flex flex-wrap gap-1 mt-2">
                    @foreach($regeln['schema'] as $b)
                        <span class="{{ $pill }} {{ $variantPill['secondary'] }}">{{ $b['label'] }}: {{ rtrim(rtrim(number_format((float) ($b['value'] ?? 0), 2, ',', '.'), '0'), ',') }}{{ str_starts_with((string) $b['type'], 'pct') ? ' %' : ($b['type'] === 'arbeitszeit' ? ' €/h' : ' €') }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- R2.2: Was-wäre-wenn-Preissimulation — hypothetischer Preissprung (WG/GP/Artikel ± X %)
             → Portfolio-Marge-Delta + Top-20. Read-only, spiegelt das MCP-Tool simulation.POST.
             #502: eigener Screen; Regel-Editor lebt jetzt in den Einstellungen. --}}
        @livewire('foodalchemist.kalkulation.simulation')

        <p class="text-[11px] text-gray-500 px-1 pt-1">
            Die gerichts- und mengenbezogene Kalkulation (HK1 → HK2 → VK-Vorschlag → Deckungsbeitrag) läuft im
            <a href="{{ route('foodalchemist.concepter.index') }}" class="text-violet-600 hover:underline" wire:navigate>Concepter</a>
            und je Einzelgericht in den
            <a href="{{ route('foodalchemist.verkauf.index') }}" class="text-violet-600 hover:underline" wire:navigate>Gerichten</a>.
            Die Kosten-Regeln pflegst du unter
            <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'herstellkosten']) }}" class="text-violet-600 hover:underline" wire:navigate>Einstellungen → Herstellkosten</a>.
        </p>
    </x-ui-page-container>
</x-ui-page>
