<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\Core\Contracts\EmbeddingProviderContract;

/**
 * Deterministischer Embedding-Provider für Tests — kein HTTP, kein API-Key.
 *
 * Erzeugt einen Bag-of-Words-Vektor über gehashte Tokens (feste Dimension):
 * gleicher Text ⇒ gleicher Vektor, Token-Overlap ⇒ hohe Cosine-Similarity.
 * Validiert die Verdrahtung (Service + Store + Hybrid-Gate), NICHT die
 * tatsächliche semantische Qualität des echten Embedders — die hängt an der
 * Live-API und wird nach dem Deploy gegen echte Pairing-Fälle geprüft.
 */
class FakeEmbeddingProvider implements EmbeddingProviderContract
{
    public function __construct(private readonly int $dimensions = 64) {}

    public function getName(): string
    {
        return 'fake';
    }

    public function getModel(): string
    {
        return 'fake-bow-1';
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function isNormalized(): bool
    {
        return false;
    }

    public function getMaxBatchSize(): int
    {
        return 256;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param  string[]  $texts
     * @return float[][]
     */
    public function embed(array $texts, string $type = 'document'): array
    {
        return array_map(fn ($t) => $this->vector((string) $t), array_values($texts));
    }

    /** @return float[] */
    private function vector(string $text): array
    {
        $vec = array_fill(0, $this->dimensions, 0.0);

        $text = mb_strtolower($text);
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        foreach (preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
            if (mb_strlen($tok) < 3) {
                continue;
            }
            $idx = (int) (hexdec(substr(md5($tok), 0, 8)) % $this->dimensions);
            $vec[$idx] += 1.0;
        }

        return $vec;
    }
}
