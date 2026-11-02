{{-- Spec 94 · Felder einer Leistungsregel (Vorlage und Konfigurator-Leistung): Bezeichnung, Mengenregel, Preis je, EK, Klasse, VK.
     Parameter: $f Formularwerte, $m Livewire-Pfad (z. B. form), $sm Pfad des Stufen-Textes, $px Id-Präfix,
     $kategorien, $klassen (Leistungsklassen), $bezugOptionen (id → Bezeichnung), $vorschau (VK-Vorschlag oder null). --}}
@php
    $leise = 'text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]';
    $euro = fn ($w) => $w !== null && $w !== '' ? number_format((float) $w, 2, ',', '.') . ' €' : '–';
@endphp
<div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))] gap-3">
    <x-fa::field label="Bezeichnung" for="{{ $px }}-bez" required><x-fa::input id="{{ $px }}-bez" wire:model="{{ $m }}.bezeichnung" placeholder="z. B. Servicekraft" data-regel-bezeichnung /></x-fa::field>
    <x-fa::field label="Kategorie" for="{{ $px }}-kat"><x-fa::select id="{{ $px }}-kat" wire:model="{{ $m }}.kategorie" :options="$kategorien" /></x-fa::field>
    <x-fa::field label="Häufigkeit" for="{{ $px }}-haeuf" hint="Je Tageszeit: fällt bei Day-into-Night für Lunch und Dinner getrennt an.">
        <x-fa::select id="{{ $px }}-haeuf" wire:model="{{ $m }}.haeufigkeit" :options="['je_event' => 'Je Event', 'je_tageszeit' => 'Je Tageszeit']" />
    </x-fa::field>
</div>

<div class="flex flex-col gap-3">
    <x-fa::choice name="{{ $m }}.mengen_regel" label="Mengenregel" :options="['fest' => 'Feste Anzahl', 'schluessel' => 'Schlüssel (1 je n)', 'stufen' => 'Stufen nach Gästen']" />
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,10rem),1fr))] gap-3">
        @if(($f['mengen_regel'] ?? 'fest') === 'fest')
            <x-fa::field label="Anzahl" for="{{ $px }}-anz"><x-fa::input id="{{ $px }}-anz" wire:model="{{ $m }}.anzahl" numeric /></x-fa::field>
        @elseif($f['mengen_regel'] === 'schluessel')
            <x-fa::field label="Bezug" for="{{ $px }}-bezug"><x-fa::select id="{{ $px }}-bezug" wire:model.live="{{ $m }}.bezug" :options="['gaeste' => 'Gäste', 'leistung' => 'Andere Leistung']" /></x-fa::field>
            @if(($f['bezug'] ?? 'gaeste') === 'leistung')
                <x-fa::field label="Hängt an" for="{{ $px }}-bezug-l"><x-fa::select id="{{ $px }}-bezug-l" wire:model="{{ $m }}.bezug_leistung_id" :options="$bezugOptionen" placeholder="Leistung wählen" /></x-fa::field>
            @endif
            <x-fa::field label="1 Stück je" for="{{ $px }}-n" hint="z. B. 30 für Service 1:30"><x-fa::input id="{{ $px }}-n" wire:model="{{ $m }}.schluessel_n" numeric /></x-fa::field>
            <x-fa::field label="Reserve %" for="{{ $px }}-res" optional><x-fa::input id="{{ $px }}-res" wire:model="{{ $m }}.reserve_pct" numeric /></x-fa::field>
            <x-fa::field label="Mindestens" for="{{ $px }}-min" optional><x-fa::input id="{{ $px }}-min" wire:model="{{ $m }}.min" numeric /></x-fa::field>
            <x-fa::field label="Höchstens" for="{{ $px }}-max" optional><x-fa::input id="{{ $px }}-max" wire:model="{{ $m }}.max" numeric /></x-fa::field>
        @else
            <x-fa::field label="Stufen" for="{{ $px }}-stufen" hint="Je Zeile „bis Gäste; Anzahl“, leeres „bis“ = darüber hinaus.">
                <x-fa::textarea id="{{ $px }}-stufen" wire:model="{{ $sm }}" rows="4" placeholder="99; 1&#10;299; 2&#10;; 3" />
            </x-fa::field>
        @endif
        <x-fa::field label="Greift ab Gästen" for="{{ $px }}-ab" optional><x-fa::input id="{{ $px }}-ab" type="number" min="1" wire:model="{{ $m }}.ab_pax" /></x-fa::field>
        <x-fa::field label="Greift bis Gästen" for="{{ $px }}-bis" optional><x-fa::input id="{{ $px }}-bis" type="number" min="1" wire:model="{{ $m }}.bis_pax" /></x-fa::field>
    </div>
</div>

<div class="flex flex-col gap-3">
    <x-fa::choice name="{{ $m }}.preis_je" label="Preis je" :options="['stueck' => 'Stück / Einsatz', 'stunde' => 'Stunde']" />
    @if(($f['preis_je'] ?? 'stueck') === 'stunde')
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-3">
            <x-fa::field label="Stunden aus" for="{{ $px }}-sq"><x-fa::select id="{{ $px }}-sq" wire:model.live="{{ $m }}.stunden_quelle" :options="['einsatzzeit' => 'Einsatzzeit des Konfigurators', 'fest' => 'Feste Stunden']" /></x-fa::field>
            @if(($f['stunden_quelle'] ?? 'einsatzzeit') === 'fest')
                <x-fa::field label="Stunden" for="{{ $px }}-std"><x-fa::input id="{{ $px }}-std" wire:model="{{ $m }}.stunden" numeric /></x-fa::field>
            @else
                <x-fa::field label="Zuschlag Stunden" for="{{ $px }}-zus" hint="Vor- und Nachlauf, z. B. 1 für Briefing und Anfahrt" optional><x-fa::input id="{{ $px }}-zus" wire:model="{{ $m }}.stunden_zuschlag" numeric /></x-fa::field>
            @endif
        </div>
    @endif
</div>

<div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,12rem),1fr))] gap-3 items-start">
    <x-fa::field label="EK-Satz" for="{{ $px }}-ek" required hint="EK je Stück, Einsatz oder Stunde, netto"><x-fa::input id="{{ $px }}-ek" wire:model.live.debounce.400ms="{{ $m }}.ek_satz" numeric data-regel-ek /></x-fa::field>
    <x-fa::field label="Aufschlagsklasse" for="{{ $px }}-klasse">
        <x-fa::select id="{{ $px }}-klasse" wire:model.live="{{ $m }}.markup_class_id" placeholder="Ohne Klasse (100 %)">
            @foreach($klassen as $k)<option value="{{ $k->id }}">{{ $k->code }} · {{ $k->label }} ({{ number_format((float) $k->class_factor_pct, 0, ',', '.') }} %)</option>@endforeach
        </x-fa::select>
    </x-fa::field>
    <x-fa::choice name="{{ $m }}.vk_modus" label="VK" :options="['auto' => 'Aus der Klasse', 'fixed' => 'Fest']" />
    @if(($f['vk_modus'] ?? 'auto') === 'fixed')
        <x-fa::field label="VK-Satz" for="{{ $px }}-vk" required><x-fa::input id="{{ $px }}-vk" wire:model="{{ $m }}.vk_satz" numeric /></x-fa::field>
        <x-fa::field label="Begründung" for="{{ $px }}-vkb" required><x-fa::input id="{{ $px }}-vkb" wire:model="{{ $m }}.vk_begruendung" placeholder="z. B. Marktpreis Personaldienstleister" /></x-fa::field>
    @elseif($vorschau !== null)
        <div class="flex flex-col gap-1 pt-6" data-regel-vorschau>
            <span class="font-medium">VK-Vorschlag {{ $euro($vorschau['vorschlag']) }}</span>
            @if($vorschau['aufschlag_pct'] !== null)<span class="{{ $leise }}">Aufschlag {{ number_format($vorschau['aufschlag_pct'], 1, ',', '.') }} % auf den EK</span>@endif
            @foreach($vorschau['warnungen'] as $w)<span class="{{ $leise }}">{{ $w }}</span>@endforeach
        </div>
    @endif
</div>
