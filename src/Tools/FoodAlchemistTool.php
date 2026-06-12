<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;

/**
 * M8-01: Basis für Modul-Tools (ToolContract) — Naming `<modul>.resource.VERB`
 * (REST-Verben; Punkte werden vom MCP-Server zu __). Tools rufen SERVICES,
 * nie Models (LLM-First-Prinzip); Team kommt aus dem ToolContext.
 */
abstract class FoodAlchemistTool
{
    protected function team(ToolContext $context): ?Team
    {
        $team = $context->team;
        if ($team instanceof Team) {
            return $team;
        }
        // Kontext liefert je nach Aufrufpfad das Core-Team-Objekt oder nichts —
        // dann auf die User-Relation zurückfallen (gleiches Verhalten wie UI)
        $user = $context->user;

        return method_exists($user, 'currentTeamRelation') ? $user->currentTeamRelation : null;
    }
}
