<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Rollen-Vorschlag aus dem Typ (Dominique 11.10.): welche Teller-Rollen ein Basisrezept eines Typs im Gericht übernehmen
 * kann — Startbestand NUR aus Dominiques eigenen Beispielen/Definitionen („Jus: Rind → sauce, aroma“, „Butternut-Püree
 * → beilage, basis“, aroma = Öl-/Würz-Akzent). Alle übrigen Typen bekommen ihren Vorschlag aus der Nutzungs-Historie,
 * bis jemand sie hier pflegt. Gelesen vom Kommando foodalchemist:basisrezept-rollen-vorschlagen (Quelle vorschlag).
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'basisrezept.typ_rollen';

    public function up(): void
    {
        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->exists()) {
            return;
        }
        $e = static fn (string $typ, string $rollen) => ['begriff' => $typ, 'ziel_typ' => 'wert', 'ziel_name' => $rollen];
        app(RegelService::class)->speichere([
            'schluessel' => self::SCHLUESSEL, 'regelwerk' => 'basisrezept', 'paragraph' => 'Rollen',
            'titel' => 'Mögliche Teller-Rollen je Basisrezept-Typ', 'art' => 'zuordnung', 'ziel' => 'rezept.rollen',
            'wirkung' => 'korrigieren',
            'params' => ['vergleich' => 'text', 'eintraege' => [
                $e('Jus', 'sauce,aroma'), $e('Sauce', 'sauce'), $e('Püree', 'beilage,basis'), $e('Crunch', 'crunch'),
                $e('Öl', 'aroma'), $e('Aromaöl', 'aroma'), $e('Beilage', 'beilage'),
            ]],
            'beispiele' => ['richtig' => [['text' => 'Jus', 'erwartet' => 'sauce,aroma']]],
            'notiz' => 'Dominique 2026-10-11 (Beispiele Jus/Püree, Definition aroma). Weitere Typen hier pflegen.',
        ], null, true, 'migration');
    }

    public function down(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($r !== null) {
            \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->where('rule_id', $r->id)->delete();
            $r->forceDelete();
        }
    }
};
