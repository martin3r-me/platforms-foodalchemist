<?php

namespace Platform\FoodAlchemist\Tools;

/** Alter Name von {@see ProduktpassKennzeichnungBestaetigenTool} (bis 16.10.2026), gleiche Wirkung: bestätigt jetzt Allergene UND Zusatzstoffe. */
class ProduktpassAllergeneBestaetigenTool extends ProduktpassKennzeichnungBestaetigenTool
{
    public function getName(): string
    {
        return 'foodalchemist.produktpass.ALLERGENE_BESTAETIGEN';
    }

    public function getDescription(): string
    {
        return '(Alter Name von foodalchemist.produktpass.KENNZEICHNUNG_BESTAETIGEN, gleiche Wirkung.) '.parent::getDescription();
    }
}
