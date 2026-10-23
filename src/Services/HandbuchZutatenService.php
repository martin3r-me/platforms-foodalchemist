<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 86 — Zutaten und Basisrezepte fürs Handbuch (Küchen-Sicht). Liest aus dem Report-Weg
 * ({@see ReportExportService}, Profil „Küche": Zutaten, Schritte, Basisrezepte — ohne Preise und
 * Lieferanten) und gibt NUR eine Allowlist weiter: Name, Menge, Einheit, Basisrezept-Verweis.
 * EK, Lieferant, Marge, Nährwert-Rohdaten und KI-Lineage bleiben draußen.
 *
 * Mengen am Gericht: je EINE Portion (Hochrechnung „1 × Standard-Darreichung"); fehlt Portionsgewicht
 * oder Ausbeute, steht die Ansatz-Menge mit Hinweis (`basis = ansatz`) — nichts wird geschätzt.
 * Basisrezepte stehen einmal je Snapshot in `basisrezepte` (Schlüssel `b<id>`) in ihrem eigenen Ansatz.
 */
class HandbuchZutatenService
{
    private const MAX_TIEFE = 3;

    /** @var array<string,array> */
    private array $basis = [];

    public function __construct(private ReportExportService $report)
    {
    }

    public function zuruecksetzen(): void
    {
        $this->basis = [];
    }

    /** @return array<string,array> */
    public function basisrezepte(): array
    {
        return $this->basis;
    }

    /**
     * @return array{basis:string, ansatz_kg:?float, zeilen:list<array{name:string, menge:?float, einheit:?string, sub:?string}>}
     */
    public function fuerGericht(Team $team, FoodAlchemistRecipe $recipe): array
    {
        $leer = ['basis' => 'ansatz', 'ansatz_kg' => null, 'zeilen' => []];
        try {
            $data = $this->report->rezeptDaten($team, (int) $recipe->id, $this->optionen(['ziel_menge' => 1]));
            $knoten = $data['recipe'];
            $portion = (bool) ($data['hochrechnung']['aktiv'] ?? false);

            return [
                'basis' => $portion ? 'portion' : 'ansatz',
                'ansatz_kg' => $knoten['yield_kg'] !== null ? (float) $knoten['yield_kg'] : null,
                'zeilen' => $this->zeilen($team, $recipe, $knoten['ingredients'] ?? [], 1),
            ];
        } catch (\Throwable $e) {
            // Fail-soft: ein Report-Fehler darf das Veröffentlichen nicht kippen — der Abschnitt fehlt dann (Lücke sichtbar).
            Log::warning('Handbuch-Zutaten nicht lesbar', ['recipe_id' => $recipe->id, 'fehler' => $e->getMessage()]);

            return $leer;
        }
    }

    /**
     * @param  list<array<string,mixed>>  $zutaten  Report-Knoten
     * @return list<array{name:string, menge:?float, einheit:?string, sub:?string}>
     */
    private function zeilen(Team $team, FoodAlchemistRecipe $recipe, array $zutaten, int $tiefe): array
    {
        $refs = $recipe->ingredients->keyBy('id');
        $out = [];
        foreach ($zutaten as $z) {
            $sub = null;
            $refId = $refs->get((int) ($z['id'] ?? 0))?->referenced_recipe_id;
            if ($refId !== null && $tiefe <= self::MAX_TIEFE) {
                $sub = $this->basisrezept($team, (int) $refId, $tiefe);
            }
            $out[] = [
                'name' => (string) ($z['name'] ?? ''),
                'menge' => isset($z['quantity']) ? (float) $z['quantity'] : null,
                'einheit' => $z['unit'] ?? null,
                'sub' => $sub,
            ];
        }

        return $out;
    }

    private function basisrezept(Team $team, int $id, int $tiefe): ?string
    {
        $key = 'b' . $id;
        if (isset($this->basis[$key])) {
            return $key;
        }
        $this->basis[$key] = ['name' => '', 'ansatz_kg' => null, 'zeilen' => [], 'schritte' => []];   // Zyklus-Schutz
        try {
            $recipe = FoodAlchemistRecipe::visibleToTeam($team)->with(['ingredients' => fn ($q) => $q->whereNull('deleted_at')])->find($id);
            if ($recipe === null) {
                unset($this->basis[$key]);

                return null;
            }
            $knoten = $this->report->rezeptDaten($team, $id, $this->optionen([]))['recipe'];
            $this->basis[$key] = [
                'name' => (string) $recipe->name,
                'ansatz_kg' => $knoten['yield_kg'] !== null ? (float) $knoten['yield_kg'] : null,
                'zeilen' => $this->zeilen($team, $recipe, $knoten['ingredients'] ?? [], $tiefe + 1),
                'schritte' => array_map(fn ($s) => (string) $s['text'], $knoten['steps'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::warning('Handbuch-Basisrezept nicht lesbar', ['recipe_id' => $id, 'fehler' => $e->getMessage()]);
            unset($this->basis[$key]);

            return null;
        }

        return $key;
    }

    /** Küchen-Profil: Zutaten + Schritte, Preise/Lieferanten/Kaskade-Tiefe/Sensorik aus (Basisrezepte holt der Dienst selbst). */
    private function optionen(array $zusatz): array
    {
        return $this->report->optionen([
            'profil' => 'produktion', 'preise' => '0', 'lieferanten' => '0', 'kaskade' => '0', 'steps' => '1',
            'regeneration' => '0', 'behaelter' => '0', 'sensorik' => '0', 'bilder' => '0', 'naehrwerte' => '0', 'notizen' => '0',
        ] + $zusatz, 'recipe');
    }
}
