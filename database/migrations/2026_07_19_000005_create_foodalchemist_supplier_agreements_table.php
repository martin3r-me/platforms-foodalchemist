<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R9.1 (E2) — Absprachen-/Zusagen-Log je Lieferant: datierte Einträge mit
 * Gültigkeit + Wiedervorlage (follow_up_at) + Autor. Kein Scaffolding vorhanden.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_supplier_agreements')) {
            return;
        }
        Schema::create('foodalchemist_supplier_agreements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $table->string('type', 32)->default('absprache')->comment('absprache|zusage|kondition|sonstiges');
            $table->text('note');
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->date('follow_up_at')->nullable()->index()->comment('Wiedervorlage/Erinnerung');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_agreements');
    }
};
