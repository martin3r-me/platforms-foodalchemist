<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 52 — Geltungs-Achse `format` → `ausgabeform`.
 *
 * „Format" ist in FoodAlchemist bereits eine **Konzeptzusammenstellung** (ein Produkt mit
 * Slots, Druck und Kalkulation). Die Achse meint etwas anderes: die Form, in der das Essen
 * herausgeht — sie bestimmt den Mengen-Multiplikator. Zwei Bedeutungen unter einem Namen
 * sind beim Kuratieren von über tausend Dossiers eine sichere Fehlerquelle.
 *
 * ★ **Warum das eine Migration braucht und kein Handgriff ist.**
 * `WissensGeltung::lesen()` validiert nicht, es decodiert nur. Eine zurückgebliebene
 * `format`-Geltung würde gegen einen Parameter geprüft, den nach der Umbenennung niemand
 * mehr schickt — sie träfe nie mehr, **ohne Fehlermeldung**. Genau die stille Sorte Ausfall,
 * die Spec 52 abbaut. Und als Handgriff auf demo würde sie in jeder frischen Umgebung fehlen.
 *
 * Umgeschrieben wird an zwei Stellen je Dossier: die Geltung des Dokuments und die Geltung
 * jeder einzelnen Datenwert-Zeile.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_knowledge_documents';

    public function up(): void
    {
        $this->umbenennen('format', 'ausgabeform');
    }

    public function down(): void
    {
        $this->umbenennen('ausgabeform', 'format');
    }

    private function umbenennen(string $von, string $nach): void
    {
        if (! Schema::hasTable(self::TABELLE)
            || ! Schema::hasColumn(self::TABELLE, 'geltung')
            || ! Schema::hasColumn(self::TABELLE, 'datenwerte')) {
            return;                       // vor der H1-Migration: nichts zu tun
        }

        $geaendert = 0;
        DB::table(self::TABELLE)
            ->where(fn ($q) => $q->whereNotNull('geltung')->orWhereNotNull('datenwerte'))
            ->orderBy('id')
            ->chunkById(200, function ($zeilen) use ($von, $nach, &$geaendert) {
                foreach ($zeilen as $z) {
                    $geltung = $this->schluesselTauschen($z->geltung, $von, $nach);
                    $werte = $this->werteGeltungTauschen($z->datenwerte, $von, $nach);
                    if ($geltung === null && $werte === null) {
                        continue;
                    }
                    DB::table(self::TABELLE)->where('id', $z->id)->update(array_filter([
                        'geltung' => $geltung,
                        'datenwerte' => $werte,
                    ], static fn ($v) => $v !== null));
                    $geaendert++;
                }
            });

        // Nachvollziehbar im Log — eine Umbenennung, die niemand sieht, ist eine Umbenennung,
        // die niemand prüfen kann.
        Log::info('foodalchemist.spec52.achse.umbenannt', ['von' => $von, 'nach' => $nach, 'dossiers' => $geaendert]);
    }

    /** @return string|null  neues JSON, oder null wenn nichts zu ändern war */
    private function schluesselTauschen(mixed $json, string $von, string $nach): ?string
    {
        $daten = $this->lesen($json);
        if (! is_array($daten) || ! array_key_exists($von, $daten)) {
            return null;
        }
        $daten[$nach] = $daten[$von];
        unset($daten[$von]);

        return $this->schreiben($daten);
    }

    /** @return string|null  neues JSON, oder null wenn nichts zu ändern war */
    private function werteGeltungTauschen(mixed $json, string $von, string $nach): ?string
    {
        $zeilen = $this->lesen($json);
        if (! is_array($zeilen) || $zeilen === []) {
            return null;
        }
        $treffer = false;
        foreach ($zeilen as $i => $zeile) {
            if (! is_array($zeile) || ! is_array($zeile['geltung'] ?? null) || ! array_key_exists($von, $zeile['geltung'])) {
                continue;
            }
            $zeilen[$i]['geltung'][$nach] = $zeile['geltung'][$von];
            unset($zeilen[$i]['geltung'][$von]);
            $treffer = true;
        }

        return $treffer ? $this->schreiben($zeilen) : null;
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
