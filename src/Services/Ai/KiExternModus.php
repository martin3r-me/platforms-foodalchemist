<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * #1002: Schalter „KI-Antworten kommen von außen (MCP-Client) statt vom Plattform-Provider".
 * Nur innerhalb einer expliziten Grenze aktiv (within, finally räumt ab) — wie KnowledgeRunContext.
 */
final class KiExternModus
{
    private bool $aktiv = false;

    public function aktiv(): bool
    {
        return $this->aktiv;
    }

    public function within(callable $action): mixed
    {
        $vorher = $this->aktiv;
        $this->aktiv = true;
        try {
            return $action();
        } finally {
            $this->aktiv = $vorher;
        }
    }
}
