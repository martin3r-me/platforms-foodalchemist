<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94b · Stufe 7a: Leistungskategorien als team-pflegbares Vokabular statt Code-Konstante (Leitregel „nichts fest codieren“).
 * Die vier bisherigen Werte werden globale Zeilen mit denselben Slugs — `kategorie` an Vorlagen und Leistungen hält weiter den
 * Slug, Bestand bleibt gültig. Dazu `…_team_aus`: ein Team schaltet eine geerbte Kategorie für sich (und seine Unter-Teams) aus.
 * Additiv; down() droppt nur die zwei neuen Tabellen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_leistungskategorien')) {
            Schema::create('foodalchemist_vocab_leistungskategorien', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global');
                $table->string('slug', 32);
                $table->string('name');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'slug'], 'fa_vocab_lk_team_slug_uq');
            });
        }
        if (! Schema::hasTable('foodalchemist_leistungskategorie_team_aus')) {
            Schema::create('foodalchemist_leistungskategorie_team_aus', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('kategorie_id')->index();
                $table->timestamps();

                $table->unique(['team_id', 'kategorie_id'], 'fa_lk_team_aus_uq');
            });
        }
        $jetzt = now();
        foreach ([['personal', 'Personal', 10], ['equipment', 'Equipment', 20], ['logistik', 'Logistik', 30], ['sonstiges', 'Sonstiges', 40]] as [$slug, $name, $sort]) {
            if (! DB::table('foodalchemist_vocab_leistungskategorien')->whereNull('team_id')->where('slug', $slug)->exists()) {
                DB::table('foodalchemist_vocab_leistungskategorien')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid7(), 'team_id' => null,
                    'slug' => $slug, 'name' => $name, 'sort_order' => $sort, 'is_inactive' => false, 'created_at' => $jetzt, 'updated_at' => $jetzt]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_leistungskategorie_team_aus');
        Schema::dropIfExists('foodalchemist_vocab_leistungskategorien');
    }
};
