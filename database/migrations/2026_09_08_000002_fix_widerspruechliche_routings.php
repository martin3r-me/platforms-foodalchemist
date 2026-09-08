<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec 52 · Paket 2 — die drei Routing-Zeilen, die zweimal mit ENTGEGENGESETZTEM Modus
 * definiert waren.
 *
 * Für `recipe.eigenschaften`, `recipe.ueberarbeiten` und `vk.ueberarbeiten` × `regelwerk`
 * gab es zwei Wahrheiten im Repo:
 *
 *   · Migration (älter): `always`, 1 Doc, 6.000 bzw. 7.000 Zeichen
 *   · `KnowledgePolicySeedCommand::ROUTINGS` (neuer): `discovery`, 3 × 4.000
 *
 * Das ist nicht Drift in Zahlen, sondern **zwei verschiedene Auswahl-Algorithmen**:
 * `always` → `regelwerkBlock()` nimmt per `orderBy('slug')->first()` EIN Dossier (bei 61
 * Regelwerks-Splits praktisch zufällig), `discovery` → `discoverGenericBlock()` rankt drei.
 *
 * Und weil der Seed bewusst nicht überschreibt („NICHT anfassen, nur melden"), entschied
 * allein die **Reihenfolge der Skripte**, welcher Zustand gilt: auf einer frischen DB legt die
 * Migration `always` an, der Seed lässt es stehen. **demo fährt `discovery` (per
 * `wissen-steuerdaten-w0 --apply` gerichtet), ein neuer Kunde bekäme `always`.** Zwei
 * Umgebungen, zwei Verhalten, ein Repo.
 *
 * Diese Migration macht den Seed-Wert zur einzigen Wahrheit — sie richtet also den
 * Migrationspfad auf den Betriebsstand, nicht umgekehrt.
 *
 * **Nur wo der Alt-Wert noch steht.** Ein bewusst abweichender Bestand (jemand hat `always`
 * mit anderen Deckeln gesetzt) wird nicht überfahren; dann greift der Drift-Test und meldet es.
 */
return new class extends Migration
{
    /** feature × category → [Alt-Modus, Alt-max_docs, Soll-Modus, Soll-max_docs, Soll-Zeichen] */
    private const ZEILEN = [
        ['recipe.eigenschaften', 'regelwerk', 'always', 1, 'discovery', 3, 4000],
        ['recipe.ueberarbeiten', 'regelwerk', 'always', 1, 'discovery', 3, 4000],
        ['vk.ueberarbeiten', 'regelwerk', 'always', 1, 'discovery', 3, 4000],
    ];

    public function up(): void
    {
        foreach (self::ZEILEN as [$feature, $kategorie, $altModus, $altDocs, $sollModus, $sollDocs, $sollZeichen]) {
            DB::table('foodalchemist_knowledge_routings')
                ->where('feature', $feature)->where('category', $kategorie)
                // Genau der Alt-Zustand, nichts anderes — ein abweichender Bestand ist eine
                // Entscheidung und gehoert nicht ueberfahren.
                ->where('mode', $altModus)->where('max_docs', $altDocs)
                ->update([
                    'mode' => $sollModus,
                    'max_docs' => $sollDocs,
                    'max_chars_per_doc' => $sollZeichen,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach (self::ZEILEN as [$feature, $kategorie, $altModus, $altDocs, $sollModus, $sollDocs, $sollZeichen]) {
            DB::table('foodalchemist_knowledge_routings')
                ->where('feature', $feature)->where('category', $kategorie)
                ->where('mode', $sollModus)->where('max_docs', $sollDocs)
                ->update([
                    'mode' => $altModus,
                    'max_docs' => $altDocs,
                    'max_chars_per_doc' => $feature === 'recipe.eigenschaften' ? 6000 : 7000,
                    'updated_at' => now(),
                ]);
        }
    }
};
