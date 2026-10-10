<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Haus-Zuordnung (Dominique 10.10.): „Habe ich eine Jus, die fertig ist, wird die auch benutzt — je nach Kontext. Die
 * Datenbasis mache ich später, die Logik muss stehen.“ Komponente im Plan → bevorzugtes Bestandsrezept, optional je
 * Kontext (sektor, niveau, convenience) — für Fälle, die der Namensvergleich nie verbindet.
 * Startet AUS mit einem Beispiel-Eintrag; die echten Einträge pflegt Dominique in Einstellungen › Regeln.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.bestand.haus_zuordnung')->exists()) {
            return;
        }
        app(RegelService::class)->speichere([
            'schluessel' => 'basisrezept.bestand.haus_zuordnung', 'regelwerk' => 'basisrezept', 'paragraph' => '§4',
            'titel' => 'Haus-Zuordnung: Komponente → Bestandsrezept', 'art' => 'zuordnung', 'ziel' => 'rezept.bestand',
            'wirkung' => 'korrigieren',
            'params' => ['vergleich' => 'text', 'eintraege' => [
                ['begriff' => 'Jus: Braten', 'aliase' => ['Bratenjus', 'Jus: Bratenjus'], 'ziel_typ' => 'rezept', 'ziel_name' => 'Jus: Braten (Zukauf)'],
            ]],
            'notiz' => 'Beispiel-Eintrag; Freigabe-, Typ- und Diät-Prüfung gelten weiter. Kontext je Eintrag: sektor, niveau, convenience.',
        ], null, false, 'migration');
    }

    public function down(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.bestand.haus_zuordnung')->first();
        if ($r !== null) {
            \Illuminate\Support\Facades\DB::table('foodalchemist_rule_versions')->where('rule_id', $r->id)->delete();
            $r->forceDelete();
        }
    }
};
