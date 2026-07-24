<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Tipo_Auditoria', function (Blueprint $table) {
            $table->id('Id_Tipo_Auditoria');
            $table->string('Nombre', 100);
            $table->unsignedInteger('Orden')->default(0);
            $table->boolean('Activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Tipo_Auditoria');
    }
};