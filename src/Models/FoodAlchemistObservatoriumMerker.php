<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/** Persönliche Merkliste je Arbeits-Team; keine gemeinsame redaktionelle Freigabe. */
class FoodAlchemistObservatoriumMerker extends Model
{
    use LogsActivity;

    protected $table = 'foodalchemist_observatorium_merker';

    protected $guarded = ['id'];

    protected $casts = ['gemerkt' => 'boolean', 'trend_pruefen' => 'boolean'];
}
