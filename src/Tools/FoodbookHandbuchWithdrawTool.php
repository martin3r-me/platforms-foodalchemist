<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;

/** Spec 86: Handbuch WITHDRAW für Foodbook (Rumpf: {@see HandbuchToolBasis}). */
class FoodbookHandbuchWithdrawTool extends HandbuchToolBasis implements ToolContract, ToolMetadataContract
{
    protected function typ(): string
    {
        return 'foodbook';
    }

    protected function verb(): string
    {
        return 'WITHDRAW';
    }
}
