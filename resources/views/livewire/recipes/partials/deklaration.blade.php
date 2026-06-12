{{-- R6 (Bild-5-Vorbild): Diät & Spezifikation · 14 EU-Allergene · 18 LMIV-Zusatzstoffe —
     EIN Partial für Basis- UND VK-Panel (erwartet $rezept + Ui-Maps im Kontext).
     Zusatz-Stufen: 3 = enthalten (rot) · 0–2 = nein/gering (grau) · NULL = unbewertet (gestrichelt). --}}

<div data-deklaration>
    <p class="{{ $dt }} mb-1">Diät & Spezifikation</p>
    <div class="grid grid-cols-2 gap-x-4 gap-y-0.5 text-sm" data-deklaration-diaet>
        @foreach([
            'spec_is_vegan' => 'Vegan', 'spec_is_vegetarian' => 'Vegetarisch',
            'spec_is_halal' => 'Halal', 'spec_is_gluten_free' => 'Glutenfrei',
            'spec_is_lactose_free' => 'Laktosefrei',
        ] as $feld => $lbl)
            @php($wert = $rezept->{$feld})
            <span class="font-medium uppercase tracking-wide text-xs {{ $wert === true ? 'text-green-600 dark:text-green-400' : ($wert === false ? 'text-gray-400 line-through decoration-1' : 'text-gray-400 italic') }}"
                  title="{{ $wert === null ? 'unbewertet' : ($wert ? 'ja' : 'nein') }}">{{ $lbl }} {{ $wert === true ? '✓' : ($wert === false ? '✕' : '?') }}</span>
        @endforeach
        @foreach(['spec_contains_pork' => 'enth. Schwein', 'spec_contains_beef' => 'enth. Rind'] as $feld => $lbl)
            @if($rezept->{$feld} === true)
                <span class="font-medium uppercase tracking-wide text-xs text-amber-600 dark:text-amber-400">{{ $lbl }} ⚠</span>
            @endif
        @endforeach
    </div>
</div>

<div data-deklaration-allergene>
    <p class="{{ $dt }} mb-1">Allergene
        <span class="normal-case ml-1 font-semibold {{ ['high' => 'text-green-600', 'medium' => 'text-amber-500', 'low' => 'text-rose-500'][$rezept->allergene_konfidenz] ?? 'text-gray-400' }}">{{ strtoupper($rezept->allergene_konfidenz) }}</span>
    </p>
    <div class="grid grid-cols-2 gap-x-4 gap-y-0.5" data-allergen-grid>
        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE as $feld => $lbl)
            @php($wert = $rezept->{"allergen_{$feld}"})
            <span class="text-sm px-1.5 py-0.5 rounded {{ [
                    'enthalten' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 font-medium',
                    'spuren' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
                    'nicht_enthalten' => 'text-gray-400',
                ][$wert] ?? 'text-gray-400 italic opacity-60' }}"
                  title="{{ $lbl }} — {{ str_replace('_', ' ', $wert ?? 'unbekannt') }}">{{ explode(' ', $lbl)[0] }}</span>
        @endforeach
    </div>
</div>

<div data-deklaration-zusatzstoffe>
    <p class="{{ $dt }} mb-1">Zusatzstoffe (LMIV)</p>
    <div class="grid grid-cols-2 gap-1" data-zusatz-grid>
        @foreach(\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE as $stoff => $lbl)
            @php($wert = $rezept->{"zusatz_{$stoff}"})
            <span class="text-xs px-1.5 py-0.5 rounded border truncate {{ $wert === null
                    ? 'border-dashed border-black/15 dark:border-white/15 text-gray-400 italic'
                    : ((int) $wert === 3
                        ? 'border-rose-300 dark:border-rose-500/40 bg-rose-500/10 text-rose-600 dark:text-rose-400 font-medium'
                        : 'border-black/5 dark:border-white/10 text-gray-400') }}"
                  title="{{ $lbl }} — {{ $wert === null ? 'unbewertet' : ((int) $wert === 3 ? 'enthalten' : 'nicht enthalten') }}">{{ ucfirst(str_replace(['mit ', 'enthält eine ', 'enthält ', 'kann bei übermäßigem Verzehr ', 'unter ', ' verpackt', 'kann Aktivität/Aufmerksamkeit bei Kindern beeinträchtigen', 'mit Zuckerart(en) und Süßungsmittel(n)'], ['', '', '', '', '', '', 'Kinder-Aufmerksamkeit', 'Zucker+Süßstoff'], $lbl)) }}</span>
        @endforeach
    </div>
</div>
