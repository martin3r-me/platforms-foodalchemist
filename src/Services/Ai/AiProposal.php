<?php

namespace Platform\FoodAlchemist\Services\Ai;

/**
 * M0-14: Vorschlags-DTO des Gateways (GL-07 Propose-Phase).
 *
 * `callLogId` bleibt NULL, bis die Audit-Tabelle existiert (M7-01) —
 * Accept/Reject können erst danach stempeln.
 */
final readonly class AiProposal
{
    public function __construct(
        public array $werte,
        public float $confidence,
        public ?string $begruendung = null,
        public array $unknownSlugs = [],
        public ?string $model = null,
        public int $elapsedMs = 0,
        public ?int $callLogId = null,
    ) {
    }
}
