<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Hausstandard Jus/Fond/Brühe von WARNEN auf BLOCKIEREN (Dominique 11.10.): erst ein harter Befund geht in die
 * Selbstheilung, die den Verkaufs-Cut gegen Knochen/Abschnitte tauscht. Anlass demo Lauf 98 (3880): 3 kg
 * Rinderbeinscheiben im Fond, als weicher Hinweis stehen geblieben. Derivate (GP §11.2) und die Zweck-Zutat
 * („Fond: Tafelspitz") bleiben über `erlaubt_wenn` frei. Der Grund nennt jetzt die Ersatzrichtung — er ist die Zeile,
 * die die Heil-Direktive der KI vorlegt. Aktiv-Status bleibt.
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'basisrezept.hausstandard.fond_knochen';

    public function up(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->first();
        if ($r === null || $r->wirkung === 'blockieren') {
            return;
        }
        $params = (array) ($r->params ?? []);
        $params['grund'] = 'Hausstandard: Jus, Fond und Brühe aus Knochen, Karkassen und Abschnitten (Parüren) derselben Tierart — '
            . 'einen Verkaufs-Cut (Beinscheibe, Hüfte, Filet …) dagegen tauschen. Ein Fleischstück als Hauptzutat nur, '
            . 'wenn es im Rezeptnamen steht (z. B. „Fond: Tafelspitz“)';
        $beispiele = (array) ($r->beispiele ?? []);
        $kontext = ['typ' => 'Fond', 'warengruppe' => '04'];
        // Gegenprobe der Freigabe: der Cut als Zweck-Zutat bleibt erlaubt (prüft der Motor beim Speichern).
        $beispiele['richtig'] = [...(array) ($beispiele['richtig'] ?? []),
            ['text' => 'Rindertafelspitz: frisch', 'kontext' => [...$kontext, 'zweck' => '1']]];
        $beispiele['falsch'] = [...(array) ($beispiele['falsch'] ?? []),
            ['text' => 'Rinderbeinscheiben: frisch', 'kontext' => [...$kontext, 'zweck' => '0']]];

        app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel',
            'dossier_slug']),
            'wirkung' => 'blockieren',
            'params' => $params,
            'beispiele' => $beispiele,
            'notiz' => trim(((string) $r->notiz) . ' Dominique 11.10.: blockieren statt warnen — Heilung tauscht Verkaufs-Cuts (Lauf 98).'),
        ], null, null, 'migration');
    }

    public function down(): void
    {
    }
};
