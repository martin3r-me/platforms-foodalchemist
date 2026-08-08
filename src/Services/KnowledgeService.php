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

    /** Max. Slug-Länge einer Wissens-Kategorie = Breite von foodalchemist_knowledge_documents.category (VARCHAR 24). */
    private const MAX_CATEGORY_SLUG_LEN = 24;

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
        // #505: expliziter Slug (z. B. Vault-konsistent skill.foo_bar) hat Vorrang, damit MCP-Anlage
        // und späterer Vault-Import denselben Slug treffen (reconcilebar, keine Dubletten).
        $slug = $this->uniqueSlug($title, isset($data['slug']) ? (string) $data['slug'] : null);
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

        // Bindungen sind team-scoped (team_id des Callers, siehe bindExisting-Docblock +
        // team-scoped Unique-Index fa_know_bind_team_uq). Idempotenz UND Soft-Delete-Revive
        // in einem Schritt — team-scoped abgefragt, soft-gelöschte Zeilen eingeschlossen:
        //   • aktive eigene Bindung  → No-op (nicht doppeln)
        //   • soft-gelöschte eigene  → wiederbeleben (das im UNBIND versprochene „sauberes
        //     Re-Bind"; ein Insert würde am Unique-Index mit der Leiche kollidieren)
        //   • keine eigene           → neu anlegen
        // Fremd-/globale Bindungen am selben Doc/Ziel bleiben unberührt (eigener team_id-Scope).
        $eigene = DB::table('foodalchemist_knowledge_bindings')
            ->where('knowledge_document_id', $docId)
            ->where('binding_type', 'layer')->where('target_key', $targetKey)
            ->where('team_id', $team->id)->first();
        if ($eigene !== null && $eigene->deleted_at === null) {
            return;
        }
        if ($eigene !== null) {
            DB::table('foodalchemist_knowledge_bindings')->where('id', $eigene->id)->update([
                'deleted_at' => null, 'active' => true, 'mode' => $mode, 'updated_at' => now(),
            ]);

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

    /**
     * Bindet ein BESTEHENDES, sichtbares Doc an einen Einsatzort — auch globalen
     * Seed / Vault-Kanon. Anders als update() (Inhalts-Edit, für Vault-Docs
     * gesperrt) ist Binden ein rein kuratorischer Akt: der Doc-Inhalt wird nicht
     * angefasst, darum KEIN source_path-/owns-Guard auf dem DOC. Sichtbarkeit =
     * globaler Seed (team_id NULL) ODER eigene Ancestry; sonst NOT_FOUND (kein
     * Existenz-Leak über die Teamgrenze). Die Bindung selbst trägt team_id des
     * Callers (siehe bindLayer) → tenancy-scoped. Idempotent.
     */
    public function bindExisting(Team $team, string $slug, string $targetKey, string $mode = 'discovery'): object
    {
        $doc = $this->findSichtbar($team, $slug);
        $this->bindLayer($team, (int) $doc->id, $targetKey, $mode, 'mcp');

        return $this->find($slug);
    }

    /**
     * Löst eine Layer-Bindung — aber NUR eine team-eigene (owns): globale/Fremd-
     * Bindungen bleiben unberührt. Soft-Delete, damit Reads (whereNull deleted_at)
     * sie ignorieren und ein späteres Re-Bind sauber neu anlegt. Gibt zurück, ob
     * etwas gelöst wurde (idempotent).
     */
    public function unbindExisting(Team $team, string $slug, string $targetKey): bool
    {
        $doc = $this->findSichtbar($team, $slug);
        $n = DB::table('foodalchemist_knowledge_bindings')->whereNull('deleted_at')
            ->where('knowledge_document_id', $doc->id)
            ->where('binding_type', 'layer')->where('target_key', trim($targetKey))
            ->where('team_id', $team->id)
            ->update(['active' => false, 'deleted_at' => now(), 'updated_at' => now()]);

        return $n > 0;
    }

    /** Doc per Slug, sichtbarkeits-gescoped (globaler Seed + eigene Ancestry); sonst NOT_FOUND. */
    private function findSichtbar(Team $team, string $slug): object
    {
        $doc = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at')->where('slug', $slug),
            'team_id', $team
        )->first();
        if ($doc === null) {
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }

        return $doc;
    }

    /**
     * Wirft, wenn die Kategorie nicht im (aktiven) Vokabular steht.
     *
     * Öffentlich seit 22·H1 (V-044): die beiden LESE-Tools (`knowledge.LIST`/`SEARCH`) trugen
     * die Kategorien als JSON-Schema-Enum im Code — eine Handkopie einer zur Laufzeit
     * pflegbaren Tabelle. Ein `getSchema()` darf keine DB anfassen (seiteneffektfrei, wird
     * bei jeder LLM-Anfrage gerufen), also fällt die Prüfung in `execute()` — und zwar über
     * DIESE Methode, damit Lese- und Schreibweg dieselbe Menge und dieselbe
     * „Verfügbar: …"-Meldung benutzen statt zwei Wahrheiten zu pflegen.
     */
    public function assertKategorie(Team $team, string $slug): void
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

    /**
     * MCP-Kategorie-Anlage: neue Wissens-Kategorie team-scoped anlegen. Slug aus dem
     * Label, Dedup gegen globales Master-Vokabular + eigenes Team. SOFORT aktiv, damit
     * sie unmittelbar in knowledge.POST/PUT ({@see assertKategorie}) nutzbar ist — anders
     * als Docs, die in Quarantäne bleiben (eine leere Kategorie fließt in keinen Prompt,
     * daher unkritisch). Spiegelt Settings\Wissenskategorien::create().
     *
     * @return array{slug:string,label:string,description:?string,scope:string,active:bool}
     */
    public function createCategory(Team $team, string $label, ?string $description = null, ?string $slug = null): array
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('label ist Pflicht.');
        }
        // Slug: expliziter Override (für deutsche Formen wie ernaehrung/geschaeftsmodell),
        // sonst aus dem Label abgeleitet — beides normalisiert (wie die Settings-UI).
        $slug = ($slug !== null && trim($slug) !== '') ? Str::slug($slug, '_') : Str::slug($label, '_');
        if ($slug === '') {
            throw new RuntimeException("Aus «{$label}» lässt sich kein gültiger Kategorie-Slug bilden.");
        }
        // Der Slug muss in foodalchemist_knowledge_documents.category (VARCHAR 24) passen —
        // sonst liesse sich die Kategorie zwar anlegen, aber kein Doc darin speichern (knowledge.POST
        // kippt beim Insert). Lieber hier klar abweisen als eine unbrauchbare Kategorie erzeugen.
        if (mb_strlen($slug) > self::MAX_CATEGORY_SLUG_LEN) {
            throw new RuntimeException(
                "Slug «{$slug}» ist zu lang (".mb_strlen($slug).' Zeichen, max. '.self::MAX_CATEGORY_SLUG_LEN
                .'). Kürzeres label oder einen kürzeren slug wählen.'
            );
        }
        $exists = DB::table('foodalchemist_knowledge_categories')
            ->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', $team->id))
            ->whereNull('deleted_at')->exists();
        if ($exists) {
            throw new RuntimeException("Kategorie \"{$slug}\" existiert schon (global oder in diesem Team).");
        }
        $description = ($description !== null && trim($description) !== '') ? trim($description) : null;
        $maxSort = (int) DB::table('foodalchemist_knowledge_categories')->max('sort_order');
        DB::table('foodalchemist_knowledge_categories')->insert([
            'uuid' => (string) Str::uuid7(),
            'team_id' => $team->id,
            'slug' => $slug,
            'label' => $label,
            'description' => $description,
            'sort_order' => $maxSort + 10,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['slug' => $slug, 'label' => $label, 'description' => $description, 'scope' => 'team', 'active' => true];
    }

    /**
     * MCP: sichtbare Wissens-Kategorien (globales Master-Vokabular + eigenes Team),
     * sortiert. Standard nur aktive; $includeInactive=true zeigt auch deaktivierte
     * (Selbst-Kontrolle des Agenten über seine eigenen/inaktiven Kategorien).
     *
     * @return list<array{slug:string,label:string,description:?string,scope:string,active:bool}>
     */
    public function listCategories(Team $team, bool $includeInactive = false): array
    {
        $q = DB::table('foodalchemist_knowledge_categories')->whereNull('deleted_at')
            ->where(fn ($qq) => $qq->whereNull('team_id')->orWhere('team_id', $team->id));
        if (! $includeInactive) {
            $q->where('active', true);
        }

        return $q->orderBy('sort_order')->orderBy('slug')
            ->get(['slug', 'label', 'description', 'team_id', 'active'])
            ->map(fn ($r) => [
                'slug' => $r->slug,
                'label' => $r->label,
                'description' => $r->description,
                'scope' => $r->team_id === null ? 'global' : 'team',
                'active' => (bool) $r->active,
            ])->all();
    }

    private function uniqueSlug(string $title, ?string $explicit = null): string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            // Expliziter Slug: leicht normalisieren, aber Punkte/Unterstriche erhalten (Vault-Format skill.foo_bar).
            $base = mb_strtolower(trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', trim($explicit)), '-.')) ?: 'wissen';
        } else {
            $base = Str::slug($title, '-') ?: 'wissen';
        }
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
