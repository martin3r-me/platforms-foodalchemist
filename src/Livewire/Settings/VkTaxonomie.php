<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Services\VocabularyService;
use RuntimeException;

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

    public ?string $fehler = null;

    public string $neuHg = '';

    public string $neuKlasse = '';

    public string $neuKlasseDiaet = 'neutral';

    public function waehleHg(int $id): void
    {
        $this->hauptgruppeId = $this->hauptgruppeId === $id ? null : $id;
        $this->reset('fehler');
    }

    /** #372: neue Speisen-Hauptgruppe anlegen (wie Rezept-Taxonomie). */
    public function createHg(): void
    {
        try {
            $hg = app(VocabularyService::class)->createDishMainGroup($this->team(), ['bezeichnung' => $this->neuHg]);
            $this->reset('neuHg', 'fehler');
            $this->hauptgruppeId = $hg->id;
            $this->meldung = 'Speisen-Hauptgruppe angelegt.';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** #372: neue Klasse zur gewählten Hauptgruppe anlegen. */
    public function createKlasse(): void
    {
        if ($this->hauptgruppeId === null) {
            $this->fehler = 'Erst eine Speisen-Hauptgruppe wählen.';

            return;
        }
        try {
            app(VocabularyService::class)->createDishClass($this->team(), $this->hauptgruppeId, [
                'bezeichnung' => $this->neuKlasse,
                'diaetform' => $this->neuKlasseDiaet,
            ]);
            $this->reset('neuKlasse', 'fehler');
            $this->neuKlasseDiaet = 'neutral';
            $this->meldung = 'Klasse angelegt.';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
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

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
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
