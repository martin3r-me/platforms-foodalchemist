<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Zertifikate am Lieferanten-Dokument (Link-Stufe 2d): Art, Nummer, Aussteller und die abgelegte Datei. term_end bleibt das Ablaufdatum. */
return new class extends Migration
{
    private array $cols = ['cert_type', 'cert_number', 'issuer', 'context_file_id', 'original_name', 'mime'];

    public function up(): void
    {
        Schema::table('foodalchemist_supplier_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_supplier_documents', 'cert_type')) {
                $table->string('cert_type', 64)->nullable()->comment('Zertifikat-Art, z. B. IFS, BRC, Bio, MSC');
            }
            if (! Schema::hasColumn('foodalchemist_supplier_documents', 'cert_number')) {
                $table->string('cert_number', 64)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_supplier_documents', 'issuer')) {
                $table->string('issuer', 128)->nullable()->comment('Aussteller/Zertifizierungsstelle');
            }
            if (! Schema::hasColumn('foodalchemist_supplier_documents', 'context_file_id')) {
                $table->unsignedBigInteger('context_file_id')->nullable()->comment('Datei in der Core-Dateiablage');
            }
            if (! Schema::hasColumn('foodalchemist_supplier_documents', 'original_name')) {
                $table->string('original_name')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_supplier_documents', 'mime')) {
                $table->string('mime', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_supplier_documents', function (Blueprint $table) {
            foreach ($this->cols as $col) {
                if (Schema::hasColumn('foodalchemist_supplier_documents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
