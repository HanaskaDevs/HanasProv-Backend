<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Tipo_Certificacion', function (Blueprint $table) {
            $table->id('Id_Tipo_Certificacion');
            $table->string('Nombre_Certificacion', 150);
            $table->boolean('Obligatoria')->default(false);
            $table->smallInteger('Vigencia_Meses')->nullable();
            $table->boolean('Activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Tipo_Certificacion');
    }
};
