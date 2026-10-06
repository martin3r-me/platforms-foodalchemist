{{-- M1-07: Kalkulations-Standards des Teams (GL-02). Die Neuberechnung (M4-03) liest dieselben Werte.
     Herstellkosten (Zuschläge, Fixkosten, Marge) liegen in der eigenen Sektion „Herstellkosten". --}}
@php
    $rundungsArten = [
        'kaufmaennisch' => 'Kaufmännisch',
        'auf' => 'Immer aufrunden',
        'ab' => 'Immer abrunden',
        'next_050' => 'Auf x,50',
        'next_090' => 'Auf x,90',
    ];
@endphp

<div class="flex flex-col gap-4">
    <x-foodalchemist::save-bar :meldung="$meldung"
        hint="Verluste gelten für die Rezepte dieses Teams und der Kind-Teams ohne eigene Werte. Speichern rechnet sie neu." />

    {{-- Garverlust: häufigste Pflege, deshalb oben --}}
    <x-fa::section title="Garverlust" icon="heroicon-o-fire" data-kalk-garverlust
        description="Standardwert in Prozent je Warengruppe. Vorrang hat der Wert an der Zutat, dann der am Grundprodukt, dann dieser Teamwert. Leer lassen heißt: kein Standardwert.">
        @if($geerbtGar)
            <x-fa::notice tone="warn" data-kalk-geerbt>Geerbt von {{ $geerbtGar['von'] }} (grau in den Feldern). Ein eigener Wert ersetzt die ganze geerbte Liste.</x-fa::notice>
        @endif
        <div class="flex flex-wrap items-center gap-3 pb-3 border-b border-[var(--fa-line)]">
            <label for="kalk-gv-alle" class="flex-1 min-w-[12rem] text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Alle Warengruppen</label>
            <div class="flex items-center gap-1.5">
                <x-fa::input id="kalk-gv-alle" wire:model="garverlust.*" placeholder="{{ $geerbtGar['werte']['*'] ?? 'leer' }}" numeric class="w-24" />
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
            </div>
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] gap-x-8">
            @foreach($warengruppen as $wg)
                <div class="flex items-center gap-3 py-1.5 border-b border-[var(--fa-line)]" wire:key="gv-{{ $wg->code }}">
                    <label for="kalk-gv-{{ $wg->code }}" class="flex-1 min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">{{ $wg->name }}</label>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <x-fa::input id="kalk-gv-{{ $wg->code }}" wire:model="garverlust.{{ $wg->code }}" placeholder="{{ $geerbtGar['werte'][$wg->code] ?? 'leer' }}" numeric size="sm" class="w-20" />
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                    </div>
                </div>
            @endforeach
        </div>
    </x-fa::section>

    {{-- Putzverlust (Phase 2, gleiche Reihenfolge wie Garverlust) --}}
    <x-fa::section title="Putzverlust" icon="heroicon-o-scissors" data-kalk-putzverlust
        description="Standardwert in Prozent je Warengruppe. Vorrang hat der Wert an der Zutat, dann der am Grundprodukt, dann dieser Teamwert. Leer lassen heißt: kein Standardwert.">
        @if($geerbtPutz)
            <x-fa::notice tone="warn" data-kalk-geerbt>Geerbt von {{ $geerbtPutz['von'] }} (grau in den Feldern). Ein eigener Wert ersetzt die ganze geerbte Liste.</x-fa::notice>
        @endif
        <div class="flex flex-wrap items-center gap-3 pb-3 border-b border-[var(--fa-line)]">
            <label for="kalk-pv-alle" class="flex-1 min-w-[12rem] text-[length:var(--fa-text-md)] font-medium text-[var(--fa-ink)]">Alle Warengruppen</label>
            <div class="flex items-center gap-1.5">
                <x-fa::input id="kalk-pv-alle" wire:model="putzverlust.*" placeholder="{{ $geerbtPutz['werte']['*'] ?? 'leer' }}" numeric class="w-24" />
                <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
            </div>
        </div>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] gap-x-8">
            @foreach($warengruppen as $wg)
                <div class="flex items-center gap-3 py-1.5 border-b border-[var(--fa-line)]" wire:key="pv-{{ $wg->code }}">
                    <label for="kalk-pv-{{ $wg->code }}" class="flex-1 min-w-0 text-[length:var(--fa-text-md)] text-[var(--fa-ink-2)]">{{ $wg->name }}</label>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <x-fa::input id="kalk-pv-{{ $wg->code }}" wire:model="putzverlust.{{ $wg->code }}" placeholder="{{ $geerbtPutz['werte'][$wg->code] ?? 'leer' }}" numeric size="sm" class="w-20" />
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                    </div>
                </div>
            @endforeach
        </div>
    </x-fa::section>

    {{-- MwSt + Rundung --}}
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,22rem),1fr))] gap-4">
        <x-fa::section title="Mehrwertsteuer" icon="heroicon-o-receipt-percent" data-kalk-mwst
            description="Steuersätze für den Bruttopreis. Preisklassen und einzelne Darreichungen können einen anderen Satz wählen.">
            <div class="grid grid-cols-2 gap-3">
                <x-fa::field label="Regulärer Satz" for="kalk-mwst-regulaer">
                    <div class="flex items-center gap-1.5">
                        <x-fa::input id="kalk-mwst-regulaer" wire:model="mwst.regulaer" numeric />
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                    </div>
                </x-fa::field>
                <x-fa::field label="Ermäßigter Satz" for="kalk-mwst-ermaessigt">
                    <div class="flex items-center gap-1.5">
                        <x-fa::input id="kalk-mwst-ermaessigt" wire:model="mwst.ermaessigt" numeric />
                        <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">%</span>
                    </div>
                </x-fa::field>
            </div>
            <x-fa::field hint="Gilt, wenn weder Preisklasse noch Darreichung einen Satz vorgeben.">
                <x-fa::choice name="mwst.default_satz" :live="false" label="Standardsatz"
                    :options="['ermaessigt' => 'Ermäßigt (Speisen)', 'regulaer' => 'Regulär']" />
            </x-fa::field>
        </x-fa::section>

        <x-fa::section title="Rundung" icon="heroicon-o-calculator" data-kalk-rundung
            description="Wie der berechnete Verkaufspreis gerundet wird. Preisklassen können eigene Regeln haben.">
            <x-fa::field label="Nachkommastellen" for="kalk-rundung-stellen" hint="0 bis 4 Stellen.">
                <x-fa::input id="kalk-rundung-stellen" type="number" min="0" max="4" wire:model="rundung.nachkommastellen" numeric class="w-24" />
            </x-fa::field>
            <x-fa::choice name="rundung.mode" :live="false" label="Art der Rundung" :options="$rundungsArten" />

            {{-- Beispielrechnung als abgesetzte Vorschau --}}
            <div class="rounded-[var(--fa-radius-control)] bg-[var(--fa-ground)] border border-[var(--fa-line)] px-3 py-2.5 flex flex-col gap-1.5">
                <p class="text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Beispiel bei 2 Nachkommastellen</p>
                <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-2)]">
                    <dt>Kaufmännisch</dt><dd class="tabular-nums">8,164 € wird 8,16 €, 8,165 € wird 8,17 €</dd>
                    <dt>Auf x,50</dt><dd class="tabular-nums">8,16 € wird 8,50 €</dd>
                    <dt>Auf x,90</dt><dd class="tabular-nums">8,16 € wird 8,90 €</dd>
                </dl>
                <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">Preisendungen runden immer nach oben, ein exakter Treffer bleibt stehen. Die Reihenfolge der Rundungsschritte ist fest, hier stellst du nur Stellen und Art ein.</p>
            </div>
        </x-fa::section>
    </div>

    <p class="flex items-center gap-1.5 text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">
        @svg('heroicon-o-information-circle', 'w-4 h-4 shrink-0')
        Zuschläge, Fixkosten, Stundensatz und Marge pflegst du unter „Herstellkosten &amp; Zuschläge“.
    </p>
</div>
