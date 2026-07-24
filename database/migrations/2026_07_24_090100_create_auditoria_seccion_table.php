<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Auditoria_Seccion', function (Blueprint $table) {
            $table->id('Id_Auditoria_Seccion');
            $table->unsignedBigInteger('Id_Tipo_Auditoria');
            $table->string('Nombre_Seccion', 150);
            $table->unsignedInteger('Orden')->default(0);
            $table->boolean('Activo')->default(true);

            $table->foreign('Id_Tipo_Auditoria', 'FK_AuditoriaSeccion_TipoAuditoria')
                ->references('Id_Tipo_Auditoria')->on('Tipo_Auditoria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Auditoria_Seccion');
    }
};