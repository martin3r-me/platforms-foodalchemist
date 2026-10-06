{{-- Kalkulations-Kennzahlen: die ausgerollten Kosten-Regeln, gegen die alles gerechnet wird.
     Gepflegt werden sie in den Einstellungen → Herstellkosten.

     Spec 32: war bis 2026-08-02 die Seite `/kalkulation`, die zusätzlich die Preissimulation
     trug. Die Simulation hat im Controlling einen eigenen Tab; hier wäre sie ein zweiter
     Einstiegspunkt in dieselbe Fläche. Seiten-Hülle entfällt, Titel trägt der Tab.

     fa-pass 2026-10-05: Tokens + Bausteine. Doppelte Überschrift entfernt (der Tab-Abschnitt trägt
     den Titel), Zielwerte als Formular mit Label oben und Speichern rechts, Kennzahlen als
     x-fa::kpis, Zuschlagsblöcke als Badges. Reihenfolge, wire:-Bindungen und data-Marker unverändert. --}}
@php
    $zahl = fn ($v, $nk = 1) => rtrim(rtrim(number_format((float) $v, $nk, ',', '.'), '0'), ',');
@endphp

<div class="flex flex-col gap-4" data-ctrl-kennzahlen>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex flex-col gap-2">
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch]">
                Die geltenden Kosten-Regeln, Grundlage jeder Kalkulation und der Simulation.
                Das volle Zuschlagsschema wird in den Einstellungen unter Herstellkosten gepflegt.
            </p>
            @if(!empty($betriebName))
                <div data-ctrl-kennzahlen-betrieb>
                    <x-fa::badge tone="info" icon="heroicon-m-building-storefront">Werte für Betrieb {{ $betriebName }}</x-fa::badge>
                </div>
            @endif
        </div>
        <x-fa::button variant="secondary" icon-right="heroicon-m-arrow-right" :href="route('foodalchemist.einstellungen', ['sektion' => 'herstellkosten'])" wire:navigate>
            Kosten-Regeln bearbeiten
        </x-fa::button>
    </div>

    {{-- Spec 32: die zwei Zielwerte, gegen die das Controlling misst, direkt hier setzen.
         Das volle Zuschlagsschema bleibt in den Einstellungen; zwei Formulare auf
         dieselben Spalten wären ein Pflege-Widerspruch. --}}
    <x-fa::section variant="plain" title="Zielwerte" icon="heroicon-o-flag"
                   :description="!empty($betriebName) ? 'Gespeichert werden die Zielwerte des Teams. Abweichende Werte je Betrieb stehen in den Einstellungen unter Betriebe.' : 'Gegen diese beiden Werte misst das Controlling Wareneinsatz und Marge.'"
                   data-ctrl-ziele>
        <div class="flex flex-wrap items-end gap-3">
            <x-fa::field label="Ziel-Wareneinsatz in %" for="ctrl-ziel-we">
                <x-fa::input id="ctrl-ziel-we" numeric inputmode="decimal" wire:model="zielWe" class="w-28" data-ctrl-ziel-we />
            </x-fa::field>
            <x-fa::field label="Zielmarge in %" for="ctrl-ziel-marge">
                <x-fa::input id="ctrl-ziel-marge" numeric inputmode="decimal" wire:model="marge" class="w-28" data-ctrl-ziel-marge />
            </x-fa::field>
            <div class="flex items-center gap-3 ml-auto">
                @if($meldung)<x-fa::signal tone="ok">{{ $meldung }}</x-fa::signal>@endif
                <x-fa::button variant="primary" icon="heroicon-o-check" wire:click="zieleSpeichern" data-ctrl-ziele-speichern>Zielwerte speichern</x-fa::button>
            </div>
        </div>
        @error('zielWe')<x-fa::signal tone="crit">{{ $message }}</x-fa::signal>@enderror
        @error('marge')<x-fa::signal tone="crit">{{ $message }}</x-fa::signal>@enderror
    </x-fa::section>

    <x-fa::kpis :items="[
        ['label' => 'Zielmarge', 'value' => number_format((float) $regeln['marge_pct'], 1, ',', '.') . ' %'],
        ['label' => 'Ziel-Wareneinsatz', 'value' => is_numeric(str_replace(',', '.', (string) $zielWe)) ? number_format((float) str_replace(',', '.', (string) $zielWe), 1, ',', '.') . ' %' : '–'],
        ['label' => 'Stundensatz', 'value' => number_format((float) $regeln['stundensatz'], 2, ',', '.') . ' €'],
        ['label' => 'HK2-Zuschlag wirksam', 'value' => number_format((float) $zuschlag, 1, ',', '.') . ' %',
         'title' => 'Wirksamer Zuschlag von den Herstellkosten 1 auf die Herstellkosten 2'],
        ['label' => 'Fixkosten je Monat', 'value' => $fixkostenMonat > 0 ? number_format((float) $fixkostenMonat, 0, ',', '.') . ' €' : 'Keine erfasst'],
        ['label' => 'Break-even je Monat', 'value' => $fixkostenMonat > 0 ? number_format((float) $breakEven, 0, ',', '.') . ' €' : 'Fixkosten fehlen',
         'title' => 'Fixkosten je Monat geteilt durch die Deckungsbeitragsquote (1 minus Ziel-Wareneinsatz). Monatsumsatz, ab dem die Fixkosten gedeckt sind.'],
    ]" />

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
        <span>
            <span class="font-medium text-[var(--fa-ink-2)]">Mehrwertsteuer</span>
            regulär {{ $zahl($mwst['regulaer']) }} % · ermäßigt {{ $zahl($mwst['ermaessigt']) }} % · Standard {{ $mwst['default_satz'] === 'regulaer' ? 'regulär' : 'ermäßigt' }}
        </span>
        <span aria-hidden="true">·</span>
        <span>{{ count($regeln['schema']) }} {{ count($regeln['schema']) === 1 ? 'aktiver Zuschlagsblock' : 'aktive Zuschlagsblöcke' }}</span>
        <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'kalkulation']) }}" class="inline-flex items-center gap-1 text-[var(--fa-accent)] hover:underline" wire:navigate>
            Mehrwertsteuer und Verluste bearbeiten @svg('heroicon-m-arrow-right', 'w-3.5 h-3.5')
        </a>
    </div>
    @if(count($regeln['schema']))
        <div class="flex flex-wrap gap-1.5">
            @foreach($regeln['schema'] as $b)
                <x-fa::badge>{{ $b['label'] }}: {{ $zahl($b['value'] ?? 0, 2) }}{{ str_starts_with((string) $b['type'], 'pct') ? ' %' : ($b['type'] === 'arbeitszeit' ? ' €/h' : ' €') }}</x-fa::badge>
            @endforeach
        </div>
    @endif

    <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)] max-w-[75ch] pt-1">
        Die Kalkulation je Gericht und Menge (Herstellkosten, Verkaufspreis-Vorschlag, Deckungsbeitrag) läuft im
        <a href="{{ route('foodalchemist.concepter.index') }}" class="text-[var(--fa-accent)] hover:underline" wire:navigate>Concepter</a>
        und je Einzelgericht unter
        <a href="{{ route('foodalchemist.verkauf.index') }}" class="text-[var(--fa-accent)] hover:underline" wire:navigate>Gerichte</a>.
        Die Kosten-Regeln stehen unter
        <a href="{{ route('foodalchemist.einstellungen', ['sektion' => 'herstellkosten']) }}" class="text-[var(--fa-accent)] hover:underline" wire:navigate>Einstellungen, Herstellkosten</a>.
    </p>
</div>
