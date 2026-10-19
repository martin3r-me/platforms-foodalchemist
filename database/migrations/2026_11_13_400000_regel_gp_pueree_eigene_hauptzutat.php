<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * GP-Regelwerk v3.6.0 §8.17 (Dominique/Klaus 19.10.2026): zugekaufte Pürees, Mus und Mark sind ein eigenes Produkt — die Hauptzutat IST das
 * Püree („Karottenpüree: …“), nie Roh-Zutat + Form „Püree“ („Karotte: frisch, Püree“; Fall io „Karottenpüree 2 kg“).
 *
 * Regel-DATEN (Spec 81): Verbot mit Muster NUR hinter dem Doppelpunkt — „Püree: Kürbis“ (Hauptzutat Püree), „Karottenpüree: …“,
 * „Tomatenmark: …“, „Apfelmus: …“ bleiben frei; Knochen-Derivate („Rinderknochen: frisch, Mark“, §11.2) sind ausgenommen.
 * Basisrezepte („Püree: Petersilienwurzel“, RezeptTypVokabular) prüft die Regel nicht: Ziel ist gp.name.
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'gp.8.17.pueree';

    public function up(): void
    {
        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->exists()) {
            return;
        }
        app(RegelService::class)->speichere([
            'schluessel' => self::SCHLUESSEL, 'regelwerk' => 'gp', 'paragraph' => '§8.17', 'titel' => 'Gekauftes Püree/Mus/Mark ist eigene Hauptzutat',
            'art' => 'verbot', 'ziel' => 'gp.name', 'wirkung' => 'blockieren',
            'params' => [
                'muster' => ['/^(?![^:]*knochen)[^:]*:.*(?<![\p{L}])(p(?:ü|ue)ree|mus|mark)(?![\p{L}])/iu'],
                'grund' => 'gekauftes Püree = eigene Hauptzutat (§8.17): „Karottenpüree: …“ statt „Karotte: …, Püree“.',
            ],
            'beispiele' => [
                'richtig' => [['text' => 'Karottenpüree: konserviert'], ['text' => 'Tomatenmark: konserviert, 2-fach konzentriert']],
                'falsch' => [['text' => 'Karotte: konserviert, Püree'], ['text' => 'Apfel: konserviert, Mus']],
            ],
            'notiz' => 'Spiegel zu Regelwerk Grundprodukte v3.6.0 §8.17.',
        ], null, true, 'migration');
    }

    public function down(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($r !== null) {
            DB::table('foodalchemist_rule_versions')->where('rule_id', $r->id)->delete();
            $r->forceDelete();
        }
    }
};
