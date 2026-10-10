<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Zukauf-Basisrezept (Dominique 10.10.): steht in der Gericht-Zeile kein Typ, kommt er aus der Warengruppe der Ware.
 * wert = Typ aus dem Typ-Vokabular, aliase = Präfixe von Warengruppe/Sub-Kategorie (längster gewinnt). Startet AUS —
 * Einschalten ist Kuration (Einstellungen › Regeln). Ohne aktive Regel wird ohne Typ normal geplant.
 * Kräuter/Microgreens haben noch keinen Typ im Vokabular („Garnitur“ ist dort ausdrücklich falsch) — Entscheid offen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.zukauf.typ')->exists()) {
            return;
        }
        app(RegelService::class)->speichere([
            'schluessel' => 'basisrezept.zukauf.typ', 'regelwerk' => 'basisrezept', 'paragraph' => '§1.2',
            'titel' => 'Zukauf: Typ aus der Warengruppe', 'art' => 'vokabular', 'ziel' => 'gp.warengruppe', 'wirkung' => 'warnen',
            'params' => ['werte' => [
                ['wert' => 'Öl', 'aliase' => ['11'], 'gruppe' => 'Öle & Essige'],
                ['wert' => 'Crunch', 'aliase' => ['09.6', '10.4'], 'gruppe' => 'Knabbereien & Toppings'],
            ]],
            'notiz' => 'Zukauf-Basisrezept ohne Typ in der Gericht-Zeile: Typ = Wert der passenden Warengruppe.',
        ], null, false, 'migration');
    }

    public function down(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.zukauf.typ')->first();
        if ($r !== null) {
            \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->where('rule_id', $r->id)->delete();
            $r->forceDelete();
        }
    }
};
