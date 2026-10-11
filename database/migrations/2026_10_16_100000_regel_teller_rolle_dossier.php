<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Spec 81 Teil E (Dominique 11.10.): das Teller-Vokabular stand zweimal — Regel gericht.teller_rolle UND Tabelle im
 * Dossier §12.2. Die KI folgte der alten Tabelle (demo Probelauf, Calls 2832–2834). Ab jetzt kommt die Liste nur aus der
 * Regel in den Prompt (RegelPromptBlock, Ziel vk.zeile.rolle); das Dossier erklärt nur noch. Die Verknüpfung mit dem
 * Dossier hält der Konsumenten-Wächter (foodalchemist:regeln-konsumenten) dicht. Aktiv-Status bleibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gericht.teller_rolle')->first();
        if ($r === null || $r->dossier_slug === 'regelwerk-basisrezepte-12-zutaten-komponenten-reihenfolge') {
            return;
        }
        app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung',
            'params', 'beispiele']),
            'dossier_slug' => 'regelwerk-basisrezepte-12-zutaten-komponenten-reihenfolge',
            'notiz' => trim(((string) $r->notiz) . ' 11.10.: Quelle der Liste im Prompt (Spec 81 E), Dossier §12 erklärt nur.'),
        ], null, null, 'migration');
    }

    public function down(): void
    {
    }
};
