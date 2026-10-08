<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\EtikettService;

/** Spec 70 · Etikett erzeugen: Inhalt prüfen + Druck-/PDF-Link für Rezept, Grundprodukt oder Stellplatz. */
class LabelsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.labels.POST';
    }

    public function getDescription(): string
    {
        return 'Erzeugt Etiketten und liefert Druck- und PDF-Link. quelle: recipe (Basisrezept/Gericht — Allergene, Zutatenliste '
            . 'nach LMIV, verbrauchen bis aus der Haltbarkeit am Rezept), gp (Anbruch-Etikett) oder stellplatz (Regal-Etikett). '
            . 'Optional vorlage_id (sonst Standard), anzahl, startplatz (A4-Bogen), eingabe {bezeichnung, zusatz, menge, kuerzel, '
            . 'charge, lagerung gekuehlt|tiefgekuehlt|trocken, hergestellt_am, eingefroren_am, geoeffnet_am, verbrauchen_bis}. '
            . 'Gibt den Etikett-Inhalt (Allergene, Daten) zur Kontrolle mit zurück.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'quelle' => ['type' => 'string', 'enum' => ['recipe', 'gp', 'stellplatz']],
                'id' => ['type' => 'integer'],
                'vorlage_id' => ['type' => 'integer'],
                'anzahl' => ['type' => 'integer'],
                'startplatz' => ['type' => 'integer'],
                'eingabe' => ['type' => 'object'],
            ],
            'required' => ['quelle', 'id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(EtikettService::class);
        $quelle = (string) ($arguments['quelle'] ?? '');
        $id = (int) ($arguments['id'] ?? 0);
        $eingabe = array_intersect_key((array) ($arguments['eingabe'] ?? []), array_flip(['bezeichnung', 'zusatz', 'menge', 'kuerzel', 'charge', 'lagerung', 'hergestellt_am', 'eingefroren_am', 'geoeffnet_am', 'verbrauchen_bis', 'hersteller']));
        try {
            $druck = $svc->druck($team, isset($arguments['vorlage_id']) ? (int) $arguments['vorlage_id'] : null, $quelle, $id, $eingabe,
                (int) ($arguments['anzahl'] ?? 1), (int) ($arguments['startplatz'] ?? 1));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return ToolResult::error('Rezept, Grundprodukt, Stellplatz oder Vorlage nicht gefunden.', 'NOT_FOUND');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }
        $d = $druck['daten'];
        $args = [$quelle, $id, (int) $druck['vorlage']->id, $eingabe, $druck['anzahl'], $druck['startplatz']];

        return ToolResult::success([
            'druck_url' => $svc->druckUrl(...$args),
            'pdf_url' => $svc->druckUrl(...[...$args, true]),
            'vorlage' => $druck['vorlage']->name,
            'inhalt' => [
                'bezeichnung' => $d['bezeichnung'],
                'allergene' => array_column($d['allergene'], 'label'),
                'spuren' => array_column($d['spuren'], 'label'),
                'allergene_unvollstaendig' => $d['allergene_unbekannt'],
                'zusatzstoffe' => array_column($d['zusatzstoffe'], 'label'),
                'verbrauchen_bis' => $d['datum']['verbrauchen_bis'] ?? null ? $d['datum']['verbrauchen_bis']->toDateString() : null,
                'lagerung' => $d['lagerung'],
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'etikett', 'lager', 'allergene'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.label_templates.GET'],
            'examples' => ['Druck mir 6 Etiketten für die Kürbissuppe, eingefroren heute.', 'Regal-Etiketten für alle Stellplätze im Kühlhaus.'],
        ];
    }
}
