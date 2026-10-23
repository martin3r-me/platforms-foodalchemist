<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KiAbrechnungService;

/**
 * KI-Verbrauch des eigenen Teams (Dominique 23.10.2026): Euro und Aufrufe, je Art; das Kunden-Hauptteam sieht den ganzen Mandanten
 * und je Standort, ein Standort nur sich. Dieselben Zahlen wie Einstellungen → KI. Read-only.
 */
class KiVerbrauchGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ki_verbrauch.GET';
    }

    public function getDescription(): string
    {
        return 'KI-Verbrauch des eigenen Teams in Euro (Preis und Kurs zum Zeitpunkt des Aufrufs) und Aufrufen, je Art (Klasse S/M/L, Sprache, Bild). '
            .'Das Kunden-Hauptteam sieht den ganzen Mandanten und je Standort, ein Standort nur sich selbst. Für Projekt-Mandanten mit Monatsdeckel (Betrag, Verbrauch). '
            .'zeitraum: monat (Standard) | vormonat | 30 | 90. Embeddings (Wissenssuche) sind nicht enthalten.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'zeitraum' => ['type' => 'string', 'enum' => ['monat', 'vormonat', '30', '90'], 'default' => 'monat'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        [$von, $bis] = match ((string) ($arguments['zeitraum'] ?? 'monat')) {
            'vormonat' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
            '30' => [now()->subDays(30), now()],
            '90' => [now()->subDays(90), now()],
            default => [now()->startOfMonth(), now()],
        };
        $v = app(KiAbrechnungService::class)->fuerTeam($team, $von, $bis);
        $knapp = fn (array $z) => ['label' => $z['label'], 'aufrufe' => $z['calls'] - $z['fehler'], 'fehllaeufe' => $z['fehler'], 'eur' => round($z['eur'], 2),
            'ohne_preis' => $z['ohne_preis']];

        return ToolResult::success([
            'von' => $von->toDateString(), 'bis' => $bis->toDateString(), 'ist_hauptteam' => $v['ist_haupt'],
            'summe' => $knapp($v['summe']),
            'je_art' => array_map($knapp, $v['klassen']),
            'je_standort' => array_map(fn ($s) => ['team_id' => $s['team_id']] + $knapp($s), $v['standorte']),
            'deckel' => $v['deckel'],
            'hinweis' => 'Embeddings (Wissenssuche) werden noch nicht erfasst und fehlen im Betrag.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'ki', 'verbrauch', 'kosten', 'abrechnung'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.mandanten.GET'],
            'examples' => ['Was hat KI uns diesen Monat gekostet?', 'Wie viel KI-Kontingent der Projektphase ist noch frei?'],
        ];
    }
}
