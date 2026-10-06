<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** Spec 63: Protokoll eines Bestell- oder Storno-Mailversands (ein Datensatz je Versand, Versuche zählen hoch). */
class FoodAlchemistOrderMail extends Model
{
    use HasUuidV7, LogsActivity, BelongsToTeamHierarchy, SoftDeletes;

    protected $table = 'foodalchemist_order_mails';

    protected $guarded = ['id'];

    protected $casts = [
        'versendet_am' => 'datetime',
        'versuche' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistOrder::class, 'order_id');
    }
}
