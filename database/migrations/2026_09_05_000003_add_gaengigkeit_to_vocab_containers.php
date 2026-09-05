<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wie gängig ist ein Behälter im Catering-Alltag (niedriger = gängiger).
 *
 * Anlass: die Variantenliste zeigte für 12 kg **38** Vorschläge, darunter „30× GN 1/9-65".
 * Rechnerisch korrekt, praktisch ein Katalog-Abzug — und die Regel lautet „das System zeigt
 * Varianten, die Küche entscheidet". Bei 38 entscheidet niemand.
 *
 * Dominique (2026-09-05): »1/1 werden im Catering in der Regel am meisten verwendet, 6er und
 * 10er« — also GN 1/1 in 65 und 100 mm. Diese Praxis steht jetzt als DATUM am Behälter, nicht
 * als Liste im Code: wer andere Formate fährt, pflegt sie in den Einstellungen um.
 *
 * Die Gängigkeit entscheidet NICHT, was vorgeschlagen wird — das bleibt die Passung (Menge wählt
 * die Größe). Sie entscheidet, WAS DANEBEN STEHT, wenn die Liste gekappt wird.
 */
return new class extends Migration
{
    private const TABELLE = 'foodalchemist_vocab_containers';

    public function up(): void
    {
        Schema::table(self::TABELLE, function (Blueprint $t) {
            if (! Schema::hasColumn(self::TABELLE, 'gaengigkeit')) {
                $t->unsignedSmallInteger('gaengigkeit')->default(50)->after('nutzfaktor')
                    ->comment('Alltagshäufigkeit, niedriger = gängiger. Steuert NUR, welche Alternativen sichtbar bleiben.');
            }
        });

        // GN 1/1-65 und -100 sind die Arbeitspferde („6er und 10er").
        $this->setze(10, fn ($q) => $q->where('familie', 'GN')->where('format_code', '1/1')->whereIn('tiefe_mm', [65, 100]));
        $this->setze(20, fn ($q) => $q->where('familie', 'GN')->where('format_code', '1/1'));
        $this->setze(20, fn ($q) => $q->whereIn('familie', ['Eimer', 'Kanne']));
        $this->setze(30, fn ($q) => $q->where('familie', 'GN')->whereIn('format_code', ['1/2', '2/1']));
        $this->setze(40, fn ($q) => $q->where('familie', 'GN')->where('format_code', '2/3'));
        $this->setze(50, fn ($q) => $q->where('familie', 'GN')->where('format_code', '1/3'));
        // Kleinstformate: Beilagen, Dips, Garnituren — nie die Antwort auf „wohin mit 12 kg".
        $this->setze(60, fn ($q) => $q->where('familie', 'GN')->whereIn('format_code', ['1/4', '1/6', '1/9']));
    }

    public function down(): void
    {
        Schema::table(self::TABELLE, function (Blueprint $t) {
            if (Schema::hasColumn(self::TABELLE, 'gaengigkeit')) {
                $t->dropColumn('gaengigkeit');
            }
        });
    }

    /** Nur setzen, wo noch der Default steht — eine gepflegte Zahl ist eine Entscheidung. */
    private function setze(int $wert, \Closure $filter): void
    {
        $q = DB::table(self::TABELLE)->whereNull('deleted_at')->where('gaengigkeit', 50);
        $filter($q);
        $q->update(['gaengigkeit' => $wert, 'updated_at' => now()]);
    }
};
