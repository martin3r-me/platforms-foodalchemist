<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Verwaltete Unterkategorien nach GP-Regelwerk v3.10.0, `regelwerk-gp-316-heimat-tabelle-teil-2` (Entscheid Dominique 13.11.2026):
 *  1. neu `06.9 Portionsware` (Butter, Margarine, Frischkäse in Portionspackung)
 *  2. neu `10.8 Portionsware` (Zucker, Salz, Pfeffer, Ketchup, Senf, Mayonnaise, Konfitüre, Honig in Portionspackung)
 *  3. `06.8` (Laktosefrei) aufgelöst — die Ware trägt die Klammer „/ (Laktosefrei)“ in der Untergruppe ihrer Warenart
 *  4. `15.9` (Barsirupe) aufgelöst — alle Aroma- und Barsirupe nach 10.7
 *  5. „Aperitifs“ gestrichen
 *  6. WG 05 neu gegliedert in vier Untergruppen: Fisch / Geräuchert und gebeizt / Konserven und Kaviar / Meeresfrüchte
 *  7. `04.7` heißt „Wurst, Schinken, Pökelware, Aufschnitt“
 *
 * Nur die verwaltete Liste (foodalchemist_commodity_group_subcategories). GP-Werte (`gps.sub_category`) werden NICHT umgehängt — das macht der GP-Lauf;
 * bis dahin zeigt das Auswahlfeld die alten GP-Werte weiter (VocabularyService mischt beide). Aufgelöst = soft gelöscht (Team-Einträge eingeschlossen),
 * neu = globaler Eintrag (team_id NULL). Die Nummern der vier WG-05-Untergruppen (05.1–05.4 in der Reihenfolge des Dossiers) vergibt diese Migration,
 * das Dossier nennt keine. Idempotent: zweiter Lauf ändert nichts.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_commodity_group_subcategories';

    /** WG => [Name => Position] — fehlende globale Einträge werden angelegt. */
    private const NEU = [
        '04' => ['04.7 Wurst, Schinken, Pökelware, Aufschnitt' => 7],
        '05' => ['05.1 Fisch' => 1, '05.2 Geräuchert und gebeizt' => 2, '05.3 Konserven und Kaviar' => 3, '05.4 Meeresfrüchte' => 4],
        '06' => ['06.9 Portionsware' => 9],
        '10' => ['10.8 Portionsware' => 8],
    ];

    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable(self::TABELLE)) {
            return;
        }
        $jetzt = now();

        // 7. 04.7 umbenennen: vorhandene Einträge „04.7 …“ bzw. „Wurstwaren“ werden zum neuen Namen (gibt es ihn im selben Team schon: alter weg)
        foreach ($this->aktive('04') as $z) {
            if ($z->name !== '04.7 Wurst, Schinken, Pökelware, Aufschnitt' && (preg_match('/^04\.7(?:\s|$)/u', $z->name) === 1 || mb_strtolower(trim($z->name)) === 'wurstwaren')) {
                $this->umbenennen($z, '04.7 Wurst, Schinken, Pökelware, Aufschnitt', $jetzt);
            }
        }

        // 3.–6. auflösen: 06.8 (auch ohne Nummer „Laktosefrei…“), 15.9 (auch „Sirupe für Bar“/„Barsirupe“), „Aperitif…“ in jeder WG, WG 05 außer den vier neuen
        foreach (DB::table(self::TABELLE)->whereNull('deleted_at')->get(['id', 'commodity_group_code', 'name']) as $z) {
            $n = mb_strtolower(trim((string) $z->name));
            $weg = match ((string) $z->commodity_group_code) {
                '06' => preg_match('/^06\.8(?:\s|$)/u', $n) === 1 || str_starts_with($n, 'laktosefrei'),
                '15' => preg_match('/^15\.9(?:\s|$)/u', $n) === 1 || in_array($n, ['sirupe für bar', 'barsirupe'], true),
                '05' => ! array_key_exists((string) $z->name, self::NEU['05']),
                default => false,
            } || str_contains($n, 'aperitif');
            if ($weg) {
                DB::table(self::TABELLE)->where('id', $z->id)->update(['deleted_at' => $jetzt, 'updated_at' => $jetzt]);
            }
        }

        // 1., 2., 6. (und 7., falls es 04.7 noch nicht gab): globale Einträge anlegen
        foreach (self::NEU as $wg => $namen) {
            foreach ($namen as $name => $pos) {
                $gibtEs = DB::table(self::TABELLE)->whereNull('team_id')->where('commodity_group_code', $wg)->where('name', $name)->first();
                if ($gibtEs === null) {
                    DB::table(self::TABELLE)->insert(['uuid' => (string) Str::uuid(), 'team_id' => null, 'commodity_group_code' => $wg, 'name' => $name,
                        'position' => $pos, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
                } elseif ($gibtEs->deleted_at !== null) {
                    DB::table(self::TABELLE)->where('id', $gibtEs->id)->update(['deleted_at' => null, 'position' => $pos, 'updated_at' => $jetzt]);
                }
            }
        }
    }

    public function down(): void
    {
        // Bewusst leer: der Stand ist das Regelwerk v3.10.0 (§3a). Zurück auf 06.8/15.9 bzw. die alte WG-05-Gliederung über die Einstellungen.
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function aktive(string $wg)
    {
        return DB::table(self::TABELLE)->whereNull('deleted_at')->where('commodity_group_code', $wg)->get(['id', 'team_id', 'commodity_group_code', 'name']);
    }

    private function umbenennen(object $z, string $neu, $jetzt): void
    {
        $gibtEs = DB::table(self::TABELLE)->whereNull('deleted_at')->where('id', '!=', $z->id)
            ->where('commodity_group_code', $z->commodity_group_code)->where('name', $neu)
            ->when($z->team_id === null, fn ($q) => $q->whereNull('team_id'), fn ($q) => $q->where('team_id', $z->team_id))
            ->exists();
        DB::table(self::TABELLE)->where('id', $z->id)->update($gibtEs ? ['deleted_at' => $jetzt, 'updated_at' => $jetzt] : ['name' => $neu, 'updated_at' => $jetzt]);
    }
};
