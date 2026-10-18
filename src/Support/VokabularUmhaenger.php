<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * Plan B (F1 c, Kurator 18.10.2026): Wird eine Abo-Fassung zur eigenen Arbeitszeile des Mandanten (Kündigung/Zurückziehen), zeigen ihre
 * Vokabular-Verweise (Kategorie, Speisen-Hauptgruppe, Speisenklasse, Aufschlagsklasse, Servierform, Behälter, Regenerationsgerät, Equipment,
 * Servier-Vehikel) auf Zeilen des MASTER-Teams. Die Kopie darf nie dorthin zeigen: der Umhänger löst auf die für den Mandanten SICHTBARE
 * Zeile auf (global oder eigene Team-Kette) — zuerst die bereits sichtbare Ausgangszeile, sonst die Zeile mit gleichem fachlichem Schlüssel
 * (code bzw. slug), die eigene vor der geerbten vor der globalen. Kein Treffer → NULL (nie die Master-ID).
 */
final class VokabularUmhaenger
{
    /** Tabelle → fachlicher Schlüssel (eindeutig je Team). */
    public const SCHLUESSEL = [
        'foodalchemist_recipe_categories' => 'code',
        'foodalchemist_dish_main_groups' => 'code',
        'foodalchemist_dish_classes' => 'code',
        'foodalchemist_markup_classes' => 'code',
        'foodalchemist_serving_forms' => 'code',
        'foodalchemist_vocab_containers' => 'slug',
        'foodalchemist_vocab_regeneration_devices' => 'slug',
        'foodalchemist_vocab_serving_vehicles' => 'slug',
        'foodalchemist_vocab_kitchen_equipment' => 'slug',
    ];

    /** @var array<string, ?int> je Prozess: "team|tabelle|id" → Ziel */
    private array $cache = [];

    public function fuer(Team $mandant, string $tabelle, mixed $quellId): ?int
    {
        if ($quellId === null || $quellId === '' || ! isset(self::SCHLUESSEL[$tabelle])) {
            return null;
        }
        $k = $mandant->id.'|'.$tabelle.'|'.(int) $quellId;
        if (array_key_exists($k, $this->cache)) {
            return $this->cache[$k];
        }
        $kette = BelongsToTeamHierarchy::teamAncestryIds($mandant);   // eigenes Team zuerst, Wurzel zuletzt
        $quelle = DB::table($tabelle)->where('id', (int) $quellId)->first();
        if ($quelle === null) {
            return $this->cache[$k] = null;
        }
        if (($quelle->deleted_at ?? null) === null && ($quelle->team_id === null || in_array((int) $quelle->team_id, $kette, true))) {
            return $this->cache[$k] = (int) $quelle->id;   // schon sichtbar (global oder eigene Kette)
        }
        $spalte = self::SCHLUESSEL[$tabelle];
        $treffer = DB::table($tabelle)->where($spalte, $quelle->{$spalte})->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', $kette))
            ->get(['id', 'team_id'])
            ->sortBy(fn ($z) => $z->team_id === null ? PHP_INT_MAX : array_search((int) $z->team_id, $kette, true))
            ->first();

        return $this->cache[$k] = $treffer !== null ? (int) $treffer->id : null;
    }

    /**
     * Servierform einer Darreichung (Pflichtfeld, NOT NULL): sichtbare Zeile gleichen Codes, sonst GENAU diese Master-Servierform beim Mandanten
     * nachlegen (team_id = Mandant, legacy_id leer) und in paket_kopien als art „kuendigung“ merken — eine spätere Paket-Buchung gilt sie dann als
     * vorhanden. Eine Kündigung muss immer gehen, auch beim Mandanten ohne Paket (Kurator 18.10.); kein „unbestimmt“-Rückfall (verlöre die Form).
     *
     * @param  int  $nachgelegt  wird um 1 erhöht, wenn eine Zeile angelegt wurde
     */
    public function servierformFuer(Team $mandant, int $quellId, int &$nachgelegt): int
    {
        $ziel = $this->fuer($mandant, 'foodalchemist_serving_forms', $quellId);
        if ($ziel !== null) {
            return $ziel;
        }
        $quelle = DB::table('foodalchemist_serving_forms')->where('id', $quellId)->first();
        if ($quelle === null) {
            throw new \RuntimeException("Servierform {$quellId} der Fassung existiert nicht mehr.");
        }
        $zeile = array_diff_key((array) $quelle, array_flip(['id', 'uuid', 'team_id', 'legacy_id', 'created_at', 'updated_at', 'deleted_at']));
        $id = (int) DB::table('foodalchemist_serving_forms')->insertGetId($zeile + ['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $mandant->id, 'legacy_id' => null, 'created_at' => now(), 'updated_at' => now()]);
        $paketTeam = (int) app(\Platform\FoodAlchemist\Services\FaRechte::class)->kundenHauptTeam($mandant)->id;
        DB::table('foodalchemist_paket_kopien')->insertOrIgnore(['team_id' => $paketTeam, 'paket' => \Platform\FoodAlchemist\Models\FoodAlchemistTeamPaket::GRUNDEINSTELLUNGEN,
            'tabelle' => 'serving_forms', 'quell_id' => $quellId, 'kopie_id' => $id, 'art' => 'kuendigung', 'created_at' => now(), 'updated_at' => now()]);
        $nachgelegt++;
        $this->cache[$mandant->id.'|foodalchemist_serving_forms|'.$quellId] = $id;

        return $id;
    }
}
