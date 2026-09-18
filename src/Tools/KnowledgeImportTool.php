<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * Briefing Zutaten-Bulk-Import, 2026-09-18: 11.966 Zutaten-Dossiers liegen fertig gebaut im
 * Vault. `knowledge.POST` legt EIN Dokument an — 11.966 Einzelaufrufe sind über MCP nicht
 * vertretbar, und bei einem Abbruch mittendrin gibt es keinen Wiederaufsetzpunkt.
 *
 * ★ Idempotent über den Slug per Content-Hash: derselbe Lauf lässt sich beliebig oft
 * wiederholen — bereits geschriebene Einträge melden beim nächsten Versuch `unveraendert`,
 * kein Doppel-Schreiben, keine Versions-Inflation.
 *
 * ★ Kein zweiter Schreibpfad im Sinne von "andere Regeln": dieselbe Kategorie-/Wissensart-
 * Prüfung wie `knowledge.POST`/`knowledge.PUT` ({@see KnowledgeService::import()}), nur mit
 * eigener, für den Massenfall zugeschnittener Slug-/Versions-Logik (Begründung dort).
 *
 * ★ Ein Fehler kippt nicht den Block: jeder Eintrag wird einzeln verbucht und gemeldet
 * (`angelegt|aktualisiert|unveraendert|abgelehnt` + Grund bei Ablehnung).
 *
 * ★ Aktiviert nichts: Import legt standardmäßig INAKTIV an (`active` fehlt → false). Freischalten
 * ist eine eigene, spätere Entscheidung — `knowledge.SET_ACTIVE` (mit `slugs[]` für Blöcke).
 *
 * ★ Embedding läuft synchron zum Schreiben AN (als Queue-Job, nicht blockierend) — für
 * `active=false`-Einträge (Regelfall) ist das ein günstiger No-op, das eigentliche Embedding
 * entsteht bei der Aktivierung.
 *
 * ★ Keine Aliasse in diesem Tool (Entscheid Dominique, 2026-09-18: Zutaten-Dossiers tragen
 * keine — der Name steht ohnehin in Titel/H1/thema). Wer Aliasse braucht, nutzt `knowledge.ALIAS`
 * danach — mit `action=check` vorher gegen stille Kollisionen prüfen.
 */
class KnowledgeImportTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge.IMPORT';
    }

    public function getDescription(): string
    {
        return 'Legt MEHRERE Wissens-Dossiers in einem Aufruf an oder aktualisiert sie (100 bis '
            . KnowledgeService::IMPORT_MAX . ' je Aufruf) — der Massen-Einstieg fuer Import-Chargen, '
            . 'fuer die knowledge.POST (ein Dokument je Aufruf) nicht praktikabel ist. Idempotent ueber '
            . 'den Slug per Content-Hash: derselbe Aufruf laesst sich beliebig oft wiederholen, ein '
            . 'unveraenderter Eintrag wird nicht neu geschrieben. Rueckgabe je Eintrag: Status '
            . 'angelegt|aktualisiert|unveraendert|abgelehnt (mit Grund: Kategorie unbekannt, Wissensart '
            . 'ungueltig, Slug-Muster, > 4.000 Zeichen, Pflichtfeld fehlt, fremdes Team). Ein '
            . 'fehlerhafter Eintrag kippt den Block NICHT. Legt standardmaessig INAKTIV an (active '
            . 'weglassen oder false) — Freischalten ist eine eigene Entscheidung, siehe '
            . 'knowledge.SET_ACTIVE mit slugs[] fuer blockweises Aktivieren. Keine Aliasse hier, dafuer '
            . 'knowledge.ALIAS (action=check vorher gegen Kollisionen). `frontmatter` wird '
            . 'entgegengenommen aber NICHT gespeichert — content_md traegt die YAML-Frontmatter bereits '
            . 'als Text, der Parameter ist reine Mitgabe fuer spaetere Auswertungen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'eintraege' => ['type' => 'array', 'description' => 'Die Dossiers, 1 bis '.KnowledgeService::IMPORT_MAX.' je Aufruf.',
                    'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                        'slug' => ['type' => 'string', 'description' => 'Eindeutiger Slug, z. B. "zutat.acerola_14--verwendung". a-z, 0-9, ".", "_", "-".'],
                        'title' => ['type' => 'string', 'description' => 'Deutscher Titel.'],
                        'category' => ['type' => 'string', 'description' => 'Wissens-Kategorie-Slug, muss existieren (foodalchemist.knowledge_categories.POST vorher).'],
                        'art' => ['type' => 'string', 'description' => 'regel | datenwerk | fachwissen | referenz | ablauf. Leer = noch nicht eingeordnet.'],
                        'content_md' => ['type' => 'string', 'description' => 'Volltext inkl. YAML-Frontmatter, max. 4.000 Zeichen.'],
                        'frontmatter' => ['type' => 'object', 'description' => 'Optionale strukturierte Metadaten (z. B. anker_slug) — wird NICHT gespeichert, nur entgegengenommen.'],
                        'active' => ['type' => 'boolean', 'default' => false, 'description' => 'Standard: inaktiv (Entwurf). true legt sofort aktiv an inkl. Embedding.'],
                    ], 'required' => ['slug', 'title', 'category', 'content_md']]],
            ],
            'required' => ['eintraege'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $eintraege = $arguments['eintraege'] ?? null;
        if (! is_array($eintraege) || $eintraege === []) {
            return ToolResult::error('Keine Eintraege uebergeben.', 'VALIDATION_ERROR');
        }
        if (count($eintraege) > KnowledgeService::IMPORT_MAX) {
            return ToolResult::error(sprintf('Hoechstens %d Eintraege je Aufruf. Bitte in Bloecken fahren.', KnowledgeService::IMPORT_MAX), 'VALIDATION_ERROR');
        }

        $ergebnisse = app(KnowledgeService::class)->import($team, $eintraege);
        $zaehler = ['angelegt' => 0, 'aktualisiert' => 0, 'unveraendert' => 0, 'abgelehnt' => 0];
        foreach ($ergebnisse as $r) {
            $zaehler[$r['status']] = ($zaehler[$r['status']] ?? 0) + 1;
        }

        return ToolResult::success([
            ...$zaehler,
            'eintraege' => $ergebnisse,
            'hinweis' => $zaehler['abgelehnt'] > 0
                ? 'Abgelehnte Eintraege wurden uebersprungen, der Rest ist verbucht. Gruende stehen je Eintrag.'
                : null,
        ]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'command', 'tags' => ['foodalchemist', 'wissen', 'import', 'bulk', 'anlage'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'moderate',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.POST', 'foodalchemist.knowledge.SET_ACTIVE',
                'foodalchemist.knowledge.ALIAS', 'foodalchemist.knowledge_categories.POST']];
    }
}
