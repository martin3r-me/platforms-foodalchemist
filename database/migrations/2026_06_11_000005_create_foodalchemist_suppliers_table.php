<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieferanten-Stamm (02_DATENMODELL §A.1, Quelle suppliers, 120 Zeilen). Global (D1), Read-only für Teams.
 * Slice-Teilmenge: Finanz-/Register-Felder (iban, bic, vat_number, …) folgen im Voll-Port P2.
 * `legacy_id` = Quell-PK für set-basierte ID-Map + Traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->comment('Quell-PK (suppliers.id)');
            $table->string('name')->index();
            $table->string('branch')->nullable();
            $table->string('gln', 32)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->string('homepage')->nullable();
            $table->string('email_order')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_suppliers');
    }
};
