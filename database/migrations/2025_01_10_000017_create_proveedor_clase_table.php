<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Proveedor_Clase', function (Blueprint $table) {
            $table->id('Id_Proveedor_Clase');
            $table->unsignedBigInteger('Id_Proveedor');
            $table->unsignedBigInteger('Id_Clase_Proveedor');
            $table->boolean('Activo')->default(true);

            $table->unique(['Id_Proveedor', 'Id_Clase_Proveedor'], 'UQ_ProveedorClase');

            $table->foreign('Id_Proveedor', 'FK_ProveedorClase_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Clase_Proveedor', 'FK_ProveedorClase_Clase')
                ->references('Id_Clase_Proveedor')->on('Clase_Proveedor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Proveedor_Clase');
    }
};
