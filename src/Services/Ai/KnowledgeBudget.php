<?php

namespace Platform\FoodAlchemist\Services\Ai;

/** Eine Zahl je Prompt-Key, gemeinsam für Kanon und Retrieval. */
final class KnowledgeBudget
{
    public const DEFAULT_CHARS = 16200;

    public static function promptKey(string $key): string
    {
        return $key === 'ai_generate_recipe' ? 'recipe.generator' : $key;
    }

    public static function forKey(string $key): int
    {
        $config = (array) config('foodalchemist.ai.knowledge_budget', []);
        $key = self::promptKey($key);
        $budget = (int) ($config[$key] ?? $config['default'] ?? self::DEFAULT_CHARS);
        if ($budget < 1) throw new \RuntimeException("Wissensbudget für «{$key}» muss positiv sein.");
        return $budget;
    }
}
