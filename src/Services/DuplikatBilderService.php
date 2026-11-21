<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookChapterImage;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;

/**
 * Bild-Löschschutz (CQ PR 2): Duplikate bekommen EIGENE Bilddateien statt Verweise auf die Dateien der Quelle.
 *
 * Vorher teilten Foodbook- und Speisekarten-Kopien die Datei mit der Quelle (nur der Pfad wurde übernommen, die
 * context_file_id nicht); die Kapitel-Galerie fehlte in der Kopie ganz. Jetzt kopiert FoodAlchemistMediaService::kopiereBild
 * jede Datei ins Ziel-Team — damit gehört die Kopie vollständig dem kopierenden Team, auch bei geerbten Quellen.
 * Fehlt die Quelldatei, bleibt das Feld der Kopie leer (kein toter Verweis).
 */
final class DuplikatBilderService
{
    public function __construct(private FoodAlchemistMediaService $media) {}

    /** @param  array<int, int>  $kapitelMap  Quell-Kapitel-ID → Kopie-Kapitel-ID */
    public function foodbook(FoodAlchemistFoodbook $quelle, FoodAlchemistFoodbook $neu, array $kapitelMap, Team $team): void
    {
        $this->kopf($quelle, $neu, $team, 'foodalchemist.foodbook', "foodalchemist/branding/{$neu->id}");
        foreach ($quelle->chapters as $k) {
            $kopie = FoodAlchemistFoodbookKapitel::where('team_id', $team->id)->find($kapitelMap[$k->id] ?? 0);
            if ($kopie === null) {
                continue;
            }
            $this->paar($k, $kopie, $team, ['image_context_file_id', 'image_path'], 'foodalchemist.foodbook_chapter', "foodalchemist/chapter/{$kopie->id}");
            $this->paar($k, $kopie, $team, ['logo_context_file_id', 'logo_path'], 'foodalchemist.foodbook_chapter', "foodalchemist/chapter/{$kopie->id}/logo");
            $kopie->save();
            foreach ($k->images as $bild) {
                $m = $this->media->kopiereBild($bild->context_file_id, $bild->path, $team, 'foodalchemist.foodbook_chapter', (int) $kopie->id, "foodalchemist/chapter/{$kopie->id}/gallery");
                if ($m !== null) {
                    FoodAlchemistFoodbookChapterImage::create([
                        'team_id' => $kopie->team_id, 'chapter_id' => $kopie->id,
                        'context_file_id' => $m['context_file_id'], 'path' => $m['path'], 'sort_order' => $bild->sort_order,
                    ]);
                }
            }
        }
    }

    /** @param  array<int, int>  $rubrikMap  Quell-Rubrik-ID → Kopie-Rubrik-ID */
    public function speisekarte(FoodAlchemistSpeisekarte $quelle, FoodAlchemistSpeisekarte $neu, array $rubrikMap, Team $team): void
    {
        $this->kopf($quelle, $neu, $team, 'foodalchemist.speisekarte', "foodalchemist/branding/menu_card/{$neu->id}");
        foreach ($quelle->sections as $r) {
            $kopie = FoodAlchemistSpeisekarteRubrik::where('team_id', $team->id)->find($rubrikMap[$r->id] ?? 0);
            if ($kopie !== null) {
                $this->paar($r, $kopie, $team, ['logo_context_file_id', 'logo_path'], 'foodalchemist.menu_card_section', "foodalchemist/menu_card_section/{$kopie->id}/logo");
                $kopie->save();
            }
        }
    }

    /** Logo + Titelbild des Kopfs. */
    private function kopf(Model $quelle, Model $neu, Team $team, string $typ, string $ordner): void
    {
        $this->paar($quelle, $neu, $team, ['logo_context_file_id', 'logo_path'], $typ, $ordner);
        $this->paar($quelle, $neu, $team, ['cover_context_file_id', 'cover_image_path'], $typ, $ordner);
        $neu->save();
    }

    /** @param  array{0:string, 1:string}  $spalten  [context_file_id-Spalte, Pfad-Spalte] — setzt die Kopie (ohne zu speichern). */
    private function paar(Model $quelle, Model $ziel, Team $team, array $spalten, string $typ, string $ordner): void
    {
        [$cf, $pfad] = $spalten;
        $m = null;
        if ($quelle->{$cf} || trim((string) $quelle->{$pfad}) !== '') {
            $m = $this->media->kopiereBild($quelle->{$cf} ? (int) $quelle->{$cf} : null, (string) $quelle->{$pfad}, $team, $typ, (int) $ziel->getKey(), $ordner);
        }
        $ziel->forceFill([$cf => $m['context_file_id'] ?? null, $pfad => $m['path'] ?? null]);
    }
}
