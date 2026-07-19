<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DishReverseService;

/**
 * R6.9 (Pairing-Offense): Dish-Reverse-Engineering — fremdes Gericht (Text/Karte)
 * → Zerlegung in eigene GPs → Aroma-Skelett aus dem Pairing-Graph → Nachbau-Kandidaten
 * aus dem EIGENEN VK-Portfolio + Lücken-Report. Unmatched → Beschaffungs-Wunsch
 * (kein Raten). READ-ONLY: die Draft-Anlage des Nachbaus ist ein expliziter
 * Folgeschritt (foodalchemist.recipes.POST). v1 Text-Input; Foto = Ausbaustufe.
 */
class DishReverseTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.dish.REVERSE';
    }

    public function getDescription(): string
    {
        return 'Zerlegt die Textbeschreibung eines (fremden) Gerichts in eigene Grundprodukte, '
            . 'leitet das Aroma-Skelett aus dem Pairing-Graph ab und schlägt Nachbau-Kandidaten '
            . 'aus dem eigenen VK-Portfolio vor (+ Lücken: welche Aroma-Anker der Bestand nicht trägt). '
            . 'Nicht zuordenbare Zutaten ohne Lieferantenartikel werden als Beschaffungs-Wunsch gelistet '
            . '(kein Raten). Read-only — den Nachbau als Draft anlegen via foodalchemist.recipes.POST.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'description' => 'Gericht-/Kartenbeschreibung als Freitext (Zeilen/Kommas werden zerlegt)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 8, 'description' => 'Anzahl Nachbau-Kandidaten'],
            ],
            'required' => ['text'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $text = trim((string) ($arguments['text'] ?? ''));
        if ($text === '') {
            return ToolResult::error('text ist erforderlich.', 'VALIDATION_ERROR');
        }
        $limit = min(20, max(1, (int) ($arguments['limit'] ?? 8)));

        return ToolResult::success(app(DishReverseService::class)->reverse($team, $text, $limit));
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'pairing', 'reverse-engineering', 'nachbau', 'aroma', 'gericht'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.substitution.SUGGEST', 'foodalchemist.pairings.SUGGEST', 'foodalchemist.recipes.POST'],
            'examples' => [
                'Bau mir das aus unserem Bestand nach: Rote Bete, Ziegenkäse, Walnuss, Honig',
                'Zerlege diese fremde Karte in unsere Grundprodukte und zeig die Lücken.',
            ],
        ];
    }
}
