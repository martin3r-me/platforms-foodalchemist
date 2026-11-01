<?php

namespace Platform\FoodAlchemist\Support;

/**
 * Quellen eines KI-gesetzten GP-Kern-Ankers ({@see \Platform\FoodAlchemist\Services\PairingService::setGpAnkerInference()}).
 *
 * Pate (Entscheid Dominique 01.11.2026): die Zutat hat keinen eigenen Inspire-Anker, die KI nimmt einen vergleichbaren Ersatz (gleiche
 * Produktgruppe, ähnlicher Geschmack, ähnliche Funktion — Dorade → Wolfsbarsch ja, Feta → Halloumi nein). Eigene Quelle, damit er vom
 * echten Anker unterscheidbar und tauschbar bleibt; Gegenstück der kuratierten Behelfsanker aus der Paket-Pflege (`inspire_behelf`).
 */
final class AnkerQuelle
{
    /** KI hat den eigenen Anker der Zutat gefunden. */
    public const KI = 'ai_inferred';

    public const PATE = 'ai_pate';

    /** Quellen, die die KI selbst setzt und ersetzen darf (eine Neu-Schätzung verdrängt beide, Handarbeit nie). */
    public const KI_QUELLEN = [self::KI, self::PATE];

    /** Anker-Quellen, die einen Ersatz statt des eigenen Ankers tragen (Übersicht „Pate", GP-Dialog). */
    public const PATEN_QUELLEN = [self::PATE, 'inspire_behelf'];
}
