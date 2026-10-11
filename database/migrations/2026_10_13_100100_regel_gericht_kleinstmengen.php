<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Kleinstmengen im Gericht (Dominique 11.10.): Butter, Öl, Salz, Pfeffer, Zitrone (Saft/Zeste) und Finish-Kräuter
 * stehen im GERICHT direkt als GP-Zeile — ohne eigenes Rüst-Basisrezept. Alles andere bleibt Basisrezept („ein Gericht
 * besteht aus Basisrezepten"). Anlass demo Lauf 98: „Aroma: Butter" (3871) als eigenes Basisrezept mit 1-kg-Block für
 * 2 g, rund 30k Tokens. Die Liste ist Regel-Daten (Einstellungen › Regeln), keine Konstante; Vergleich über Wortmengen
 * nach Abzug von Merkmalen („Pfeffer schwarz, frisch gemahlen" → Pfeffer), ein Halbfabrikat („Zitronenbutter",
 * „Kräuteröl") trifft nicht. Die Kräuter-Einträge sind ein Startbestand zur Pflege.
 */
return new class extends Migration
{
    private const SCHLUESSEL = 'gericht.kleinstmengen';

    public function up(): void
    {
        if (FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', self::SCHLUESSEL)->exists()) {
            return;
        }
        $e = static fn (string $begriff, array $aliase = [], array $erlaubt = []) => ['begriff' => $begriff, 'aliase' => $aliase,
            'erlaubt' => $erlaubt, 'ziel_typ' => 'wert', 'ziel_name' => $begriff];
        app(RegelService::class)->speichere([
            'schluessel' => self::SCHLUESSEL, 'regelwerk' => 'gericht', 'paragraph' => 'Kleinstmengen',
            'titel' => 'Kleinstmengen im Gericht direkt als Grundprodukt', 'art' => 'zuordnung', 'ziel' => 'vk.zeile.kleinstmenge',
            'wirkung' => 'korrigieren',
            'params' => ['vergleich' => 'tokens',
                // Merkmale, die den Eintrag nicht ändern („Pfeffer schwarz, frisch gemahlen", „Petersilie: gehackt") — pflegbar.
                'merkmale' => ['frisch', 'gemahlen', 'frisch gemahlen', 'gehackt', 'gezupft', 'geschnitten', 'fein', 'grob',
                    'zum', 'abschmecken', 'finish', 'garnitur'],
                'eintraege' => [
                $e('Butter', ['Süßrahmbutter', 'Sauerrahmbutter'], ['gesalzen', 'ungesalzen', 'kalt', 'weich']),
                $e('Öl', ['Olivenöl', 'Rapsöl', 'Sonnenblumenöl', 'Pflanzenöl', 'Speiseöl'], ['extra', 'vergine', 'nativ', 'kaltgepresst']),
                $e('Salz', ['Meersalz', 'Fleur de Sel', 'Salzflocken', 'Meersalzflocken'], ['grob', 'fein']),
                $e('Pfeffer', ['Pfeffer schwarz*', 'Pfeffer weiss*'], ['schwarz', 'schwarzer', 'weiss', 'weisser', 'gemoersert']),
                $e('Zitrone', ['Zitronensaft', 'Zitronenzeste', 'Zitronenabrieb', 'Zitronenschale', 'Zitrone Saft', 'Zitrone Zeste', 'Zitrone Abrieb']),
                $e('Schnittlauch'), $e('Petersilie', ['Blattpetersilie'], ['glatt', 'kraus']), $e('Kerbel'), $e('Dill'),
                $e('Kresse', ['Gartenkresse']), $e('Basilikum'), $e('Koriander', ['Koriandergrün']), $e('Estragon'), $e('Minze'),
            ]],
            'beispiele' => [
                'richtig' => [
                    ['text' => 'Butter', 'erwartet' => 'Butter'], ['text' => 'Olivenöl extra vergine', 'erwartet' => 'Öl'],
                    ['text' => 'Pfeffer schwarz', 'erwartet' => 'Pfeffer'], ['text' => 'Zitronenzeste', 'erwartet' => 'Zitrone'],
                    ['text' => 'Fleur de Sel', 'erwartet' => 'Salz'], ['text' => 'Schnittlauch', 'erwartet' => 'Schnittlauch'],
                ],
                'falsch' => [['text' => 'Zitronenbutter'], ['text' => 'Kräuteröl'], ['text' => 'Butter braun'], ['text' => 'Pfefferkörner']],
            ],
            'notiz' => 'Dominique 2026-10-11 (Lauf 98). Kräuter = Startbestand Finish-Kräuter, pflegbar.',
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
