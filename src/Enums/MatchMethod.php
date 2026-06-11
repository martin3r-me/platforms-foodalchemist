<?php

namespace Platform\FoodAlchemist\Enums;

/**
 * M4-01 / GL-04 §2.3: match_method-Vokabular — der Enum-Cast macht den
 * `override_sub`-Tippfehler-Bug (A-10) unmöglich.
 */
enum MatchMethod: string
{
    case GpV2Fk = 'gp_v2_fk';
    case RecipeRef = 'recipe_ref';
    case GeminiProposed = 'gemini_proposed';
    case OverrideSubrecipe = 'override_subrecipe';
    case OverrideGp = 'override_gp';
    case Manual = 'manual';
    case Unmatched = 'unmatched';
    case Ignored = 'ignored';
}
