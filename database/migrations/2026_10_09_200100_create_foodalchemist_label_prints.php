<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 76 · Druckprotokoll der Etiketten: wer hat wann was gedruckt (Rückverfolgung, „nochmal drucken").
 * Sammeldruck (Produktion) = mehrere Zeilen mit derselben `gruppe`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_label_prints')) {
            return;
        }
        Schema::create('foodalchemist_label_prints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('gruppe')->nullable()->index();
            $table->string('quelle', 16);
            $table->unsignedBigInteger('bezug_id');
            $table->unsignedBigInteger('label_template_id')->nullable();
            $table->unsignedInteger('anzahl')->default(1);
            $table->json('eingabe')->nullable();
            $table->string('bezeichnung', 160)->nullable();
            $table->string('lagerung', 16)->nullable();
            $table->date('verbrauchen_bis')->nullable();
            $table->string('charge', 40)->nullable();
            $table->unsignedBigInteger('production_order_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['team_id', 'created_at'], 'fa_label_prints_team_zeit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_label_prints');
    }
};
