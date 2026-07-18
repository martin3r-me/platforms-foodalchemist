<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 06·H1 — Convenience-Highlights: kuratiertes Flag + flache Anzeige-Reihenfolge
 * am GP (global/Master trägt die Werte). Orthogonal zum Property-Tag
 * `tag_is_convenience` (= „ist ein Convenience-Produkt"): dies hier ist die
 * bewusst KURATIERTE Haus-Standard-Liste (opt-in KI-Baustein). Soft-Regel
 * (kein DB-Constraint): highlight nur bei tag_is_convenience=true — im
 * Kuratierungs-Screen erzwungen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->boolean('is_convenience_highlight')->default(false)->index()->after('tag_is_gluten_free');
            $table->unsignedInteger('highlight_rank')->nullable()->after('is_convenience_highlight');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->dropColumn(['is_convenience_highlight', 'highlight_rank']);
        });
    }
};
