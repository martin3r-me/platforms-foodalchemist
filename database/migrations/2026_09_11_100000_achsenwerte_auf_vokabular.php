<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 52 — Achsenwerte auf das vorhandene Vokabular ziehen.
 *
 * ★ Der Anlass ist ein eigener Fehler. Beim ersten Einordnen der sechs Mengen-Dossiers habe
 * ich Werte geschrieben, die es bei FoodAlchemist gar nicht gibt: `protein` statt der
 * gepflegten Rolle `komponente`, `obst_zitrus` statt der GP-Warengruppe, `getraenk` als
 * Rolle (es ist eine Warengruppe). Solche Werte fallen NICHT auf — sie treffen einfach nie.
 * Ab jetzt weist {@see \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::normalisieren()}
 * sie beim Schreiben ab; diese Migration holt den Bestand nach.
 *
 * Gespeichert wird der stabile Bezeichner der Quelle (Code bei Warengruppe und
 * Speisen-Hauptgruppe), nicht ein aus dem Label gebauter Slug.
 *
 * ⚠ Was KEINE Entsprechung hat, wird nicht hineingezwängt, sondern protokolliert und
 * entfernt. Konkret `gang: petit_four` — „Petit Four" ist keine Speisen-Hauptgruppe. Ob es
 * eine werden soll oder die Zeile woanders hingehört, entscheidet der Mensch.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_knowledge_documents';

    /**
     * alter Wert → neuer Wert, je Achse. `null` = kein Ziel, Wert fällt weg (protokolliert).
     *
     * @var array<string, array<string, string|null>>
     */
    private const ABBILDUNG = [
        'komponentenrolle' => [
            'protein' => 'komponente',      // euer Vokabular: SpeisenKlassenService::ROLLEN
            'suppe' => null,                // keine Rolle — wandert unten auf gang=sup
            'getraenk' => null,             // keine Rolle — wandert unten auf warengruppe=15
        ],
        'warengruppe' => [
            'getreide_pseudogetreide' => '07',   // Getreide & Huelsenfruechte
            'obst_zitrus' => '02',               // Obst — groeber; die Schaerfe traegt die Kennzahl
            'schokolade' => '09',                // Backwaren & Suesswaren
        ],
        'gang' => [
            'hauptgang' => 'hg',
            'vorspeise' => 'vor',
            'dessert' => 'des',
            'amuse_bouche' => 'amu',
            'petit_four' => null,           // ⚠ keine Speisen-Hauptgruppe — Mensch entscheidet
        ],
    ];

    /** Rollen, die in Wahrheit eine ANDERE Achse sind. alter Rollenwert → [achse, wert] */
    private const ROLLE_WIRD_ACHSE = [
        'suppe' => ['gang', 'sup'],             // Suppe & Eintopf ist eine Speisen-Hauptgruppe
        'getraenk' => ['warengruppe', '15'],    // Getraenke ist eine Warengruppe
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABELLE) || ! Schema::hasColumn(self::TABELLE, 'geltung')) {
            return;
        }

        $bericht = ['dossiers' => 0, 'geltungen' => 0, 'entfernt' => []];

        DB::table(self::TABELLE)
            ->where(fn ($q) => $q->whereNotNull('geltung')->orWhereNotNull('datenwerte'))
            ->orderBy('id')
            ->chunkById(200, function ($zeilen) use (&$bericht) {
                foreach ($zeilen as $z) {
                    $neu = [];
                    $geltung = $this->umschreiben($this->lesen($z->geltung), $bericht);
                    if ($geltung !== null) {
                        $neu['geltung'] = $this->schreiben($geltung);
                    }
                    $werte = $this->lesen($z->datenwerte);
                    if (is_array($werte) && $werte !== []) {
                        $treffer = false;
                        foreach ($werte as $i => $zeile) {
                            if (! is_array($zeile)) {
                                continue;
                            }
                            $g = $this->umschreiben($this->lesen($zeile['geltung'] ?? []), $bericht);
                            if ($g !== null) {
                                $werte[$i]['geltung'] = $g;
                                $treffer = true;
                            }
                        }
                        if ($treffer) {
                            $neu['datenwerte'] = $this->schreiben($werte);
                        }
                    }
                    if ($neu !== []) {
                        DB::table(self::TABELLE)->where('id', $z->id)->update($neu);
                        $bericht['dossiers']++;
                    }
                }
            });

        // Sichtbar machen, was weggefallen ist — eine stille Loeschung waere genau der
        // Fehler, den diese Migration behebt.
        Log::info('foodalchemist.spec52.achsenwerte.migriert', $bericht);
    }

    public function down(): void
    {
        // Kein Rueckweg: die alten Werte waren nie gueltiges Vokabular. Sie wiederherzustellen
        // hiesse, den Fehler zurueckzuschreiben.
    }

    /**
     * @param  array<string, mixed>  $geltung
     * @return array<string, list<string>>|null  neue Geltung, oder null wenn unveraendert
     */
    private function umschreiben(mixed $geltung, array &$bericht): ?array
    {
        if (! is_array($geltung) || $geltung === []) {
            return null;
        }
        $neu = [];
        $geaendert = false;

        foreach ($geltung as $achse => $werte) {
            foreach ((array) $werte as $wert) {
                $wert = mb_strtolower(trim((string) $wert));
                if ($wert === '') {
                    continue;
                }
                // Rolle, die in Wahrheit eine andere Achse ist
                if ($achse === 'komponentenrolle' && isset(self::ROLLE_WIRD_ACHSE[$wert])) {
                    [$zielAchse, $zielWert] = self::ROLLE_WIRD_ACHSE[$wert];
                    $neu[$zielAchse][] = $zielWert;
                    $geaendert = true;

                    continue;
                }
                if (array_key_exists($wert, self::ABBILDUNG[$achse] ?? [])) {
                    $ziel = self::ABBILDUNG[$achse][$wert];
                    $geaendert = true;
                    if ($ziel === null) {
                        $bericht['entfernt'][] = $achse.':'.$wert;

                        continue;
                    }
                    $neu[$achse][] = $ziel;

                    continue;
                }
                $neu[$achse][] = $wert;
            }
        }
        if (! $geaendert) {
            return null;
        }
        foreach ($neu as $a => $w) {
            $neu[$a] = array_values(array_unique($w));
        }
        ksort($neu);
        $bericht['geltungen']++;

        return $neu;
    }

    private function lesen(mixed $json): mixed
    {
        if (is_array($json)) {
            return $json;
        }

        return is_string($json) && $json !== '' ? json_decode($json, true) : null;
    }

    private function schreiben(array $daten): string
    {
        return (string) json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
};
