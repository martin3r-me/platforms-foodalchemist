<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\InspireImportService;

/**
 * Inspire-Voll-Import — mintet je Inspire-Zutat einen Anker (+ label_en-Brücke) und
 * schreibt die Kanten (pairings_strong L2+L3) in einem Pass. Kein Merge auf Bestand.
 * Default = Dry-Run. Redo mit --purge (löscht alle Inspire-Anker/-Kanten vorher).
 * Schreibt KEINE Embeddings — danach `foodalchemist:embed --pool=knowledge` off-peak
 * (sonst findet die semantische Anker-Auflösung / Qdrant-RAG die neuen Anker nicht).
 *
 *   php artisan foodalchemist:inspire-import --source=/pfad/foodpairing_kompakt.db
 *   php artisan foodalchemist:inspire-import --source=/pfad/foodpairing_kompakt.db --apply
 *   php artisan foodalchemist:inspire-import --source=/pfad/foodpairing_kompakt.db --apply --purge
 */
class InspireImportCommand extends Command
{
    protected $signature = 'foodalchemist:inspire-import '
        .'{--source= : Pfad zur foodpairing_kompakt.db (SQLite, kommerziell — nie ins Repo)} '
        .'{--apply : wirklich schreiben (sonst nur Dry-Run-Statistik)} '
        .'{--purge : vorhandene Inspire-Anker/-Kanten erst löschen (sauberer Redo)} '
        .'{--team=1 : team_id der neuen Anker/Kanten}';

    protected $description = 'Inspire-Voll-Import: 2.628 Anker + ~279.920 aroma-Kanten (source=inspire), kein Merge.';

    public function handle(InspireImportService $svc): int
    {
        $source = (string) $this->option('source');
        if ($source === '' || ! is_file($source)) {
            $this->error("--source fehlt oder Datei nicht gefunden: {$source}");

            return self::FAILURE;
        }

        try {
            $pdo = new \PDO('sqlite:'.$source);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\Throwable $e) {
            $this->error('Kann Quelle nicht öffnen: '.$e->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $teamId = (int) $this->option('team');

        if ($apply && (bool) $this->option('purge')) {
            $p = $svc->purgeInspire();
            $this->warn("Purge: {$p['anchors']} Anker, {$p['map']} Brücken, {$p['edges']} Kanten gelöscht.");
        }

        $already = $svc->existingInspireAnchors();
        if ($apply && $already > 0) {
            $this->error("Es existieren bereits {$already} Inspire-Anker. Redo mit --purge.");

            return self::FAILURE;
        }

        $this->info(($apply ? 'APPLY' : 'DRY-RUN')." — source={$source}, team={$teamId}");
        $stats = $svc->import($pdo, $apply, $teamId);

        $this->table(['Kennzahl', 'Wert'], [
            ['Inspire-Zutaten (has_pairing_data=1)', $stats['ingredients']],
            [$apply ? 'Anker angelegt' : 'würde anlegen', $stats['anchors_created']],
            ['Slug-Kollisionen aufgelöst', $stats['slug_collisions_fixed']],
            ['Kandidaten-Kanten (beide Richtungen)', $stats['edge_candidates']],
            ['übersprungen (self)', $stats['skipped_self']],
            [$apply ? 'Kanten eingefügt' : 'würde einfügen', $stats['edges_inserted']],
        ]);

        if (! $apply) {
            $this->line('→ Dry-Run. Mit --apply schreiben (--purge für Redo).');

            return self::SUCCESS;
        }

        // Der Import schreibt NUR Anker/Kanten (core_embeddings bleibt per Design unberührt).
        // Ohne Nach-Embed findet die semantische Anker-Auflösung (RAG/Qdrant) die neuen Anker nicht.
        $this->newLine();
        $this->warn('Anker + Kanten geschrieben — aber KEINE Embeddings (core_embeddings unberührt).');
        $this->warn('→ Jetzt OFF-PEAK nachziehen (sonst sieht die semantische Anker-Suche die neuen Anker nicht,');
        $this->warn('  und nicht parallel zur Nutzung — schwerer Embed-Job):');
        $this->line('    php artisan foodalchemist:embed --pool=knowledge');

        return self::SUCCESS;
    }
}
