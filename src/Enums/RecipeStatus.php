<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * M4-01 / D-5 §2.1: Rezept-Workflow (Quell-CHECK → Enum).
 */
enum RecipeStatus: string
{
    case Stub = 'stub';
    case Draft = 'draft';
    case Review = 'review';
    case Approved = 'approved';
    case Deprecated = 'deprecated';

    public function label(): string
    {
        return match ($this) {
            self::Stub => 'Platzhalter',
            self::Draft => 'Entwurf',
            self::Review => 'Prüfen',
            self::Approved => 'Freigegeben',
            self::Deprecated => 'Veraltet',
        };
    }
}
