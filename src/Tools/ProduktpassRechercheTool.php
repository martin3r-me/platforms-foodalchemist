<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Produktpass\ProduktdatenQuelle;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassService;

/**
 * Web-Recherche zu einem Lieferantenartikel per MCP (#1002, 2d-2): derselbe Vorschlag wie der „Recherchieren"-Knopf im Produktpass-Panel
 * (ProduktpassService::vorschlagAusQuelle über die Quelle `websuche`), nur synchron. Per MCP läuft die Websuche extern: das Tool liefert einen
 * KI-Auftrag mit `web_suche=true`; der MCP-Client sucht selbst im Web und antwortet mit den tatsächlich gefundenen Seiten (`quellen`) —
 * eine Antwort ohne Quellen wird abgelehnt. Der Vorschlag hat Status `gelesen`; Übernehmen und Allergene bestätigen bleibt wie beim Produktpass.
 */
class ProduktpassRechercheTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.produktpass.RECHERCHE';
    }

    public function getDescription(): string
    {
        return 'Recherchiert im Web zu einem Lieferantenartikel (Allergene, Zusatzstoffe, Nährwerte, Zutatenliste, Gebinde, Haltbarkeit, Herkunft, Marke) '
            .'und legt einen Produktpass-Vorschlag (Status gelesen, Quelle websuche) an. Per MCP kommt zuerst ein KI-Auftrag (status ki_auftrag_offen, '
            .'web_suche=true): selbst im Web suchen und per foodalchemist.ki_auftraege.ANTWORT mit werte UND quellen (url, title der gefundenen Seiten) '
            .'antworten — ohne Quellen wird die Antwort abgelehnt; danach dieses Tool mit denselben Argumenten wiederholen. Nur belegte Werte, nichts raten. '
            .'Übernehmen/Bestätigen wie beim Produktpass (produktpass.GET, produktpass.ALLERGENE_BESTAETIGEN).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['supplier_item_id' => ['type' => 'integer', 'description' => 'ID des Lieferantenartikels']],
            'required' => ['supplier_item_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->find((int) ($arguments['supplier_item_id'] ?? 0));
        if ($item === null) {
            return ToolResult::error('Artikel nicht gefunden.', 'NOT_FOUND');
        }
        $quelle = null;
        foreach (app()->tagged('produktdaten.quellen') as $q) {
            if ($q instanceof ProduktdatenQuelle && $q->name() === 'websuche') {
                $quelle = $q;
            }
        }
        if ($quelle === null) {
            return ToolResult::error('Die Websuche ist nicht eingerichtet.', 'EXECUTION_ERROR');
        }
        try {
            $pass = app(ProduktpassService::class)->vorschlagAusQuelle($team, $item, $quelle, [], $context->user?->id);
        } catch (FaRechtFehltException $e) {
            return ToolResult::error($e->getMessage(), 'FORBIDDEN');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'EXECUTION_ERROR');
        }
        $extrakt = is_array($pass->extrakt) ? $pass->extrakt : [];

        return ToolResult::success([
            'produktpass_id' => $pass->id, 'version' => $pass->version, 'status' => $pass->status, 'quelle' => $pass->quelle,
            'felder' => array_keys((array) ($extrakt['felder'] ?? [])), 'quellen' => self::belegUrls($extrakt),
            'hinweis' => 'Vorschlag aus belegten Web-Quellen. Stand und Übernahme: foodalchemist.produktpass.GET; Allergene erst nach Bestätigung.',
        ]);
    }

    /** Die URLs, auf die sich die Angaben des Vorschlags stützen (je Feld/Allergen die `url`), je URL einmal. @return list<string> */
    private static function belegUrls(array $extrakt): array
    {
        $urls = [];
        array_walk_recursive($extrakt, function ($wert, $schluessel) use (&$urls) {
            if ($schluessel === 'url' && is_string($wert) && $wert !== '') {
                $urls[$wert] = true;
            }
        });

        return array_keys($urls);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action', 'tags' => ['foodalchemist', 'produktpass', 'lieferantenartikel', 'websuche', 'recherche', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'ai_call', 'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.produktpass.GET', 'foodalchemist.produktpass.ALLERGENE_BESTAETIGEN', 'foodalchemist.ki_auftraege.ANTWORT'],
            'examples' => ['Recherchiere im Web die Produktdaten zu Artikel 4711.'],
        ];
    }
}
