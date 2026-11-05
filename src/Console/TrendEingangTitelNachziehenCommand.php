<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Trends\FeedLeser;
use Platform\FoodAlchemist\Services\Trends\QuellenAbruf;
use Platform\FoodAlchemist\Support\TrendUrl;

/**
 * Einmal-Reparatur: Übersichts-Quellen, deren Artikel-Kopf früher weggeworfen wurde, haben den ganzen Teaser samt
 * „mehr erfahren“ als Titel (BZfE, Quelle 29). Ein neuer Abruf korrigiert das nicht (bekannte URL → nur zuletzt_gesehen).
 * Liest die Übersicht neu und schreibt titel + teaser NUR bei Zeilen dieser Quelle, deren Titel auf „mehr erfahren“ & Co.
 * endet und deren URL auf der Seite noch steht. Zusammenfassung, Thema und Einordnung (KI) bleiben unangetastet.
 * Ohne --apply: Probelauf (alt → neu), schreibt nichts.
 */
class TrendEingangTitelNachziehenCommand extends Command
{
    protected $signature = 'foodalchemist:trend-eingang-titel-nachziehen
        {--quelle= : Quelle (ID), Pflicht}
        {--apply : Änderungen schreiben (sonst Probelauf)}';

    protected $description = 'Trend-Eingang → Titel aus Übersichtsseiten neu ziehen, wo der Teaser-Link samt „mehr erfahren“ zum Titel wurde';

    public function handle(QuellenAbruf $abruf): int
    {
        $quelle = FoodAlchemistTrendQuelle::find((int) $this->option('quelle'));
        if (! $quelle) {
            $this->error('Quelle nicht gefunden: --quelle=<ID> angeben.');

            return self::FAILURE;
        }
        if ($quelle->feed_typ !== 'uebersicht') {
            $this->error('Nur Übersichts-Quellen (feed_typ=uebersicht); diese ist „'.$quelle->feed_typ.'“.');

            return self::FAILURE;
        }
        try {
            $neu = collect($abruf->lesen($quelle))->keyBy(fn ($e) => TrendUrl::hash($e['url']));
        } catch (\RuntimeException $e) {
            $this->error('Abruf fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }
        $kandidaten = FoodAlchemistTrendEingang::where('team_id', $quelle->team_id)->where('quelle_id', $quelle->id)->orderBy('id')->get()
            ->filter(fn ($e) => preg_match(FeedLeser::MEHR_LINK, (string) $e->titel) === 1);
        $plan = [];
        $nichtGefunden = 0;
        foreach ($kandidaten as $e) {
            $treffer = $neu->get($e->url_hash);
            if ($treffer === null) {
                $nichtGefunden++;

                continue;
            }
            if ($treffer['titel'] === $e->titel) {
                continue;
            }
            $plan[] = ['eintrag' => $e, 'titel' => $treffer['titel'], 'teaser' => $treffer['teaser'] ?? $e->teaser];
        }
        foreach ($plan as $p) {
            $this->line(sprintf('#%d  %s', $p['eintrag']->id, mb_strimwidth((string) $p['eintrag']->titel, 0, 90, '…')));
            $this->line('     → '.$p['titel']);
        }
        $this->info(sprintf('%d Kandidaten mit „mehr erfahren“-Titel, %d korrigierbar, %d nicht mehr auf der Seite (bleiben unverändert).',
            $kandidaten->count(), count($plan), $nichtGefunden));
        if (! $this->option('apply')) {
            $this->comment('Probelauf: nichts geschrieben. Mit --apply übernehmen.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($plan) {
            foreach ($plan as $p) {
                // Nur Titel und Teaser; updated_at bleibt Spur der Reparatur.
                $p['eintrag']->forceFill(['titel' => $p['titel'], 'teaser' => $p['teaser']])->save();
            }
        });
        $this->info(count($plan).' Einträge korrigiert.');

        return self::SUCCESS;
    }
}
