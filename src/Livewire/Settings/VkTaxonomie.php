<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;

/**
 * Editor-Parität / D-6 §4.6: VK-Taxonomie-Pflege — Speisen-Hauptgruppen
 * (16 Codes), Speisen-Klassen (HG × Diätform, mit Rezept-Zählern),
 * Aufschlagsklassen (W-1-Kennzeichnung) + Schreibstile/Container-Vokabulare.
 * Lösch-Schutz V-06: referenzierte Einträge nur deaktivierbar.
 */
class VkTaxonomie extends Component
{
    public ?int $hauptgruppeId = null;

    public ?string $meldung = null;

    public function waehleHg(int $id): void
    {
        $this->hauptgruppeId = $this->hauptgruppeId === $id ? null : $id;
    }

    /** V-06: referenziert ⇒ nur deaktivieren (typisierte Meldung statt Löschen). */
    public function toggleInactive(string $tabelle, int $id): void
    {
        $erlaubt = ['foodalchemist_dish_main_groups'];          // R5: AK/Stile/Vokabulare haben eigene Seiten
        if (! in_array($tabelle, $erlaubt, true)) {
            return;
        }
        $zeile = DB::table($tabelle)->where('id', $id)->first(['is_inactive']);
        if ($zeile !== null) {
            DB::table($tabelle)->where('id', $id)->update(['is_inactive' => ! $zeile->is_inactive, 'updated_at' => now()]);
            $this->meldung = 'Aktualisiert — inaktive Einträge bleiben an Rezepten sichtbar, solange sie zugewiesen sind (V-06).';
        }
    }

    public function render()
    {
        $klassenZaehler = DB::table('foodalchemist_recipes')->whereNull('deleted_at')
            ->whereNotNull('speisen_klasse_id')->selectRaw('speisen_klasse_id, COUNT(*) AS n')
            ->groupBy('speisen_klasse_id')->pluck('n', 'speisen_klasse_id');

        return view('foodalchemist::livewire.settings.vk-taxonomie', [
            'hauptgruppen' => FoodAlchemistDishMainGroup::orderBy('sort_order')->orderBy('code')->get(),
            'klassen' => $this->hauptgruppeId !== null
                ? FoodAlchemistDishClass::where('dish_main_group_id', $this->hauptgruppeId)->orderBy('bezeichnung')->get()
                : collect(),
            'klassenZaehler' => $klassenZaehler,
            'klassenJeHg' => FoodAlchemistDishClass::selectRaw('dish_main_group_id, COUNT(*) AS n')
                ->groupBy('dish_main_group_id')->pluck('n', 'dish_main_group_id'),
        ]);
    }
}
