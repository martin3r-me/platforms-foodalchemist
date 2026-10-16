<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;
use Platform\FoodAlchemist\Models\Concerns\HasUuidV7;

/**
 * Spec 84 §4.4 (E3) · Befristeter Support-Zugang, den ein Mandant Plattform-Admins gibt. Geschrieben nur über
 * {@see \Platform\FoodAlchemist\Services\SupportFreigabeService}; jede Freigabe, jeder Widerruf und jeder Zugriff hängt als
 * Plattform-Protokoll an dieser Zeile.
 */
class FoodAlchemistSupportFreigabe extends Model
{
    use HasUuidV7, LogsActivity;

    protected $table = 'foodalchemist_support_freigaben';

    protected $guarded = ['id'];

    protected $casts = [
        'gueltig_bis' => 'datetime',
        'widerrufen_am' => 'datetime',
    ];

    public function istAktiv(): bool
    {
        return $this->widerrufen_am === null && $this->gueltig_bis !== null && $this->gueltig_bis->isFuture();
    }
}
