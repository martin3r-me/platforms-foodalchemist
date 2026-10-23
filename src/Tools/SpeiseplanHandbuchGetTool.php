<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;

/** Spec 86: Handbuch GET für Speiseplan (Rumpf: {@see HandbuchToolBasis}). */
class SpeiseplanHandbuchGetTool extends HandbuchToolBasis implements ToolContract, ToolMetadataContract
{
    protected function typ(): string
    {
        return 'speiseplan';
    }

    protected function verb(): string
    {
        return 'GET';
    }
}
