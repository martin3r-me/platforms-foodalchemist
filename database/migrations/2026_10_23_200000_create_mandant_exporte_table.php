<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §13 C3 — Datenexport je Mandant (ZIP). Eine Zeile je Export: Zustand, Ablage (privates Storage), Größe, Prüfsumme,
 * Manifest (Zählung je Tabelle). Reine Verwaltungsdaten; der INHALT liegt nur in der Datei, die nur Inhaber/Admin des
 * Mandanten-Teams (und der Master bei kuratierten Mandanten) laden darf (§13 G).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_mandant_exporte', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index();            // Kunden-Hauptteam (Mandant)
            $table->string('status', 16)->default('laeuft');           // laeuft | fertig | fehler
            $table->string('pfad')->nullable();                        // Pfad im privaten Storage
            $table->unsignedBigInteger('groesse')->nullable();         // Bytes
            $table->string('pruefsumme', 64)->nullable();              // sha256 der ZIP-Datei
            $table->json('manifest')->nullable();                      // tabelle => Zeilen
            $table->text('fehler')->nullable();
            $table->unsignedBigInteger('ausgeloest_von')->nullable();
            $table->timestamp('fertig_at')->nullable();
            $table->timestamp('zuletzt_geladen_at')->nullable();
            $table->unsignedBigInteger('zuletzt_geladen_von')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_mandant_exporte');
    }
};
