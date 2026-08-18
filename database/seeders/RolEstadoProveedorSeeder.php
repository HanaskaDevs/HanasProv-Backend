<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Rol y Estado_Proveedor NO tienen (ni deberían tener) un CRUD libre
 * desde la interfaz -> el backend tiene IDs y nombres de estos
 * hardcodeados en constantes PHP:
 *   - EstadoProveedor::ASPIRANTE = 1 / APROBADO = 2 / RECHAZADO = 3 /
 *     SUSPENDIDO = 4 (en el modelo App\Modules\Proveedores\Models\
 *     EstadoProveedor, único lugar donde viven estos IDs)
 *   - Roles referenciados por Nombre_Rol en varios lugares
 *     ('Admin', 'Calidad', 'Compras', 'Proveedor', 'Sistemas')
 *
 * Si alguien pudiera crear/borrar/reordenar estas filas desde una
 * pantalla, esas referencias se rompen en silencio. Por eso quedan acá,
 * en un seeder, para cargarse siempre en el mismo orden (así las
 * IDENTITY calzan con lo que el código espera) apenas se corre
 * `php artisan db:seed`.
 *
 * OJO: esto asume que las tablas están vacías (recién truncadas o
 * recién creadas) -> si ya tienen filas, insertar de nuevo duplicaría
 * nombres. No corre automático dentro de un up() de migración a
 * propósito, para que quede como un paso consciente del que instala.
 */
class RolEstadoProveedorSeeder extends Seeder
{
    public function run(): void
    {
        // OJO: antes esto chequeaba count()===0 sobre TODA la tabla ->
        // si alguien ya había insertado 1 sola fila a mano (ej. el
        // usuario Sistemas inicial), el seeder se salteaba los otros 4
        // roles enteros, dejando el sistema incompleto en silencio.
        // Ahora revisa rol por rol (firstOrCreate por Nombre_Rol), así
        // completa lo que falte sin duplicar lo que ya esté, sin
        // importar el estado previo de la tabla.
        $roles = [
            ['Nombre_Rol' => 'Admin', 'Descripcion' => 'Administrador de la empresa'],
            ['Nombre_Rol' => 'Calidad', 'Descripcion' => 'Equipo de calidad, aprueba proveedores'],
            ['Nombre_Rol' => 'Compras', 'Descripcion' => 'Equipo de compras'],
            ['Nombre_Rol' => 'Proveedor', 'Descripcion' => 'Usuario proveedor'],
            ['Nombre_Rol' => 'Sistemas', 'Descripcion' => 'Acceso total a todos los módulos'],
        ];

        foreach ($roles as $rol) {
            if (! DB::table('Rol')->where('Nombre_Rol', $rol['Nombre_Rol'])->exists()) {
                DB::table('Rol')->insert([...$rol, 'Activo' => true, 'Fecha_Creacion' => now()]);
            }
        }

        if (DB::table('Estado_Proveedor')->count() === 0) {
            // El orden acá importa: sin filas previas, la primera
            // insertada recibe Id=1 y la segunda Id=2 -> tienen que
            // coincidir con EstadoProveedor::ASPIRANTE=1 / APROBADO=2.
            DB::table('Estado_Proveedor')->insert([
                ['Nombre_Estado' => 'Aspirante', 'Descripcion' => 'Proveedor postulando, todavía no aprobado', 'Activo' => true],
                ['Nombre_Estado' => 'Aprobado', 'Descripcion' => 'Proveedor activo y aprobado', 'Activo' => true],
                ['Nombre_Estado' => 'Rechazado', 'Descripcion' => 'Postulación rechazada definitivamente', 'Activo' => true],
                ['Nombre_Estado' => 'Suspendido', 'Descripcion' => 'Proveedor aprobado pero suspendido temporalmente', 'Activo' => true],
            ]);
        }
    }
}