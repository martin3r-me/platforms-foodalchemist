<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Bedeutung je Teller-Rolle (Dominique 11.10., Probelauf 3 auf demo): die KI vergab Kokos-Milchreis, Ochsenherztomate und
 * alle Häppchen einer Fingerfood-Platte als protein — hauptkomponente nie. Die Abgrenzung stand im Dossier §12 (v10), aber
 * weit entfernt von der Werteliste. Jetzt steht sie je Wert in der Regel und im Prompt direkt daneben.
 * Wortlaut aus Dossier §12.2 v10; protein und hauptkomponente wörtlich wie entschieden. „beilage“ nennt das Dossier nicht —
 * Kurzform mit dem Dossier-Beispiel (Tellerlinsen). Vorhandene Beschreibungen bleiben; Aktiv-Status bleibt.
 */
return new class extends Migration
{
    private const BESCHREIBUNG = [
        'traeger' => 'Bun, Cracker, Tartelette, Löffel-Basis, Brot beim Fingerfood/Street Food.',
        'basis' => 'Tellerbasis: Untergrund (Püree, Creme), nicht die Sauce.',
        'protein' => 'Hauptelement aus Fleisch, Fisch, Meeresfrüchten, Ei, Käse (z. B. Burrata), Tofu, Tempeh, Seitan — sonst NICHT.',
        'hauptkomponente' => 'Jedes andere Hauptelement: Gemüse (Ochsenherztomate), Pasta, Milchreis, Mousse, Parfait, Kuchen, Tarte. '
            . 'Auf einer Fingerfood-Platte ist jedes eigenständige Häppchen eine Hauptkomponente.',
        'beilage' => 'Begleitende Komponente zum Hauptelement (z. B. Tellerlinsen).',
        'frucht' => 'Kompott, Grütze, Chutney, frische oder marinierte Frucht, Röster.',
        'sauce' => 'Sauce/Jus, wird angegossen, nachdem Hauptelement und Beilage liegen.',
        'crunch' => 'Knuspriges Element.',
        'eis' => 'Eis, Sorbet, Granita.',
        'saeure' => 'Säure-Element: Gel, Pickle, Gastrique.',
        'aroma' => 'Öl- oder Würz-Akzent.',
        'garnitur' => 'Finish, kommt zuletzt.',
    ];

    public function up(): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gericht.teller_rolle')->first();
        if ($r === null) {
            return;
        }
        $params = (array) $r->params;
        $geaendert = false;
        foreach ((array) ($params['werte'] ?? []) as $i => $w) {
            if (is_array($w) && empty($w['beschreibung']) && isset(self::BESCHREIBUNG[$w['wert'] ?? ''])) {
                $params['werte'][$i]['beschreibung'] = self::BESCHREIBUNG[$w['wert']];
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
