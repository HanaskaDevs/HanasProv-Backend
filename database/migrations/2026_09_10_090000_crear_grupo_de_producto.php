<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Grupo de producto": una etiqueta OPCIONAL y MÚLTIPLE que se le pone a
 * cada producto (EK, CD, PH, IM). Un producto puede no tener ninguna,
 * tener una, o tenerlas todas.
 *
 * POR QUÉ UNA TABLA PUENTE Y NO UNA COLUMNA. La primera idea siempre es
 * guardar "EK,CD" en un varchar del propio Producto. Con eso, filtrar
 * "todos los del grupo CD" obliga a un LIKE '%CD%' que además matchearía
 * cualquier grupo futuro que contenga esas letras, y no hay forma de que
 * la base impida que alguien escriba un grupo que no existe. Con la tabla
 * puente el filtro es un JOIN normal y la FK garantiza que el valor exista.
 *
 * POR QUÉ UN CATÁLOGO ADMINISTRABLE Y NO 4 VALORES FIJOS EN EL CÓDIGO
 * (decisión del usuario, 10-sep-2026): los 4 grupos de hoy son una
 * decisión comercial, no una constante técnica. Puestos en una constante
 * de PHP, agregar el quinto exigiría tocar código y desplegar; en una
 * tabla, Sistemas lo agrega desde Catálogos como ya hace con las clases de
 * proveedor y las categorías de producto.
 *
 * El catálogo es GLOBAL, sin Id_Empresa, igual que Categoria_Producto y
 * Clase_Proveedor: los grupos son los mismos para todo el grupo empresarial.
 *
 * Codigo va aparte de Nombre porque son dos cosas distintas: "EK" es lo
 * que la gente escribe y ve en la tabla, y el Nombre es lo que explica qué
 * significa. Se puede renombrar el nombre largo sin romper nada de lo ya
 * etiquetado.
 */
return new class extends Migration
{
    /**
     * Los 4 grupos con los que arranca el catálogo. El Nombre queda igual
     * al código a propósito: todavía no está definido qué significa cada
     * sigla, y Sistemas puede completarlo desde Catálogos sin desplegar.
     */
    protected const GRUPOS_INICIALES = ['EK', 'CD', 'PH', 'IM'];

    public function up(): void
    {
        Schema::create('Grupo_Producto', function (Blueprint $table) {
            // increments() y no id(): el resto de los catálogos de esta base
            // (Categoria_Producto, Clase_Proveedor, Unidad_Presentacion)
            // tienen la clave INT, y así las FK que apunten acá pueden
            // declararse unsignedInteger sin desentonar.
            $table->increments('Id_Grupo_Producto');
            $table->string('Codigo', 10)->unique('UQ_Grupo_Producto_Codigo');
            $table->string('Nombre', 100);
            $table->string('Descripcion', 200)->nullable();
            // Para que el orden en pantalla lo decida el negocio y no el
            // alfabeto (EK antes que CD, si así lo quieren).
            $table->integer('Orden')->default(0);
            $table->boolean('Activo')->default(true);
        });

        Schema::create('Producto_Grupo', function (Blueprint $table) {
            $table->increments('Id_Producto_Grupo');
            // Producto.Id_Producto es INT en esta base (no BIGINT), así que
            // la FK tiene que declararse unsignedInteger o SQL Server la
            // rechaza. Mismo caso que la FK a Usuario en otras migraciones.
            $table->unsignedInteger('Id_Producto');
            $table->unsignedInteger('Id_Grupo_Producto');

            $table->foreign('Id_Producto')->references('Id_Producto')->on('Producto');
            $table->foreign('Id_Grupo_Producto')->references('Id_Grupo_Producto')->on('Grupo_Producto');

            // Un producto no puede estar dos veces en el mismo grupo. Es la
            // red de seguridad del sync(): si alguna vez llega el mismo id
            // repetido en la petición, la base lo corta.
            $table->unique(['Id_Producto', 'Id_Grupo_Producto'], 'UQ_Producto_Grupo');

            // El listado de productos carga los grupos de toda una página de
            // una sola vez (whereIn sobre Id_Producto) -> sin este índice esa
            // consulta hace un scan de la tabla entera en cada página.
            $table->index('Id_Producto', 'IX_Producto_Grupo_Producto');
        });

        foreach (self::GRUPOS_INICIALES as $orden => $codigo) {
            DB::table('Grupo_Producto')->insert([
                'Codigo' => $codigo,
                'Nombre' => $codigo,
                'Descripcion' => null,
                'Orden' => $orden,
                'Activo' => 1,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('Producto_Grupo');
        Schema::dropIfExists('Grupo_Producto');
    }
};
