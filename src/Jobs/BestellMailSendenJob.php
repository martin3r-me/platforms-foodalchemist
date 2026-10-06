<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Services\OrderMailService;

/** Spec 63: versendet einen geplanten Protokoll-Eintrag (Bestellung/Storno) außerhalb des Requests. */
class BestellMailSendenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public int $mailId)
    {
    }

    public function handle(OrderMailService $service): void
    {
        $service->senden($this->mailId);
    }
}
