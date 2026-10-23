<?php

namespace Platform\FoodAlchemist\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\GebuehrenZaehler;

/**
 * Spec 82 §13 D — täglicher Zähl-Lauf der FA-Gebühren je Kunden-Hauptteam; schreibt Tageswerte in team_billable_usages.
 * Läuft nur bei eingeschalteter Abrechnung (config foodalchemist.gebuehren.zaehlen_aktiv) oder mit --force; --dry-run immer. Idempotent (Tageswert je Team/Baustein/Datum wird überschrieben). Mit --von/--bis lassen sich verpasste Tage nachholen.
 */
class PlanNutzungZaehlenCommand extends Command
{
    protected $signature = 'foodalchemist:plan-nutzung-zaehlen
        {--datum= : Stichtag (Y-m-d), Standard heute}
        {--von= : Nachholen ab (Y-m-d), zusammen mit --bis}
        {--bis= : Nachholen bis (Y-m-d)}
        {--team= : nur diesen Mandanten (Team-ID)}
        {--dry-run : nur anzeigen, nichts schreiben}
        {--force : auch ohne eingeschaltete Abrechnung (foodalchemist.gebuehren.zaehlen_aktiv) in team_billable_usages schreiben}';

    protected $description = 'Zählt die FA-Gebühren je Kunden-Hauptteam (Basis, User, Unter-Teams, Stamm-Paket, Abo, KI-Verbrauch) und schreibt Tageswerte in team_billable_usages';

    public function handle(GebuehrenZaehler $zaehler): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('force') && ! config('foodalchemist.gebuehren.zaehlen_aktiv', false)) {
            $this->error('Die Gebühren-Abrechnung ist nicht eingeschaltet (FOODALCHEMIST_GEBUEHREN_ZAEHLEN=false). Der Lauf schreibt in die Core-Rechnungstabelle team_billable_usages. Mit --dry-run nur rechnen, mit --force bewusst schreiben.');

            return self::FAILURE;
        }
        $von = Carbon::parse($this->option('von') ?: ($this->option('datum') ?: now()->toDateString()))->startOfDay();
        $bis = Carbon::parse($this->option('bis') ?: ($this->option('datum') ?: $von->toDateString()))->startOfDay();
        if ($bis->lt($von)) {
            $this->error('--bis liegt vor --von.');

            return self::FAILURE;
        }

        $mandanten = $zaehler->abrechenbareMandanten();
        if ($this->option('team')) {
            $mandanten = $mandanten->where('id', (int) $this->option('team'))->values();
        }

        $zeilen = $fehlend = 0;
        for ($tag = $von->copy(); $tag->lte($bis); $tag->addDay()) {
            foreach ($mandanten as $mandant) {
                $r = $zaehler->zaehle($mandant, $tag, ! $dry);
                $zeilen += count($r['zeilen']);
                foreach ($r['ohne_preis'] as $baustein) {
                    $fehlend++;
                    $this->warn(sprintf('%s · %s: kein Preis für „%s“ am %s (übersprungen).', $mandant->name, $tag->toDateString(), $baustein, $tag->toDateString()));
                }
                if ($dry) {
                    foreach ($r['zeilen'] as $z) {
                        $this->line($z['betrag_monat'] === null
                            ? sprintf('  %s · %s · %s: %d Aufrufe → %s €', $mandant->name, $z['datum'], $z['baustein'], $z['menge'], number_format($z['tageswert'], 2, ',', '.'))
                            : sprintf('  %s · %s · %s: %d × %s €/Monat → %s €', $mandant->name, $z['datum'], $z['baustein'], $z['menge'], $z['betrag_monat'], number_format($z['tageswert'], 2, ',', '.')));
                    }
                }
            }
        }
        $this->info(sprintf('%s %d Tageswert(e) für %d Mandant(en), %d Baustein(e) ohne Preis.', $dry ? 'Gerechnet (nichts geschrieben):' : 'Geschrieben:', $zeilen, $mandanten->count(), $fehlend));

        return self::SUCCESS;
    }
}
