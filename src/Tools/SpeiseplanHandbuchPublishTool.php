<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;

/** Spec 86: Handbuch PUBLISH für Speiseplan (Rumpf: {@see HandbuchToolBasis}). */
class SpeiseplanHandbuchPublishTool extends HandbuchToolBasis implements ToolContract, ToolMetadataContract
{
    protected function typ(): string
    {
        return 'speiseplan';
    }

    protected function verb(): string
    {
        return 'PUBLISH';
    }
}
