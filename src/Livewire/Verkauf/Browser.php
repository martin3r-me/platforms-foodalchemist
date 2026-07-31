<?php

namespace Platform\FoodAlchemist\Livewire\Verkauf;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\FoodAlchemist\Enums\RecipeStatus;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Support\Curate;

/**
 * M6-03 / D-6 §4.1 + 13_REFERENZ: VK-Browser — VK-Hauptgruppen mit Codes
 * ([APE]…[GET]) + Klassen-Kaskade links, Geschmacks-Pills, dichte Tabelle
 * (VK netto · EK · Zutaten · Allergen-Konf.), DetailPanel rechts (P-1,
 * Kontext in der URL). »✨ KI-Rezept« = VK-Generator (M6-06, bis dahin aus).
 */
class Browser extends Component
{
    use WithPagination;

    #[Url(as: 'rezept')]
    public ?int $recipeId = null;

    #[Url(as: 'hg')]
    public ?int $hauptgruppe = null;

    #[Url(as: 'class')]
    public ?int $klasse = null;

    #[Url]
    public string $status = '';

    #[Url(as: 'geschmack')]
    public string $geschmack = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'zeilen')]
    public int $perPage = 100;

    /**
     * Spec 28 / E14: Spalten-Ansichten (Muster wie im Basisrezept-Browser).
     * Katalog-Reihenfolge = Anzeige-Reihenfolge = Reihenfolge der <td> im Blade. Ansichten sind
     * MENGEN — würde der Kopf der Ansichts-Ordnung folgen, stünden Kopf und Zellen versetzt.
     */
    #[Url(as: 'ansicht')]
    public string $ansicht = 'standard';

    public const SPALTEN = [
        'klasse' => ['Klasse', ''],
        'geschmack' => ['Geschmack', ''],
        'hauptgruppe' => ['HG', ''],
        'ek' => ['EK', 'text-right'],
        'vk' => ['VK netto', 'text-right'],
        'we' => ['W %', 'text-right'],           // Wareneinsatz — die Entscheidungszahl am Gericht
        'zutaten' => ['Zutaten', 'text-right'],
        'allergen' => ['Allergen-Konf.', ''],
        'status' => ['Status', ''],
    ];

    public const ANSICHTEN = [
        'standard' => ['Standard', ['klasse', 'vk', 'we', 'status']],
        'kalkulation' => ['Kalkulation', ['klasse', 'ek', 'vk', 'we', 'status']],
        'pflege' => ['Datenpflege', ['klasse', 'hauptgruppe', 'zutaten', 'allergen', 'status']],
    ];

    /** Sichtbare Spalten in KATALOG-Reihenfolge; unbekannte Ansicht fällt auf Standard zurück. */
    public function spalten(): array
    {
        $menge = (self::ANSICHTEN[$this->ansicht] ?? self::ANSICHTEN['standard'])[1];

        return array_values(array_filter(array_keys(self::SPALTEN), fn ($k) => in_array($k, $menge, true)));
    }

    public function waehleHauptgruppe(?int $id): void
    {
        // Modell A: HG und Klasse(Diät) sind unabhängige Achsen — kein Kaskaden-Reset mehr.
        $this->hauptgruppe = $this->hauptgruppe === $id ? null : $id;
        $this->resetPage();
    }

    public function waehleKlasse(int $id): void
    {
        $this->klasse = $this->klasse === $id ? null : $id;
        $this->resetPage();
    }

    public function waehleGeschmack(string $wert): void
    {
        $this->geschmack = $this->geschmack === $wert ? '' : $wert;
        $this->resetPage();
    }

    /** R6: Direkt-Öffnen — Namens-Klick öffnet den VK-Editor. */
    public function bearbeite(int $id): void
    {
        $this->waehleRezept($id);
        $this->dispatch('vk-modal.oeffnen', id: $id);
    }

    public function waehleRezept(int $id): void
    {
        $this->recipeId = $id;
        $this->dispatch('vk-recipe-selected', id: $id);
    }

    /** MVP-024: sichtbarer Statusfehler, spiegelbildlich zum Basisrezept-Browser. */
    public ?string $statusFehler = null;

    /** Inline-Status-Pflege aus der Gerichte-Liste (canCurate-Gate, D1) — Setter im RecipeService. */
    public function statusSetzen(int $id, string $status, RecipeService $svc): void
    {
        $this->statusFehler = null;
        $team = Auth::user()?->currentTeamRelation;
        $recipe = $team !== null ? FoodAlchemistRecipe::visibleToTeam($team)->find($id) : null;
        if ($recipe === null) {
            $this->statusFehler = 'Gericht nicht gefunden oder nicht sichtbar — Status nicht geändert.';

            return;
        }
        if (! Curate::canCurate(Auth::user(), $recipe)) {
            $this->statusFehler = 'Geerbtes Gericht — Statuspflege nur durchs Besitzer-Team.';

            return;
        }
        if (RecipeStatus::tryFrom($status) === null) {
            $this->statusFehler = "Unbekannter Status [{$status}] — nicht gesetzt.";

            return;
        }
        try {
            $svc->setStatus($team, $id, $status);
        } catch (\RuntimeException $e) {
            $this->statusFehler = $e->getMessage();
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->perPage = in_array((int) $this->perPage, [25, 50, 100, 250, 500], true) ? (int) $this->perPage : 100;
        $this->resetPage();
    }

    #[On('recipe-gespeichert')]
    public function aktualisiere(): void
    {
        // Edit/Recompute → Tabelle + Counts neu (Kontext bleibt)
    }

    public function mount(): void
    {
        if ($this->recipeId !== null) {
            $this->dispatch('vk-recipe-selected', id: $this->recipeId);
        }
    }

    public function render(SalesRecipeService $verkauf)
    {
        $team = Auth::user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');

        // EIN Filtersatz für Tabelle und Facetten (MVP-048): vorher kannten die Zähler die
        // aktive Klasse, Suche und den Status nicht — `?class=61&hg=10` lieferte dann 0 Treffer.
        $filters = [
            'search' => $this->search,
            'hauptgruppe' => $this->hauptgruppe,
            'class' => $this->klasse,
            'status' => $this->status,
            'geschmack' => $this->geschmack,
        ];

        $rezepte = $verkauf->paginateBrowser($filters, $team, in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 100);

        return view('foodalchemist::livewire.verkauf.browser', [
            'spalten' => $this->spalten(),
            'spaltenKatalog' => self::SPALTEN,
            'ansichten' => self::ANSICHTEN,
            'rezepte' => $rezepte,
            'hauptgruppen' => $verkauf->dishMainGroups($team),
            'gesamtCount' => $verkauf->gesamtCount($team, $filters),
            'hgCounts' => $verkauf->hauptgruppenCounts($team, $filters),
            // Modell A: Klasse = die 4 flachen Diätformen (unabhängige Achse). Baum-Ansicht
            // 2026-07-06: Counts auf die gewählte HG gescoped, wenn ein HG-Knoten offen ist —
            // die HG steckt jetzt im Filtersatz statt in einem eigenen Parameter.
            'klassen' => FoodAlchemistDishClass::visibleToTeam($team)->whereNull('dish_main_group_id')->orderBy('id')->get(),
            'klassenCounts' => $verkauf->klassenCounts($team, $filters),
            'statusFaelle' => RecipeStatus::cases(),
            'statusCounts' => $verkauf->statusCounts($team),
        ])->layout('platform::layouts.app');
    }
}
