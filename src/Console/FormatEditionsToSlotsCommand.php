<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatSlot;

/**
 * Format-Umbau F2c: bestehende Editionen (concepts.format_id + format_position) in
 * format_slots-Concept-Referenzen überführen (Besitz → Referenz). Idempotent — legt je
 * (Format, Concept) höchstens EINEN concept-Slot an; vorhandene bleiben unangetastet.
 * concepts.format_id bleibt vorerst intakt (Cutover/Stilllegung erst F2e).
 * Ohne --apply nur Dry-Run.
 */
class FormatEditionsToSlotsCommand extends Command
{
    protected $signature = 'foodalchemist:format-editions-to-slots {--apply : schreiben (ohne Flag nur Dry-Run)}';

    protected $description = 'Format-Editionen (concepts.format_id) → format_slots-Referenzen (F2c, idempotent)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . ' — Format-Editionen → format_slots');

        $neu = 0;
        $skip = 0;
        FoodAlchemistFormat::withTrashed()->orderBy('id')->each(function (FoodAlchemistFormat $format) use ($apply, &$neu, &$skip) {
            $editionen = FoodAlchemistConcept::where('format_id', $format->id)
                ->orderBy('format_position')->orderBy('id')
                ->get(['id', 'name', 'format_position']);
            if ($editionen->isEmpty()) {
                return;
            }
            $vorhanden = FoodAlchemistFormatSlot::where('format_id', $format->id)
                ->whereNotNull('concept_id')->pluck('concept_id')->map(fn ($v) => (int) $v)->all();
            $maxPos = (int) (FoodAlchemistFormatSlot::where('format_id', $format->id)->max('position') ?? 0);

            foreach ($editionen as $ed) {
                if (in_array((int) $ed->id, $vorhanden, true)) {
                    $skip++;
                    $this->line("  = skip  [{$format->name}] {$ed->name} (schon als Slot)");

                    continue;
                }
                $maxPos++;
                $this->line("  + neu   [{$format->name}] {$ed->name} → Slot pos {$maxPos}");
                if ($apply) {
                    FoodAlchemistFormatSlot::create([
                        'team_id' => $format->team_id,
                        'format_id' => $format->id,
                        'type' => 'concept',
                        'concept_id' => (int) $ed->id,
                        'position' => $maxPos,
                    ]);
                }
                $neu++;
            }
        });

        $this->info(($apply ? 'Fertig' : 'Dry-Run') . ": {$neu} neu, {$skip} übersprungen." . ($apply ? '' : ' Mit --apply ausführen.'));

        return self::SUCCESS;
    }
}
