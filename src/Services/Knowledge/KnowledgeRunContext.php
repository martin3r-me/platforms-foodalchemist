<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

/** Nur innerhalb einer expliziten Lauf-Grenze aktiv; finally verhindert Worker-Leaks. */
final class KnowledgeRunContext
{
    private ?RecipeKnowledgeRun $run = null;

    public function current(?int $teamId = null): ?RecipeKnowledgeRun
    {
        if ($this->run !== null && $teamId !== null && $this->run->teamId !== $teamId) {
            throw new \RuntimeException('Wissenslauf gehört zu einem anderen Team.');
        }
        return $this->run;
    }

    public function within(?RecipeKnowledgeRun $run, callable $action): mixed
    {
        $previous = $this->run;
        $this->run = $run;
        try {
            return $action();
        } finally {
            $this->run = $previous;
        }
    }
}
