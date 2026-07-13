<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * GP-Kurationsstatus (GL-05/GL-07; Quelle: wawi_gp_v2.status-CHECK).
 */
enum GpStatus: string
{
    case Approved = 'approved';
    case Tentative = 'tentative';
    case Review = 'review';
    case Rejected = 'rejected';
    case Merged = 'merged';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Freigegeben',
            self::Tentative => 'Vorläufig',
            self::Review => 'In Prüfung',
            self::Rejected => 'Abgelehnt',
            self::Merged => 'Zusammengeführt',
        };
    }

    /** x-ui-badge-Variante (UI-Inventar 01_ARCHITEKTUR §5). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Tentative => 'warning',
            self::Review => 'info',
            self::Rejected => 'danger',
            self::Merged => 'secondary',
        };
    }
}
