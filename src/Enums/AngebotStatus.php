<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * Angebots-Lifecycle (#380 — Kunden-Modul „Angebote", brief-getrieben).
 *
 * Fluss: Anfrage → in Arbeit → Angebot → versendet → angenommen | abgelehnt.
 * 07 §7-Konvention: Enum lebt im PHP-Layer (Spalte ist string(16), keine
 * CHECK-Constraint), damit Migrationen engine-agnostisch bleiben.
 */
enum AngebotStatus: string
{
    case Anfrage = 'anfrage';
    case InArbeit = 'in_arbeit';
    case Angebot = 'angebot';
    case Versendet = 'versendet';
    case Angenommen = 'angenommen';
    case Abgelehnt = 'abgelehnt';

    public function label(): string
    {
        return match ($this) {
            self::Anfrage => 'Anfrage',
            self::InArbeit => 'In Arbeit',
            self::Angebot => 'Angebot',
            self::Versendet => 'Versendet',
            self::Angenommen => 'Angenommen',
            self::Abgelehnt => 'Abgelehnt',
        };
    }

    /** x-ui-badge-Variante (UI-Inventar 01_ARCHITEKTUR §5). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Anfrage => 'secondary',
            self::InArbeit => 'info',
            self::Angebot => 'warning',
            self::Versendet => 'primary',
            self::Angenommen => 'success',
            self::Abgelehnt => 'danger',
        };
    }

    /** Offene (noch nicht final entschiedene) Angebote — für Listen-Filter/Signale. */
    public function istOffen(): bool
    {
        return match ($this) {
            self::Angenommen, self::Abgelehnt => false,
            default => true,
        };
    }

    /**
     * Erlaubte Folge-Stati (Lifecycle-Guards für die Workflow-Buttons).
     *
     * @return list<self>
     */
    public function uebergaenge(): array
    {
        return match ($this) {
            self::Anfrage => [self::InArbeit, self::Abgelehnt],
            self::InArbeit => [self::Angebot, self::Abgelehnt],
            self::Angebot => [self::Versendet, self::Abgelehnt],
            self::Versendet => [self::Angenommen, self::Abgelehnt],
            self::Angenommen => [],
            self::Abgelehnt => [self::Anfrage],
        };
    }
}
