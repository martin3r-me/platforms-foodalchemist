<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;

/**
 * Spec 32 · C4 — die Abweichungsanalyse. Das ist der Grund, warum C3 gebaut wurde.
 *
 * Erst mit beiden Ist-Seiten (Einkaufsjournal + Verkaufsjournal) lassen sich zwei Fragen
 * beantworten, die bis dahin nicht rechenbar waren:
 *
 *  1. **Die echte Wareneinsatzquote.** Bisher zeigte das Modul überall den KALKULIERTEN
 *     Wareneinsatz (EK der Rezeptur gegen VK) — eine Soll-Zahl. Die Ist-Quote ist
 *     `Einkauf ÷ Umsatz` über einen Zeitraum. Beide heißen „Wareneinsatz" und sind
 *     verschiedene Dinge; die Fläche muss das benennen.
 *
 *  2. **Theoretisch gegen tatsächlich.** Was HÄTTE der Wareneinsatz laut Rezeptur gekostet
 *     (Σ verkaufte Menge × `ek_portion`), und was wurde tatsächlich eingekauft? Die Differenz
 *     ist der klassische Küchen-Schwund: Verschnitt, Verderb, Überproduktion, Schwund im
 *     engeren Sinne — plus alles, was die Rechnung stört (Lageraufbau, falsche Rezeptmengen).
 *
 * **Zwei ehrliche Grenzen, die die Fläche mitliefern muss:**
 *  - **Kein Bestand.** Ohne Inventur ist die Differenz eine PERIODEN-Rechnung: wer am
 *    Monatsende das Lager füllt, sieht Schwund, der keiner ist. Deshalb heißt der Wert
 *    „Abweichung" und nicht „Schwund", und lange Zeiträume sind aussagekräftiger als kurze.
 *  - **Nur zugeordnete Verkaufszeilen** tragen zum theoretischen Wareneinsatz bei. Was
 *    keinem Gericht zugeordnet ist, hat keine Rezeptur — es fließt in den Umsatz, aber nicht
 *    in die Theorie. Der Anteil wird darum ausgewiesen; unter einer gewissen Abdeckung ist
 *    die Aussage wertlos, und das steht dann da.
 */
class WareneinsatzAbweichungService
{
    /**
     * Unter dieser Zuordnungs-Abdeckung (Umsatz mit Gericht ÷ Umsatz gesamt) ist der
     * theoretische Wareneinsatz nicht aussagekräftig.
     */
    public const MIN_ABDECKUNG_PCT = 80.0;

    public function __construct(
        private PurchaseJournalService $journal,
        private TeamSettingsService $settings,
    ) {
    }

    /**
     * @return array{von:?string,bis:?string,umsatz:float,umsatz_zugeordnet:float,abdeckung_pct:?float,
     *               einkauf:float,ist_pct:?float,ziel_pct:float,ist_delta_pp:?float,
     *               theoretisch:float,abweichung_eur:?float,abweichung_pp:?float,
     *               belastbar:bool,hinweis:?string}
     */
    public function analyse(Team $team, ?string $von = null, ?string $bis = null): array
    {
        // Spalten qualifiziert: die theoretische Rechnung joint die Darreichungen dazu, und die
        // tragen team_id/deleted_at ebenfalls — unqualifiziert wirft SQLite „ambiguous column".
        $t = 'foodalchemist_sales_facts';
        $basis = fn () => DB::table($t)
            ->where($t . '.team_id', $team->id)->whereNull($t . '.deleted_at')
            ->when($von !== null, fn ($q) => $q->whereDate($t . '.sold_at', '>=', $von))
            ->when($bis !== null, fn ($q) => $q->whereDate($t . '.sold_at', '<=', $bis));

        $umsatz = (float) $basis()->sum($t . '.revenue_net');
        $umsatzZugeordnet = (float) $basis()->whereNotNull($t . '.recipe_id')->sum($t . '.revenue_net');
        $einkauf = $this->journal->spend($team, null, $von, $bis);
        $ziel = $this->settings->zielWareneinsatzPct($team);

        $abdeckung = $umsatz > 0 ? round($umsatzZugeordnet / $umsatz * 100, 1) : null;
        $istPct = $umsatz > 0 ? round($einkauf / $umsatz * 100, 1) : null;

        // Theoretischer Wareneinsatz: verkaufte Menge × EK je Portion an der Standard-Darreichung.
        // Dieselbe EK-Zahl wie in der Matrix und in der W%-Ampel — sonst stünden hier zwei
        // Wareneinsätze nebeneinander, die sich widersprechen.
        $theoretisch = (float) $basis()
            ->whereNotNull($t . '.recipe_id')->whereNotNull($t . '.qty_sold')
            ->join('foodalchemist_recipe_presentations as p', function ($j) use ($t) {
                $j->on('p.recipe_id', '=', $t . '.recipe_id')
                    ->where('p.is_standard', true)->whereNull('p.deleted_at');
            })
            ->whereNotNull('p.ek_portion')
            ->sum(DB::raw($t . '.qty_sold * p.ek_portion'));
        $theoretisch = round($theoretisch, 2);

        $belastbar = $umsatz > 0 && $theoretisch > 0
            && $abdeckung !== null && $abdeckung >= self::MIN_ABDECKUNG_PCT;

        $hinweis = match (true) {
            $umsatz <= 0 => 'Kein Verkaufs-Ist im Zeitraum — ohne Umsatz gibt es keine Ist-Quote.',
            $einkauf <= 0 => 'Kein Einkaufsjournal im Zeitraum — die Kostenseite fehlt.',
            $abdeckung !== null && $abdeckung < self::MIN_ABDECKUNG_PCT => 'Nur '
                . number_format($abdeckung, 1, ',', '.')
                . ' % des Umsatzes hängen an einem Gericht — der theoretische Wareneinsatz ist damit '
                . 'nicht belastbar. Erst die offenen Verkaufszeilen zuordnen.',
            $theoretisch <= 0 => 'Kein theoretischer Wareneinsatz berechenbar — den verkauften Gerichten '
                . 'fehlt ein EK je Portion an der Standard-Darreichung.',
            default => null,
        };

        return [
            'von' => $von, 'bis' => $bis,
            'umsatz' => round($umsatz, 2),
            'umsatz_zugeordnet' => round($umsatzZugeordnet, 2),
            'abdeckung_pct' => $abdeckung,
            'einkauf' => round($einkauf, 2),
            'ist_pct' => $istPct,
            'ziel_pct' => $ziel,
            'ist_delta_pp' => $istPct !== null ? round($istPct - $ziel, 1) : null,
            'theoretisch' => $theoretisch,
            // Positiv = es wurde MEHR eingekauft als die Rezepturen hergeben.
            'abweichung_eur' => $belastbar ? round($einkauf - $theoretisch, 2) : null,
            'abweichung_pp' => $belastbar && $umsatz > 0
                ? round(($einkauf - $theoretisch) / $umsatz * 100, 1) : null,
            'belastbar' => $belastbar,
            'hinweis' => $hinweis,
        ];
    }
}
