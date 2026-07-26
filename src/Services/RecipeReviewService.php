<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Matching\MatchHeuristics;

/**
 * Spec 03 L6 — Rezept-Copilot: der proaktive Prüf-Pass („was ist an diesem
 * Rezept schief?") als Gegenstück zum Freitext-Revise (L1), der immer alles
 * neu schreibt. Hier bleibt das Rezept stehen und die KI liefert BEFUNDE,
 * die der Mensch einzeln annimmt oder liegen lässt.
 *
 * Zwei Hälften, bewusst getrennt:
 *  - pruefe()      : read-only. Ruft den Prompt, normalisiert die Befunde und
 *                    entscheidet je Befund, ob er überhaupt anwendbar IST.
 *  - uebernehmen() : der EINE Schreib-Moment, genau ein Befund pro Aufruf.
 *
 * Die zwei tragenden Regeln (aus der CJ-Referenz übernommen):
 *  1. **Kein Raten.** Ein `fehlt`-Befund wird sofort gegen den GP-/Sub-Pool
 *     gematcht; ohne Treffer ist er NICHT anwendbar, sondern trägt den
 *     Hard-Stop-Hinweis „erst GP anlegen" (dieselbe Doktrin wie #508-Revise).
 *  2. **Kein Kollateralschaden.** Ein Befund ändert genau seine Zeile. Weil
 *     `syncIngredients` Voll-Ersatz-Semantik hat, wird der komplette Bestand
 *     über `RecipeReviseService::bestandsZeile()` mitgeschickt (V-027-Wurzel);
 *     der Recompute hängt danach ohnehin am Sync.
 */
class RecipeReviewService
{
    /** Befund-Arten DIESES Passes (CJ-Parität). `hinweis` ist bewusst nie anwendbar — er hat kein Schreibziel. */
    public const ARTEN_COPILOT = ['menge', 'einheit', 'entfernen', 'fehlt', 'hinweis'];

    /**
     * Spec 21 · S5b-2 — Befund-Arten über das Rezept ALS GANZES, erzeugt von einem
     * anderen Pass ({@see RecipeBauartService}). Sie teilen sich die Ablage, aber
     * nicht den Erzeuger, nicht den Prüf-Stempel und nicht das Signal. Getrennt
     * geführt, weil sonst jeder Konsument der Ablage (Signal-Register, MCP, Fläche)
     * zwei verschiedene Sachverhalte in eine Zahl legte.
     */
    public const ARTEN_STRUKTUR = ['bauart'];

    /** Alle bekannten Arten — was hier nicht steht, wird zum `hinweis` entschärft. */
    public const ARTEN = ['menge', 'einheit', 'entfernen', 'fehlt', 'hinweis', 'bauart'];

    /**
     * Read-only Prüf-Pass. Persistiert NICHTS ausser dem Gateway-Audit (GL-07 I3).
     *
     * @return array{gesamturteil:string, confidence:float, befunde:array<int, array<string,mixed>>}
     */
    public function pruefe(Team $team, int $recipeId): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $recipeId);
        if ($r === null) {
            throw new \RuntimeException('Rezept nicht gefunden oder nicht sichtbar.');
        }
        $vk = (bool) $r->is_sales_recipe;

        $vorschlag = app(AiGatewayService::class)->propose(
            $vk ? 'vk.review' : 'recipe.review',
            $this->kontext($r, $vk),
            ['target_table' => 'foodalchemist_recipes', 'target_id' => $r->id],
        );

        $roh = $vorschlag->werte['befunde'] ?? [];

        return [
            'gesamturteil' => trim((string) ($vorschlag->werte['gesamturteil'] ?? '')),
            'confidence' => max(0.0, min(1.0, $vorschlag->confidence)),
            'befunde' => is_array($roh) ? $this->normalisiere($team, $r, $roh) : [],
        ];
    }

    /**
     * Bestehende Befunde gegen den FRISCHEN Bestand neu bewerten — ohne KI-Call.
     *
     * Gebraucht ab L6b: nach jeder Einzel-Übernahme kann ein anderer Befund tot
     * sein (seine Zielzeile wurde gerade entfernt) oder erledigt (`fehlt`, das
     * jetzt drinsteht). Die Anwendbarkeits-Entscheidung bleibt damit an EINER
     * Stelle — das UI schreibt sie nicht selbst fort.
     *
     * Zulässige Eingabe ist die Ausgabe von `pruefe()`: `normalisiere()` liest
     * dieselben Schlüssel, die es schreibt (idempotent).
     *
     * @param  array<int, array<string, mixed>>  $befunde
     * @return array<int, array<string, mixed>>
     */
    public function bewerte(Team $team, int $recipeId, array $befunde): array
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $recipeId);
        if ($r === null) {
            throw new \RuntimeException('Rezept nicht gefunden oder nicht sichtbar.');
        }

        return $this->normalisiere($team, $r, $befunde);
    }

    /**
     * Prompt-Kontext: Rezept + Zutaten + Zubereitung, sonst nichts. Die
     * CJ-Referenz injiziert hier bewusst KEIN Pairing-/Vault-Wissen — ein
     * Prüf-Pass soll das Rezept beurteilen, nicht es umdichten.
     *
     * @return array<string, mixed>
     */
    private function kontext(FoodAlchemistRecipe $r, bool $vk): array
    {
        $kontext = [
            'name' => $r->name,
            'description' => $r->description,
            'preparation' => $r->preparation,
            'kategorie' => $r->kategorie?->label,
            'zutaten' => $r->ingredients->map(fn ($z) => [
                'id' => $z->id,
                'text' => $z->gp?->name ?? $z->referencedRecipe?->name ?? $z->display_name ?? $z->raw_text,
                'quantity' => (float) $z->quantity,
                'einheit_slug' => $z->unit?->slug,
                'geerdet' => $z->gp_id !== null || $z->referenced_recipe_id !== null,
            ])->values()->all(),
        ];
        if ($vk) {
            // Verkaufs-Facetten sind Beurteilungs-MASSSTAB (passt die Portion zur Klasse?),
            // kein Schreibziel — der Copilot schreibt ohnehin nur Zutaten-Zeilen.
            $kontext['speisen_klasse'] = $r->dishClass?->label;
            $kontext['diaetform'] = $r->dishClass?->diet_form;
            $kontext['portion_g'] = $r->sales_quantity_per_unit_g;
            $kontext['verkaufseinheiten'] = $r->sales_unit_count;
        } else {
            $kontext['ansatz_kg'] = $r->yield_kg_manual ?? $r->yield_kg;
            $kontext['ansatz_stueck'] = $r->yield_pieces;
        }

        return $kontext;
    }

    /**
     * Roh-Befunde → geprüfte Befunde. Jeder Befund bekommt `auto_applicable`
     * + `status` (WARUM nicht anwendbar) — das UI erfindet diese Entscheidung
     * später nicht neu, und der MCP-Weg sieht dieselbe Wahrheit.
     *
     * @param  array<int, mixed>  $roh
     * @return array<int, array<string, mixed>>
     */
    private function normalisiere(Team $team, FoodAlchemistRecipe $r, array $roh): array
    {
        $zeilen = $r->ingredients->keyBy('id');
        $einheiten = FoodAlchemistRecipe::query()->getConnection()->table('foodalchemist_vocab_units')
            ->whereNull('deleted_at')->pluck('id', 'slug');
        $matcher = app(IngredientMatchService::class);
        $heuristik = app(MatchHeuristics::class);

        $out = [];
        foreach ($roh as $b) {
            if (! is_array($b)) {
                continue;
            }
            $art = strtolower(trim((string) ($b['art'] ?? '')));
            $text = trim((string) ($b['zutat_text'] ?? $b['text'] ?? ''));
            $begruendung = trim((string) ($b['begruendung'] ?? $b['grund'] ?? ''));
            // Unbekannte Art nicht wegwerfen, sondern entschärfen: der Inhalt kann
            // stimmen, nur das Schreibziel ist unklar → als Hinweis anzeigen.
            if (! in_array($art, self::ARTEN, true)) {
                $art = 'hinweis';
            }
            if ($begruendung === '' && $text === '') {
                continue;                                             // leerer Befund ist Rauschen
            }

            $mengeRoh = str_replace(',', '.', (string) ($b['quantity'] ?? $b['menge'] ?? ''));
            $menge = is_numeric($mengeRoh) ? (float) $mengeRoh : null;
            $einheitSlug = trim((string) ($b['einheit_slug'] ?? '')) ?: null;

            $befund = [
                // S5b: stammt der Befund aus der Ablage, reist seine Zeilen-id mit —
                // sonst könnte die Fläche eine Übernahme dort nicht vermerken und das
                // Signal zählte den erledigten Befund bis zum nächsten Batch weiter.
                // Aus `pruefe()` ist der Schlüssel schlicht nicht gesetzt.
                'finding_id' => ($b['finding_id'] ?? null) !== null ? (int) $b['finding_id'] : null,
                'art' => $art,
                'zutat_id' => null,
                'zutat_text' => $text,
                'quantity' => $menge,
                'einheit_slug' => $einheitSlug,
                'begruendung' => $begruendung,
                'konfidenz' => $this->konfidenz($b['konfidenz'] ?? $b['confidence'] ?? null),
                'ziel' => null,
                'kind' => null,
                'primaer' => null,
                'status' => 'anwendbar',
                'auto_applicable' => false,
            ];

            // Zielzeile: id gewinnt, sonst Namens-Rückfall (echte Modelle nennen die
            // Zutat oft, ohne die id mitzuschleppen). Keine Fuzzy-Suche — exakt oder nichts.
            $ziel = null;
            if (($b['zutat_id'] ?? null) !== null && $zeilen->has((int) $b['zutat_id'])) {
                $ziel = $zeilen->get((int) $b['zutat_id']);
            } elseif ($text !== '') {
                $ziel = $zeilen->first(fn ($z) => in_array(mb_strtolower($text), array_filter([
                    mb_strtolower((string) $z->raw_text),
                    mb_strtolower((string) $z->display_name),
                    $z->gp !== null ? mb_strtolower((string) $z->gp->name) : null,
                    $z->referencedRecipe !== null ? mb_strtolower((string) $z->referencedRecipe->name) : null,
                ]), true));
            }
            $befund['zutat_id'] = $ziel?->id;

            switch ($art) {
                case 'menge':
                    if ($ziel === null) {
                        $befund['status'] = 'kein_ziel';
                    } elseif ($menge === null || $menge <= 0) {
                        $befund['status'] = 'ohne_wert';
                    } else {
                        $befund['auto_applicable'] = true;
                    }
                    break;

                case 'einheit':
                    if ($ziel === null) {
                        $befund['status'] = 'kein_ziel';
                    } elseif ($einheitSlug === null || ! $einheiten->has($einheitSlug)) {
                        $befund['status'] = 'ohne_wert';              // Einheit ausserhalb des Vokabulars
                    } else {
                        $befund['auto_applicable'] = true;
                    }
                    break;

                case 'entfernen':
                    if ($ziel === null) {
                        $befund['status'] = 'kein_ziel';
                    } elseif ($r->ingredients->count() <= 1) {
                        $befund['status'] = 'letzte_zutat';           // ein Rezept ohne Zutaten ist kein Rezept
                    } else {
                        $befund['auto_applicable'] = true;
                    }
                    break;

                case 'fehlt':
                    if ($text === '') {
                        $befund['status'] = 'ohne_wert';
                        break;
                    }
                    if ($ziel !== null) {
                        $befund['status'] = 'schon_drin';             // Modell hat die Zeile übersehen
                        break;
                    }
                    // Hard-Stop-Doktrin: nur ein echter Treffer macht den Befund anwendbar.
                    $t = $matcher->matchIngredient($team, $text);
                    if ($t['target'] === 'gp') {
                        $befund['kind'] = 'gp';
                        $befund['ziel'] = $t['gp_name'];
                        $befund['auto_applicable'] = true;
                    } elseif ($t['target'] === 'sub_recipe') {
                        $befund['kind'] = 'sub';
                        $befund['ziel'] = $t['recipe_name'];
                        $befund['auto_applicable'] = true;
                    } else {
                        $befund['status'] = 'kein_treffer';
                        $befund['primaer'] = $heuristik->istSubRezeptKandidat($text) ? 'basisrezept_anlegen' : 'gp_anlegen';
                    }
                    break;

                case 'bauart':
                    // S5b-2: der Befund fragt, ob `is_sales_recipe` stimmt. Daran hängen
                    // Taxonomie, VK-Felder und Darreichungen — das ist keine Zeile, die
                    // ein Knopf umlegt. Er bleibt darum grundsätzlich nicht anwendbar und
                    // wird nur angezeigt/entschieden. Die Zielzeilen-Auflösung oben trifft
                    // hier gelegentlich zufällig eine Zutat (der `zutat_text` ist der
                    // Rezeptname) — für diese Art ist sie bedeutungslos, also weg damit.
                    $befund['zutat_id'] = null;
                    $befund['status'] = 'strukturentscheidung';
                    break;

                default:                                              // hinweis
                    $befund['status'] = 'nur_hinweis';
                    break;
            }

            $out[] = $befund;
        }

        return $out;
    }

    /** Konfidenz robust lesen: Zahl 0..1, Prozentzahl oder hoch/mittel/niedrig. */
    private function konfidenz(mixed $wert): float
    {
        if (is_numeric($wert)) {
            $z = (float) $wert;

            return max(0.0, min(1.0, $z > 1 ? $z / 100 : $z));
        }

        return match (mb_strtolower(trim((string) $wert))) {
            'hoch', 'high' => 0.9,
            'niedrig', 'low' => 0.3,
            default => 0.6,
        };
    }

    /**
     * Der EINE Schreib-Moment: genau ein Befund. Nur `auto_applicable` wird
     * geschrieben — alles andere ist ein Anzeige-Befund, kein Auftrag.
     *
     * Der Weg läuft absichtlich über `syncIngredients`: dort hängen Grounding
     * (#508 / LA-First-Mint) und `recomputeAndPropagate` dran. Deshalb geht der
     * VOLLE Bestand mit — nur die Zielzeile ist mutiert, gelöscht oder ergänzt.
     */
    public function uebernehmen(Team $team, int $recipeId, array $befund): FoodAlchemistRecipe
    {
        $r = app(RecipeService::class)->detailAnySicht($team, $recipeId);
        if ($r === null) {
            throw new \RuntimeException('Rezept nicht gefunden oder nicht sichtbar.');
        }
        if (($befund['auto_applicable'] ?? false) !== true) {
            throw new \RuntimeException('Befund ist nicht anwendbar — er ist ein Hinweis, kein Auftrag.');
        }

        $revise = app(RecipeReviseService::class);
        $einheiten = FoodAlchemistRecipe::query()->getConnection()->table('foodalchemist_vocab_units')
            ->whereNull('deleted_at')->pluck('id', 'slug');

        $zielId = ($befund['zutat_id'] ?? null) !== null ? (int) $befund['zutat_id'] : null;
        $art = (string) ($befund['art'] ?? '');

        $zeilen = [];
        foreach ($r->ingredients as $z) {
            if ($art === 'entfernen' && $z->id === $zielId) {
                continue;                                             // weglassen = löschen (Voll-Ersatz-Semantik)
            }
            $zeile = $revise->bestandsZeile($z);
            if ($z->id === $zielId) {
                if ($art === 'menge' && ($befund['quantity'] ?? null) !== null) {
                    $zeile['quantity'] = (float) $befund['quantity'];
                }
                if ($art === 'einheit' && isset($einheiten[$befund['einheit_slug'] ?? ''])) {
                    $zeile['unit_vocab_id'] = $einheiten[$befund['einheit_slug']];
                }
            }
            $zeilen[] = $zeile;
        }

        if ($art === 'fehlt') {
            // Ohne Verknüpfung in den Sync: der GL-04-Resolver erdet die Zeile dort
            // (derselbe Pfad wie Revise/Generator) statt hier ein zweites Mal.
            $zeilen[] = [
                'id' => null,
                'gp_id' => null,
                'referenced_recipe_id' => null,
                'raw_text' => (string) $befund['zutat_text'],
                'display_name' => (string) $befund['zutat_text'],
                'quantity' => ($befund['quantity'] ?? null) !== null && (float) $befund['quantity'] > 0
                    ? (float) $befund['quantity'] : 1.0,
                'unit_vocab_id' => $einheiten[$befund['einheit_slug'] ?? ''] ?? $einheiten['g'] ?? null,
                'is_optional' => false,
                'is_value_relevant' => false,
            ];
        }

        if ($zeilen === []) {
            throw new \RuntimeException('Übernahme würde das Rezept ohne Zutaten zurücklassen.');
        }

        // Sync = Schreiben + Grounding + recomputeAndPropagate in einem.
        return app(RecipeService::class)->syncIngredients($team, $recipeId, $zeilen);
    }
}
