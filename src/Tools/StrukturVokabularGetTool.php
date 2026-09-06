<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;

/**
 * Spec 50 · C-8 — Struktur-Vokabular als Read (deterministisch, kein Team-Bezug, kein Provider-Call).
 *
 * Bis hier steckten die kanonischen Header-Labels (30+, mit Slug-Lineage) in `FoodbookService::headerPresets()`
 * und wurden nur von zwei Livewire-Pickern gelesen; das Sektions-Gerüst der Formate hing am UI-Button, die
 * Gang-Leiter/Buffet-Stationen privat im Generator. Ein Agent musste Header-Titel als freien Text raten.
 * Dieses Tool liefert alle drei Vokabulare, damit `concept_blocks.POST`, `foodbook_blocks.POST`,
 * `format_blocks.POST` und `concepts.POST geruest` / `format_editions.POST neu.geruest` aus demselben
 * Wortschatz schöpfen wie die UI.
 */
class StrukturVokabularGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.struktur_vokabular.GET';
    }

    public function getDescription(): string
    {
        return 'Struktur-Vokabular für Header/Sektionen/Gerüste (read-only, deterministisch). '
            . 'header_presets: kanonische Header-Labels in Gruppen (Gänge/Service, Tageszeit, Konzept/Format mit Preis, Intern) '
            . 'mit slug (Lineage für header_source), label, type (header_neutral|header_frei_preis), price_basis, visible — '
            . 'für foodbook_blocks/concept_blocks/format_blocks/offer_block.POST statt frei erfundener Titel. '
            . 'sektions_geruest: UI-Standard-Sektionen einer Format-Edition. '
            . 'geruest_vorschau: kanonische Positionen je Gerüst-Typ (menue mit gaenge 3–9 = Gang-Leiter, buffet = Stationen mit '
            . 'target_count), exakt das, was concepts.POST geruest bzw. format_editions.POST neu.geruest anlegt. '
            . 'Optional typ + gaenge, um nur eine Vorschau zu holen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'typ' => ['type' => 'string', 'enum' => ['menue', 'buffet'], 'description' => 'optional: nur die Gerüst-Vorschau dieses Typs'],
                'gaenge' => ['type' => 'integer', 'description' => 'nur menue: Gangzahl 3–9 (Default 3)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $generator = app(ConceptGeneratorService::class);
        $typ = isset($arguments['typ']) ? (string) $arguments['typ'] : null;
        $gaenge = isset($arguments['gaenge']) ? (int) $arguments['gaenge'] : null;

        try {
            if ($typ !== null) {
                return ToolResult::success([
                    'geruest_vorschau' => [$typ => $generator->geruestVorschau($typ, $gaenge)],
                    'hinweis' => 'Anlegen: foodalchemist.concepts.POST geruest={typ,gaenge} (eigenständig) oder foodalchemist.format_editions.POST neu={name, geruest} (als Edition eines Formats).',
                ]);
            }

            $vorschau = ['buffet' => $generator->geruestVorschau('buffet')];
            foreach (range(3, 9) as $n) {
                $vorschau['menue_' . $n] = $generator->geruestVorschau('menue', $n);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'header_presets' => FoodbookService::headerPresets(),
            'sektions_geruest' => FormatService::SEKTIONS_GERUEST,
            'geruest_vorschau' => $vorschau,
            'hinweis' => 'Header-Titel aus header_presets[].label nehmen, slug als header_source-Lineage mitgeben. '
                . 'Gerüste per concepts.POST geruest bzw. format_editions.POST neu.geruest anlegen — Header-Titel dort = role = Label der Vorschau.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'vokabular', 'header', 'sektion', 'gerüst', 'gang', 'buffet', 'struktur', 'concept', 'format', 'foodbook'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => false,   // statisches Vokabular, kein Team-Bezug
            'cost_class' => 'local_db',
            'related_tools' => [
                'foodalchemist.concepts.POST', 'foodalchemist.concept_blocks.POST', 'foodalchemist.format_editions.POST',
                'foodalchemist.foodbook_blocks.POST', 'foodalchemist.format_blocks.POST', 'foodalchemist.offer_block.POST',
            ],
            'examples' => [
                'Welche Header-Titel gibt es kanonisch für Gänge und Tageszeiten?',
                'Wie sieht ein 5-Gang-Menü-Gerüst aus? (typ=menue, gaenge=5)',
                'Welche Stationen hat das Buffet-Gerüst?',
            ],
        ];
    }
}
