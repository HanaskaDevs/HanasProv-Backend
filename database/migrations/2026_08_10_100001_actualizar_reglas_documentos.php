<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migración de DATOS (no de esquema) que aplica las 7 reglas de negocio
 * pedidas por Isak en agosto 2026 sobre el catálogo ya cargado de
 * Tipo_Documento / Tipo_Documento_Producto. Se apoya en
 * 2026_08_10_100000_ajustes_esquema_reglas_documentos para las columnas
 * nuevas. Idempotente (igual criterio que CatalogosSeeder: revisa antes
 * de insertar) para poder correrla más de una vez sin duplicar nada.
 *
 * 1) "Notificación sanitaria o Certificado de Inscripción de
 *    alimentos" pasa de ser un documento del PROVEEDOR a ser un
 *    documento POR PRODUCTO -> se desactiva la fila vieja en
 *    Tipo_Documento (se conserva el historial de lo ya cargado, solo
 *    deja de pedirse a nivel proveedor) y se crea la fila nueva en
 *    Tipo_Documento_Producto.
 * 2) LUAE solo en Quito ya estaba resuelto (Requiere_Solo_Quito);
 *    ahora además "Permiso de funcionamiento Bomberos" se marca
 *    Requiere_Excepto_Quito = 1 -> en Quito ya no se pide Bomberos
 *    (se pide LUAE en su lugar), fuera de Quito se sigue pidiendo
 *    Bomberos y no LUAE.
 * 3) "Hojas de seguridad" pasa de proveedor a producto, mismo
 *    tratamiento que el punto 1.
 * 4) "Certificado de IESS" se renombra a "Certificado de afiliación
 *    al IESS", se vuelve obligatorio (ya lo era) y se mueve de la
 *    categoría "Certificaciones" a "General" (documentación) ->
 *    reemplaza a "Aprobación de reglamento de trabajo en el
 *    Ministerio", que se desactiva.
 * 5) Se excluye LUAE para la Clase de Proveedor "Productor Agrícola"
 *    vía el nuevo pivote Tipo_Documento_Clase_Excluida.
 * 6) "Certificaciones de calidad (BPM, HACCP, etc.)" pasa a ser
 *    opcional para todos (ya estaba en la categoría "Certificaciones").
 * 7) Nuevo Tipo_Documento "Certificado bancario" en la categoría
 *    "General" (documentos generales).
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- 4) IESS reemplaza a "Aprobación de reglamento de trabajo" ---
        DB::table('Tipo_Documento')
            ->where('Nombre_Documento', 'Certificado de IESS')
            ->update([
                'Nombre_Documento' => 'Certificado de afiliación al IESS',
                'Categoria' => 'General',
                'Obligatorio' => true,
            ]);

        DB::table('Tipo_Documento')
            ->where('Nombre_Documento', 'Aprobación de reglamento de trabajo en el Ministerio')
            ->update(['Activo' => false]);

        // --- 2) Bomberos solo fuera de Quito ---
        DB::table('Tipo_Documento')
            ->where('Nombre_Documento', 'Permiso de funcionamiento Bomberos')
            ->update(['Requiere_Excepto_Quito' => true]);

        // --- 6) Certificaciones de calidad pasan a opcionales ---
        DB::table('Tipo_Documento')
            ->where('Nombre_Documento', 'Certificaciones de calidad (BPM, HACCP, etc.)')
            ->update(['Obligatorio' => false]);

        // --- 1) y 3) Notificación sanitaria y Hojas de seguridad: de
        // proveedor a producto ---
        DB::table('Tipo_Documento')
            ->whereIn('Nombre_Documento', [
                'Notificación sanitaria o Certificado de Inscripción de alimentos',
                'Hojas de seguridad',
            ])
            ->update(['Activo' => false]);

        if (! DB::table('Tipo_Documento_Producto')->where('Nombre_Documento', 'Notificación sanitaria o Certificado de Inscripción de alimentos')->exists()) {
            DB::table('Tipo_Documento_Producto')->insert([
                'Nombre_Documento' => 'Notificación sanitaria o Certificado de Inscripción de alimentos',
                'Carpeta_Slug' => 'notificacion-sanitaria',
                'Codigo_Archivo' => 'NSANITARIA',
                'Obligatorio' => true,
                'Permite_Multiples' => false,
                'Requiere_Fecha_Caducidad' => true,
                'Activo' => true,
            ]);
        }

        if (! DB::table('Tipo_Documento_Producto')->where('Nombre_Documento', 'Hojas de seguridad')->exists()) {
            DB::table('Tipo_Documento_Producto')->insert([
                'Nombre_Documento' => 'Hojas de seguridad',
                'Carpeta_Slug' => 'hojas-seguridad',
                'Codigo_Archivo' => 'HS',
                'Obligatorio' => false,
                'Permite_Multiples' => true,
                'Requiere_Fecha_Caducidad' => false,
                'Activo' => true,
            ]);
        }

        // --- 7) Nuevo documento general: Certificado bancario ---
        if (! DB::table('Tipo_Documento')->where('Nombre_Documento', 'Certificado bancario')->exists()) {
            DB::table('Tipo_Documento')->insert([
                'Categoria' => 'General',
                'Nombre_Documento' => 'Certificado bancario',
                'Carpeta_Slug' => 'certificado-bancario',
                'Codigo_Archivo' => 'CBANCARIO',
                'Obligatorio' => false,
                'Permite_Multiples' => false,
                'Requiere_Fecha_Caducidad' => false,
                'Requiere_Solo_Quito' => false,
                'Requiere_Excepto_Quito' => false,
                'Activo' => true,
            ]);
        }

        // --- 5) LUAE no se le pide a la Clase "Productor Agrícola" ---
        $idLuae = DB::table('Tipo_Documento')->where('Nombre_Documento', 'LUAE')->value('Id_Tipo_Documento');
        $idAgricola = DB::table('Clase_Proveedor')->where('Nombre_Clase', 'Productor Agrícola')->value('Id_Clase_Proveedor');

        if ($idLuae && $idAgricola) {
            $yaExiste = DB::table('Tipo_Documento_Clase_Excluida')
                ->where('Id_Tipo_Documento', $idLuae)
                ->where('Id_Clase_Proveedor', $idAgricola)
                ->exists();

            if (! $yaExiste) {
                DB::table('Tipo_Documento_Clase_Excluida')->insert([
                    'Id_Tipo_Documento' => $idLuae,
                    'Id_Clase_Proveedor' => $idAgricola,
                    'Activo' => true,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Migración de datos: no se revierte automáticamente (mismo
        // criterio que 2026_07_25_090001_asignar_codigos_archivos_conocidos),
        // revertir esto a mano si hiciera falta.
    }
};
