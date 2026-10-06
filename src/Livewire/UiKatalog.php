<?php

namespace Platform\FoodAlchemist\Livewire;

use Livewire\Component;

/**
 * fa-pass Welle 0: Musterseite der Bausteinbibliothek <x-fa::…>.
 *
 * Lebende Referenz für Entwicklung und Abnahme — jeder Baustein mit seinen Zuständen,
 * hell und im Werkbank-Modus (dunkle Editoren). Reine Darstellung, keine Daten aus der DB.
 */
class UiKatalog extends Component
{
    public string $kreativModus = 'voll';

    /** @var array<int, string> */
    public array $diaeten = ['vegan', 'glutenfrei'];

    public string $menge = '3,5';

    public function render()
    {
        // Beispielobjekt für x-fa::deklaration (Werte wie BBQ-Sauce/Coleslaw aus der Sandbox, als Beispiel markiert).
        $beispielRezept = (object) array_merge(
            array_fill_keys(array_map(fn ($k) => "allergen_{$k}", array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::ALLERGENE)), 'nicht_enthalten'),
            array_fill_keys(array_map(fn ($k) => "additive_{$k}", array_keys(\Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration::STOFFE)), 0),
            [
                'allergen_eggs' => 'enthalten', 'allergen_milk' => 'enthalten', 'allergen_sesame' => 'enthalten',
                'allergen_mustard' => 'spuren', 'allergen_lupin' => null,
                'additive_with_preservative' => 3, 'additive_with_antioxidant' => 3,
                'spec_is_vegan' => false, 'spec_is_vegetarian' => true, 'spec_is_halal' => true,
                'spec_is_gluten_free' => true, 'spec_is_lactose_free' => false,
                'spec_contains_pork' => false, 'spec_contains_beef' => false,
                'allergens_confidence' => 'low',
            ],
        );

        return view('foodalchemist::livewire.ui-katalog', ['beispielRezept' => $beispielRezept])
            ->layout('foodalchemist::layouts.standalone', ['title' => 'Designsystem']);
    }
}
