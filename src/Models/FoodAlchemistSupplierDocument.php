<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/** R9.1 (E3) — Vertrags-/Dokument-Metadaten je Lieferant (Laufzeit + Kündigungsfrist). */
class FoodAlchemistSupplierDocument extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_supplier_documents';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string',
        'term_start' => 'date',
        'term_end' => 'date',
        'notice_period_days' => 'integer',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistSupplier::class, 'supplier_id');
    }

    /** Kündigungs-Deadline = Laufzeitende − Kündigungsfrist (oder null, wenn Daten fehlen). */
    public function noticeDeadline(): ?\Illuminate\Support\Carbon
    {
        if ($this->term_end === null || $this->notice_period_days === null) {
            return null;
        }

        return $this->term_end->copy()->subDays((int) $this->notice_period_days);
    }
}
