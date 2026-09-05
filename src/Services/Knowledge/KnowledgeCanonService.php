<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\TeamScope;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * KANON — welche Dossiers ein Feature/Prompt-Key verbindlich mitbekommt (Spec 50 E-7).
 *
 * Bild für Nicht-Entwickler: der Kanon ist die PACKLISTE, nicht der Wissensspeicher. Er
 * sagt „Rezept-Generator: nimm §1.0–1.2 und §10 mit", das Sachwissen selbst kommt weiter
 * über Discovery (semantische Suche). Ohne Kanon-Zeilen ändert sich nichts — dann liefert
 * der Block-Builder wie heute ganze Docs per always-Binding.
 *
 * Einheit ist das DOSSIER (Dominique 2026-09-05), nicht ein Abschnitt: ein Dossier = ein
 * Thema ≤ Deckel (`foodalchemist.semantic_search.dossier_max_chars`), Regelwerk = pro §
 * ein Dossier. Regel ändern = Dossier editieren, der Kanon zeigt weiter auf denselben Slug.
 *
 * Mandanten-Regel: Zeile mit team_id NULL = globaler Kanon (nur Master-Team pflegt ihn,
 * nur globale Dossiers erlaubt). Team-Zeile darf globale + eigene Dossiers. Beim Lesen
 * gilt global ∪ eigene Ahnenkette; referenziert eine Team-Zeile dasselbe Dossier wie eine
 * globale, gewinnt die Team-Zeile (ord/mode).
 *
 * Kurationsregel statt Code-Eigenschaft (Peer 9b, Spec 50 §5.1): Changelog/Meta gehört nie
 * in ein Kanon-Dossier — sonst schleppt jeder Treffer den Changelog in den Prompt. Der PUT
 * lehnt Dossiers mit `## Changelog` im Body deshalb ab.
 */
class KnowledgeCanonService
{
    public const SCOPES = ['feature', 'prompt_key'];
    public const ROLES = ['root', 'child'];
    public const MODES = ['pflicht', 'wenn_platz'];

    private const TABLE = 'foodalchemist_knowledge_canon';
    private const DOCS = 'foodalchemist_knowledge_documents';

    /**
     * Vertrag für den Prompt-Bau (KnowledgeContextService, Peer-Strang):
     * aktive Kanon-Dossiers in `ord`-Reihenfolge — leer = kein Kanon → Fallback ganze Docs.
     *
     * @return Collection<int, object{document_id:int, slug:string, title:string, category:string,
     *   content_md:string, mode:string, version:int, char_count:int, ord:int, canon_team_id:?int}>
     */
    public function documentsFor(string $scope, string $scopeKey, Team $team, string $role = 'root'): Collection
    {
        $rows = $this->sichtbareZeilen($team)
            ->where('c.scope', $scope)->where('c.scope_key', $scopeKey)->where('c.role', $role)
            ->where('c.active', 1)
            ->where('d.active', 1)
            ->orderBy('c.ord')->orderBy('c.id')
            ->get();

        // Team-Zeile gewinnt gegen globale Zeile auf dasselbe Dossier.
        $proDoc = [];
        foreach ($rows as $r) {
            $id = (int) $r->document_id;
            if (! isset($proDoc[$id]) || ($proDoc[$id]->canon_team_id === null && $r->canon_team_id !== null)) {
                $proDoc[$id] = $r;
            }
        }

        return collect(array_values($proDoc))
            ->sortBy([['ord', 'asc'], ['canon_id', 'asc']])
            ->values()
            ->map(static fn ($r) => (object) [
                'document_id' => (int) $r->document_id,
                'slug' => (string) $r->slug,
                'title' => (string) $r->title,
                'category' => (string) $r->category,
                'content_md' => (string) $r->content_md,
                'mode' => (string) $r->mode,
                'version' => (int) $r->version,
                'char_count' => (int) $r->char_count,
                'ord' => (int) $r->ord,
                'canon_team_id' => $r->canon_team_id === null ? null : (int) $r->canon_team_id,
            ]);
    }

    /** Hat dieser Scope/Key überhaupt einen Kanon (aktive Zeilen, unabhängig vom Doc-Status)? */
    public function hasCanon(string $scope, string $scopeKey, Team $team, string $role = 'root'): bool
    {
        return $this->sichtbareZeilen($team)
            ->where('c.scope', $scope)->where('c.scope_key', $scopeKey)->where('c.role', $role)
            ->where('c.active', 1)->exists();
    }

    /**
     * Listen-Sicht für MCP/Browser (ohne content_md — Packliste, kein Volltext).
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(Team $team, ?string $scope = null, ?string $scopeKey = null, ?string $role = null, bool $includeInactive = false): array
    {
        $q = $this->sichtbareZeilen($team);
        if ($scope !== null) {
            $q->where('c.scope', $scope);
        }
        if ($scopeKey !== null) {
            $q->where('c.scope_key', $scopeKey);
        }
        if ($role !== null) {
            $q->where('c.role', $role);
        }
        if (! $includeInactive) {
            $q->where('c.active', 1);
        }

        return $q->orderBy('c.scope')->orderBy('c.scope_key')->orderBy('c.role')->orderBy('c.ord')->orderBy('c.id')
            ->get()->map(fn ($r) => $this->zeile($r))->all();
    }

    /**
     * Kanon-Zeile setzen (Upsert auf team_id × scope × scope_key × role × Dossier).
     *
     * @param  array{scope:string, scope_key:string, slug:string, role?:string, mode?:string, ord?:int, active?:bool, global?:bool}  $data
     * @return array{zeile: array<string,mixed>, hinweise: list<string>}
     */
    public function set(Team $team, array $data): array
    {
        $scope = $this->pruefeEnum((string) ($data['scope'] ?? ''), self::SCOPES, 'scope');
        $role = $this->pruefeEnum((string) ($data['role'] ?? 'root'), self::ROLES, 'role');
        $mode = $this->pruefeEnum((string) ($data['mode'] ?? 'pflicht'), self::MODES, 'mode');
        $scopeKey = trim((string) ($data['scope_key'] ?? ''));
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($scopeKey === '' || $slug === '') {
            throw new InvalidArgumentException('scope_key und slug sind Pflicht.');
        }
        if (mb_strlen($scopeKey) > 64) {
            throw new InvalidArgumentException('scope_key ist länger als 64 Zeichen.');
        }

        $global = (bool) ($data['global'] ?? false);
        if ($global && ! TeamScope::isMaster($team)) {
            throw new RuntimeException('Globale Kanon-Zeilen pflegt nur das Master-Team.');
        }
        $teamId = $global ? null : (int) $team->id;

        $doc = TeamScope::applyVisible(
            DB::table(self::DOCS)->whereNull('deleted_at')->where('slug', $slug), 'team_id', $team
        )->first();
        if ($doc === null) {
            throw new RuntimeException("Wissens-Dokument \"{$slug}\" nicht gefunden.");
        }
        // Invariante: globaler Kanon → nur globale Dossiers (sonst zöge er Team-Wissen in fremde Prompts).
        if ($teamId === null && $doc->team_id !== null) {
            throw new RuntimeException(
                "\"{$slug}\" ist team-eigenes Wissen — eine GLOBALE Kanon-Zeile darf nur globale Dossiers "
                . 'referenzieren. Entweder ohne global=true (Team-Kanon) oder das Dossier erst globalisieren.'
            );
        }
        // Kurationsregel: kein Changelog im Kanon-Dossier.
        if ($this->hatChangelog((string) $doc->content_md)) {
            throw new RuntimeException(
                "\"{$slug}\" enthält eine Changelog-Überschrift — Kanon-Dossiers tragen keinen Changelog "
                . '(er würde bei jedem Treffer in den Prompt wandern). Changelog raus oder als eigenes Dossier ohne Kanon-Bindung.'
            );
        }

        $hinweise = [];
        $deckel = $this->dossierMaxChars();
        if ((int) $doc->char_count > $deckel) {
            $hinweise[] = sprintf(
                'Dossier hat %d Zeichen, Deckel ist %d — im Kanon landet es KOMPLETT im Prompt. Teilen (ein Thema pro Dossier).',
                (int) $doc->char_count, $deckel
            );
        }
        if (! (bool) $doc->active) {
            $hinweise[] = 'Dossier ist inaktiv — die Kanon-Zeile greift erst, wenn es aktiviert wird.';
        }

        $now = now();
        $existing = DB::table(self::TABLE)
            ->where('scope', $scope)->where('scope_key', $scopeKey)->where('role', $role)
            ->where('knowledge_document_id', $doc->id)
            ->when($teamId === null, fn ($q) => $q->whereNull('team_id'), fn ($q) => $q->where('team_id', $teamId))
            ->first();

        $payload = [
            'mode' => $mode,
            'ord' => max(0, (int) ($data['ord'] ?? ($existing?->ord ?? $this->naechsteOrd($teamId, $scope, $scopeKey, $role)))),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'updated_at' => $now,
            'deleted_at' => null,                                    // Reaktivierung einer soft-gelöschten Zeile
        ];

        if ($existing !== null) {
            DB::table(self::TABLE)->where('id', $existing->id)->update($payload);
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table(self::TABLE)->insertGetId($payload + [
                'uuid' => (string) UuidV7::generate(),
                'team_id' => $teamId,
                'scope' => $scope, 'scope_key' => $scopeKey, 'role' => $role,
                'knowledge_document_id' => (int) $doc->id,
                'created_at' => $now,
            ]);
        }

        $row = $this->zeilenQuery()->where('c.id', $id)->first();

        return ['zeile' => $this->zeile($row), 'hinweise' => $hinweise];
    }

    /**
     * Kanon-Zeile entfernen (soft). Team-Zeilen: nur das eigene Team; globale: nur Master mit global=true.
     */
    public function remove(Team $team, string $scope, string $scopeKey, string $slug, string $role = 'root', bool $global = false): int
    {
        if ($global && ! TeamScope::isMaster($team)) {
            throw new RuntimeException('Globale Kanon-Zeilen pflegt nur das Master-Team.');
        }

        return DB::table(self::TABLE . ' as c')
            ->join(self::DOCS . ' as d', 'd.id', '=', 'c.knowledge_document_id')
            ->where('c.scope', $scope)->where('c.scope_key', $scopeKey)->where('c.role', $role)
            ->where('d.slug', $slug)
            ->whereNull('c.deleted_at')
            ->when($global, fn ($q) => $q->whereNull('c.team_id'), fn ($q) => $q->where('c.team_id', (int) $team->id))
            ->update(['c.deleted_at' => now(), 'c.updated_at' => now()]);
    }

    public function dossierMaxChars(): int
    {
        $n = (int) config('foodalchemist.semantic_search.dossier_max_chars', 4000);

        return $n > 0 ? $n : 4000;
    }

    /**
     * Größen-Hinweis fürs Anlegen/Ändern (nicht blockierend — Kuration entscheidet, der Deckel erinnert).
     * Zählt die `##`-Abschnitte mit: das ist die natürliche Split-Linie („ein Thema pro Dossier").
     */
    public function groessenHinweis(int $charCount, string $contentMd = ''): ?string
    {
        $deckel = $this->dossierMaxChars();
        if ($charCount <= $deckel) {
            return null;
        }
        $abschnitte = preg_match_all('/^\s{0,3}##\s+\S/mu', $contentMd);

        return sprintf(
            'Dossier hat %d Zeichen (Deckel %d, Embedding-Fenster %d): Inhalt jenseits des Fensters ist semantisch '
            . 'kaum findbar. Ein Thema pro Dossier — %s.',
            $charCount, $deckel, (int) config('foodalchemist.semantic_search.embed_lead_chars', 2000),
            $abschnitte > 1 ? "die {$abschnitte} ##-Abschnitte sind die natürliche Trennlinie" : 'bitte teilen'
        );
    }

    /** Überschrift „Changelog" (## / ### …, beliebige Groß-/Kleinschreibung) im Body? */
    public function hatChangelog(string $contentMd): bool
    {
        return (bool) preg_match('/^\s{0,3}#{1,6}\s*changelog\b/miu', $contentMd);
    }

    // ── intern ───────────────────────────────────────────────────────────────

    private function zeilenQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table(self::TABLE . ' as c')
            ->join(self::DOCS . ' as d', 'd.id', '=', 'c.knowledge_document_id')
            ->whereNull('c.deleted_at')->whereNull('d.deleted_at')
            ->select([
                'c.id as canon_id', 'c.team_id as canon_team_id', 'c.scope', 'c.scope_key', 'c.role', 'c.ord',
                'c.mode', 'c.active as canon_active',
                'd.id as document_id', 'd.slug', 'd.title', 'd.category', 'd.content_md', 'd.version',
                'd.char_count', 'd.active as doc_active', 'd.team_id as doc_team_id',
            ]);
    }

    /** Global ∪ eigene Ahnenkette — auf Kanon-Zeile UND Dossier (Doppelfilter, kein Leak über die Zeile). */
    private function sichtbareZeilen(Team $team): \Illuminate\Database\Query\Builder
    {
        $q = $this->zeilenQuery();
        TeamScope::applyVisible($q, 'c.team_id', $team);
        TeamScope::applyVisible($q, 'd.team_id', $team);

        return $q;
    }

    private function zeile(object $r): array
    {
        return [
            'scope' => (string) $r->scope, 'scope_key' => (string) $r->scope_key, 'role' => (string) $r->role,
            'ord' => (int) $r->ord, 'mode' => (string) $r->mode, 'active' => (bool) $r->canon_active,
            'global' => $r->canon_team_id === null,
            'slug' => (string) $r->slug, 'title' => (string) $r->title, 'category' => (string) $r->category,
            'char_count' => (int) $r->char_count, 'version' => (int) $r->version,
            'doc_active' => (bool) $r->doc_active,
        ];
    }

    private function naechsteOrd(?int $teamId, string $scope, string $scopeKey, string $role): int
    {
        $max = DB::table(self::TABLE)->where('scope', $scope)->where('scope_key', $scopeKey)->where('role', $role)
            ->when($teamId === null, fn ($q) => $q->whereNull('team_id'), fn ($q) => $q->where('team_id', $teamId))
            ->whereNull('deleted_at')->max('ord');

        return $max === null ? 0 : (int) $max + 10;
    }

    /** @param list<string> $erlaubt */
    private function pruefeEnum(string $wert, array $erlaubt, string $feld): string
    {
        if (! in_array($wert, $erlaubt, true)) {
            throw new InvalidArgumentException(sprintf('%s muss eines von %s sein.', $feld, implode('|', $erlaubt)));
        }

        return $wert;
    }
}
