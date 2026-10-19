<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * GP-Namen mit echten Umlauten (Dominique 19.10.): die Muster der Regel-Daten müssen beide Schreibweisen tragen. `gp.8.2.eier` kannte
 * „Groesse“/„Grösse“, aber nicht „Größe“ (ß). Token-Regeln sind schon tolerant (RegelText::norm transliteriert ä→ae/ß→ss).
 */
return new class extends Migration
{
    private const ALT = '/(?:^|[\s,:])(?:Gr(?:oe|ö)sse\s+)?(?:S|M|L|XL)(?:$|[\s,\/])/u';

    private const NEU = '/(?:^|[\s,:])(?:Gr(?:oe|ö)(?:ss|ß)e\s+)?(?:S|M|L|XL)(?:$|[\s,\/])/u';

    public function up(): void
    {
        $this->tausche(self::ALT, self::NEU);
    }

    public function down(): void
    {
        $this->tausche(self::NEU, self::ALT);
    }

    private function tausche(string $von, string $nach): void
    {
        $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', 'gp.8.2.eier')->first();
        if ($r === null) {
            return;
        }
        $params = (array) $r->params;
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $neu = json_decode(str_replace(json_encode($von, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($nach, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string) $json), true);
        if ($neu === $params) {
            return;   // Muster schon angepasst oder von Hand geändert
        }
        app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'dossier_slug']),
            'wirkung' => $r->wirkung, 'params' => $neu, 'beispiele' => $r->beispiele, 'notiz' => $r->notiz], null, (bool) $r->aktiv, 'migration');
    }
};
