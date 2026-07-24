<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Foodbook (M11) — Angebots-/Menü-Mappe, komponiert Concepts +
 * Gerichte zu einem versendbaren Kunden-Dokument. Kunde + Pax (Gästezahl, F-12)
 * leben HIER (D-CON-5), nicht am person-unabhängigen Concept. team-eigen.
 */
class FoodAlchemistFoodbook extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_foodbooks';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'jahr' => 'integer',
        'personen' => 'integer',
    ];

    /**
     * ALLE Kapitel (flach, jede Ebene) nach position — NICHT nur Top-Kapitel.
     * Den n-tiefen Baum bauen Service/UI/Coverage aus `parent_id` (Rollup: Kapitel-Scope
     * = Kapitel + alle Nachfahren, Spec 19).
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(FoodAlchemistFoodbookKapitel::class, 'foodbook_id')->orderBy('position');
    }

    /** @deprecated #486 deutscher Alias → chapters() */
    public function kapitel(): HasMany
    {
        return $this->chapters();
    }

    /** Schreibstil-Override je Kunde/Foodbook (M10R-1, §10.8). */
    public function writingStyle(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistWritingStyle::class, 'writing_style_id');
    }

    /** @deprecated #486 deutscher Alias → writingStyle() */
    public function schreibstil(): BelongsTo
    {
        return $this->writingStyle();
    }

    /** #369: CRM-Firma (verlinkt, MVP — kein Rücksync). */
    public function crmCompany(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CrmCompany::class, 'crm_company_id');
    }

    /** #369: CRM-Kontakt (verlinkt, MVP). */
    public function crmContact(): BelongsTo
    {
        return $this->belongsTo(\Platform\Crm\Models\CrmContact::class, 'crm_contact_id');
    }

    /** Default-Zielgruppen (Spec 19, M1) — 1–n, kaskadieren als Foodbook-Boden. */
    public function targetGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            FoodAlchemistTargetGroup::class,
            'foodalchemist_foodbook_target_groups',
            'foodbook_id',
            'target_group_id'
        );
    }
}
