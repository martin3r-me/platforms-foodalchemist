<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Regelwerk v3.7.1 §5 Ausnahme 3 (Dominique 26.10.2026): Rezepturware trägt Marke bzw. Hersteller als Klammer-Zusatz. Dafür braucht der
 * Lieferant zwei globale Merkmale: `rolle` (hersteller | haendler | offen) — nur beim Hersteller darf sein Name in die Klammer — und
 * `kurzname` (Schreibweise für die Klammer, z. B. „Heisters“ statt „Bäckerei Heisters“). Bestand = offen (verhält sich wie Händler).
 * Gepflegt von Plattform-Admin/Master-Admin bzw. per `foodalchemist:marken-einspielen`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_suppliers') || Schema::hasColumn('foodalchemist_suppliers', 'rolle')) {
            return;
        }
        Schema::table('foodalchemist_suppliers', function (Blueprint $t) {
            $t->string('rolle', 12)->default('offen')->comment('§5 Ausnahme 3: hersteller | haendler | offen (offen = wie Händler)');
            $t->string('kurzname', 60)->nullable()->comment('Schreibweise in der Klammer „/ (…)“, nur bei rolle = hersteller');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_suppliers') && Schema::hasColumn('foodalchemist_suppliers', 'rolle')) {
            Schema::table('foodalchemist_suppliers', fn (Blueprint $t) => $t->dropColumn(['rolle', 'kurzname']));
        }
    }
};
