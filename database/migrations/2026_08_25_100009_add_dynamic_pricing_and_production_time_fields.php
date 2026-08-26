<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trennt den dynamischen Katalogpreis vom auftragsspezifischen Produktions-HK2.
 * Alte Aufschlags-/Preisfelder bleiben ausschließlich als Migrationsquelle erhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_markup_classes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_markup_classes', 'class_factor_pct')) {
                $table->decimal('class_factor_pct', 9, 3)->default(100)->after('label');
            }
            if (! Schema::hasColumn('foodalchemist_markup_classes', 'vat_profile_key')) {
                $table->string('vat_profile_key', 16)->nullable()->after('vat_rate');
            }
            if (! Schema::hasColumn('foodalchemist_markup_classes', 'rounding_decimals')) {
                $table->unsignedTinyInteger('rounding_decimals')->nullable()->after('vat_profile_key');
            }
            if (! Schema::hasColumn('foodalchemist_markup_classes', 'rounding_mode')) {
                $table->string('rounding_mode', 16)->nullable()->after('rounding_decimals');
            }
            if (! Schema::hasColumn('foodalchemist_markup_classes', 'pricing_v2_migrated_at')) {
                $table->timestamp('pricing_v2_migrated_at')->nullable()->after('rounding_mode');
            }
        });

        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'calculated_sales_net')) {
                $table->decimal('calculated_sales_net', 12, 2)->nullable()->after('sales_net');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'vat_profile_key')) {
                $table->string('vat_profile_key', 16)->nullable()->after('price_mode');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'price_calculation_source')) {
                $table->string('price_calculation_source', 32)->nullable()->after('vat_profile_key');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'price_calculation_version')) {
                $table->string('price_calculation_version', 24)->nullable()->after('price_calculation_source');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'price_calculated_at')) {
                $table->timestamp('price_calculated_at')->nullable()->after('price_calculation_version');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'price_override_reason')) {
                $table->text('price_override_reason')->nullable()->after('price_calculated_at');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'price_override_user_id')) {
                $table->unsignedBigInteger('price_override_user_id')->nullable()->after('price_override_reason');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'price_override_at')) {
                $table->timestamp('price_override_at')->nullable()->after('price_override_user_id');
            }
            if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'price_override_expires_at')) {
                $table->timestamp('price_override_expires_at')->nullable()->after('price_override_at');
            }
        });

        Schema::table('foodalchemist_packages', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_packages', 'calculated_price_per_person')) {
                $table->decimal('calculated_price_per_person', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'price_calculation_source')) {
                $table->string('price_calculation_source', 32)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'price_calculation_version')) {
                $table->string('price_calculation_version', 24)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'price_override_reason')) {
                $table->text('price_override_reason')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'price_override_user_id')) {
                $table->unsignedBigInteger('price_override_user_id')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'price_override_at')) {
                $table->timestamp('price_override_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_packages', 'price_override_expires_at')) {
                $table->timestamp('price_override_expires_at')->nullable();
            }
        });

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concepts', 'calculated_price_per_person')) {
                $table->decimal('calculated_price_per_person', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_calculation_source')) {
                $table->string('price_calculation_source', 32)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_calculation_version')) {
                $table->string('price_calculation_version', 24)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_calculated_at')) {
                $table->timestamp('price_calculated_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_override_reason')) {
                $table->text('price_override_reason')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_override_user_id')) {
                $table->unsignedBigInteger('price_override_user_id')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_override_at')) {
                $table->timestamp('price_override_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_override_expires_at')) {
                $table->timestamp('price_override_expires_at')->nullable();
            }
        });

        Schema::table('foodalchemist_offers', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_offers', 'calculated_total_price')) {
                $table->decimal('calculated_total_price', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_offers', 'price_calculation_source')) {
                $table->string('price_calculation_source', 32)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_offers', 'price_calculation_version')) {
                $table->string('price_calculation_version', 24)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_offers', 'price_calculated_at')) {
                $table->timestamp('price_calculated_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_offers', 'price_override_reason')) {
                $table->text('price_override_reason')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_offers', 'price_override_user_id')) {
                $table->unsignedBigInteger('price_override_user_id')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_offers', 'price_override_at')) {
                $table->timestamp('price_override_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_offers', 'price_override_expires_at')) {
                $table->timestamp('price_override_expires_at')->nullable();
            }
        });

        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'variable_work_time_min')) {
                $table->decimal('variable_work_time_min', 10, 3)->nullable()->after('work_time_min');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'variable_work_time_basis')) {
                $table->string('variable_work_time_basis', 16)->nullable()->after('variable_work_time_min');
            }
        });

        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'labor_cost_source')) {
                $table->string('labor_cost_source', 24)->default('team_flat')->after('stundensatz_eur');
            }
        });

        if (! Schema::hasTable('foodalchemist_price_change_audits')) {
            Schema::create('foodalchemist_price_change_audits', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('entity_type', 40);
                $table->unsignedBigInteger('entity_id');
                $table->decimal('old_calculated_net', 12, 2)->nullable();
                $table->decimal('new_calculated_net', 12, 2)->nullable();
                $table->decimal('old_effective_net', 12, 2)->nullable();
                $table->decimal('new_effective_net', 12, 2)->nullable();
                $table->string('price_mode', 12)->nullable();
                $table->string('source', 32)->nullable();
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['entity_type', 'entity_id'], 'fa_price_audit_entity_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_price_change_audits');
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_team_settings', 'labor_cost_source')) {
                $table->dropColumn('labor_cost_source');
            }
        });
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            foreach (['variable_work_time_min', 'variable_work_time_basis'] as $column) {
                if (Schema::hasColumn('foodalchemist_recipes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            foreach (['calculated_sales_net', 'vat_profile_key', 'price_calculation_source',
                'price_calculation_version', 'price_calculated_at', 'price_override_reason',
                'price_override_user_id', 'price_override_at', 'price_override_expires_at'] as $column) {
                if (Schema::hasColumn('foodalchemist_recipe_presentations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('foodalchemist_packages', function (Blueprint $table) {
            foreach (['calculated_price_per_person', 'price_calculation_source', 'price_calculation_version',
                'price_override_reason', 'price_override_user_id', 'price_override_at', 'price_override_expires_at'] as $column) {
                if (Schema::hasColumn('foodalchemist_packages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            foreach (['calculated_price_per_person', 'price_calculation_source', 'price_calculation_version', 'price_calculated_at',
                'price_override_reason', 'price_override_user_id', 'price_override_at', 'price_override_expires_at'] as $column) {
                if (Schema::hasColumn('foodalchemist_concepts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('foodalchemist_offers', function (Blueprint $table) {
            foreach (['calculated_total_price', 'price_calculation_source', 'price_calculation_version', 'price_calculated_at',
                'price_override_reason', 'price_override_user_id', 'price_override_at', 'price_override_expires_at'] as $column) {
                if (Schema::hasColumn('foodalchemist_offers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('foodalchemist_markup_classes', function (Blueprint $table) {
            foreach (['class_factor_pct', 'vat_profile_key', 'rounding_decimals', 'rounding_mode', 'pricing_v2_migrated_at'] as $column) {
                if (Schema::hasColumn('foodalchemist_markup_classes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
