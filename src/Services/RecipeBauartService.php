<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * Spec 21 · S5b-2 (Tranche B) — der EIGENE Erzeuger für `rezept_gericht_vs_komponente`.
 *
 * Warum nicht der Copilot: `RecipeReviewService` beurteilt die Rezeptur (Mengen,
 * Einheiten, fehlende Schlüsselkomponenten) und schreibt in Zutaten-ZEILEN. Die Frage
 * „ist das ein fertiges Gericht oder eine Komponente?" hat gar kein Zeilen-Ziel — sie
 * beurteilt das Rezept als Ganzes. In denselben Prompt gepackt hätte sie den Copilot
 * verwässert (ein Pass, zwei Denkweisen) und wäre als Befund ohne Ziel durch den
 * Fingerprint gefallen. Vorlage ist der Vault-One-Shot 272 („Gericht-vs-Komponente",
 * 68 geflaggt) — hier laufend statt einmalig.
 *
 * Drei Festlegungen:
 *
 *  1. **Der Maßstab ist die Bauart, nicht der Einsatzort** (269er-Neutralisierung,
 *     Klassifikations-Regel „Wie gebaut?" nie „Wo eingesetzt?"). Eine Sauce bleibt
 *     eine Komponente, auch wenn sie als Gericht verkauft wird; ein Teller mit
 *     Sättigungsbeilage und Sauce bleibt ein Gericht, auch wenn er im Buffet steht.
 *  2. **Kein Auto-Apply, nie.** Ein Befund kippt hier `is_sales_recipe` — daran hängen
 *     Taxonomie, VK-Felder, Darreichungen und die halbe Verkaufsschicht. Der Befund
 *     ist eine Frage an den Menschen, kein Auftrag (deshalb `strukturentscheidung`
 *     als Anwendbarkeits-Grund, s. RecipeReviewService).
 *  3. **Einigkeit erzeugt keine Zeile.** Bestätigt das Modell die bestehende
 *     Einstufung, gibt der Pass eine leere Befund-Liste zurück — die schließt über
 *     `RecipeFindingService::speichere()` einen etwaigen Vorbefund und hinterlässt
 *     sonst nichts. Ein „alles in Ordnung" gehört in den Stempel, nicht in die Ablage.
 */
class RecipeBauartService
{
    /** Die zwei zulässigen Einstufungen — dasselbe Wort im Prompt wie im Vergleich. */
    public const EINSTUFUNGEN = ['gericht', 'komponente'];

    /**
     * Read-only Bauart-Pass über EIN Rezept.
     *
     * @return array{ist:string, urteil:?string, konfidenz:float, befunde:array<int, array<string, mixed>>}
     */
    public function pruefe(Team $team, int $recipeId): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $recipeId);
        if ($r === null) {
            throw new \RuntimeException('Rezept nicht gefunden oder nicht sichtbar.');
        }
        $ist = $r->is_sales_recipe ? 'gericht' : 'komponente';

        $vorschlag = app(AiGatewayService::class)->propose(
            'recipe.bauart',
            $this->kontext($r, $ist),
            ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id],
        );

        $urteil = mb_strtolower(trim((string) ($vorschlag->werte['einstufung'] ?? '')));
        $konfidenz = max(0.0, min(1.0, (float) ($vorschlag->werte['konfidenz'] ?? $vorschlag->confidence)));
        $begruendung = trim((string) ($vorschlag->werte['begruendung'] ?? $vorschlag->reasoning ?? ''));

        // Unbekanntes Wort ⇒ ehrlicher Nicht-Treffer (SpeisenKlassenService-Doktrin):
        // lieber kein Befund als ein Befund aus einer Antwort, die wir nicht verstehen.
        if (! in_array($urteil, self::EINSTUFUNGEN, true)) {
            return ['ist' => $ist, 'urteil' => null, 'konfidenz' => 0.0, 'befunde' => []];
        }

        return [
            'ist' => $ist,
            'urteil' => $urteil,
            'konfidenz' => $konfidenz,
            'befunde' => $urteil === $ist ? [] : [[
                'art' => 'bauart',
                // Das Rezept selbst ist das Ziel — kein `zutat_id`. Der Fingerprint
                // weiß das (RecipeFindingService::fingerprint) und bleibt darum über
                // Läufe hinweg stabil, obwohl die Begründung jedes Mal anders klingt.
                'zutat_text' => (string) $r->name,
                'begruendung' => $begruendung !== ''
                    ? $begruendung
                    : 'Nach Bauart eher ' . $urteil . ' als ' . $ist . '.',
                'konfidenz' => $konfidenz,
                'einstufung' => $urteil,
                // Festlegung 2, hier ausgesprochen statt vererbt: dieser Pass hat kein
                // `normalisiere()`, das die Anwendbarkeit setzt — er setzt sie selbst,
                // und zwar immer gleich. Ein Bauart-Befund ist nie ein Auftrag.
                'auto_applicable' => false,
                'status' => 'strukturentscheidung',
            ]],
        ];
    }

    /**
     * Prompt-Kontext: was die Bauart verrät — Name, Bestandteile, Zubereitung, die
     * bestehende Einstufung. Bewusst OHNE Preis, Konzept-Zugehörigkeit oder Foodbook-
     * Platzierung: das wäre „wo eingesetzt", also genau der Maßstab, den die
     * 269er-Regel verbietet.
     *
     * @return array<string, mixed>
     */
    private function kontext(FoodAlchemistRecipe $r, string $ist): array
    {
        return [
            'name' => $r->name,
            'einstufung_ist' => $ist,
            'kategorie' => $r->kategorie?->label,
            'hauptgruppe' => $r->dishMainGroup?->label,
            'zubereitung' => mb_strimwidth((string) $r->preparation, 0, 1500, '…'),
            'bestandteile' => $r->ingredients
                ->map(fn ($z) => $z->referencedRecipe?->name ?? $z->gp?->name ?? $z->display_name ?? $z->raw_text)
                ->filter()->values()->all(),
            'vokabular' => self::EINSTUFUNGEN,
        ];
    }
}
