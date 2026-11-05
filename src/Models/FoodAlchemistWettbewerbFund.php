<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

class FoodAlchemistWettbewerbFund extends Model
{
    use BelongsToTeamHierarchy;

    protected $table = 'foodalchemist_wettbewerb_funde';

    protected $guarded = ['id'];

    protected $casts = ['geprueft_at' => 'datetime'];

    public function artikel()
    {
        return $this->belongsTo(FoodAlchemistTrendEingang::class, 'eingang_id');
    }

    public function wettbewerber()
    {
        return $this->belongsTo(FoodAlchemistWettbewerber::class, 'wettbewerber_id');
    }
}
