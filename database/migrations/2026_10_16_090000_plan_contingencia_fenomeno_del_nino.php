<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nuevo documento opcional: "Plan de contingencia contra el fenómeno del
 * Niño" (pedido del usuario, 16-oct-2026).
 *
 * POR QUÉ UNA MIGRACIÓN Y NO SOLO EL SEEDER: CatalogosSeeder solo inserta
 * en una base vacía de este tipo; en desarrollo y producción la fila tiene
 * que entrar por acá (mismo criterio que el Certificado bancario en
 * 2026_08_10_100001_actualizar_reglas_documentos).
 *
 * CÓMO SE COMPORTA (ver TipoDocumento::esInformativo):
 *   - Obligatorio = 0: no frena el registro de la documentación ni cuenta
 *     en la calificación global.
 *   - Sin fecha de caducidad: no entra en los avisos de vencimiento ni
 *     puede suspender a nadie.
 *   - Es INFORMATIVO: Calidad lo ve y lo descarga, pero no lo califica. Al
 *     subirlo queda resuelto, así no aparece como "por calificar" ni frena
 *     la aprobación automática del proveedor.
 *   - Se puede subir en CUALQUIER momento, aunque el proveedor ya haya
 *     registrado su documentación o ya esté aprobado: es justamente a los
 *     proveedores activos a quienes se les pide.
 *
 * SE BUSCA POR Codigo_Archivo: el nombre se edita desde el catálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('Tipo_Documento')->where('Codigo_Archivo', 'PCNINO')->exists()) {
            return;
        }

        DB::table('Tipo_Documento')->insert([
            'Categoria' => 'General',
            'Nombre_Documento' => 'Plan de contingencia contra el fenómeno del Niño',
            'Carpeta_Slug' => 'plan-contingencia-nino',
            'Codigo_Archivo' => 'PCNINO',
            'Obligatorio' => false,
            'Permite_Multiples' => false,
            'Requiere_Fecha_Caducidad' => false,
            'Requiere_Solo_Quito' => false,
            'Requiere_Excepto_Quito' => false,
            'Activo' => true,
        ]);
    }

    /**
     * Se desactiva en vez de borrarse: si algún proveedor ya lo subió, su
     * Documento_Proveedor apunta a esta fila.
     */
    public function down(): void
    {
        DB::table('Tipo_Documento')
            ->where('Codigo_Archivo', 'PCNINO')
            ->update(['Activo' => false]);
    }
};
