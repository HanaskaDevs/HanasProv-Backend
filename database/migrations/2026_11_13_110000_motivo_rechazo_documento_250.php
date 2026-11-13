<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Motivo de rechazo de un documento: de 50 a 250 caracteres (13-nov-2026,
 * pedido del usuario).
 *
 * Documento_Proveedor.Comentario_Calificacion era NVARCHAR(50) mientras la
 * validación aceptaba 1000 y la pantalla no ponía tope: un motivo de más de
 * 50 caracteres hacía fallar el guardado y el rechazo no quedaba registrado.
 * Ahora columna, validación (CalificarRequest::MAX_MOTIVO_DOCUMENTO) y
 * pantalla dicen lo mismo: 250.
 *
 * La tabla se creó fuera de las migraciones (ver CLAUDE.md), por eso va con
 * SQL directo y no con el Schema builder. Solo agranda la columna: no se
 * pierde ni se recorta nada de lo guardado. Sin índices ni defaults sobre
 * ella (verificado en la .75 antes de escribir esto).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE Documento_Proveedor ALTER COLUMN Comentario_Calificacion NVARCHAR(250) NULL');
    }

    public function down(): void
    {
        // Volver a 50 recortaría los motivos largos que ya se hayan guardado:
        // se aborta en vez de perder texto en silencio.
        $largos = DB::table('Documento_Proveedor')
            ->whereRaw('LEN(Comentario_Calificacion) > 50')
            ->count();

        if ($largos > 0) {
            throw new RuntimeException(
                "No se revierte: {$largos} motivo(s) de rechazo tienen más de 50 caracteres y se recortarían."
            );
        }

        DB::statement('ALTER TABLE Documento_Proveedor ALTER COLUMN Comentario_Calificacion NVARCHAR(50) NULL');
    }
};
