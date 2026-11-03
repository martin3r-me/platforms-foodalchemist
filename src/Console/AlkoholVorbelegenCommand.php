<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Support\AlkoholAngabe;

/**
 * Spec 94c · Einmalige Vorbelegung von `gps.alkohol` für Getränke-GPs (WG 15) nach den Regeln in {@see AlkoholAngabe::ausGp}.
 * Standard ist der Probelauf (zählt nur). Mit --apply schreibt er Herkunft `abgeleitet`, nie über einen manuell gepflegten Wert
 * (`alkohol_source = manuell`) und nie außerhalb WG 15. Nur auf Ansage und mit Backup laufen lassen; danach Recompute mit Ansage.
 * --vorschlaege listet Koch-Alkoholika außerhalb WG 15 ohne Wert (Kochwein, Mirin, Rum-Aroma …) mit Rezept-Zahl — nur als Vorschlag
 * zur Freigabe durch einen Menschen, der Befehl schreibt sie nie.
 */
class AlkoholVorbelegenCommand extends Command
{
    protected $signature = 'foodalchemist:alkohol-vorbelegen {--apply : Werte schreiben (sonst nur Probelauf)} {--offen : Getränke-GPs ohne Aussage auflisten} {--vorschlaege : Koch-Alkoholika außerhalb WG 15 als Vorschlag auflisten (schreibt nie)}';

    protected $description = 'Spec 94c: Alkohol (ja / nein / offen) an Getränke-GPs vorbelegen — Probelauf als Standard, manuell gepflegte Werte bleiben';

    /** Vorschlags-Muster: Wortteil am Ende (Rotwein, Himbeergeist) bzw. ganzes Wort (Mirin, Rum-Aroma); Essig und Schwein ausgenommen. */
    private const VORSCHLAG = '/(wein|bier|likör|likoer|brand|geist|schnaps)\b|\b(mirin|sake|rum|cognac|brandy|sherry|marsala|calvados|grappa|amaretto|'
        . 'kirschwasser|wodka|vodka|gin|whisky|whiskey|noilly|madeira|wermut|vermouth|ouzo|pastis|tequila|baileys|sekt|prosecco|champagner)\b/iu';

    private const VORSCHLAG_NICHT = '/essig|schwein/iu';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $zahl = ['ja' => 0, 'nein' => 0, 'offen' => 0, 'manuell' => 0, 'geaendert' => 0];
        $offen = [];
        foreach (DB::table('foodalchemist_gps')->whereNull('deleted_at')->where('status', '!=', 'merged')
            ->where('commodity_group_code', AlkoholAngabe::WG_GETRAENKE)->orderBy('id')
            ->get(['id', 'name', 'pflichtangabe', 'commodity_group_code', 'sub_category', 'alkohol', 'alkohol_source']) as $gp) {
            if ($gp->alkohol_source === 'manuell') {
                $zahl['manuell']++;

                continue;
            }
            $wert = AlkoholAngabe::ausGp($gp->commodity_group_code, $gp->pflichtangabe, $gp->name, $gp->sub_category);
            $zahl[$wert === null ? 'offen' : ($wert ? 'ja' : 'nein')]++;
            if ($wert === null) {
                $offen[] = [$gp->id, $gp->name, (string) $gp->sub_category];
            }
            $alt = $gp->alkohol === null ? null : (bool) $gp->alkohol;
            if ($alt === $wert) {
                continue;
            }
            $zahl['geaendert']++;
            if ($apply) {
                DB::table('foodalchemist_gps')->where('id', $gp->id)
                    ->update(['alkohol' => $wert, 'alkohol_source' => $wert === null ? null : 'abgeleitet', 'updated_at' => now()]);
            }
        }

        $vorschlaege = $this->vorschlaege();
        if ($this->option('vorschlaege') && $vorschlaege !== []) {
            $this->table(['GP', 'Name', 'WG', 'Rezepte'], $vorschlaege);
        }
        if ($this->option('offen') && $offen !== []) {
            $this->table(['GP', 'Name', 'Unterkategorie'], $offen);
        }
        $this->line(sprintf('%s · ja: %d · nein: %d · offen: %d · manuell (bleibt): %d · %s: %d',
            $apply ? 'Geschrieben' : 'Probelauf', $zahl['ja'], $zahl['nein'], $zahl['offen'], $zahl['manuell'],
            $apply ? 'geändert' : 'würde ändern', $zahl['geaendert']));
        $this->line(sprintf('Vorschläge außerhalb WG 15 (nur Liste, nicht geschrieben): %d', count($vorschlaege)));

        return self::SUCCESS;
    }

    /** @return list<array{0: int, 1: string, 2: string, 3: int}> */
    private function vorschlaege(): array
    {
        $rezepte = DB::table('foodalchemist_recipe_ingredients')->whereNull('deleted_at')->whereNotNull('gp_id')
            ->groupBy('gp_id')->selectRaw('gp_id, COUNT(DISTINCT recipe_id) AS n')->pluck('n', 'gp_id');

        return DB::table('foodalchemist_gps')->whereNull('deleted_at')->where('status', '!=', 'merged')->whereNull('alkohol')
            ->where(fn ($w) => $w->whereNull('commodity_group_code')->orWhere('commodity_group_code', '!=', AlkoholAngabe::WG_GETRAENKE))
            ->orderBy('id')->get(['id', 'name', 'commodity_group_code'])
            ->filter(fn ($gp) => preg_match(self::VORSCHLAG, (string) $gp->name) === 1 && preg_match(self::VORSCHLAG_NICHT, (string) $gp->name) !== 1)
            ->map(fn ($gp) => [(int) $gp->id, (string) $gp->name, (string) $gp->commodity_group_code, (int) ($rezepte[$gp->id] ?? 0)])
            ->values()->all();
    }
}
