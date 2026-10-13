<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;

/** Spec 82 §13 I: Zuordnung Quelle → Kopie je Mandant (Idempotenz + ID-Abbildung). */
class FoodAlchemistPaketKopie extends Model
{
    protected $table = 'foodalchemist_paket_kopien';

    protected $guarded = ['id'];
}
