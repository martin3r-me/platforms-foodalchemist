<?php

namespace Platform\FoodAlchemist\Exceptions;

/**
 * Spec 53 / Paket C: Server-Guard gegen Doppel-Enqueue (regeneriereStep/reAnreichern/reBilder/
 * konformitaetPruefen) — ein zweiter Klick, während der erste Versuch noch läuft. Typisiert,
 * damit die Livewire-Aufrufer das als neutralen Hinweis ($meldung) statt als Fehler ($fehler)
 * anzeigen können (kein rotes Banner für „läuft schon", nur für echte Fehlschläge).
 */
class PlanungAktionLaeuftBereitsException extends \RuntimeException
{
}
