<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Zahl je Prompt-Key, gemeinsam für Kanon und Retrieval.
 *
 * Rangfolge: eingestellter Wert (`foodalchemist_knowledge_budgets`) → Config → Default. Die
 * Tabelle ist der Hebel für Kosten gegen Qualität und gehört dem Betreiber; die Config bleibt
 * der ausgelieferte Startwert, den ein frisches System ohne jede Pflege bekommt.
 *
 * Warum überhaupt einstellbar: die Wissens-Steuerung zeigte je Schritt „Σ Pflicht / Budget" —
 * und wer sah, dass ein Schritt an seiner Grenze steht, konnte nichts tun ausser einen Deploy
 * bestellen. Ein Grenzwert, den man sieht aber nicht bewegt, ist eine Diagnose ohne Therapie.
 *
 * Der Cache gilt PRO REQUEST: ein Aufruf baut oft ein Dutzend Kontexte, und das Budget ändert
 * sich innerhalb eines Requests nicht. `vergiss()` ist der Rückweg für den Editor und für Tests.
 */
final class KnowledgeBudget
{
    public const DEFAULT_CHARS = 16200;

    /** @var array<string, int>|null Request-Cache; null = noch nicht gelesen. */
    private static ?array $eingestellt = null;

    public static function promptKey(string $key): string
    {
        return $key === 'ai_generate_recipe' ? 'recipe.generator' : $key;
    }

    public static function forKey(string $key): int
    {
        $key = self::promptKey($key);
        $budget = self::eingestellt()[$key] ?? null;
        if ($budget === null) {
            $config = (array) config('foodalchemist.ai.knowledge_budget', []);
            $budget = (int) ($config[$key] ?? $config['default'] ?? self::DEFAULT_CHARS);
        }
        if ($budget < 1) {
            throw new \RuntimeException("Wissensbudget für «{$key}» muss positiv sein.");
        }

        return $budget;
    }

    /** Der ausgelieferte Startwert — für den Editor, der „eingestellt" von „Standard" trennen muss. */
    public static function standardFuer(string $key): int
    {
        $config = (array) config('foodalchemist.ai.knowledge_budget', []);
        $key = self::promptKey($key);

        return (int) ($config[$key] ?? $config['default'] ?? self::DEFAULT_CHARS);
    }

    /** @return array<string, int> nur die ABWEICHENDEN Werte (leer = alles auf Standard) */
    public static function eingestellt(): array
    {
        if (self::$eingestellt !== null) {
            return self::$eingestellt;
        }
        // Fail-soft wie der Rest der Wissens-Schicht: fehlt die Tabelle (frische DB, Migration
        // noch nicht durch), gilt die Config. Ein Budget darf nie der Grund sein, dass ein
        // Prompt gar nicht erst gebaut wird.
        if (! Schema::hasTable('foodalchemist_knowledge_budgets')) {
            return self::$eingestellt = [];
        }

        return self::$eingestellt = DB::table('foodalchemist_knowledge_budgets')
            ->pluck('max_chars', 'prompt_key')
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Setzt oder entfernt einen Wert. `null` = zurück auf den ausgelieferten Standard —
     * bewusst ein eigener Zustand und nicht „0", damit „ich will die Config" sich vom
     * „ich will ausdrücklich diese Zahl" unterscheidet.
     */
    public static function setze(string $key, ?int $chars): void
    {
        $key = self::promptKey($key);
        if ($chars === null) {
            DB::table('foodalchemist_knowledge_budgets')->where('prompt_key', $key)->delete();
            self::vergiss();

            return;
        }
        if ($chars < 1) {
            throw new \RuntimeException("Wissensbudget für «{$key}» muss positiv sein.");
        }
        DB::table('foodalchemist_knowledge_budgets')->updateOrInsert(
            ['prompt_key' => $key],
            ['max_chars' => $chars, 'updated_at' => now(), 'created_at' => now()],
        );
        self::vergiss();
    }

    public static function vergiss(): void
    {
        self::$eingestellt = null;
    }
}
