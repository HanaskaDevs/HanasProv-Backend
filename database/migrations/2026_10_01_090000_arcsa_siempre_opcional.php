<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El "Permiso de funcionamiento ARCSA" deja de ser obligatorio y pasa a ser
 * opcional para todos (pedido del usuario, 01-oct-2026).
 *
 * POR QUÉ UNA MIGRACIÓN Y NO SOLO EL SEEDER: CatalogosSeeder solo inserta
 * los tipos que todavía no existen, nunca actualiza los que ya están
 * cargados -> en una base con datos (desarrollo y producción) el seeder no
 * cambiaría nada. Es el mismo criterio de
 * 2026_08_10_100001_actualizar_reglas_documentos, que ya hizo opcional a
 * "Certificaciones de calidad".
 *
 * SE BUSCA POR Codigo_Archivo Y NO POR NOMBRE: el nombre es texto que se
 * edita desde el catálogo de administración, el código no.
 *
 * QUÉ CAMBIA EN LA PRÁCTICA, con Obligatorio = 0:
 *   - deja de aparecer en la lista de faltantes al registrar la
 *     documentación, así que ya no frena el envío a calificación;
 *   - deja de contar en el componente "Documentación" de la calificación
 *     global, que es todo-o-nada sobre los obligatorios aplicables;
 *   - se puede eliminar (a los obligatorios solo se los puede reemplazar).
 *
 * Lo que NO cambia: si el proveedor igual lo sube, se sigue calificando y
 * se le sigue exigiendo fecha de caducidad, y esa fecha sigue entrando en
 * el ciclo de avisos de vencimiento. Un documento que el proveedor decidió
 * presentar se mantiene vigente; opcional es presentarlo, no cuidarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('Tipo_Documento')
            ->where('Codigo_Archivo', 'ARCSA')
            ->update(['Obligatorio' => false]);
    }

    public function down(): void
    {
        DB::table('Tipo_Documento')
            ->where('Codigo_Archivo', 'ARCSA')
            ->update(['Obligatorio' => true]);
    }
};
