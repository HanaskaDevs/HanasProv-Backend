<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Proveedor_Certificacion', function (Blueprint $table) {
            $table->id('Id_Proveedor_Certificacion');
            $table->unsignedBigInteger('Id_Proveedor');
            $table->unsignedBigInteger('Id_Tipo_Certificacion');
            $table->unsignedBigInteger('Id_Archivo');
            $table->string('Numero_Certificado', 100)->nullable();
            $table->date('Fecha_Emision');
            $table->date('Fecha_Vencimiento');
            $table->boolean('Notificacion_Enviada')->default(false);
            $table->string('Estado', 20)->default('Vigente');
            $table->boolean('Activo')->default(true);
            $table->unsignedBigInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();
            $table->unsignedBigInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Proveedor', 'FK_ProvCert_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Tipo_Certificacion', 'FK_ProvCert_Tipo')
                ->references('Id_Tipo_Certificacion')->on('Tipo_Certificacion');
            $table->foreign('Id_Archivo', 'FK_ProvCert_Archivo')
                ->references('Id_Archivo')->on('Archivo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Proveedor_Certificacion');
    }
};
