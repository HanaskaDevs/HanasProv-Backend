<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Auditoria', function (Blueprint $table) {
            $table->id('Id_Auditoria');
            $table->unsignedInteger('Id_Empresa');
            $table->unsignedBigInteger('Id_Tipo_Auditoria');
            $table->unsignedInteger('Id_Proveedor');
            $table->unsignedInteger('Id_Usuario_Auditor');
            $table->date('Fecha_Auditoria');
            // Borrador -> se está llenando; Finalizada -> ya se cerró.
            $table->string('Estado', 20)->default('Borrador');
            $table->unsignedInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion');
            $table->unsignedInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Empresa', 'FK_Auditoria_Empresa')
                ->references('Id_Empresa')->on('Empresa');
            $table->foreign('Id_Tipo_Auditoria', 'FK_Auditoria_TipoAuditoria')
                ->references('Id_Tipo_Auditoria')->on('Tipo_Auditoria');
            $table->foreign('Id_Proveedor', 'FK_Auditoria_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Usuario_Auditor', 'FK_Auditoria_Usuario')
                ->references('Id_Usuario')->on('Usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Auditoria');
    }
};