<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine Kanon-Zeile in der Fixture setzen — der geteilte Helfer.
 *
 * ★ **Warum als Trait und nicht als globale Funktion in der Testdatei.** Genau daran ist der
 * erste Lauf dieses Umbaus gescheitert: `WissenSteuerdatenPolitikTest` und
 * `WissenTokenWelle0Test` deklarierten beide ein `w0Kanon()`, und der sequentielle Lauf lädt
 * beide Dateien in denselben Prozess → `Cannot redeclare`. Der Kopf von `fa_test.sh` warnt
 * wörtlich davor; ich bin trotzdem hineingelaufen, weil der PARALLELE Lauf es nicht zeigt
 * (jeder Worker lädt nur seine eigenen Dateien).
 *
 * Zweiter, wichtigerer Grund: seit Spec 52 · F2 ist der Kanon die EINZIGE Quelle für „muss in
 * diesen Prompt". Damit brauchen ihn jetzt viele Tests, die vorher `w0Bind()` benutzt haben —
 * das wäre die vierte, fünfte, sechste Kopie derselben Insert-Zeile geworden.
 *
 * ⚠ Ehrlich dazu: sechs ältere Dateien halten ihren Kanon-Insert weiter als eigene
 * `beforeEach`-Closure (`$this->mkKanon`). Die kollidieren nicht (keine globalen Funktionen)
 * und sind je auf ihren Test zugeschnitten — sie umzustellen wäre Diff ohne Gewinn. Neue
 * Tests nehmen diesen Trait.
 */
trait SeedsKanon
{
    /**
     * Hängt ein bestehendes Dossier (per Slug) verbindlich an einen Prompt-Key.
     *
     * Bewusst per Insert und nicht über `KnowledgeCanonService::set()`: die Fixture soll auch
     * Zustände stellen können, die der Service ablehnt — ein Dossier über dem Deckel, eine
     * inaktive Zeile, ein Changelog im Body. Genau die will der Integritäts-Bericht ja melden.
     */
    protected function kanonZeile(
        int $teamId,
        string $promptKey,
        string $slug,
        string $mode = 'pflicht',
        int $ord = 0,
        string $scope = 'prompt_key',
        bool $aktiv = true,
    ): void {
        DB::table('foodalchemist_knowledge_canon')->insert([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => $teamId,
            'scope' => $scope,
            'scope_key' => $promptKey,
            'role' => 'root',
            'ord' => $ord,
            'knowledge_document_id' => (int) DB::table('foodalchemist_knowledge_documents')
                ->where('slug', $slug)->value('id'),
            'mode' => $mode,
            'active' => $aktiv,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
