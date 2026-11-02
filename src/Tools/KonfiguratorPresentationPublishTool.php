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
        $svc = app(\Platform\FoodAlchemist\Services\KonfiguratorService::class);
        $k = $svc->finde($team, self::id($a));
        $kopf = array_intersect_key($a, array_flip(['verantwortlich_user_id', 'gueltig_bis']));
        if ($kopf !== []) {
            $k = $svc->aendern($team, $k->id, $kopf);   // „Anfragen gehen an“ und „Gültig bis“ vor dem Einfrieren setzen
        }
        $ablauf = $a['expires_at'] ?? $k->gueltig_bis?->endOfDay()->toIso8601String() ?? now()->addMonths(6)->toIso8601String();
        $erg = app(\Platform\FoodAlchemist\Services\PresentationService::class)->publish($team, \Platform\FoodAlchemist\Services\PresentationService::TYPE_KONFIGURATOR, $k->id,
            array_filter(['expires_at' => $ablauf, 'design' => $a['design'] ?? ($k->presentation_design ?: 'konfigurator'), 'slug' => $a['slug'] ?? null], fn ($v) => $v !== null) + ['price_mode' => 'auto']);
        $k = $svc->aendern($team, $k->id, ['status' => 'live']);

        return $erg + ['verantwortlich_user_id' => $k->verantwortlich_user_id, 'gueltig_bis' => $k->gueltig_bis?->format('Y-m-d')];
    }
}
