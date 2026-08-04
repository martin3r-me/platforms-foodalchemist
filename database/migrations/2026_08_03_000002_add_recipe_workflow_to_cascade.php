<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
            $table->unsignedTinyInteger('depth')->default(0)->after('parent_step_id');
            $table->string('dedupe_key', 191)->nullable()->after('label');
            $table->json('context_snapshot')->nullable()->after('dedupe_key');
            $table->unique(['cascade_run_id', 'dedupe_key'], 'fa_casc_step_dedupe_uq');
        });

        Schema::create('foodalchemist_cascade_recipe_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->index('fa_casc_dep_team_ix');
            $table->unsignedBigInteger('cascade_run_id')->index('fa_casc_dep_run_ix');
            $table->unsignedBigInteger('parent_step_id')->index('fa_casc_dep_parent_ix');
            $table->unsignedBigInteger('child_step_id')->index('fa_casc_dep_child_ix');
            $table->unsignedBigInteger('ingredient_id')->index('fa_casc_dep_ing_ix');
            $table->timestamps();
            $table->unique(['parent_step_id', 'ingredient_id'], 'fa_casc_dep_parent_ing_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_cascade_recipe_dependencies');
        Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
            $table->dropUnique('fa_casc_step_dedupe_uq');
            $table->dropColumn(['depth', 'dedupe_key', 'context_snapshot']);
        });
    }
};
