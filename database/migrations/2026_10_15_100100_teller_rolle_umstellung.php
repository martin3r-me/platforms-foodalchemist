<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rollen-Umbau (Dominique 11.10.): `recipe_ingredients.role` wird auf das Teller-Vokabular umgestellt (kein zweites Feld).
 *  · Altwerte (V-21) bleiben in `role_v21` — Archiv, nur lesend.
 *  · Gericht: eindeutig übertragbar nur beilage und garnitur; „komponente“ (protein/sauce/basis?) und „aroma_treiber“
 *    (im Gericht oft die Sauce) werden NICHT geraten → leer, Neu-Klassifizierung per Kommando
 *    `foodalchemist:teller-rollen-klassifizieren` (nur leere Zeilen).
 *  · Basisrezept: keine Zeilen-Rolle mehr → leer.
 * Dazu die möglichen Rollen eines Basisrezepts: `foodalchemist_recipe_rollen` (Quelle je Eintrag).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipe_ingredients', 'role_v21')) {
            Schema::table('foodalchemist_recipe_ingredients', function (Blueprint $table) {
                $table->string('role_v21', 30)->nullable()->after('role');
            });
            DB::table('foodalchemist_recipe_ingredients')->whereNotNull('role')->update(['role_v21' => DB::raw('role')]);
            $gerichte = DB::table('foodalchemist_recipes')->where('is_sales_recipe', true)->select('id');
            DB::table('foodalchemist_recipe_ingredients')->whereIn('recipe_id', $gerichte)
                ->whereNotIn('role', ['beilage', 'garnitur'])->update(['role' => null]);
            DB::table('foodalchemist_recipe_ingredients')->whereNotIn('recipe_id', $gerichte)->update(['role' => null]);
        }
        // Wissensachse `komponentenrolle` (Dossier-Geltung, auch je Datenwert) auf das Teller-Vokabular: beilage/garnitur
        // bleiben, V-21-Werte ohne eindeutiges Ziel fallen weg (demo 11.10.: 0 Dossiers betroffen). Wiederholbar.
        if (Schema::hasTable('foodalchemist_knowledge_documents')) {
            $alt = ['aroma_treiber', 'komponente'];
            $bereinige = static function (?array $g) use ($alt): ?array {
                if (! is_array($g) || ! isset($g['komponentenrolle'])) {
                    return $g;
                }
                $werte = array_values(array_diff((array) $g['komponentenrolle'], $alt));
                if ($werte === []) {
                    unset($g['komponentenrolle']);
                } else {
                    $g['komponentenrolle'] = $werte;
                }

                return $g;
            };
            DB::table('foodalchemist_knowledge_documents')
                ->where(fn ($q) => $q->where('geltung', 'like', '%komponentenrolle%')->orWhere('datenwerte', 'like', '%komponentenrolle%'))
                ->orderBy('id')->each(function ($d) use ($bereinige) {
                    $geltung = $bereinige(json_decode((string) $d->geltung, true));
                    $werte = json_decode((string) $d->datenwerte, true);
                    if (is_array($werte)) {
                        foreach ($werte as $i => $w) {
                            if (is_array($w) && isset($w['geltung'])) {
                                $werte[$i]['geltung'] = $bereinige((array) $w['geltung']) ?? [];
                            }
                        }
                    }
                    DB::table('foodalchemist_knowledge_documents')->where('id', $d->id)->update([
                        'geltung' => $geltung !== null ? json_encode($geltung, JSON_UNESCAPED_UNICODE) : null,
                        'datenwerte' => is_array($werte) ? json_encode($werte, JSON_UNESCAPED_UNICODE) : $d->datenwerte,
                    ]);
                });
        }
        if (! Schema::hasTable('foodalchemist_recipe_rollen')) {
            Schema::create('foodalchemist_recipe_rollen', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
                $table->string('rolle', 20);
                $table->unsignedTinyInteger('position')->default(0);   // 0 = Hauptrolle (Kaskade: geplante Rolle)
                $table->string('source', 12);                          // ki | kaskade | vorschlag | manuell
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['recipe_id', 'rolle']);
                $table->index('rolle');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_rollen');
        if (Schema::hasColumn('foodalchemist_recipe_ingredients', 'role_v21')) {
            DB::table('foodalchemist_recipe_ingredients')->whereNotNull('role_v21')->update(['role' => DB::raw('role_v21')]);
            Schema::table('foodalchemist_recipe_ingredients', fn (Blueprint $table) => $table->dropColumn('role_v21'));
        }
    }
};
