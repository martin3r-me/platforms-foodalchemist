<?php

namespace Platform\FoodAlchemist\Services\Ai;

final class KnowledgeBudgetExceeded extends \RuntimeException
{
    public function __construct(public readonly string $feature, public readonly int $requiredChars, public readonly int $budget)
    {
        parent::__construct("Wissensbudget für «{$feature}» zu klein: Pflichtwissen benötigt {$requiredChars} Zeichen, verfügbar sind {$budget}. Wissensprofil verkleinern oder Budget anpassen.");
    }
}
