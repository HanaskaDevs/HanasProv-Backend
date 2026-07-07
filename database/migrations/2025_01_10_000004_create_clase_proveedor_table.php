<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Clase_Proveedor', function (Blueprint $table) {
            $table->id('Id_Clase_Proveedor');
            $table->string('Nombre_Clase', 100);
            $table->string('Icono_Url', 300)->nullable();
            $table->boolean('Activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Clase_Proveedor');
    }
};
