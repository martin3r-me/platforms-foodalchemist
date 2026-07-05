<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11 / Jarvis-Parität (FoodbookBlockList): Staffelpreise für header_frei_preis-
 * Blöcke (Format „Staffelpreis-Block") — ab Mindestpersonenzahl gilt ein €/Person.
 * Typischer Catering-Fall: „ab 50 Pers. 38 €, ab 100 Pers. 34 €". Auflösung über
 * die Pax am Foodbook (F-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_foodbook_block_staffel', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('block_id')->constrained('foodalchemist_foodbook_blocks')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->unsignedInteger('min_persons')->default(1);
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_foodbook_block_staffel');
    }
};
