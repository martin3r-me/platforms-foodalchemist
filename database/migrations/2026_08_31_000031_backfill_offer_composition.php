<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistOfferChapter;

/**
 * #380 Composer — Backfill: bestehende Angebote (flaches Modell aus angebots-lokalen
 * Concepts `offer_id` + referenzierten Katalog-Concepts Pivot `foodalchemist_offer_concept`)
 * in die neue Kapitel/Block-Komposition heben. Je Angebot OHNE Kapitel ein Default-Kapitel
 * „Menü" + je Concept ein `concept_ref`-Block (dedupliziert, Reihenfolge lokal → referenziert).
 *
 * IDEMPOTENT: Angebote, die bereits ein Kapitel haben, werden übersprungen. Die alten
 * Relationen (offer_id / Pivot) bleiben unangetastet (Rollback-Sicherheit) — die Komposition
 * ist ab jetzt die autoritative Preis-/Anzeige-Quelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SoftDeletes-Scope schließt gelöschte Angebote aus; kein Team-Global-Scope (Modul nutzt explizit visibleToTeam).
        FoodAlchemistAngebot::query()->orderBy('id')->chunkById(200, function ($offers) {
            foreach ($offers as $offer) {
                if (FoodAlchemistOfferChapter::where('offer_id', $offer->id)->exists()) {
                    continue; // schon migriert
                }
                // lokale + referenzierte Concept-IDs, dedupliziert, lokale zuerst
                $lokal = DB::table('foodalchemist_concepts')->whereNull('deleted_at')
                    ->where('offer_id', $offer->id)->orderBy('name')->pluck('id')->all();
                $ref = DB::table('foodalchemist_offer_concept')
                    ->where('offer_id', $offer->id)->orderBy('position')->pluck('concept_id')->all();
                $conceptIds = [];
                foreach (array_merge($lokal, $ref) as $cid) {
                    $cid = (int) $cid;
                    if ($cid > 0 && ! in_array($cid, $conceptIds, true)) {
                        $conceptIds[] = $cid;
                    }
                }
                if ($conceptIds === []) {
                    continue; // leeres Angebot → kein Kapitel nötig
                }
                $kapitel = FoodAlchemistOfferChapter::create([
                    'team_id' => $offer->team_id, 'offer_id' => $offer->id,
                    'title' => 'Menü', 'price_mode' => 'auto', 'position' => 0,
                ]);
                foreach ($conceptIds as $i => $cid) {
                    FoodAlchemistOfferBlock::create([
                        'team_id' => $offer->team_id, 'chapter_id' => $kapitel->id,
                        'type' => 'concept_ref', 'concept_id' => $cid, 'position' => $i,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Kein automatisches Zurückrollen: die Backfill-Kapitel/Blöcke sind ab jetzt die
        // autoritative Quelle. Ein gezieltes Entfernen würde live gepflegte Kompositionen
        // treffen — bewusst no-op (die Tabellen-Drop-Migration räumt ohnehin alles ab).
    }
};
