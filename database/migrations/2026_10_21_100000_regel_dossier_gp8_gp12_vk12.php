<?php

use Illuminate\Database\Migrations\Migration;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelService;

/**
 * Spec 81 Teil E (Dominique 13.10.): die geseedeten Regeln GP §8 (Pflichtangaben), GP §12 (Anti-Pattern) und VK §1.2
 * (Diät, Füllwörter) sind eingeschaltet — ihr Dossier war aber nicht verknüpft. Ohne Verknüpfung prüft der
 * Konsumenten-Wächter (foodalchemist:regeln-konsumenten) nicht, ob jeder Kanon-Leser des Dossiers die Regel als
 * Regel-Block bekommt; die Dossiers verweisen ab jetzt auf die Regel statt die Werte selbst zu führen. Aktiv-Status bleibt.
 */
return new class extends Migration
{
    private const VERKNUEPFUNG = [
        'regelwerk-gp-78-grammatur-regeln-produktspezifische-pflichtangaben' => [
            'gp.8.1.fett', 'gp.8.2.eier', 'gp.8.4.garnelen', 'gp.8.9.reis', 'gp.8.10.schokolade', 'gp.8.11.mehl', 'gp.8.12.kartoffel',
        ],
        'regelwerk-gp-12-anti-patterns' => ['gp.12.antipattern'],
        'regelwerk.regelwerk_verkaufsgerichte--1-naming-hg-praefix-pipe-skelett-verbindlich-use' => ['vk.1.2.diaet', 'vk.1.2.fuellwoerter'],
    ];

    public function up(): void
    {
        foreach (self::VERKNUEPFUNG as $slug => $schluessel) {
            foreach ($schluessel as $s) {
                $r = FoodAlchemistRule::query()->whereNull('team_id')->where('schluessel', $s)->first();
                if ($r === null || $r->dossier_slug === $slug) {
                    continue;
                }
                app(RegelService::class)->speichere([...$r->only(['schluessel', 'regelwerk', 'paragraph', 'titel', 'art', 'ziel', 'wirkung',
                    'params', 'beispiele', 'notiz']),
                    'dossier_slug' => $slug,
                ], null, null, 'migration');
            }
        }
    }

    public function down(): void
    {
    }
};
