<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/**
 * Spec 94 · foodalchemist.konfigurator_konzepte.WORDING_GENERATE — KI-Wording wie foodbook_kapitel.WORDING_GENERATE: je Concept
 * des Konfigurator-Konzepts ein propose('concept.wording') im gewählten Schreibstil; die Texte werden konfigurator-lokale
 * Überschreibungen (wie konfigurator_konzepte.WORDING). Extern über den MCP-Server umgeleitet (KiUmleitung::TOOLS).
 */
class KonfiguratorKonzepteWordingGenerateTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_konzepte';
    }

    protected function verb(): string
    {
        return 'WORDING_GENERATE';
    }

    protected function beschreibung(): string
    {
        return 'Betextet die Gerichte eines Konfigurator-Konzepts (konzept_id, Haupt oder Add-on) per KI im Schreibstil writing_style_id. '
            . 'Die Texte gelten nur in diesem Konfigurator (Überschreibung, wie konfigurator_konzepte.WORDING); das Concept bleibt unverändert. '
            . 'Gibt die Anzahl gesetzter Texte und die Überschreibungen zurück.';
    }

    protected function felder(): array
    {
        return [['konzept_id' => ['type' => 'integer'], 'writing_style_id' => ['type' => 'integer', 'description' => 'Schreibstil (foodalchemist.writing_styles.GET)']], ['konzept_id', 'writing_style_id']];
    }

    protected function beispiele(): array
    {
        return ['Betexte im Konfigurator-Konzept 9 die Gerichte im Schreibstil 3.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        $svc = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class);
        $n = $svc->wordingGenerieren($team, self::id($a, 'konzept_id'), self::id($a, 'writing_style_id'));
        $kz = \Platform\FoodAlchemist\Models\FoodAlchemistKonfiguratorKonzept::visibleToTeam($team)->find(self::id($a, 'konzept_id'));

        return ['konzept_id' => self::id($a, 'konzept_id'), 'gesetzt' => $n, 'wording_overrides' => $kz?->payload_json['wording_overrides'] ?? []];
    }

    public function getMetadata(): array
    {
        return ['cost_class' => 'llm', 'related_tools' => ['foodalchemist.konfigurator_konzepte.WORDING', 'foodalchemist.foodbook_kapitel.WORDING_GENERATE']] + parent::getMetadata();
    }
}
