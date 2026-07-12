<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * #469 v3 — Schreib-Layer fürs Wissens-Modul (MCP-Wachstum, „Wissen von außen").
 * LLM-First: Tools rufen diesen Service, nie Models direkt.
 *
 * Leitplanken (analog Phase-A-Rezept-Kaskade):
 *  - Neu angelegte Docs sind INAKTIV (Quarantäne) — ein Mensch aktiviert sie im
 *    Browser, erst dann fließen sie in den KI-Kontext (~48 Prompts). Kein stiller
 *    Einzug KI-generierten Wissens.
 *  - Herkunft `created_via='mcp'`; Bindungen `source='mcp'` (Provenienz/Audit).
 *  - Vault-verwaltete Docs (source_path != null) sind für den MCP-Pfad GESPERRT
 *    — sie werden im Vault gepflegt (Import-Guard-Gegenstück). MCP wächst nur
 *    NEUES Wissen bzw. editiert sein eigenes.
 */
class KnowledgeService
{
    private const BINDING_MODES = ['always', 'discovery', 'grounding', 'reference'];

    /**
     * Legt ein neues Wissens-Dokument an (inaktiv). Optional: Aliase + Einsatzort-Bindungen.
     *
     * @param  array{title?:string,category?:string,content_md?:string,source?:string,aliases?:array,bind_layers?:array}  $data
     */
    public function create(Team $team, array $data): object
    {
        $title = trim((string) ($data['title'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        if ($title === '' || $category === '') {
            throw new RuntimeException('title und category sind Pflicht.');
        }
        $this->assertKategorie($team, $category);

        $content = (string) ($data['content_md'] ?? '');
        $source = ((string) ($data['source'] ?? 'mcp')) ?: 'mcp';
        $slug = $this->uniqueSlug($title);
        $now = now();

        $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => $team->id,
            'slug' => $slug,
            'title' => $title,
            'category' => $category,
            'content_md' => $content,
            'version' => 1,
            'content_hash' => hash('sha256', $content),
            'imported_hash' => null,       // nicht Vault-verwaltet → Import-Guard N/A
            'char_count' => mb_strlen($content),
            'active' => false,             // Quarantäne — Aktivierung ist menschlich
            'source_path' => null,
            'created_via' => $source,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ($this->cleanAliases($data['aliases'] ?? []) as $alias) {
            DB::table('foodalchemist_knowledge_aliases')->insertOrIgnore([
                'alias_slug' => $alias, 'knowledge_document_id' => $id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($this->cleanBindings($data['bind_layers'] ?? []) as $b) {
            $this->bindLayer($team, $id, $b['target_key'], $b['mode'], $source);
        }

        return $this->find($slug);
    }

    /**
     * Aktualisiert ein NICHT Vault-verwaltetes Dokument (per slug). Inhalts-Änderung
     * ⇒ version+1 + neuer content_hash. Optional: Aliase/Bindungen ergänzen.
     *
     * @param  array{title?:string,category?:string,content_md?:string,active?:bool,aliases?:array,bind_layers?:array}  $data
     */
    public function update(Team $team, string $slug, array $data): object
    {
        $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->whereNull('deleted_at')->first();
        if ($doc === null) {
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }
        if ($doc->source_path !== null) {
            throw new RuntimeException("\"{$slug}\" ist Vault-verwaltet — via MCP nicht editierbar. "
                . 'Pflege es über den Vault-Import oder im Browser.');
        }
        // Nur EIGENE Dokumente editierbar — Master/Seed (team_id NULL) + Fremd-Teams read-only.
        // Bewusst als "nicht gefunden" (NOT_FOUND, kein Existenz-Leak über die Teamgrenze).
        if (! TeamScope::owns($doc->team_id, $team)) {
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }

        $payload = ['updated_at' => now()];
        if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
            $payload['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('category', $data) && trim((string) $data['category']) !== '') {
            $cat = trim((string) $data['category']);
            $this->assertKategorie($team, $cat);
            $payload['category'] = $cat;
        }
        if (array_key_exists('active', $data)) {
            $payload['active'] = (bool) $data['active'];
        }
        if (array_key_exists('content_md', $data)) {
            $content = (string) $data['content_md'];
            $payload['content_md'] = $content;
            $payload['content_hash'] = hash('sha256', $content);
            $payload['char_count'] = mb_strlen($content);
            $payload['version'] = (int) $doc->version + 1;
        }
        DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->update($payload);

        $now = now();
        foreach ($this->cleanAliases($data['aliases'] ?? []) as $alias) {
            DB::table('foodalchemist_knowledge_aliases')->insertOrIgnore([
                'alias_slug' => $alias, 'knowledge_document_id' => $doc->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ($this->cleanBindings($data['bind_layers'] ?? []) as $b) {
            $this->bindLayer($team, (int) $doc->id, $b['target_key'], $b['mode'], 'mcp');
        }

        return $this->find($slug);
    }

    /** Bindet ein Doc an einen Einsatzort (knowledge_layers-Slug), Provenienz $source. */
    public function bindLayer(Team $team, int $docId, string $targetKey, string $mode, string $source = 'mcp'): void
    {
        $targetKey = trim($targetKey);
        $layer = DB::table('foodalchemist_knowledge_layers')->whereNull('deleted_at')
            ->where('active', true)->where('slug', $targetKey)->first();
        if ($layer === null) {
            $verfuegbar = DB::table('foodalchemist_knowledge_layers')->whereNull('deleted_at')
                ->where('active', true)->orderBy('slug')->pluck('slug')->implode(', ');
            throw new RuntimeException("Unbekannter Einsatzort \"{$targetKey}\". Verfügbar: {$verfuegbar}");
        }
        $mode = in_array($mode, self::BINDING_MODES, true) ? $mode : 'discovery';

        $exists = DB::table('foodalchemist_knowledge_bindings')->whereNull('deleted_at')
            ->where('knowledge_document_id', $docId)
            ->where('binding_type', 'layer')->where('target_key', $targetKey)->exists();
        if ($exists) {
            return;
        }
        DB::table('foodalchemist_knowledge_bindings')->insert([
            'uuid' => (string) UuidV7::generate(),
            'team_id' => $team->id,
            'knowledge_document_id' => $docId,
            'binding_type' => 'layer',
            'target_key' => $targetKey,
            'mode' => $mode,
            'weight' => 0,
            'active' => true,
            'source' => $source,
            'created_by' => Auth::id(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Wirft, wenn die Kategorie nicht im (aktiven) Vokabular steht. */
    private function assertKategorie(Team $team, string $slug): void
    {
        $ok = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
            ->where('active', true)->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
            ->exists();
        if (! $ok) {
            $verfuegbar = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
                ->where('active', true)
                ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
                ->orderBy('slug')->pluck('slug')->implode(', ');
            throw new RuntimeException("Unbekannte Kategorie \"{$slug}\". Verfügbar: {$verfuegbar}");
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title, '-') ?: 'wissen';
        $slug = $base;
        for ($i = 2; DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->exists(); $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    /** @return list<string> */
    private function cleanAliases(mixed $aliases): array
    {
        if (! is_array($aliases)) {
            return [];
        }
        $out = [];
        foreach ($aliases as $a) {
            $slug = Str::slug((string) $a, '_');
            if ($slug !== '') {
                $out[$slug] = true;
            }
        }

        return array_keys($out);
    }

    /** @return list<array{target_key:string,mode:string}> */
    private function cleanBindings(mixed $bindings): array
    {
        if (! is_array($bindings)) {
            return [];
        }
        $out = [];
        foreach ($bindings as $b) {
            if (! is_array($b)) {
                continue;
            }
            $key = trim((string) ($b['target_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $out[] = ['target_key' => $key, 'mode' => (string) ($b['mode'] ?? 'discovery')];
        }

        return $out;
    }

    /** Volle Doc-Zeile per Slug (auch inaktiv — anders als KnowledgeContextService::getDocument). */
    private function find(string $slug): object
    {
        return DB::table('foodalchemist_knowledge_documents')->where('slug', $slug)->firstOrFail();
    }
}
