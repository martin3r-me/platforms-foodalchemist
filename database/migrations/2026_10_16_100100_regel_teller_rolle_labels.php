<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Anzeigenamen der Teller-Rollen in die Regel (Kuratorin 11.10.): ein neuer Wert auf der Pflegeseite wird sofort richtig
 * angezeigt („Säure“ statt „Saeure“), ohne Code-Änderung. Vorhandene Labels bleiben unangetastet. Aktiv-Status bleibt.
 */
return new class extends Migration
{
    private const LABELS = ['basis' => 'Basis', 'protein' => 'Protein', 'beilage' => 'Beilage', 'sauce' => 'Sauce',
        'crunch' => 'Crunch', 'saeure' => 'Säure', 'aroma' => 'Aroma', 'garnitur' => 'Garnitur'];

    public function up(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gericht.teller_rolle')->first();
        if ($r === null) {
            return;
        }
        $params = (array) $r->params;
        $geaendert = false;
        foreach ((array) ($params['werte'] ?? []) as $i => $w) {
            if (is_array($w) && empty($w['label']) && isset(self::LABELS[$w['wert'] ?? ''])) {
                $params['werte'][$i]['label'] = self::LABELS[$w['wert']];
                $geaendert = true;
            }
        }
        if ($geaendert) {
            app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung',
                'beispiele', 'dossier_slug', 'notiz']), 'params' => $params], null, null, 'migration');
        }
    }

    public function down(): void
    {
    }
};
