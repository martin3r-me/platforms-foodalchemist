<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;

/**
 * Spec 33 · P6 — Promotion-Überwachung: was bringt eine laufende Ausgabe?
 *
 * Setzt auf dem Verkaufsjournal aus Spec 32 · C3 auf und schlägt die Brücke, die es bisher nicht
 * gab: von der Ausgabe über ihre Positionen zu den Gerichten und von dort zum Umsatz im
 * Gültigkeitsfenster.
 *
 * ```
 * Ausgabe → Positionen → sales_recipe_id → sales_facts im Fenster
 * ```
 *
 * **Zwei Ehrlichkeiten sind fest eingebaut, nicht optional:**
 *
 * 1. **Mehrfachzuordnung.** Steht ein Gericht in zwei laufenden Ausgaben, zählt sein Umsatz bei
 *    beiden. Die Summe über alle Ausgaben ist dann GRÖSSER als der Gesamtumsatz — das ist kein
 *    Rechenfehler, sondern die Natur der Frage. Jede Zeile weist deshalb aus, welcher Anteil
 *    exklusiv ist. Ohne diesen Ausweis würde man Ausgaben addieren, die sich überlappen.
 * 2. **Zuordnungs-Abdeckung.** Verkaufszeilen ohne Gericht (`recipe_id = null`) können keiner
 *    Ausgabe zugerechnet werden. Ihr Anteil steht daneben — dieselbe Zurückhaltung wie bei der
 *    Wareneinsatz-Abweichung in Spec 32 · C4.
 *
 * **Kein eigener Umsatzbegriff:** gelesen wird `foodalchemist_sales_facts`, dieselbe Quelle wie
 * Menu-Engineering und Abweichungsanalyse. Zwei Umsatzzahlen im selben Modul wären ein
 * Widerspruch, den niemand auflösen kann.
 */
class PromotionService
{
    public function __construct(private PortfolioService $portfolio)
    {
    }

    /**
     * Umsatz je laufender Ausgabe im jeweiligen Gültigkeitsfenster.
     *
     * @return array{stichtag:string,umsatz_gesamt:float,umsatz_zugeordnet:float,abdeckung_pct:?float,
     *               zeilen:list<array<string,mixed>>,hinweis:?string}
     */
    public function uebersicht(Team $team, mixed $stichtag = null): array
    {
        $tag = Carbon::parse($stichtag ?? now())->startOfDay();
        $laufend = $this->portfolio->laufendeAm($team, $tag);

        // Erst alle Gericht-Mengen sammeln — daraus ergibt sich, welches Gericht in MEHR als
        // einer laufenden Ausgabe steckt. Ohne diesen Vorlauf gäbe es keinen exklusiven Anteil.
        $gerichteJe = [];
        $vorkommen = [];
        foreach ($laufend as $z) {
            $ids = $this->gerichteFuerAusgabe($z['art'], $z['id']);
            $gerichteJe[$z['art'] . ':' . $z['id']] = $ids;
            foreach ($ids as $rid) {
                $vorkommen[$rid] = ($vorkommen[$rid] ?? 0) + 1;
            }
        }

        $zeilen = [];
        foreach ($laufend as $z) {
            $ids = $gerichteJe[$z['art'] . ':' . $z['id']];
            $exklusiv = array_values(array_filter($ids, fn ($rid) => ($vorkommen[$rid] ?? 0) === 1));

            $alle = $this->umsatz($team, $ids, $z['von'], $z['bis'], $tag);
            $nurExklusiv = $this->umsatz($team, $exklusiv, $z['von'], $z['bis'], $tag);

            $zeilen[] = $z + [
                'n_gerichte' => count($ids),
                'n_gerichte_exklusiv' => count($exklusiv),
                'umsatz' => $alle['umsatz'],
                'menge' => $alle['menge'],
                'umsatz_exklusiv' => $nurExklusiv['umsatz'],
                // Wie belastbar ist die Zahl? 100 % = kein Gericht wird mit einer anderen
                // laufenden Ausgabe geteilt.
                'exklusiv_pct' => $alle['umsatz'] > 0
                    ? round($nurExklusiv['umsatz'] / $alle['umsatz'] * 100, 1) : null,
            ];
        }

        usort($zeilen, fn ($a, $b) => $b['umsatz'] <=> $a['umsatz']);

        $gesamt = $this->journalSumme($team, $tag, false);
        $zugeordnet = $this->journalSumme($team, $tag, true);
        $abdeckung = $gesamt > 0 ? round($zugeordnet / $gesamt * 100, 1) : null;

        return [
            'stichtag' => $tag->toDateString(),
            'umsatz_gesamt' => $gesamt,
            'umsatz_zugeordnet' => $zugeordnet,
            'abdeckung_pct' => $abdeckung,
            'zeilen' => $zeilen,
            'hinweis' => match (true) {
                $gesamt <= 0 => 'Kein Verkaufs-Ist im betrachteten Zeitraum — ohne eingelesene '
                    . 'Verkaufszahlen lässt sich keiner Ausgabe ein Umsatz zurechnen.',
                $laufend === [] => 'Es läuft am Stichtag keine Ausgabe — es gibt nichts zuzurechnen.',
                $abdeckung !== null && $abdeckung < 80.0 => 'Nur '
                    . number_format($abdeckung, 1, ',', '.') . ' % des Umsatzes hängen an einem Gericht. '
                    . 'Was keinem Gericht zugeordnet ist, kann auch keiner Ausgabe zugerechnet werden — '
                    . 'erst die offenen Verkaufszeilen zuordnen.',
                default => null,
            },
        ];
    }

    /**
     * In welchen Ausgaben steckt dieses Gericht? Die Rückrichtung, die es bisher nicht gab —
     * am Rezept selbst hängt keine Relation zu den Ausgabeformen.
     *
     * @return list<array<string,mixed>>
     */
    public function ausgabenFuerGericht(Team $team, int $recipeId, mixed $stichtag = null, bool $nurLaufende = true): array
    {
        $quelle = $nurLaufende
            ? $this->portfolio->laufendeAm($team, $stichtag)
            : $this->portfolio->uebersicht($team, $stichtag);

        return array_values(array_filter(
            $quelle,
            fn ($z) => in_array($recipeId, $this->gerichteFuerAusgabe($z['art'], $z['id']), true),
        ));
    }

    /**
     * Die Verkaufsgerichte einer Ausgabe — direkt über `sales_recipe_id` und indirekt über
     * verlinkte Konzepte bzw. Pakete.
     *
     * @return list<int>
     */
    public function gerichteFuerAusgabe(string $art, int $id): array
    {
        [$direkt, $konzepte, $pakete] = match ($art) {
            'foodbook' => $this->ausFoodbook($id),
            'speisekarte' => $this->ausSpeisekarte($id),
            'speiseplan' => $this->ausSpeiseplan($id),
            default => [[], [], []],
        };

        // Ein Konzept-Block trägt kein eigenes Gericht, sondern eine Menge davon — ohne die
        // Auflösung fehlte einer Menü-Karte ihr halber Umsatz.
        if ($konzepte !== []) {
            $direkt = array_merge($direkt, DB::table('foodalchemist_concept_slots')
                ->whereIn('concept_id', $konzepte)->whereNotNull('sales_recipe_id')
                ->whereNull('deleted_at')->pluck('sales_recipe_id')->all());
        }
        if ($pakete !== []) {
            $direkt = array_merge($direkt, DB::table('foodalchemist_package_dishes')
                ->whereIn('package_id', $pakete)->whereNotNull('sales_recipe_id')
                ->whereNull('deleted_at')->pluck('sales_recipe_id')->all());
        }

        return array_values(array_unique(array_map('intval', array_filter($direkt))));
    }

    // ── intern ────────────────────────────────────────────────────────────────

    /** @return array{0:list<int>,1:list<int>,2:list<int>} direkt · concept_ids · package_ids */
    private function ausFoodbook(int $id): array
    {
        $rows = DB::table('foodalchemist_foodbook_blocks as b')
            ->join('foodalchemist_foodbook_chapters as c', 'c.id', '=', 'b.chapter_id')
            ->where('c.foodbook_id', $id)->whereNull('b.deleted_at')->whereNull('c.deleted_at')
            ->get(['b.sales_recipe_id', 'b.concept_id']);

        return [$rows->pluck('sales_recipe_id')->filter()->all(),
            $rows->pluck('concept_id')->filter()->unique()->all(), []];
    }

    /** @return array{0:list<int>,1:list<int>,2:list<int>} */
    private function ausSpeisekarte(int $id): array
    {
        $rows = DB::table('foodalchemist_menu_card_items as i')
            ->join('foodalchemist_menu_card_sections as s', 's.id', '=', 'i.section_id')
            ->where('s.menu_card_id', $id)->whereNull('i.deleted_at')->whereNull('s.deleted_at')
            ->get(['i.sales_recipe_id', 'i.concept_id']);

        return [$rows->pluck('sales_recipe_id')->filter()->all(),
            $rows->pluck('concept_id')->filter()->unique()->all(), []];
    }

    /** @return array{0:list<int>,1:list<int>,2:list<int>} */
    private function ausSpeiseplan(int $id): array
    {
        $rows = DB::table('foodalchemist_menu_plan_entries')
            ->where('menu_plan_id', $id)->whereNull('deleted_at')
            ->get(['sales_recipe_id', 'concept_id', 'package_id']);

        return [$rows->pluck('sales_recipe_id')->filter()->all(),
            $rows->pluck('concept_id')->filter()->unique()->all(),
            $rows->pluck('package_id')->filter()->unique()->all()];
    }

    /**
     * Umsatz und Menge für eine Gericht-Menge im Fenster der Ausgabe.
     *
     * Ein offenes Fenster wird am Stichtag gekappt: eine unbefristet laufende Karte würde sonst
     * jeden jemals erfassten Umsatz einsammeln, auch den von vor ihrer Aktivierung.
     *
     * @param  list<int>  $recipeIds
     * @return array{umsatz:float,menge:float}
     */
    private function umsatz(Team $team, array $recipeIds, ?string $von, ?string $bis, Carbon $tag): array
    {
        if ($recipeIds === []) {
            return ['umsatz' => 0.0, 'menge' => 0.0];
        }

        $t = 'foodalchemist_sales_facts';
        $q = DB::table($t)
            ->where($t . '.team_id', $team->id)->whereNull($t . '.deleted_at')
            ->whereIn($t . '.recipe_id', $recipeIds)
            ->whereDate($t . '.sold_at', '<=', $bis !== null ? min($bis, $tag->toDateString()) : $tag->toDateString())
            ->when($von !== null, fn ($qq) => $qq->whereDate($t . '.sold_at', '>=', $von));

        return [
            'umsatz' => round((float) (clone $q)->sum($t . '.revenue_net'), 2),
            'menge' => round((float) (clone $q)->sum($t . '.qty_sold'), 3),
        ];
    }

    /** Gesamtumsatz des Teams bis zum Stichtag — Bezugsgröße für die Abdeckung. */
    private function journalSumme(Team $team, Carbon $tag, bool $nurZugeordnet): float
    {
        $t = 'foodalchemist_sales_facts';

        return round((float) DB::table($t)
            ->where($t . '.team_id', $team->id)->whereNull($t . '.deleted_at')
            ->whereDate($t . '.sold_at', '<=', $tag->toDateString())
            ->when($nurZugeordnet, fn ($q) => $q->whereNotNull($t . '.recipe_id'))
            ->sum($t . '.revenue_net'), 2);
    }
}
