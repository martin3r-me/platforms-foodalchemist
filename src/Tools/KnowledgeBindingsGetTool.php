<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 52/A3 — Bindungen LESEN. Die Alt-Struktur war schreibbar, aber nicht lesbar.
 *
 * `knowledge.BIND` und `.UNBIND` schreiben seit #469 Bindungen, und der Wissens-Browser zeigt
 * sie je Dossier — aber es gab **kein** Tool, das den Bestand als Ganzes ausgibt. Damit war der
 * Zustand der Alt-Struktur weder per MCP prüfbar noch von einem Wächter erfassbar, und genau
 * deshalb blieb diese Fehlerklasse still: eine Bindung auf ein deaktiviertes Dossier sieht im
 * Browser nach Verdrahtung aus und liefert nichts.
 *
 * Drei Dinge, die diese Auskunft ausdrücklich mitliefert, weil sie über die Wirkung entscheiden:
 *   · **`doc_active`** — eine Bindung auf ein inaktives Dossier ist keine Versorgung. So ist
 *     beim Cutover der 155 Originale still Wissen verschwunden.
 *   · **`ziel_art`** (`bereich` | `prompt`) — ein Bereichs-Ziel wie `recipe` hängt dasselbe
 *     Dossier an ALLE Prompts des Bereichs. Gemessen: zwei Dossiers an je 23 Keys.
 *   · **`stumm_wegen_kanon`** — hat der Prompt-Key Kanon-Zeilen, stellt der Gateway die
 *     Bindungen für ihn stumm (`AiGatewayService:178`). Die Bindung existiert dann, wirkt aber
 *     nicht. Ohne dieses Feld kuratiert ein Mensch ins Leere.
 *
 * Read-only. Der Schreib-Gegenpart bleibt `knowledge.BIND`/`.UNBIND` — bis Spec 52/F3 beide
 * durch die Profil-Pflege ersetzt.
 */
class KnowledgeBindingsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_bindings.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Wissens-BINDUNGEN (Alt-Struktur #469): welches Dossier hängt an welchem '
            . 'Einsatzort. Je Zeile target_key + ziel_art (bereich = gilt für ALLE Prompts des '
            . 'Bereichs, prompt = ein einzelner Aufruf), Dossier-Slug, mode, weight — und zwei '
            . 'Wirksamkeits-Felder: doc_active (Bindung auf ein inaktives Dossier liefert NICHTS) '
            . 'und stumm_wegen_kanon (hat der Prompt-Key einen Kanon, sind Bindungen für ihn stumm). '
            . 'Optional auf target_key oder slug filtern. Lese-Gegenpart zu knowledge.BIND/.UNBIND.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_key' => ['type' => 'string', 'description' => 'optional: nur dieser Einsatzort (Bereich wie «recipe» oder Prompt wie «recipe.geschmack»)'],
                'slug' => ['type' => 'string', 'description' => 'optional: nur Bindungen dieses Dossiers'],
                'nur_wirkungslos' => ['type' => 'boolean', 'description' => 'optional: nur Bindungen, die nichts liefern (inaktives Dossier oder durch Kanon stumm)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $targetKey = trim((string) ($arguments['target_key'] ?? ''));
        $slug = trim((string) ($arguments['slug'] ?? ''));
        $nurWirkungslos = (bool) ($arguments['nur_wirkungslos'] ?? false);

        $q = DB::table('foodalchemist_knowledge_bindings as b')
            ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'b.knowledge_document_id')
            ->whereNull('b.deleted_at')->whereNull('d.deleted_at')
            ->where('b.binding_type', 'layer');
        TeamScope::applyVisible($q, 'b.team_id', $team);
        TeamScope::applyVisible($q, 'd.team_id', $team);

        if ($targetKey !== '') {
            $q->where('b.target_key', $targetKey);
        }
        if ($slug !== '') {
            $q->where('d.slug', $slug);
        }

        $rows = $q->orderBy('b.target_key')->orderByDesc('b.weight')->orderBy('d.slug')
            ->get(['b.target_key', 'b.mode', 'b.weight', 'b.active as bindung_active', 'b.source',
                'd.slug', 'd.category', 'd.char_count', 'd.active as doc_active']);

        // Welche Prompt-Keys haben überhaupt Kanon-Zeilen? Eine Abfrage statt einer pro Zeile.
        $mitKanon = DB::table('foodalchemist_knowledge_canon')
            ->where('scope', 'prompt_key')->where('role', 'root')->where('active', 1)
            ->whereNull('deleted_at')
            ->distinct()->pluck('scope_key')->map(fn ($k) => (string) $k)->all();

        $registry = array_keys((array) config('foodalchemist.prompts', []));

        $zeilen = [];
        foreach ($rows as $r) {
            $ziel = (string) $r->target_key;
            $istBereich = ! str_contains($ziel, '.');

            // Ein Bereichs-Ziel erbt auf jeden Prompt des Bereichs — und ist genau dort stumm,
            // wo dieser Prompt einen Kanon hat. Deshalb zählen wir beide Seiten.
            $betroffene = $istBereich
                ? array_values(array_filter($registry, fn ($k) => str_starts_with((string) $k, $ziel.'.')))
                : [$ziel];
            $stumm = array_values(array_intersect($betroffene, $mitKanon));

            $wirkungslos = (int) $r->doc_active !== 1
                || (int) $r->bindung_active !== 1
                || ($betroffene !== [] && count($stumm) === count($betroffene));

            if ($nurWirkungslos && ! $wirkungslos) {
                continue;
            }

            $zeilen[] = [
                'target_key' => $ziel,
                'ziel_art' => $istBereich ? 'bereich' : 'prompt',
                'wirkt_auf_prompt_keys' => count($betroffene),
                'slug' => (string) $r->slug,
                'kategorie' => (string) $r->category,
                'zeichen' => (int) $r->char_count,
                'mode' => (string) $r->mode,
                'weight' => (int) $r->weight,
                'quelle' => (string) $r->source,
                'bindung_active' => (bool) $r->bindung_active,
                'doc_active' => (bool) $r->doc_active,
                'stumm_wegen_kanon' => $stumm,
                'wirkungslos' => $wirkungslos,
            ];
        }

        $toteDocs = count(array_filter($zeilen, fn ($z) => ! $z['doc_active']));

        return ToolResult::success([
            'total' => count($zeilen),
            'wirkungslos' => count(array_filter($zeilen, fn ($z) => $z['wirkungslos'])),
            'auf_inaktive_dossiers' => $toteDocs,
            'bindungen' => $zeilen,
            'hinweis' => $toteDocs > 0
                ? $toteDocs.' Bindung(en) zeigen auf INAKTIVE Dossiers und liefern nichts — sie sehen '
                    .'im Browser trotzdem nach Verdrahtung aus. Das ist die stille Fehlerklasse aus dem '
                    .'155-Originale-Cutover.'
                : null,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'knowledge', 'bindung', 'wissen', 'einsatzort', 'diagnose'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.BIND', 'foodalchemist.knowledge.UNBIND', 'foodalchemist.knowledge_canon.GET'],
            'examples' => [
                'Welche Bindungen hängen am Bereich recipe?',
                'Zeig alle wirkungslosen Wissens-Bindungen',
                'Woran ist geschmacksbalance gebunden?',
            ],
        ];
    }
}
