<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Estado_Proveedor', function (Blueprint $table) {
            $table->id('Id_Estado_Proveedor');
            $table->string('Nombre_Estado', 30)->unique();
            $table->string('Descripcion', 200)->nullable();
            $table->boolean('Activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Estado_Proveedor');
    }
};
