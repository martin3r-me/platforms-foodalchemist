<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Spec 17 / S2 — Lebenszyklus einer Bestellschiene (N-Track, OHNE Bestand).
 * draft = offene Schiene, sammelt Bedarf (E1) · sent = versendet, Snapshot
 * eingefroren (E2) · confirmed = vom Lieferanten bestätigt · delivered =
 * manueller Haken (KEINE Bestandsbuchung, E4) · cancelled = storniert.
 * Nur `draft` ist editierbar/akkumulierend; alles andere ist read-only-Beleg.
 */
enum OrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Confirmed = 'confirmed';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Sent => 'versendet',
            self::Confirmed => 'bestätigt',
            self::Delivered => 'geliefert',
            self::Cancelled => 'storniert',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Sent => 'info',
            self::Confirmed => 'success',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Nur der Entwurf darf verändert/befüllt werden. */
    public function istOffen(): bool
    {
        return $this === self::Draft;
    }

    /** Erlaubte Folge-Status (Guard im Service). */
    public function darfWechselnZu(self $ziel): bool
    {
        return match ($this) {
            self::Draft => in_array($ziel, [self::Sent, self::Cancelled], true),
            self::Sent => in_array($ziel, [self::Confirmed, self::Delivered, self::Cancelled], true),
            self::Confirmed => in_array($ziel, [self::Delivered, self::Cancelled], true),
            self::Delivered => false,
            self::Cancelled => false,
        };
    }
}
