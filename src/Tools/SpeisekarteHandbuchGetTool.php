<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;

/** Spec 86: Handbuch GET für Speisekarte (Rumpf: {@see HandbuchToolBasis}). */
class SpeisekarteHandbuchGetTool extends HandbuchToolBasis implements ToolContract, ToolMetadataContract
{
    protected function typ(): string
    {
        return 'speisekarte';
    }

    protected function verb(): string
    {
        return 'GET';
    }
}
