<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Support\Datenmodell\SpiegelfelderRueckbau;

/**
 * Plan 98 · 3.5 Schritt 6c · Gespiegelte Darreichungsfelder am Rezept entfernen (B11/U6): sales_unit_vocab_id, sales_quantity_per_unit_g,
 * sales_unit_count, container_warm/cold_vocab_id, serving_vehicle_vocab_id und die *_legacy_id-Altspalten.
 *
 * Freigabe Dominique „Ja, nach Report“ (foodalchemist:diagnose:spiegelfelder). ERST NACH 6a (Leser auf die Darreichung) und 6b
 * (Schreiber gestoppt) mergen. Trockenlauf: SpiegelfelderRueckbau::plan() (Spalten, zu sichernde Zeilen, Fassungen für den Rehash).
 *
 * up(): sichern (Backup-Tabelle foodalchemist_spiegelfelder_backup) → unveränderte Fassungen merken → FKs/Indizes lösen, Spalten
 * droppen → Rehash dieser Fassungen (alter/neuer Hash in foodalchemist_spiegelfelder_backup_hash). Idempotent.
 * down(): Spalten + FKs zurück, Werte und alte Hashes aus dem Backup. Die Backup-Tabellen bleiben bis zur Freigabe zum Aufräumen stehen.
 */
return new class extends Migration
{
    /** SQLite-Testbasis: originalgetreuer Umbau braucht abgeschaltete FK-Prüfung, die wirkt nur ohne Transaktion (MySQL: ohne Wirkung). */
    public $withinTransaction = false;

    public function up(): void
    {
        if (SpiegelfelderRueckbau::vorhanden() === []) {
            return;
        }
        SpiegelfelderRueckbau::sichern();
        $unveraendert = SpiegelfelderRueckbau::unveraenderte();
        SpiegelfelderRueckbau::entfernen();
        SpiegelfelderRueckbau::rehash($unveraendert);
    }

    public function down(): void
    {
        SpiegelfelderRueckbau::wiederherstellen();
    }
};
