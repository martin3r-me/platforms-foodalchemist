<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * Ein Wissenskanal mit strukturellen Quellgrenzen. Markdown im Dossier darf selbst
 * Überschriften und Trenner enthalten; daraus werden niemals Grenzen erraten.
 */
final class KnowledgeContextBlock
{
    /** @param list<array{file: ?string, text: string}> $documents */
    public function __construct(
        public readonly string $header,
        public readonly array $documents,
        public readonly string $separator = "\n\n---\n\n",
    ) {}

    public function text(): string
    {
        return $this->documents === [] ? '' : $this->header.implode($this->separator, array_column($this->documents, 'text'));
    }

    /** @param list<int> $indices */
    public function select(array $indices): self
    {
        return new self($this->header, array_values(array_intersect_key($this->documents, array_flip($indices))), $this->separator);
    }

    /** @param list<self> $blocks */
    public static function join(array $blocks): string
    {
        return implode("\n\n", array_values(array_filter(array_map(static fn (self $block) => $block->text(), $blocks), static fn ($text) => $text !== '')));
    }

    /**
     * Pflichtquellen reservieren, dann optionale Quellen in Prioritätsreihenfolge
     * ergänzen. Die Größenrechnung benutzt denselben Renderer wie das Ergebnis.
     *
     * @param list<self> $blocks
     * @param list<string> $requiredFiles
     * @return array{block: string, files_used: list<string>, required_chars: int}
     */
    public static function assemble(array $blocks, array $requiredFiles, int $budget, string $feature, bool $callerOverride = false): array
    {
        $indices = [];
        $selected = [];
        foreach ($blocks as $key => $block) {
            $indices[$key] = array_keys(array_filter($block->documents, static fn ($document) => ($document['required'] ?? false) || in_array($document['file'], $requiredFiles, true)));
            $selected[$key] = $block->select($indices[$key]);
        }
        $requiredChars = mb_strlen(self::join($selected));
        // Ein Kompositions-Override darf Pflichtwissen nicht verkleinern. Die
        // konfigurierte Obergrenze bleibt verbindlich und wird nicht hochgesetzt.
        if ($callerOverride) {
            $budget = max($budget, $requiredChars);
        }
        if ($requiredChars > $budget) {
            throw new KnowledgeBudgetExceeded($feature, $requiredChars, $budget);
        }
        foreach ($blocks as $key => $block) {
            foreach ($block->documents as $index => $document) {
                if (in_array($index, $indices[$key], true)) {
                    continue;
                }
                $candidate = $selected;
                $candidate[$key] = $block->select([...$indices[$key], $index]);
                if (mb_strlen(self::join($candidate)) <= $budget) {
                    $indices[$key][] = $index;
                    $selected = $candidate;
                }
            }
        }
        $files = [];
        foreach ($selected as $block) {
            foreach ($block->documents as $document) {
                if ($document['file'] !== null) {
                    $files[] = $document['file'];
                }
            }
        }

        return ['block' => self::join($selected), 'files_used' => $files, 'required_chars' => $requiredChars];
    }
}
