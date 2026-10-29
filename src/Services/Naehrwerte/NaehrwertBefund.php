<?php

namespace Platform\FoodAlchemist\Services\Naehrwerte;

/**
 * Ein Befund der Nährwert-Prüfung an EINEM Artikel ({@see NaehrwertPruefService}).
 *
 * schwere: `hart` = der Wert ist physikalisch/gesetzlich unmöglich oder weicht klar ab → der Artikel zählt (modus scharf) nicht in den
 * GP-Nährwerten · `hinweis` = auffällig, rechnet weiter mit · `umrechnung` = eindeutige Einheiten-Probe, gerechnet wird mit `vorschlag.werte`.
 */
final class NaehrwertBefund
{
    public const HART = 'hart';

    public const HINWEIS = 'hinweis';

    public const UMRECHNUNG = 'umrechnung';

    /** @param  array{faktor?: int, werte?: array<string, float>}|null  $vorschlag */
    public function __construct(
        public readonly string $regel,
        public readonly string $schwere,
        public readonly string $feld,
        public readonly float|string|null $wert,
        public readonly string $erwartet,
        public readonly string $grund,
        public readonly ?array $vorschlag = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['regel' => $this->regel, 'schwere' => $this->schwere, 'feld' => $this->feld, 'wert' => $this->wert,
            'erwartet' => $this->erwartet, 'grund' => $this->grund, 'vorschlag' => $this->vorschlag];
    }
}
