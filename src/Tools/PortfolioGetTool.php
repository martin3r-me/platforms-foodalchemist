<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PortfolioService;

/**
 * Spec 33 · P8 (read): wer fährt gerade was — Foodbook, Speisekarte und Speiseplan in einer
 * Zeilenform, zum frei wählbaren Stichtag.
 *
 * Die drei Befunde kommen **mit**, nicht auf Nachfrage: Konflikte (zwei laufende Ausgaben
 * derselben Art in derselben Zuordnung), Lücken (Zuordnung ohne laufende Ausgabe) und Ausgaben
 * ohne jede Zuordnung. Eine reine Liste beantwortet die Steuerungsfrage nicht — der Wert der
 * Konzern-Sicht liegt darin, was daran nicht stimmt.
 */
class PortfolioGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.portfolio.GET';
    }

    public function getDescription(): string
    {
        return 'Portfolio-Steuerung: welche Ausgaben (Foodbook, Speisekarte, Speiseplan) laufen '
            . 'am Stichtag, je Betrieb und je Kunde. Liefert zusätzlich `konflikte` (zwei laufende '
            . 'Ausgaben derselben Art in derselben Zuordnung — Hinweis, kein Fehler), `luecken` '
            . '(Zuordnung ohne laufende Ausgabe) und `ohne_zuordnung` (weder Betrieb noch Kunde — '
            . 'in keiner Brille sichtbar). „Läuft" heißt Status `aktiv` UND Datum im '
            . 'Gültigkeitsfenster; abgelaufene Fenster laufen nicht, auch bei Status aktiv. Der '
            . 'Abruf ändert nie einen Status.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'stichtag' => ['type' => 'string', 'description' => 'YYYY-MM-DD, Default heute'],
                'art' => ['type' => 'string', 'enum' => ['foodbook', 'speisekarte', 'speiseplan'],
                    'description' => 'Nur eine Ausgabeform'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Nur einen Betrieb'],
                'nur_laufend' => ['type' => 'boolean', 'description' => 'Default false — sonst kommt auch, was NICHT läuft, mit Grund'],
                'brille' => ['type' => 'string', 'enum' => ['betrieb', 'kunde'],
                    'description' => 'Achse für die Lücken-Analyse, Default betrieb'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $portfolio = app(PortfolioService::class);
        $tag = ($arguments['stichtag'] ?? '') !== '' ? (string) $arguments['stichtag'] : null;
        $brille = ($arguments['brille'] ?? 'betrieb') === 'kunde' ? 'kunde' : 'betrieb';

        $filter = array_filter([
            'art' => ($arguments['art'] ?? null) ?: null,
            'outlet_id' => ($arguments['outlet_id'] ?? null) !== null ? (int) $arguments['outlet_id'] : null,
            'nur_laufend' => (bool) ($arguments['nur_laufend'] ?? false) ?: null,
        ], fn ($v) => $v !== null);

        $zeilen = $portfolio->uebersicht($team, $tag, $filter);

        return ToolResult::success([
            'stichtag' => $tag ?? now()->toDateString(),
            'brille' => $brille,
            'n_gesamt' => count($zeilen),
            'n_laufend' => count(array_filter($zeilen, fn ($z) => $z['laeuft'])),
            'ausgaben' => array_map(fn ($z) => [
                'art' => $z['art'], 'id' => $z['id'], 'name' => $z['name'],
                'status' => $z['status'], 'laeuft' => $z['laeuft'],
                // Warum etwas NICHT läuft, ist die eigentliche Auskunft: entwurf, inaktiv oder
                // abgelaufen sind drei verschiedene Sachverhalte mit verschiedenen Konsequenzen.
                'zustand' => $z['zustand'], 'grund' => $z['grund'],
                'von' => $z['von'], 'bis' => $z['bis'],
                'outlet_id' => $z['outlet_id'], 'outlet_name' => $z['outlet_name'],
                'kunde' => $z['kunde'], 'n_positionen' => $z['n_positionen'],
            ], $zeilen),
            'konflikte' => array_map(fn ($k) => [
                'brille' => $k['brille'], 'zuordnung' => $k['zuordnung'], 'art' => $k['art'],
                'ausgaben' => array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $k['ausgaben']),
            ], $portfolio->konflikte($team, $tag)),
            'luecken' => $portfolio->luecken($team, $brille, $tag),
            'ohne_zuordnung' => array_map(
                fn ($z) => ['art' => $z['art'], 'id' => $z['id'], 'name' => $z['name'], 'laeuft' => $z['laeuft']],
                $portfolio->ohneZuordnung($team, $tag),
            ),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'controlling', 'portfolio', 'ausgaben', 'betrieb', 'outlet', 'aktiv'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'read',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.portfolio_promotion.GET', 'foodalchemist.speisekarte.GET'],
            'examples' => [
                'Welche Speisekarten laufen heute in welchem Betrieb?',
                'Wo läuft im September nichts?',
                'Gibt es Betriebe mit zwei parallelen Karten?',
            ],
        ];
    }
}
