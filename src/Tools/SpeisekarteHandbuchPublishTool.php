<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;

/** Spec 86: Handbuch PUBLISH für Speisekarte (Rumpf: {@see HandbuchToolBasis}). */
class SpeisekarteHandbuchPublishTool extends HandbuchToolBasis implements ToolContract, ToolMetadataContract
{
    protected function typ(): string
    {
        return 'speisekarte';
    }

    protected function verb(): string
    {
        return 'PUBLISH';
    }
}
