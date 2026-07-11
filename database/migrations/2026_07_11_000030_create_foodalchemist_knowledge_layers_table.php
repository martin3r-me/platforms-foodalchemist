<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Wissens-Modul #469 (Reframe 2026-07-11): Bindungs-Achse = „Einsatzort/Layer".
 * ZWEI Granularitäten (Dominique): grob = Bereiche (gp/recipe/vk/concept/price) ·
 * fein = die einzelnen KI-Prompts aus der Registry (config foodalchemist.prompts, ~48).
 * Der Gateway (AiGatewayService::propose) lädt bei Prompt X alles, was an X (exakt)
 * ODER an dessen Bereich (Präfix vor dem '.') gebunden ist.
 * `knowledge_bindings.binding_type` wird auf einheitlich 'layer' gezogen (warengruppe raus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_knowledge_layers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global');
            $table->string('slug', 64);
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('kind', 12)->default('prompt')->comment('bereich (grob, Präfix) | prompt (fein, Registry-Key)');
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'slug'], 'fa_know_layer_team_slug_uq');
        });

        $bereichLabel = [
            'gp' => 'Grundprodukte', 'recipe' => 'Basisrezepte', 'vk' => 'Verkauf / Gerichte',
            'concept' => 'Konzepte', 'price' => 'Preis', 'chat' => 'Chat',
        ];
        $bereichSort = ['gp' => 10, 'recipe' => 20, 'vk' => 30, 'concept' => 40, 'price' => 50, 'chat' => 60];

        // Prompts aus der echten Registry (Bereich 'demo' = Smoke-Test, ausgelassen).
        $prompts = array_keys(config('foodalchemist.prompts', []));
        $prompts = array_values(array_filter($prompts, fn ($k) => ! str_starts_with($k, 'demo.') && str_contains($k, '.')));

        // Bereiche seeden (nur die, die real vorkommen)
        $bereiche = [];
        foreach ($prompts as $k) {
            $bereiche[explode('.', $k, 2)[0]] = true;
        }
        $rows = [];
        foreach (array_keys($bereiche) as $b) {
            $rows[] = ['slug' => $b, 'label' => $bereichLabel[$b] ?? ucfirst($b), 'kind' => 'bereich', 'sort_order' => $bereichSort[$b] ?? 90];
        }
        // Prompts seeden (nach Bereich gruppiert einsortiert)
        $psort = 100;
        foreach ($prompts as $k) {
            $rows[] = ['slug' => $k, 'label' => $k, 'kind' => 'prompt', 'sort_order' => $psort += 1];
        }
        foreach ($rows as $r) {
            DB::table('foodalchemist_knowledge_layers')->insert($r + [
                'uuid' => (string) Str::uuid7(),
                'team_id' => null,
                'active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Bindungen auf einheitliche Achse ziehen; warengruppe-Bindungen entfernen.
        if (Schema::hasTable('foodalchemist_knowledge_bindings')) {
            DB::table('foodalchemist_knowledge_bindings')->where('binding_type', 'ki_layer')->update(['binding_type' => 'layer']);
            DB::table('foodalchemist_knowledge_bindings')->where('binding_type', 'warengruppe')->delete();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_knowledge_layers');
    }
};
