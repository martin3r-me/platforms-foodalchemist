{{-- x-fa::deklaration — Allergene, Zusatzstoffe, Diät für Basis- und VK-Rezepte (ein Baustein, drei Orte).

     Leitidee: oben steht nur, was der Gast wissen muss. Enthalten (rot) und Spuren (Bernstein) zuerst,
     „geeignet für" als positive Liste ohne Durchstreichungen, die Konfidenz als Satz.
     Die vollständige 14-/18er-Liste bleibt erreichbar, aber eingeklappt.

     Erwartet: rezept mit spec_is_* · spec_contains_* · allergen_<key> ('enthalten'|'spuren'|'nicht_enthalten'|null)
               · additive_<key> (0–3|null, 3 = enthalten) · allergens_confidence ('high'|'medium'|'low'|'none'|null). --}}
@props(['rezept'])
@php
    $allergenListe = \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE;
    $stoffListe = \Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE;

    $kurz = fn (string $label) => $label === 'Glutenhaltiges Getreide' ? 'Gluten' : trim(explode(' (', $label)[0]);
    $stoffKurz = fn (string $label) => ucfirst(str_replace(
        ['mit ', 'enthält eine ', 'enthält ', 'kann bei übermäßigem Verzehr ', 'unter ', ' verpackt', 'kann Aktivität/Aufmerksamkeit bei Kindern beeinträchtigen', 'mit Zuckerart(en) und Süßungsmittel(n)'],
        ['', '', '', '', '', '', 'Kinder-Aufmerksamkeit', 'Zucker und Süßungsmittel'], $label));

    $enthalten = $spuren = $frei = $offen = [];
    foreach ($allergenListe as $feld => $label) {
        $wert = $rezept->{"allergen_{$feld}"} ?? null;
        match ($wert) {
            'enthalten' => $enthalten[$feld] = $kurz($label),
            'spuren' => $spuren[$feld] = $kurz($label),
            'nicht_enthalten' => $frei[$feld] = $kurz($label),
            default => $offen[$feld] = $kurz($label),
        };
    }

    $stoffeJa = $stoffeOffen = [];
    foreach ($stoffListe as $stoff => $label) {
        $wert = $rezept->{"additive_{$stoff}"} ?? null;
        if ($wert === null) { $stoffeOffen[$stoff] = $stoffKurz($label); }
        elseif ((int) $wert === 3) { $stoffeJa[$stoff] = $stoffKurz($label); }
    }

    $diaeten = ['spec_is_vegan' => 'vegan', 'spec_is_vegetarian' => 'vegetarisch', 'spec_is_halal' => 'halal', 'spec_is_gluten_free' => 'glutenfrei', 'spec_is_lactose_free' => 'laktosefrei'];
    $geeignet = $nichtGeeignet = $diaetOffen = [];
    foreach ($diaeten as $feld => $label) {
        $wert = $rezept->{$feld} ?? null;
        if ($wert === true) { $geeignet[] = $label; } elseif ($wert === false) { $nichtGeeignet[] = $label; } else { $diaetOffen[] = $label; }
    }
    $fleisch = array_keys(array_filter(['Schwein' => ($rezept->spec_contains_pork ?? null) === true, 'Rind' => ($rezept->spec_contains_beef ?? null) === true]));

    [$konfText, $konfTon] = match ($rezept->allergens_confidence ?? null) {
        'high' => ['Deklaration vollständig belegt', 'ok'],
        'medium' => ['Deklaration teilweise belegt, bitte stichprobenartig prüfen', 'warn'],
        'low' => ['Deklaration unsicher, vor Ausgabe prüfen', 'crit'],
        default => ['Deklaration noch nicht bewertet', 'warn'],
    };
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-4']) }} data-deklaration>
    <x-fa::signal :tone="$konfTon" data-deklaration-konfidenz="{{ $rezept->allergens_confidence ?? 'none' }}">{{ $konfText }}</x-fa::signal>

    <div class="flex flex-col gap-2" data-deklaration-allergene>
        @if($enthalten !== [] || $spuren !== [])
            @if($enthalten !== [])
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-[92px] shrink-0 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Enthält</span>
                    @foreach($enthalten as $feld => $label)<x-fa::badge tone="crit" data-allergen="{{ $feld }}">{{ $label }}</x-fa::badge>@endforeach
                </div>
            @endif
            @if($spuren !== [])
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-[92px] shrink-0 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Spuren von</span>
                    @foreach($spuren as $feld => $label)<x-fa::badge tone="warn" data-allergen="{{ $feld }}">{{ $label }}</x-fa::badge>@endforeach
                </div>
            @endif
        @elseif($offen === [])
            <x-fa::signal tone="ok">Keines der 14 Hauptallergene enthalten</x-fa::signal>
        @endif
        @if($offen !== [])
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">{{ count($offen) }} von 14 Allergenen noch nicht bewertet</p>
        @endif
    </div>

    @if($stoffeJa !== [])
        <div class="flex flex-wrap items-center gap-1.5" data-deklaration-zusatzstoffe>
            <span class="w-[92px] shrink-0 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Kennzeichnung</span>
            @foreach($stoffeJa as $stoff => $label)<x-fa::badge data-zusatzstoff="{{ $stoff }}">{{ $label }}</x-fa::badge>@endforeach
        </div>
    @endif

    <div class="flex flex-col gap-1.5" data-deklaration-diaet>
        @if($geeignet !== [])
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span class="w-[92px] shrink-0 text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-ink-2)]">Geeignet für</span>
                @foreach($geeignet as $label)<x-fa::signal tone="ok" icon="heroicon-m-check">{{ $label }}</x-fa::signal>@endforeach
            </div>
        @endif
        @if($nichtGeeignet !== [])
            <p class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]"><span class="inline-block w-[92px] font-medium text-[var(--fa-ink-2)]">Nicht geeignet</span>{{ implode(' · ', $nichtGeeignet) }}</p>
        @endif
        @foreach($fleisch as $tier)
            <x-fa::signal tone="warn">enthält {{ $tier }}</x-fa::signal>
        @endforeach
    </div>

    <details class="group">
        <summary class="inline-flex items-center gap-1 cursor-pointer select-none text-[length:var(--fa-text-sm)] font-medium text-[var(--fa-accent)] hover:text-[var(--fa-accent-hover)]">
            @svg('heroicon-m-chevron-right', 'w-4 h-4 transition-transform group-open:rotate-90')
            Alle 14 Allergene und {{ count($stoffListe) }} Zusatzstoffe
        </summary>
        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4">
            <table class="fa-table fa-table--compact" data-allergen-grid>
                <caption class="sr-only">Allergene</caption>
                <tbody>
                    @foreach($allergenListe as $feld => $label)
                        @php($wert = $rezept->{"allergen_{$feld}"} ?? null)
                        <tr>
                            <td>{{ $kurz($label) }}</td>
                            <td class="text-right">
                                @switch($wert)
                                    @case('enthalten') <x-fa::badge tone="crit">enthalten</x-fa::badge> @break
                                    @case('spuren') <x-fa::badge tone="warn">Spuren</x-fa::badge> @break
                                    @case('nicht_enthalten') <span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">nein</span> @break
                                    @default <span class="text-[length:var(--fa-text-sm)] italic text-[var(--fa-ink-3)]">unbewertet</span>
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <table class="fa-table fa-table--compact" data-zusatz-grid>
                <caption class="sr-only">Zusatzstoffe</caption>
                <tbody>
                    @foreach($stoffListe as $stoff => $label)
                        @php($wert = $rezept->{"additive_{$stoff}"} ?? null)
                        <tr>
                            <td>{{ $stoffKurz($label) }}</td>
                            <td class="text-right">
                                @if($wert === null)<span class="text-[length:var(--fa-text-sm)] italic text-[var(--fa-ink-3)]">unbewertet</span>
                                @elseif((int) $wert === 3)<x-fa::badge>ja</x-fa::badge>
                                @else<span class="text-[length:var(--fa-text-sm)] text-[var(--fa-ink-3)]">nein</span>@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
