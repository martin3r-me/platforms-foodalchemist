<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Symfony\Component\Uid\UuidV7;

/**
 * UuidV7 beim Anlegen vergeben (Plattform-Konvention, vgl. PlannerProject::booted()).
 * Als Trait-Boot, damit Models zusätzlich eigene booted()-Hooks definieren können.
 */
trait HasUuidV7
{
    protected static function bootHasUuidV7(): void
    {
        static::creating(function ($model) {
            if (! empty($model->uuid)) {
                return;
            }
            do {
                $uuid = (string) UuidV7::generate();
            } while (static::where('uuid', $uuid)->exists());

            $model->uuid = $uuid;
        });
    }
}
