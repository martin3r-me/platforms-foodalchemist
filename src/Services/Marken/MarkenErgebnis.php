<?php

namespace Platform\FoodAlchemist\Services\Marken;

/**
 * Ergebnis von {@see MarkeAusArtikel}: die Marke für die GP-Klammer „/ (…)“ und woher sie kommt. `marke` null = offen (Händler ohne Marke
 * im Artikel) — dann bleibt die Klammer leer und eine Rezepturware bekommt `marke_offen`.
 */
final class MarkenErgebnis
{
    public const QUELLE_NECTA = 'necta_brand';

    public const QUELLE_KUERZEL = 'kuerzel';

    public const QUELLE_KURZNAME = 'kurzname';

    public const QUELLE_OFFEN = 'offen';

    public function __construct(
        public readonly ?string $marke,
        public readonly string $quelle,
        public readonly ?string $roh = null,   // wie es im Artikel stand (Kürzel bzw. Necta-Wert), für Begründung und Prüfung
    ) {}

    public function offen(): bool
    {
        return $this->marke === null;
    }

    /** @return array{marke: ?string, quelle: string, roh: ?string, offen: bool} */
    public function toArray(): array
    {
        return ['marke' => $this->marke, 'quelle' => $this->quelle, 'roh' => $this->roh, 'offen' => $this->offen()];
    }
}
