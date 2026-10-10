<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Hausstandard Fond/Jus/Brühe: Beispiele ergänzen, damit der Regel-Block ganze Zutaten statt Wortstämme zeigt.
 * demo Lauf 89: trotz Regel 2 kg Rinderbeinscheiben in der Rinderjus. Aktiv-Status bleibt, wie er ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'basisrezept.hausstandard.fond_knochen')->first();
        if ($r === null || ! empty(($r->beispiele ?? [])['falsch'])) {
            return;
        }
        app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung', 'params',
            'notiz', 'dossier_slug']), 'beispiele' => [
                'falsch' => array_map(fn ($t) => ['text' => $t, 'kontext' => ['typ' => 'Jus']],
                    ['Rinderbeinscheiben: frisch', 'Kalb-Beinscheiben / Ossobuco: frisch', 'Rinderfilet: frisch']),
                'richtig' => array_map(fn ($t) => ['text' => $t, 'kontext' => ['typ' => 'Jus']],
                    ['Rinderknochen: frisch', 'Kalbsknochen: frisch, roh', 'Kalb: frisch, Parüren', 'Geflügel: frisch, Karkasse']),
            ]], null, null, 'migration');
    }

    public function down(): void
    {
    }
};
