<?php

namespace Platform\FoodAlchemist\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptService;
use RuntimeException;

/**
 * Konzept-Taxonomie — Pflege der zwei Klassifikations-Bäume über den Concepts:
 * KATEGORIE (FoodAlchemistConceptCategory, self-parent) und KLASSE (vocab_klassen).
 * Beide rein organisatorisch (Filter-/Gruppier-Achse im Concept-Browser + Foodbook-/
 * Angebots-Picker) — keine Preis-/Kalkulationslogik.
 *
 * UI = Master-Detail wie Rezept-/VK-Taxonomie (2026-06-17, Dominique): oberste Knoten
 * links wählbar → deren direkte Kinder rechts. 2 Ebenen (Ober → Unter); keine flache
 * Gesamttabelle mehr (wurde mit Verschachtelung zu lang).
 */
class KonzeptTaxonomie extends Component
{
    /** Achse der Master-Detail-Ansicht: kategorie|klasse. */
    public string $achse = 'category';

    // ── Kategorie ──
    public ?int $katSelectedId = null;     // gewählte Ober-Kategorie (zeigt ihre Kinder)

    public string $neuTopKat = '';

    public string $neuSubKat = '';

    public ?int $editKatId = null;

    public string $editKatName = '';

    // ── Klasse ──
    public ?int $klasseSelectedId = null;

    public string $neuTopKlasse = '';

    public string $neuSubKlasse = '';

    public ?int $editKlasseId = null;

    public string $editKlasseName = '';

    public ?string $fehler = null;

    public function setAchse(string $achse): void
    {
        $this->achse = $achse === 'class' ? 'class' : 'category';
        $this->reset('editKatId', 'editKlasseId', 'fehler');
    }

    // ── Kategorie ────────────────────────────────────────────────────────────

    public function katWaehlen(int $id): void
    {
        $this->katSelectedId = $id;
        $this->editKatId = null;
    }

    public function katNeuTop(ConceptService $svc): void
    {
        $this->kategorieAnlegen($svc, $this->neuTopKat, null);
        $this->neuTopKat = '';
    }

    public function katNeuSub(ConceptService $svc): void
    {
        if ($this->katSelectedId === null) {
            return;
        }
        $this->kategorieAnlegen($svc, $this->neuSubKat, $this->katSelectedId);
        $this->neuSubKat = '';
    }

    private function kategorieAnlegen(ConceptService $svc, string $name, ?int $parent): void
    {
        if (trim($name) === '') {
            return;
        }
        try {
            $svc->createCategory($this->team(), $name, $parent);
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
            if ($this->katSelectedId === $id) {
                $this->katSelectedId = null;
            }
            $this->fehler = null;
        } catch (RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    // ── Klasse ───────────────────────────────────────────────────────────────

    public function klasseWaehlen(int $id): void
    {
        $this->klasseSelectedId = $id;
        $this->editKlasseId = null;
    }

    public function klasseNeuTop(ConceptService $svc): void
    {
        $this->klasseAnlegen($svc, $this->neuTopKlasse, null);
        $this->neuTopKlasse = '';
    }

    public function klasseNeuSub(ConceptService $svc): void
    {
        if ($this->klasseSelectedId === null) {
            return;
        }
        $this->klasseAnlegen($svc, $this->neuSubKlasse, $this->klasseSelectedId);
        $this->neuSubKlasse = '';
    }

    private function klasseAnlegen(ConceptService $svc, string $name, ?int $parent): void
    {
        if (trim($name) === '') {
            return;
        }
        try {
            $svc->createKlasse($this->team(), $name, $parent);
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
            if ($this->klasseSelectedId === $id) {
                $this->klasseSelectedId = null;
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

        $klasseCounts = FoodAlchemistConcept::visibleToTeam($team)
            ->whereNotNull('class')->selectRaw('class, COUNT(*) AS n')
            ->groupBy('class')->pluck('n', 'class');

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
