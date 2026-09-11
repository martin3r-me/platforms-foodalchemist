<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KnowledgeBudget;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Das Wissensbudget eines Arbeitsschritts einstellen — der Hebel für Kosten gegen Qualität.
 *
 * Eine Zahl je Schritt, geteilt von Kanon und Suche. Mehr Zeichen heisst mehr Kontext und ein
 * teurerer Call; weniger heisst, dass optionales Wissen früher wegfällt. Diese Abwägung gehört
 * dem Betreiber — bis hierher stand sie in der Code-Config und brauchte einen Deploy.
 *
 * Global wie die Routings (keine team_id): nur das Master-Team ändert, alle lesen.
 *
 * ★ Der Riegel, der diesem Tool seinen Wert gibt: er meldet, wenn das neue Budget UNTER der
 * Pflichtmenge dieses Schritts liegt. `pflicht` wird nie gekappt — passt sie nicht, bricht der
 * Aufbau zur Laufzeit ab. Ohne Vorwarnung wäre das eine Einstellung, die den Schritt beim
 * nächsten Aufruf stilllegt, und niemand brächte es mit dieser Zahl in Verbindung.
 */
class KnowledgeBudgetPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_budget.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt das Wissensbudget (Zeichen) für EINEN Arbeitsschritt — eine Zahl, geteilt von Kanon '
            . 'und Suche. Global, nur das Master-Team darf schreiben. Ohne max_chars (oder mit reset=true) '
            . 'fällt der Schritt auf den ausgelieferten Standard zurück. Meldet als Fehler, wenn das Budget '
            . 'unter der Pflichtmenge liegt: `pflicht` wird nie gekappt, der Aufbau bräche stattdessen ab. '
            . 'Ohne prompt_key werden alle Schritte mit abweichendem Budget gelistet.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt_key' => ['type' => 'string', 'description' => 'Arbeitsschritt, z. B. recipe.generator (leer = nur listen)'],
                'max_chars' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Neues Budget in Zeichen'],
                'reset' => ['type' => 'boolean', 'description' => 'true → zurück auf den ausgelieferten Standard'],
                'trotzdem' => ['type' => 'boolean', 'description' => 'Budget auch unter der Pflichtmenge setzen (bewusste Entscheidung, der Schritt bricht dann ab)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $key = trim((string) ($arguments['prompt_key'] ?? ''));

        if ($key === '') {
            return ToolResult::success([
                'eingestellt' => KnowledgeBudget::eingestellt(),
                'standard_default' => KnowledgeBudget::DEFAULT_CHARS,
                'hinweis' => 'Nur abweichende Werte. Was hier fehlt, läuft auf dem ausgelieferten Standard.',
            ]);
        }

        if (! TeamScope::mayWrite(null, $team)) {
            return ToolResult::error('Das Wissensbudget ist global — nur das Master-Team darf es ändern.', 'FORBIDDEN');
        }

        $reset = ($arguments['reset'] ?? false) === true || ! array_key_exists('max_chars', $arguments);
        if ($reset) {
            KnowledgeBudget::setze($key, null);

            return ToolResult::success([
                'prompt_key' => $key, 'max_chars' => KnowledgeBudget::forKey($key), 'quelle' => 'standard',
            ]);
        }

        $chars = (int) $arguments['max_chars'];
        $pflicht = $this->pflichtZeichen($key, $team);
        if ($pflicht !== null && $chars < $pflicht && ($arguments['trotzdem'] ?? false) !== true) {
            return ToolResult::error(sprintf(
                'Budget %d liegt unter der Pflichtmenge von %d Zeichen für «%s». Pflichtwissen wird nie gekappt — '
                .'der Aufbau würde stattdessen abbrechen. Kanon kürzen, oder mit trotzdem=true bewusst setzen.',
                $chars, $pflicht, $key), 'VALIDATION_ERROR');
        }

        try {
            KnowledgeBudget::setze($key, $chars);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'prompt_key' => $key,
            'max_chars' => $chars,
            'standard' => KnowledgeBudget::standardFuer($key),
            'pflicht_zeichen' => $pflicht,
            'quelle' => 'eingestellt',
        ]);
    }

    /** Pflichtmenge dieses Schritts, oder null wenn das Profil sie nicht kennt (dann kein Riegel). */
    private function pflichtZeichen(string $key, $team): ?int
    {
        try {
            $profil = app(WissensProfilService::class)->profil($key, $team);
        } catch (\Throwable) {
            return null;
        }

        return isset($profil['pflicht_zeichen']) ? (int) $profil['pflicht_zeichen'] : null;
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'config',
            'tags' => ['foodalchemist', 'knowledge', 'wissen', 'budget', 'kosten', 'konfiguration'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.PREVIEW', 'foodalchemist.knowledge_canon.PUT'],
            'examples' => ['Setze das Wissensbudget von recipe.generator auf 52000', 'Wieviel Budget weicht vom Standard ab?'],
        ];
    }
}
