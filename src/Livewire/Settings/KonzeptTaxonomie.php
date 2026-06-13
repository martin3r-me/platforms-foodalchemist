<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;
use RuntimeException;

/**
 * Konzept-Taxonomie — Pflege der zwei Klassifikations-Bäume über den Concepts:
 * KATEGORIE (FoodAlchemistConceptCategory, self-parent) und KLASSE (vocab_klassen,
 * seit 2026-06-14 self-parent). Beide rein organisatorisch (Filter-/Gruppier-Achse
 * im Concept-Browser, später Foodbook-Concept-Picker) — keine Preis-/Kalkulationslogik.
 *
 * Gleiche CRUD-Mechanik wie der Inline-Baum im Concept-Browser; gerendert über die
 * wiederverwendbare <x-foodalchemist::tree>-Komponente (Basisrezepte-Look).
 */
class KonzeptTaxonomie extends Component
{
    // Kategorie-Baum
    public ?int $katParent = null;          // gewählter Knoten = Eltern für „neu"

    public string $neueKategorie = '';

    public ?int $editKatId = null;

    public string $editKatName = '';

    // Klasse-Baum
    public ?int $klasseParent = null;

    public string $neueKlasse = '';

    public ?int $editKlasseId = null;

    public string $editKlasseName = '';

    public ?string $fehler = null;

    /** Achse für die Detail-Tabelle (Master-Detail wie Rezept-Taxonomie): kategorie|klasse. */
    public string $achse = 'kategorie';

    public function setAchse(string $achse): void
    {
        $this->achse = $achse === 'klasse' ? 'klasse' : 'kategorie';
        $this->reset('editKatId', 'editKlasseId', 'fehler');
    }

    // ── Kategorie ──────────────────────────────────────────────────────────

    public function katWaehlen(int $id): void
    {
        $this->katParent = $this->katParent === $id ? null : $id;
    }

    public function katNeu(ConceptService $svc): void
    {
        if (trim($this->neueKategorie) === '') {
            return;
        }
        try {
            $svc->createCategory($this->team(), $this->neueKategorie, $this->katParent);
            $this->neueKategorie = '';
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function katEditStart(int $id, string $name): void
    {
        $this->editKatId = $id;
        $this->editKatName = $name;
    }

    public function katRename(ConceptService $svc): void
    {
        if ($this->editKatId !== null) {
            try {
                $svc->renameCategory($this->team(), $this->editKatId, $this->editKatName);
                $this->fehler = null;
            } catch (RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }
        $this->editKatId = null;
        $this->editKatName = '';
    }

    public function katLoeschen(int $id, ConceptService $svc): void
    {
        try {
            $svc->deleteCategory($this->team(), $id);
            if ($this->katParent === $id) {
                $this->katParent = null;
            }
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Klasse ─────────────────────────────────────────────────────────────

    public function klasseWaehlen(int $id): void
    {
        $this->klasseParent = $this->klasseParent === $id ? null : $id;
    }

    public function klasseNeu(ConceptService $svc): void
    {
        if (trim($this->neueKlasse) === '') {
            return;
        }
        try {
            $svc->createKlasse($this->team(), $this->neueKlasse, $this->klasseParent);
            $this->neueKlasse = '';
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function klasseEditStart(int $id, string $name): void
    {
        $this->editKlasseId = $id;
        $this->editKlasseName = $name;
    }

    public function klasseRename(ConceptService $svc): void
    {
        if ($this->editKlasseId !== null) {
            try {
                $svc->renameKlasse($this->team(), $this->editKlasseId, $this->editKlasseName);
                $this->fehler = null;
            } catch (RuntimeException $e) {
                $this->fehler = $e->getMessage();
            }
        }
        $this->editKlasseId = null;
        $this->editKlasseName = '';
    }

    public function klasseLoeschen(int $id, ConceptService $svc): void
    {
        try {
            $svc->deleteKlasse($this->team(), $id);
            if ($this->klasseParent === $id) {
                $this->klasseParent = null;
            }
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    public function render(ConceptService $svc)
    {
        $team = $this->team();

        $katCounts = FoodAlchemistConcept::visibleToTeam($team)
            ->whereNotNull('category_id')->selectRaw('category_id, COUNT(*) AS n')
            ->groupBy('category_id')->pluck('n', 'category_id');

        // Klasse ist ein freier String am Concept → Count je Klasse-NAME.
        $klasseCounts = FoodAlchemistConcept::visibleToTeam($team)
            ->whereNotNull('klasse')->selectRaw('klasse, COUNT(*) AS n')
            ->groupBy('klasse')->pluck('n', 'klasse');

        return view('foodalchemist::livewire.settings.konzept-taxonomie', [
            'kategorien' => $svc->categoriesFlat($team),
            'klassen' => $svc->klassenFlat($team),
            'katCounts' => $katCounts,
            'klasseCounts' => $klasseCounts,
        ]);
    }

    private function team()
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
