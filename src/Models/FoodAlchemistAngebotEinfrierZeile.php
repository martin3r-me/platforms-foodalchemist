<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * @ai.description Zeile einer Einfrierung (Spec 82 §12 B3): ein Gericht des Angebots mit Abo-Fassung im Baum — Fassungs-IDs, EK (Teilsumme bei
 * unvollständig), VK (NULL ohne vollständigen EK) und Allergenbild zum Zeitpunkt des Einfrierens. Trägt ihre Werte selbst, ohne FK auf Rezepte.
 */
class FoodAlchemistAngebotEinfrierZeile extends Model
{
    use BelongsToTeamHierarchy, HasUuidV7, LogsActivity, SoftDeletes;

    protected $table = 'foodalchemist_angebot_einfrier_zeilen';

    protected $guarded = ['id'];

    protected $casts = [
        'uuid' => 'string', 'fassungen' => 'array', 'fehlende' => 'array', 'allergene' => 'array', 'vollstaendig' => 'boolean',
        'kosten_stand_at' => 'datetime', 'ek_summe' => 'float', 'ek_per_kg' => 'float', 'vk' => 'float',
    ];

    public function einfrierung(): BelongsTo
    {
        return $this->belongsTo(FoodAlchemistAngebotEinfrierung::class, 'einfrierung_id');
    }
}
