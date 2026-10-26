<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;

/**
 * Stufe 2 „Form vor Zustand“ (Dominique 25.10.2026): Beispiel-Namen der globalen Regeln in die neue Reihenfolge
 * („Kartoffel: frisch, ganz“ → „Kartoffel: ganz, frisch“, Fragmente „frisch, Wuerfel 5 mm“ → „Würfel 5 mm, frisch“). Die Beispiele
 * gehen in Prompts und die Regelpflege; alte Reihenfolge würde der KI das alte Schema vorführen.
 *
 * NICHT umgestellt: `params.eintraege[].ziel_name` und `beispiele.*.erwartet` — sie verweisen auf echte GP-Namen und ziehen mit dem
 * Re-Render-Datenlauf (Klaus) um, sonst zeigen sie bis dahin ins Leere. Nur Schreibweise: die aktuelle Fassung wird angeglichen, keine neue Regel-Version.
 */
return new class extends Migration
{
    public function up(): void
    {
        $naming = app(GpNamingService::class);
        $zustand = GpNamingService::zustandMuster();
        $umstellen = function (string $s) use ($naming, $zustand): string {
            $fragment = preg_match('/^[^:]+:\s/u', $s) !== 1;
            $text = $fragment ? 'X: ' . $s : $s;
            if (preg_match('/^[^:]+:\s*(?:' . $zustand . ')\s*,\s*\S/iu', $text) !== 1) {
                return $s;   // nur Namen, deren Eigenschaften mit dem Zustand beginnen (alte Reihenfolge)
            }
            $neu = $naming->renderGpName($naming->felderAusName($text, true, true));

            return $fragment ? (string) preg_replace('/^X: /u', '', $neu) : $neu;
        };
        $walk = function (mixed $v) use (&$walk, $umstellen): mixed {
            if (is_array($v)) {
                foreach ($v as $k => $x) {
                    $v[$k] = $k === 'erwartet' ? $x : $walk($x);
                }

                return $v;
            }

            return is_string($v) ? $umstellen($v) : $v;
        };

        foreach (FoodAlchemistRule::query()->whereNull('team_id')->whereNotNull('beispiele')->orderBy('id')->get() as $regel) {
            $alt = (array) $regel->beispiele;
            $neu = $walk($alt);
            if ($neu !== $alt) {
                // Nur die Schreibweise der Beispiele ändert sich, nicht die Regel: aktuelle Fassung (Regel + ihre Versionszeile) direkt angleichen, keine neue Version
                $json = json_encode($neu, JSON_UNESCAPED_UNICODE);
                DB::table('foodalchemist_rules')->where('id', $regel->id)->update(['beispiele' => $json]);
                DB::table('foodalchemist_rule_versions')->where('rule_id', $regel->id)->where('version', $regel->version)->update(['beispiele' => $json]);
            }
        }
        RegelBuch::vergessen();
    }

    public function down(): void
    {
        // Bewusst leer: reine Umsortierung der Beispiel-Namen; die alte Reihenfolge ist nach Stufe 2 nicht mehr gültig.
    }
};
