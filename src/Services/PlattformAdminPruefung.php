<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;

/** Spec 82 §13 — nur Plattform-Admin steuert Stamm-Paket und Master-Team (Rechteprüfung im Modul über FaRechte, nicht im Host). */
trait PlattformAdminPruefung
{
    protected function nurPlattformAdmin(?User $user, string $wofuer): User
    {
        if ($user === null || $user->isAiUser() || ! app(FaRechte::class)->istPlattformAdmin($user)) {
            throw new FaRechtFehltException(FaRolle::Admin, null, $wofuer.' (nur Plattform-Admin)');
        }

        return $user;
    }
}
