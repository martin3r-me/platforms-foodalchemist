<?php

namespace Platform\FoodAlchemist\Support\Datenmodell;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Fassung\FassungService;

/**
 * Plan 98 · 3.5 Schritt 6c · Rückbau der gespiegelten Darreichungsfelder am Rezept (B11/U6). Freigabe Dominique: „Ja, nach Report“
 * ({@see \Platform\FoodAlchemist\Services\Rezept\SpiegelfelderReport}). Erst nach 6a (Leser) und 6b (Schreiber) mergen.
 *
 * Ablauf (Migration 2027_01_16_100000): {@see sichern()} (alle Werte inkl. gelöschter Zeilen in die Backup-Tabelle) →
 * {@see unveraenderte()} (je Arbeitszeile: passt ihr Hash noch zur jüngsten Fassung?) → {@see entfernen()} (FKs, Indizes, Spalten)
 * → {@see rehash()} (für genau diese Fassungen den neuen Hash eintragen, alten mitschreiben). Rückweg {@see wiederherstellen()}:
 * Spalten + FKs anlegen, Werte und alte Hashes aus dem Backup zurück.
 *
 * Warum Rehash (Risiko R1): der Rezept-Hash nimmt alle Rezept-Spalten; ohne Nachtrag „wiche“ nach dem Drop jede Fassung „ab“ und das
 * nächste Veröffentlichen erzeugte für alle Abonnenten neue Fassungen ohne inhaltliche Änderung.
 */
final class SpiegelfelderRueckbau
{
    public const BACKUP = 'foodalchemist_spiegelfelder_backup';

    public const BACKUP_HASH = 'foodalchemist_spiegelfelder_backup_hash';

    /** Spalte → [Typ, FK-Ziel|null] — Original-Definitionen (2026_06_12_000022 / _000029) für den Rückweg. */
    public const SPALTEN = [
        'sales_unit_vocab_id' => ['fk', 'foodalchemist_vocab_units'],
        'sales_quantity_per_unit_g' => ['decimal', null],
        'sales_unit_count' => ['decimal', null],
        'container_warm_vocab_id' => ['fk', 'foodalchemist_vocab_containers'],
        'container_cold_vocab_id' => ['fk', 'foodalchemist_vocab_containers'],
        'serving_vehicle_vocab_id' => ['fk', 'foodalchemist_vocab_serving_vehicles'],
        'container_warm_legacy_id' => ['id', null],
        'container_cold_legacy_id' => ['id', null],
        'serving_vehicle_legacy_id' => ['id', null],
    ];

    private const REZEPTE = 'foodalchemist_recipes';

    /** @return list<string> noch vorhandene Spiegelspalten */
    public static function vorhanden(): array
    {
        return array_values(array_filter(array_keys(self::SPALTEN), fn ($s) => Schema::hasColumn(self::REZEPTE, $s)));
    }

    /**
     * Trockenlauf: was die Migration täte, ohne etwas zu ändern.
     *
     * @return array{spalten: list<string>, zu_sichern: int, fassungen_rehash: int}
     */
    public static function plan(): array
    {
        $spalten = self::vorhanden();

        return ['spalten' => $spalten, 'zu_sichern' => $spalten === [] ? 0 : self::belegt($spalten)->count(), 'fassungen_rehash' => count(self::unveraenderte())];
    }

    /** Werte aller Zeilen (auch gelöschter, auch Fassungen), in denen mindestens eine Spiegelspalte belegt ist. Idempotent. */
    public static function sichern(): int
    {
        $spalten = self::vorhanden();
        if ($spalten === []) {
            return 0;
        }
        if (! Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $t) {
                $t->unsignedBigInteger('recipe_id')->primary();
                foreach (self::SPALTEN as $s => [$typ]) {
                    $typ === 'decimal' ? $t->decimal($s, 10, 2)->nullable() : $t->unsignedBigInteger($s)->nullable();
                }
                $t->timestamp('gesichert_at')->nullable();
            });
        }
        $n = 0;
        self::belegt($spalten)->orderBy('id')->chunk(500, function ($zeilen) use ($spalten, &$n) {
            $rows = $zeilen->map(fn ($z) => ['recipe_id' => $z->id, ...array_intersect_key((array) $z, array_flip($spalten)), 'gesichert_at' => now()])->all();
            DB::table(self::BACKUP)->upsert($rows, ['recipe_id'], [...$spalten, 'gesichert_at']);
            $n += count($rows);
        });

        return $n;
    }

    /**
     * Arbeitszeilen, deren jüngste Fassung noch zum aktuellen Hash passt (vor dem Drop zu ermitteln).
     *
     * @return array<int, array{recipe_id: int, fassung_id: int, hash_alt: string}> fassung_id → …
     */
    public static function unveraenderte(): array
    {
        $svc = app(FassungService::class);
        $ids = DB::table(self::REZEPTE)->where('ist_fassung', true)->whereNull('deleted_at')->whereNotNull('fassung_von_id')
            ->distinct()->pluck('fassung_von_id')->map(fn ($i) => (int) $i)->all();
        $out = [];
        foreach (array_chunk($ids, 200) as $teil) {
            foreach (FoodAlchemistRecipe::ohneTeamSicht('abo-pflege')->whereIn('id', $teil)->where('ist_fassung', false)->get() as $r) {
                $letzte = FoodAlchemistRecipe::mitFassungen()->ohneTeamSicht('abo-pflege')->where('fassung_von_id', $r->id)->where('ist_fassung', true)
                    ->whereNull('deleted_at')->orderByDesc('fassung_nr')->orderByDesc('id')->first();
                if ($letzte !== null && $letzte->inhalt_hash === $svc->inhaltHashFuer($r)) {
                    $out[(int) $letzte->id] = ['recipe_id' => (int) $r->id, 'fassung_id' => (int) $letzte->id, 'hash_alt' => (string) $letzte->inhalt_hash];
                }
            }
        }

        return $out;
    }

    /** FKs und Indizes auf den Spiegelspalten lösen, dann die Spalten droppen. */
    public static function entfernen(): void
    {
        $spalten = self::vorhanden();
        if ($spalten === []) {
            return;
        }
        if (SqliteTabellenUmbau::aktiv()) {
            // Testbasis: FK-Klauseln per originalgetreuem Umbau raus (Laravels Umbau verlöre partielle Indizes), dann nativ
            // DROP INDEX / DROP COLUMN — beides ohne Tabellen-Neubau.
            SqliteTabellenUmbau::umbauen(self::REZEPTE, function (string $sql) use ($spalten) {
                foreach ($spalten as $s) {
                    $sql = SqliteTabellenUmbau::ohneFk($sql, $s);
                }

                return $sql;
            });
        } else {
            foreach (Schema::getForeignKeys(self::REZEPTE) as $fk) {
                if (array_intersect($fk['columns'], $spalten) !== []) {
                    Schema::table(self::REZEPTE, fn (Blueprint $t) => $t->dropForeign($fk['name']));
                }
            }
        }
        foreach (Schema::getIndexes(self::REZEPTE) as $ix) {
            if (! $ix['primary'] && array_intersect($ix['columns'], $spalten) !== []) {
                DB::statement('DROP INDEX '.(SqliteTabellenUmbau::aktiv() ? '"'.$ix['name'].'"' : '`'.$ix['name'].'` ON `'.self::REZEPTE.'`'));
            }
        }
        if (SqliteTabellenUmbau::aktiv()) {
            foreach ($spalten as $s) {
                DB::statement('ALTER TABLE "'.self::REZEPTE.'" DROP COLUMN "'.$s.'"');
            }
        } else {
            Schema::table(self::REZEPTE, fn (Blueprint $t) => $t->dropColumn($spalten));
        }
    }

    /**
     * Neuen Hash an die zuvor unveränderten Fassungen schreiben; alten Hash ins Backup (für down()).
     *
     * @param  array<int, array{recipe_id: int, fassung_id: int, hash_alt: string}>  $unveraendert
     */
    public static function rehash(array $unveraendert): int
    {
        if (! Schema::hasTable(self::BACKUP_HASH)) {
            Schema::create(self::BACKUP_HASH, function (Blueprint $t) {
                $t->unsignedBigInteger('fassung_id')->primary();
                $t->unsignedBigInteger('recipe_id');
                $t->string('hash_alt', 64);
                $t->string('hash_neu', 64);
            });
        }
        $svc = app(FassungService::class);
        $n = 0;
        foreach ($unveraendert as $u) {
            $r = FoodAlchemistRecipe::ohneTeamSicht('abo-pflege')->find($u['recipe_id']);   // frisch geladen: ohne die gedroppten Spalten
            if ($r === null) {
                continue;
            }
            $neu = $svc->inhaltHashFuer($r);
            DB::table(self::BACKUP_HASH)->upsert([['fassung_id' => $u['fassung_id'], 'recipe_id' => $u['recipe_id'], 'hash_alt' => $u['hash_alt'], 'hash_neu' => $neu]],
                ['fassung_id'], ['hash_neu']);
            DB::table(self::REZEPTE)->where('id', $u['fassung_id'])->update(['inhalt_hash' => $neu]);
            $n++;
        }

        return $n;
    }

    /** Rückweg: Spalten (+ FKs, nullOnDelete wie im Original) anlegen, Werte und alte Fassungs-Hashes aus dem Backup zurück. */
    public static function wiederherstellen(): void
    {
        $fehlt = array_values(array_filter(array_keys(self::SPALTEN), fn ($s) => ! Schema::hasColumn(self::REZEPTE, $s)));
        if ($fehlt !== [] && SqliteTabellenUmbau::aktiv()) {
            // Testbasis: natives ADD COLUMN (REFERENCES ist bei DEFAULT NULL erlaubt) — kein Tabellen-Neubau
            foreach ($fehlt as $s) {
                [$typ, $ziel] = self::SPALTEN[$s];
                $def = match ($typ) {
                    'decimal' => 'numeric',
                    'fk' => 'integer REFERENCES "'.$ziel.'"("id") ON DELETE SET NULL',
                    default => 'integer',
                };
                DB::statement('ALTER TABLE "'.self::REZEPTE.'" ADD COLUMN "'.$s.'" '.$def);
            }
            $fehlt = [];
        }
        if ($fehlt !== []) {
            Schema::table(self::REZEPTE, function (Blueprint $t) use ($fehlt) {
                foreach ($fehlt as $s) {
                    [$typ, $ziel] = self::SPALTEN[$s];
                    if ($typ === 'decimal') {
                        $t->decimal($s, 10, 2)->nullable();
                    } elseif ($typ === 'fk') {
                        $t->foreignId($s)->nullable()->constrained($ziel)->nullOnDelete();
                    } else {
                        $t->unsignedBigInteger($s)->nullable();
                    }
                }
            });
        }
        if (Schema::hasTable(self::BACKUP)) {
            DB::table(self::BACKUP)->orderBy('recipe_id')->chunk(500, function ($zeilen) {
                foreach ($zeilen as $z) {
                    DB::table(self::REZEPTE)->where('id', $z->recipe_id)->update(array_intersect_key((array) $z, self::SPALTEN));
                }
            });
        }
        if (Schema::hasTable(self::BACKUP_HASH)) {
            foreach (DB::table(self::BACKUP_HASH)->get() as $h) {
                DB::table(self::REZEPTE)->where('id', $h->fassung_id)->where('inhalt_hash', $h->hash_neu)->update(['inhalt_hash' => $h->hash_alt]);
            }
        }
    }

    private static function belegt(array $spalten): \Illuminate\Database\Query\Builder
    {
        return DB::table(self::REZEPTE)->select(['id', ...$spalten])
            ->where(function ($q) use ($spalten) {
                foreach ($spalten as $s) {
                    $q->orWhereNotNull($s);
                }
            });
    }
}
