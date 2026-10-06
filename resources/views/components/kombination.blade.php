{{-- Spec 60 · P7 — „Passt das zusammen?": Aussagen der Kombinationslogik mit Grundlage.
     Daten: Kombinationslogik::daten(). Gericht: Bestandteile = Basisrezepte, mit Vorschlägen.
     Basisrezept: eigenes Aromenprofil + Aussagen über seine Zutaten. Wichtiges zuerst,
     „neutral"/„passt" eingeklappt. --}}
@props(['daten'])
@php
    $a = $daten['aussagen'] ?? [];
    $liste = fn (string $typ) => collect($a[$typ] ?? []);
    $wichtig = [
        'konflikt' => ['Konflikt', 'bg-rose-50 text-rose-800'],
        'bedarf_offen' => ['Es fehlt', 'bg-amber-50 text-amber-800'],
        'harmoniert' => ['Harmonie (gemessen)', 'text-gray-800'],
        'spannung' => ['Spannung', 'text-gray-800'],
        'kombination' => ['Klassiker', 'text-gray-800'],
        'unbekannt' => ['Ohne Aroma-Zuordnung', 'text-gray-500'],
    ];
    $leise = $liste('passt')->concat($liste('neutral'));
@endphp
<div class="flex flex-col gap-3 text-sm" data-kombination data-art="{{ $daten['art'] ?? '' }}">
    <p class="text-gray-600" data-zusammenfassung>{{ $daten['zusammenfassung'] ?? '' }}</p>

    @if(! empty($daten['profil']['anker']))
        <div data-profil>
            <p class="text-xs font-medium text-gray-500 mb-1">Aromenprofil · {{ number_format((float) $daten['profil']['abdeckung'], 0, ',', '.') }} % der Masse zugeordnet</p>
            <div class="flex flex-wrap gap-1">
                @foreach($daten['profil']['anker'] as $k)
                    <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-700">{{ $k['name'] }} {{ number_format($k['anteil'], 0, ',', '.') }} %</span>
                @endforeach
            </div>
        </div>
    @endif

    @foreach($wichtig as $typ => [$titel, $ton])
        @if($liste($typ)->isNotEmpty())
            <div>
                <p class="text-xs font-medium text-gray-500 mb-1">{{ $titel }}</p>
                <ul class="flex flex-col gap-1" data-aussagen="{{ $typ }}">
                    @foreach($liste($typ) as $x)
                        <li class="flex flex-wrap items-baseline gap-x-2 {{ $ton }} {{ str_starts_with($ton, 'bg-') ? 'rounded px-2 py-1' : '' }}">
                            <span>{{ $x['text'] }}</span>
                            <span class="text-[11px] text-gray-400">{{ $x['grundlage_label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endforeach

    @if(! empty($daten['vorschlaege']))
        <div data-vorschlaege>
            <p class="text-xs font-medium text-gray-500 mb-1">Was dem Teller fehlt</p>
            <ul class="flex flex-col gap-2">
                @foreach($daten['vorschlaege'] as $v)
                    <li>
                        <span class="font-medium text-gray-800">{{ \Platform\FoodAlchemist\Enums\Achse::from($v['achse'])->label() }}</span>
                        @foreach($v['formwechsel'] as $f)
                            <span class="block text-gray-700">→ {{ $f }}</span>
                        @endforeach
                        @foreach($v['basisrezepte'] as $b)
                            <span class="block text-gray-700">+ {{ $b['name'] }}<span class="text-[11px] text-gray-400"> · harmoniert mit {{ $b['mit'] }}</span></span>
                        @endforeach
                        @if($v['formwechsel'] === [] && $v['basisrezepte'] === [])
                            <span class="block text-gray-400">kein passendes Basisrezept im Bestand</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($leise->isNotEmpty())
        <details class="text-gray-500">
            <summary class="cursor-pointer text-xs">Weitere Paare ({{ $leise->count() }}): nur „passen" oder ohne nennenswerten aromatischen Bezug</summary>
            <ul class="mt-1 flex flex-col gap-0.5" data-aussagen="weitere">
                @foreach($leise as $x)
                    <li>{{ $x['text'] }}</li>
                @endforeach
            </ul>
        </details>
    @endif
</div>
