<?php

namespace Platform\FoodAlchemist\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\ActivityLog\Traits\LogsActivity;

/** Persönliches Profil für „Mein Briefing“ je (Arbeits-Team, Nutzer) — wie die Merkliste nie vererbt. */
class FoodAlchemistObservatoriumBriefingProfil extends Model
{
    use LogsActivity;

    protected $table = 'foodalchemist_observatorium_briefing_profil';

    protected $guarded = ['id'];

    protected $casts = ['branchen' => 'array', 'themen' => 'array'];
}
