<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Models\Team;

/** Spec 94 · foodalchemist.konfigurator_presentation.PUBLISH */
class KonfiguratorPresentationPublishTool extends KonfiguratorToolBasis
{
    protected function ressource(): string
    {
        return 'konfigurator_presentation';
    }

    protected function verb(): string
    {
        return 'PUBLISH';
    }

    protected function beschreibung(): string
    {
        return 'Kundenlink veröffentlichen (Preistabelle wird neu gerechnet, kein EK im Link). Optional vorher setzen: verantwortlich_user_id (bekommt die Anfragen per Mail, Mitglied des Teams) und gueltig_bis (YYYY-MM-DD, zugleich Ablauf des Links). expires_at übersteuert den Ablauf; ohne beides 6 Monate. design, slug optional.';
    }

    protected function felder(): array
    {
        return [['id' => ['type' => 'integer'], 'verantwortlich_user_id' => ['type' => 'integer', 'description' => '„Anfragen gehen an“'],
            'gueltig_bis' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'expires_at' => ['type' => 'string'], 'design' => ['type' => 'string'], 'slug' => ['type' => 'string']], ['id']];
    }

    protected function beispiele(): array
    {
        return ['Veröffentliche Konfigurator 3 bis 31.12.2026, Anfragen an Benutzer 7.'];
    }

    protected function ausfuehren(Team $team, array $a): mixed
    {
        return app(\Platform\FoodAlchemist\Services\KonfiguratorService::class)->veroeffentlichen($team, self::id($a),
            array_intersect_key($a, array_flip(['verantwortlich_user_id', 'gueltig_bis', 'expires_at', 'design', 'slug'])));
    }
}
