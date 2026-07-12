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

    /** Inline-Rename. */
    public ?int $hgEditId = null;

    public string $hgEditName = '';

    public ?int $klasseEditId = null;

    public string $klasseEditName = '';

    public function waehleHg(int $id): void
    {
        $this->hauptgruppeId = $this->hauptgruppeId === $id ? null : $id;
        $this->reset('fehler');
    }

    /** #372: neue Speisen-Hauptgruppe anlegen (wie Rezept-Taxonomie). */
    public function createHg(): void
    {
        try {
            $hg = app(VocabularyService::class)->createDishMainGroup($this->team(), ['label' => $this->neuHg]);
            $this->reset('neuHg', 'fehler');
            $this->hauptgruppeId = $hg->id;
            $this->meldung = 'Speisen-Hauptgruppe angelegt.';
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /**
     * Modell A (Regelwerk_Verkaufsgerichte v1.1): Klassen = die 4 fixen Diätformen
     * (Fleisch/Fisch/Vegi/Vegan), global und HG-unabhängig. Es werden keine neuen,
     * HG-gebundenen Klassen mehr angelegt. Diät wird am Gericht gewählt.
     */
    public function createKlasse(): void
    {
        $this->fehler = 'Klassen sind unter Modell A die 4 fixen Diätformen (Fleisch/Fisch/Vegi/Vegan) — es werden keine neuen Klassen mehr angelegt. Die Hauptgruppe ist die Kategorie.';
    }

    /** VK-Hauptgruppe umbenennen (Inline; Code bleibt stabil). */
    public function startHgEdit(int $id, string $aktuell): void
    {
        $this->hgEditId = $id;
        $this->hgEditName = $aktuell;
        $this->fehler = null;
    }

    public function hgSave(): void
    {
        try {
            app(VocabularyService::class)->updateDishMainGroup($this->team(), (int) $this->hgEditId, $this->hgEditName);
            $this->reset('hgEditId', 'hgEditName');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function hgDelete(int $id): void
    {
        try {
            app(VocabularyService::class)->deleteDishMainGroup($this->team(), $id);
            if ($this->hauptgruppeId === $id) {
                $this->hauptgruppeId = null;
            }
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** VK-Klasse umbenennen (Inline). */
    public function startKlasseEdit(int $id, string $aktuell): void
    {
        $this->klasseEditId = $id;
        $this->klasseEditName = $aktuell;
        $this->fehler = null;
    }

    public function klasseSave(): void
    {
        try {
            app(VocabularyService::class)->updateDishClass($this->team(), (int) $this->klasseEditId, ['label' => $this->klasseEditName]);
            $this->reset('klasseEditId', 'klasseEditName');
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function klasseDelete(int $id): void
    {
        try {
            app(VocabularyService::class)->deleteDishClass($this->team(), $id);
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
        $team = $this->team();   // Mandanten-Sichtbarkeit (D1): globaler Seed + eigenes Team/Master-Kette.

        // Modell A (Regelwerk_Verkaufsgerichte v1.1): Klasse = Diätform (4 flache Klassen, global).
        $klassenZaehler = DB::table('foodalchemist_recipes')->whereNull('deleted_at')
            ->whereNotNull('dish_class_id')->selectRaw('dish_class_id, COUNT(*) AS n')
            ->groupBy('dish_class_id')->pluck('n', 'dish_class_id');

        // Rezept-Zähler je Hauptgruppe (HG direkt am Rezept via dish_main_group_id).
        $rezepteJeHg = DB::table('foodalchemist_recipes')->whereNull('deleted_at')
            ->whereNotNull('dish_main_group_id')->selectRaw('dish_main_group_id, COUNT(*) AS n')
            ->groupBy('dish_main_group_id')->pluck('n', 'dish_main_group_id');

        return view('foodalchemist::livewire.settings.vk-taxonomie', [
            'hauptgruppen' => FoodAlchemistDishMainGroup::visibleToTeam($team)->orderBy('sort_order')->orderBy('code')->get(),
            // Die 4 globalen Diät-Klassen (HG-unabhängig), immer sichtbar.
            'klassen' => FoodAlchemistDishClass::visibleToTeam($team)->whereNull('dish_main_group_id')->orderBy('id')->get(),
            'klassenZaehler' => $klassenZaehler,
            'klassenJeHg' => $rezepteJeHg,
        ]);
    }
}
