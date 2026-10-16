<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Job-Trait (KI-Abrechnung): rechnet KI-Aufrufe eines Queue-Jobs ohne angemeldeten Nutzer dem Team des Jobs zu (`$this->teamId`).
 * Ohne ihn stünde ein Massenlauf, den ein Mandant ausgelöst hat, als „plattform“ im Log und wäre nicht abrechenbar.
 */
trait MitKiVerursacher
{
    /** @return list<object> */
    public function middleware(): array
    {
        return [new class($this->teamId)
        {
            public function __construct(private readonly int $teamId) {}

            public function handle(object $job, callable $next): mixed
            {
                return KiVerursacher::imKontext($this->teamId, fn () => $next($job));
            }
        }];
    }
}
