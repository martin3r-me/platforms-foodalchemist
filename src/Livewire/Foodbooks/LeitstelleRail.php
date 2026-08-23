<?php

namespace Platform\FoodAlchemist\Livewire\Foodbooks;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Services\CoverageService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\LeitstelleService;

/**
 * Spec 19 E5.3 — Leitstelle-Rail (Nested-Livewire, rechte activity-Sidebar des Foodbook-Cockpits).
 *
 * Spec-42-Vollzug S3b: die Kapitel-PLANUNG (M3-Ziele-Editor, Zielgruppen-Stempel, Kapitel-Go „Anlegen"
 * + Undo) ist in die Leitstelle gewandert ({@see \Platform\FoodAlchemist\Livewire\Planung\KapitelRail}).
 * Diese Rail zeigt jetzt nur noch KURATION/QC:
 * - **Kopf-Modus** ($kapitelId === null): 3-Panel-Umschalter Fortschritt (Kapitel-Matrix) · Speisen
 *   (heterogener Baum) · Kalkulation (Portfolio + WE-Ampel je Kapitel).
 * - **Kapitel-Modus**: Kapitel-Coverage + Kapitel-Kalkulation (read-only).
 */
class LeitstelleRail extends Component
{
    public int $foodbookId;

    public ?int $kapitelId = null;

    public function mount(int $foodbookId, ?int $kapitelId = null): void
    {
        $this->foodbookId = $foodbookId;
        $this->kapitelId = $kapitelId;
    }

    public function render(LeitstelleService $leit, FoodbookService $svc)
    {
        $team = $this->team();
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->find($this->foodbookId);
        if ($fb === null) {
            return view('foodalchemist::livewire.foodbooks.leitstelle-rail', ['fb' => null, 'modus' => 'leer']);
        }

        // ── Kapitel-Modus: Coverage + Kalkulation (read-only Kuration) ──
        if ($this->kapitelId !== null) {
            $k = $this->kapitel();
            $stand = $k !== null ? $leit->kapitelStand($team, $k) : null;
            $befunde = [];
            if ($k !== null) {
                $cov = app(CoverageService::class)->coverage($team, 'foodbook', $fb->id);
                $befunde = collect($cov['befunde'] ?? [])->where('chapter_id', $this->kapitelId)->values()->all();
            }

            return view('foodalchemist::livewire.foodbooks.leitstelle-rail', [
                'fb' => $fb,
                'modus' => $k !== null ? 'kapitel' : 'leer',
                'stand' => $stand,
                'befunde' => $befunde,
            ]);
        }

        // ── Kopf-Modus: Matrix / Speisen / Kalkulation (Alpine-Umschalter) ──
        return view('foodalchemist::livewire.foodbooks.leitstelle-rail', [
            'fb' => $fb,
            'modus' => 'kopf',
            'matrix' => $leit->kapitelMatrix($team, $fb),
            'baum' => $leit->speisenBaum($team, $fb),
            'gesamt' => $svc->gesamt($team, $fb),
            // Portfolio-WE-Ampel des ganzen Foodbooks (E8.2) — Kopf der Kalkulation-Panel.
            'weGesamt' => $svc->foodbookWareneinsatzAmpel($team, $fb),
        ]);
    }

    private function kapitel(): ?FoodAlchemistFoodbookKapitel
    {
        if ($this->kapitelId === null) {
            return null;
        }

        return FoodAlchemistFoodbookKapitel::where('foodbook_id', $this->foodbookId)->find($this->kapitelId);
    }

    private function team(): Team
    {
        return Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
    }
}
