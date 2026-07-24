<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bugfix Wissens-Modul (#469): der Unique-Index der Layer-Bindungen war
 * team-AGNOSTISCH — `(knowledge_document_id, binding_type, target_key)`. Das
 * widerspricht der durchgängig team-scoped Tool-Logik (Bindung trägt `team_id`
 * des Callers, `UNBIND` löst nur team-eigene Bindungen): zwei Teams konnten
 * dasselbe Doc NICHT an denselben Einsatzort binden, und eine Fremd-Bindung
 * unterdrückte fälschlich die eigene Anlage.
 *
 * Fix: `team_id` in den Unique aufnehmen → `(team_id, knowledge_document_id,
 * binding_type, target_key)`. Pro Team genau eine Bindung je Doc/Ziel; Teams
 * unabhängig. Bestehende Zeilen erfüllen bereits den strengeren Sub-Key
 * (doc,type,target_key) global-unique → kein Konflikt beim Umbau.
 *
 * (Der komplementäre Soft-Delete-Revive beim Re-Bind sitzt in
 * KnowledgeService::bindLayer — ohne ihn kollidierte ein Re-Bind mit der
 * soft-gelöschten Zeile, die den Unique-Key weiterhin belegt.)
 *
 * Additiv/idempotent (Index-Existenz via SHOW INDEX geprüft); MySQL.
 */
return new class extends Migration
{
    private string $tabelle = 'foodalchemist_knowledge_bindings';

    public function up(): void
    {
        if (! Schema::hasTable($this->tabelle)) {
            return;
        }
        // Der FK fa_know_bind_doc_fk (knowledge_document_id) nutzt bislang den alten
        // Unique als Stütz-Index. Der neue Unique führt mit team_id, deckt den FK also
        // NICHT ab → zuerst einen eigenen Doc-Index anlegen, sonst verweigert MySQL den Drop.
        if (! $this->hatIndex('fa_know_bind_doc_ix')) {
            Schema::table($this->tabelle, fn (Blueprint $t) => $t->index('knowledge_document_id', 'fa_know_bind_doc_ix'));
        }
        if ($this->hatIndex('fa_know_bind_uq')) {
            Schema::table($this->tabelle, fn (Blueprint $t) => $t->dropUnique('fa_know_bind_uq'));
        }
        if (! $this->hatIndex('fa_know_bind_team_uq')) {
            Schema::table($this->tabelle, fn (Blueprint $t) => $t->unique(
                ['team_id', 'knowledge_document_id', 'binding_type', 'target_key'],
                'fa_know_bind_team_uq'
            ));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->tabelle)) {
            return;
        }
        // Alten Unique zuerst wiederherstellen (stützt den FK), dann team-Unique + Doc-Index abbauen.
        if (! $this->hatIndex('fa_know_bind_uq')) {
            Schema::table($this->tabelle, fn (Blueprint $t) => $t->unique(
                ['knowledge_document_id', 'binding_type', 'target_key'],
                'fa_know_bind_uq'
            ));
        }
        if ($this->hatIndex('fa_know_bind_team_uq')) {
            Schema::table($this->tabelle, fn (Blueprint $t) => $t->dropUnique('fa_know_bind_team_uq'));
        }
        if ($this->hatIndex('fa_know_bind_doc_ix')) {
            Schema::table($this->tabelle, fn (Blueprint $t) => $t->dropIndex('fa_know_bind_doc_ix'));
        }
    }

    /** DB-portabel (MySQL live + SQLite Test) über den Schema-Builder statt SHOW INDEX. */
    private function hatIndex(string $name): bool
    {
        return Schema::hasIndex($this->tabelle, $name);
    }
};
