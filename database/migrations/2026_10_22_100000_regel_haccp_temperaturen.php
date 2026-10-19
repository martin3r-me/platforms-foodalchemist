<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Spec 85 D-7 PR 1b — HACCP-Temperaturen als Regeln (Dominique 19.10.): die Hausstandards werden die Wahrheit für die Werte,
 * das Dossier bleibt die Erklärung. Werte NUR aus den Dossiers, keine neuen Zahlen:
 * - `hygiene_haccp--hausstandards-2026-10` (Entscheidungen Dominique 05.10.): Heißhalten ≥ 65 °C, Kühlkette ≤ 7 °C, Kühlen < 4 °C,
 *   Abkühlen < 7 °C in 2 h, Standzeit 4–65 °C max. 2 h, Garen Hack/Farce/Wurstbrät/Innereien/Rollbraten > 75 °C 2 min.
 * - `cookchill_regenerieren_transport_kennwerte` (Quellen DIN 10508:2022, DIN 10506): Schnellkühlen +70 → < 3 °C in ≤ 90 min,
 *   Regenerieren Kern ≥ 72 °C ≥ 2 min.
 *
 * Aktiv ab Saat, obwohl neue Regeln sonst inaktiv starten (Spec 81 Teil G): Dominique hat die Werte entschieden (19.10.).
 * Untergrenze für Team-Abweichungen nur, wo Norm UND Wert wörtlich belegt sind (Kurator 19.10.): Garen zerkleinert ≥ 72 °C/2 min
 * (DIN 10506). Alle anderen ohne Untergrenze = Team nur strenger. Heißhalten: „DIN ≥ 60 °C“ ohne Normnummer im Dossier — offener
 * Punkt „Untergrenze Heißhalten: Norm + Wert belegen“ (Spec 85); der Master trägt sie später als Datenwert nach.
 * Wirkung „warnen“: Temperaturen blockieren nie (Spec 81, Offen: KI-Pfad blockiert nie).
 */
return new class extends Migration
{
    private const HAUS = 'hygiene_haccp--hausstandards-2026-10';

    private const COOKCHILL = 'cookchill_regenerieren_transport_kennwerte';

    public function up(): void
    {
        $svc = app(RegelService::class);
        foreach ($this->regeln() as $r) {
            $svc->speichere($r + ['regelwerk' => 'haccp', 'art' => 'schwelle', 'ziel' => 'haccp.temperatur', 'wirkung' => 'warnen'], null, true, 'seed');
        }
        RegelBuch::vergessen();
    }

    public function down(): void
    {
        $ids = FoodAlchemistRule::withTrashed()->whereNull('team_id')
            ->whereIn('schluessel', array_column($this->regeln(), 'schluessel'))->pluck('id');
        \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->whereIn('rule_id', $ids)->delete();
        FoodAlchemistRule::withTrashed()->whereIn('id', $ids)->forceDelete();
        RegelBuch::vergessen();
    }

    /** @return list<array<string, mixed>> */
    private function regeln(): array
    {
        return [
            ['schluessel' => 'haccp.heisshalten', 'titel' => 'Heißhalten', 'dossier_slug' => self::HAUS,
                'params' => ['vergleich' => '>=', 'wert' => 65, 'einheit' => '°C', 'geltung' => 'warme Speisen beim Warmhalten und in der Ausgabe'],
                'notiz' => 'Hausstandard einheitlich ≥ 65 °C. Untergrenze für Team-Abweichung offen: „DIN ≥ 60 °C“ steht ohne Normnummer — bis zum Beleg nur strenger.'],
            ['schluessel' => 'haccp.kuehlen', 'titel' => 'Kühlen', 'dossier_slug' => self::HAUS,
                'params' => ['vergleich' => '<', 'wert' => 4, 'einheit' => '°C', 'geltung' => 'Kühllagerung (Kühlziel)']],
            ['schluessel' => 'haccp.schnellkuehlen', 'titel' => 'Schnellkühlen nach dem Garen', 'dossier_slug' => self::COOKCHILL,
                'params' => ['vergleich' => '<', 'wert' => 3, 'einheit' => '°C', 'dauer' => 90, 'dauer_art' => 'hoechstens', 'dauer_einheit' => 'min',
                    'geltung' => 'Cook & Chill: von +70 °C im Schockkühler']],
            ['schluessel' => 'haccp.kuehlkette', 'titel' => 'Kühlkette', 'dossier_slug' => self::HAUS,
                'params' => ['vergleich' => '<=', 'wert' => 7, 'einheit' => '°C', 'geltung' => 'Kühlware von Wareneingang bis Ausgabe']],
            ['schluessel' => 'haccp.abkuehlen', 'titel' => 'Abkühlen', 'dossier_slug' => self::HAUS,
                'params' => ['vergleich' => '<', 'wert' => 7, 'einheit' => '°C', 'dauer' => 120, 'dauer_art' => 'hoechstens', 'dauer_einheit' => 'min',
                    'geltung' => 'gegarte Speisen nach dem Garen']],
            ['schluessel' => 'haccp.standzeit_gefahrenzone', 'titel' => 'Standzeit 4–65 °C', 'dossier_slug' => self::HAUS,
                'params' => ['vergleich' => '<=', 'wert' => 120, 'einheit' => 'min', 'geltung' => 'Lebensmittel zwischen 4 °C und 65 °C']],
            ['schluessel' => 'haccp.garen_zerkleinert', 'titel' => 'Garen zerkleinert/zusammengesetzt', 'dossier_slug' => self::HAUS,
                'params' => ['vergleich' => '>', 'wert' => 75, 'einheit' => '°C', 'dauer' => 2, 'dauer_art' => 'mindestens', 'dauer_einheit' => 'min',
                    'geltung' => 'Hack, Farce, Wurstbrät, Innereien, Rollbraten / zusammengesetzte Stücke (Kerntemperatur)',
                    'untergrenze' => 72, 'untergrenze_dauer' => 2,
                    'untergrenze_quelle' => 'DIN 10506 — Dossier cookchill_regenerieren_transport_kennwerte §1: „Kerntemperatur ≥ 72 °C für ≥ 2 min (DIN 10506)“'],
                'notiz' => 'Ausnahmen per Zeit-Temperatur-Äquivalent (FDA-Pasteurisationstabellen) und Schwein ganzes Muskelstück a point 54–56 °C stehen im Dossier hygiene_haccp--hausstandards-2026-10; ganzer Muskel fällt nicht unter diese Regel.'],
            ['schluessel' => 'haccp.regenerieren', 'titel' => 'Regenerieren', 'dossier_slug' => self::COOKCHILL,
                'params' => ['vergleich' => '>=', 'wert' => 72, 'einheit' => '°C', 'dauer' => 2, 'dauer_art' => 'mindestens', 'dauer_einheit' => 'min',
                    'geltung' => 'Regenerieren (Kern, kälteste Stelle; danach nicht wieder abkühlen)'],
                'notiz' => 'DIN 10506; Hausstandard laut Entscheid Dominique 19.10. Untergrenze = Hausstandard (nur strenger).'],
        ];
    }
};
