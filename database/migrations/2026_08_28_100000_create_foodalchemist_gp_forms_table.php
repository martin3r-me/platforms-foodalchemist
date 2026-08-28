<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #9 (Dominique 2026-08-28): Naturaleinheit/Formen-Modell. Pro GP mehrere „Formen" mit Gramm-Gewicht
 * (Stück/Scheibe/Würfel/Streifen/Blatt …) — Basis dafür, dass die KI je Form ein Gewicht schätzt und
 * der Rezept-Einheiten-Dropdown NUR die für das Produkt hinterlegten Formen zeigt (statt aller Einheiten).
 *
 * form_slug = Einheiten-Vokabular-Slug einer Stück/Zähl-Einheit (stk/scheibe/wuerfel/streifen/blatt …),
 * damit die Form direkt als wählbare Rezept-Einheit + für die EK-Umrechnung (gramm) taugt.
 * source: manual|ki (GL-07). Das bestehende piece_default_g (Stück) wird als Form „stk" migriert.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_gp_forms')) {
            Schema::create('foodalchemist_gp_forms', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('gp_id')->index();
                $table->string('form_slug', 32)->comment('stk|scheibe|wuerfel|streifen|blatt … (Einheiten-Vokabular)');
                $table->decimal('gramm', 8, 2)->comment('Gewicht EINER Einheit dieser Form in Gramm');
                $table->string('source', 16)->default('manual')->comment('manual|ki (GL-07)');
                $table->text('ai_reasoning')->nullable();
                $table->timestamps();
                $table->unique(['gp_id', 'form_slug']);
            });
        }

        // Bestehende piece_default_g (Stück) als Form „stk" übernehmen — eine Wahrheit, kein Daten-Bruch.
        if (Schema::hasTable('foodalchemist_gps') && Schema::hasTable('foodalchemist_gp_forms')) {
            $now = now()->toDateTimeString();
            DB::table('foodalchemist_gps')
                ->whereNotNull('piece_default_g')
                ->where('piece_default_g', '>', 0)
                ->orderBy('id')
                ->select('id', 'piece_default_g', 'piece_default_g_source')
                ->chunkById(500, function ($gps) use ($now) {
                    foreach ($gps as $gp) {
                        DB::table('foodalchemist_gp_forms')->insertOrIgnore([
                            'gp_id' => $gp->id,
                            'form_slug' => 'stk',
                            'gramm' => $gp->piece_default_g,
                            'source' => in_array($gp->piece_default_g_source, ['manual', 'ki', 'auto'], true) ? $gp->piece_default_g_source : 'manual',
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_forms');
    }
};
