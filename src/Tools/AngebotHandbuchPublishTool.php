<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;

/** Spec 86: Handbuch PUBLISH für Angebot (Rumpf: {@see HandbuchToolBasis}). */
class AngebotHandbuchPublishTool extends HandbuchToolBasis implements ToolContract, ToolMetadataContract
{
    protected function typ(): string
    {
        return 'angebot';
    }

    protected function verb(): string
    {
        return 'PUBLISH';
    }
}
