<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\DatenwerkResolver;
use Platform\FoodAlchemist\Services\Knowledge\WissensGeltung;

/**
 * Spec 52/Grundsatz A — Datenwerke AUFLÖSEN, nicht suchen.
 *
 * Die Lücke, die dieses Werkzeug schliesst: ein Agent (Sidebar-Mikrofon, MCP) konnte bisher
 * nur `knowledge.SEARCH` rufen und bekam die PROSA eines Mengen-Dossiers zurück — eine
 * Markdown-Tabelle, aus der er die Zahl selbst herauslesen musste, und das auch nur, wenn
 * die Suche das richtige Dossier unter die ersten Treffer brachte. Genau davon soll ein
 * verbindlicher Standard nicht abhängen.
 *
 * Hier kommt stattdessen der strukturierte Wert: Bereich, Einheit, **Bezugsgrösse** und
 * Quelle. Der Bezug ist der eigentliche Gewinn — „180 g" allein sagt nicht, ob roh oder
 * gegart, pro Portion oder pro Ansatz. Bei 30 % Garverlust ist das der Unterschied zwischen
 * 180 g und 260 g Einkauf.
 *
 * Drei ehrliche Ausgänge statt einer plausiblen Zahl:
 *   · `aufgeloest`  — alle Quellen sind sich einig
 *   · `widerspruch` — zwei Dossiers sagen Verschiedenes: KEIN stiller Gewinner, beide sichtbar
 *   · `luecken`     — nichts gepflegt oder Bedingungen fehlen; es wird nichts geraten
 *
 * ⚠ Ohne Geltungs-Angabe gibt es keine Antwort, sondern eine Lücke. Das ist Absicht: eine
 * Portionsmenge ohne Gang und Rolle ist keine Auskunft, sondern ein Zufallswert.
 */
class DatenwerkGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.datenwerk.GET';
    }

    public function getDescription(): string
    {
        return 'Loest kuratierte Zahlen-Standards auf (Portionsmengen, Mengen-Faktoren, Garverluste) — '
            . 'ueber Geltungs-Achsen statt ueber eine Suche. Liefert je Kennzahl Wert/Bereich, Einheit, '
            . 'BEZUGSGROESSE (roh | gegart | trocken | mit_knochen | mit_schale | stueck | ganzes_tier) und '
            . 'Quelle. Widerspruechliche Quellen werden als solche gemeldet, fehlende als Luecke — es wird '
            . 'NIE ein Wert geschaetzt. Fuer Prosa-Wissen `knowledge.SEARCH` nehmen.';
    }

    public function getSchema(): array
    {
        $achsen = [];
        foreach (WissensGeltung::ACHSEN as $achse => $label) {
            $achsen[$achse] = ['type' => 'string', 'description' => "Geltungs-Achse {$label}."];
        }

        return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => $achsen + [
                'kennzahl' => ['type' => 'string', 'description' => 'Optionaler Filter auf eine Kennzahl, '
                    . 'z. B. portion.filet_rind.roh — Teiltreffer erlaubt (portion.filet).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $params = [];
        foreach (array_keys(WissensGeltung::ACHSEN) as $achse) {
            $wert = $arguments[$achse] ?? null;
            if (is_string($wert) && trim($wert) !== '') {
                $params[$achse] = trim($wert);
            }
        }

        $ergebnis = app(DatenwerkResolver::class)->resolve(
            app(KnowledgeContextService::class)->sichtbareDokumente($team), $params);

        $filter = is_string($arguments['kennzahl'] ?? null) ? trim($arguments['kennzahl']) : '';
        if ($filter !== '') {
            $ergebnis['ergebnisse'] = array_values(array_filter($ergebnis['ergebnisse'],
                static fn ($e) => str_contains((string) $e['kennzahl'], $filter)));
        }

        return ToolResult::success([
            'geltung' => $params,
            'treffer' => count($ergebnis['ergebnisse']),
            'ergebnisse' => $ergebnis['ergebnisse'],
            'luecken' => $ergebnis['luecken'],
            'hinweis' => $params === []
                ? 'Ohne Geltungs-Achse gibt es keine Auskunft — eine Portionsmenge ohne Gang und Rolle '
                    . 'waere ein Zufallswert. Mindestens eine Achse angeben (z. B. gang, komponentenrolle, '
                    . 'warengruppe, format).'
                : null,
        ]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['foodalchemist', 'wissen', 'datenwerk', 'mengen', 'nachschlagen'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.SEARCH', 'foodalchemist.knowledge.GET',
                'foodalchemist.knowledge.PREVIEW']];
    }
}
