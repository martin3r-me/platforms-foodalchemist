<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;

/** Spec 86: Handbuch WITHDRAW für Angebot (Rumpf: {@see HandbuchToolBasis}). */
class AngebotHandbuchWithdrawTool extends HandbuchToolBasis implements ToolContract, ToolMetadataContract
{
    protected function typ(): string
    {
        return 'angebot';
    }

    protected function verb(): string
    {
        return 'WITHDRAW';
    }
}
