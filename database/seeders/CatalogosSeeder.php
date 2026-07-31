<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Precarga los 5 catálogos que hoy se gestionan desde Sistemas ->
 * Catálogos (Clase de Proveedor, Categoría de Producto, Tipo de
 * Documento, Tipo de Documento de Producto, Unidad de Presentación),
 * con los datos reales que el equipo cargó a mano tras el último
 * truncate. Mismo criterio que RolEstadoProveedorSeeder: revisa
 * fila por fila (por el nombre, que es único) en vez de mirar si la
 * tabla entera está vacía, así corre seguro las veces que haga falta
 * sin duplicar nada ni saltarse algo por culpa de una fila suelta que
 * ya exista.
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedClasesProveedor();
        $this->seedCategoriasProducto();
        $this->seedTiposDocumento();
        $this->seedTiposDocumentoProducto();
        $this->seedUnidadesPresentacion();
    }

    protected function seedClasesProveedor(): void
    {
        $clases = [
            'Productor Agrícola',
            'Comerciante',
            'Distribuidor',
            'Procesador de Alimento',
            'Fabricante de insumos',
            'Servicio',
            'Maquila',
            'Fabricante Otros',
        ];

        foreach ($clases as $nombre) {
            if (! DB::table('Clase_Proveedor')->where('Nombre_Clase', $nombre)->exists()) {
                DB::table('Clase_Proveedor')->insert(['Nombre_Clase' => $nombre, 'Activo' => true]);
            }
        }
    }

    protected function seedCategoriasProducto(): void
    {
        $categorias = [
            'Cárnicos / Embutidos',
            'Mariscos / Pescados',
            'Lácteos',
            'Conservas, salsas, especias',
            'Legumbres / Frutas',
            'Suministros',
            'Huevos',
            'Cereales',
            'Confitería',
            'Congelados Frutas Verduras',
            'Comestibles',
            'Grasas y Aceites',
            'Licores / Bebidas',
            'Empaques',
            'Edulcorantes',
            'Químicos',
            'Servicio Laboratorio',
            'Servicio de Mantenimiento',
            'Servicio Control de Plagas',
            'Servicio de Seguridad',
        ];

        foreach ($categorias as $nombre) {
            if (! DB::table('Categoria_Producto')->where('Nombre_Categoria', $nombre)->exists()) {
                DB::table('Categoria_Producto')->insert(['Nombre_Categoria' => $nombre, 'Activo' => true]);
            }
        }
    }

    protected function seedTiposDocumento(): void
    {
        $tipos = [
            ['Categoria' => 'Certificaciones', 'Nombre_Documento' => 'Certificado de IESS', 'Carpeta_Slug' => 'certificado-iess', 'Codigo_Archivo' => 'IESS', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Carta de Garantia', 'Carpeta_Slug' => 'carta-garantia', 'Codigo_Archivo' => 'CGARANTIA', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Notificación sanitaria o Certificado de Inscripción de alimentos', 'Carpeta_Slug' => 'notificacion-sanitaria', 'Codigo_Archivo' => 'NSANITARIA', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => true, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Permiso de funcionamiento ARCSA', 'Carpeta_Slug' => 'permiso-arcsa', 'Codigo_Archivo' => 'ARCSA', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => true, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Permiso de funcionamiento Bomberos', 'Carpeta_Slug' => 'permiso-bomberos', 'Codigo_Archivo' => 'PBOMBEROS', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'RUC', 'Carpeta_Slug' => 'ruc', 'Codigo_Archivo' => 'RUC', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'LUAE', 'Carpeta_Slug' => 'luae', 'Codigo_Archivo' => 'LUAE', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => true],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Aprobación de reglamento de trabajo en el Ministerio', 'Carpeta_Slug' => 'reglamento-trabajo-ministerio', 'Codigo_Archivo' => 'RTMINISTERIO', 'Obligatorio' => false, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'Certificaciones', 'Nombre_Documento' => 'Certificaciones de calidad (BPM, HACCP, etc.)', 'Carpeta_Slug' => 'certificaciones-calidad', 'Codigo_Archivo' => 'CC', 'Obligatorio' => true, 'Permite_Multiples' => true, 'Requiere_Fecha_Caducidad' => true, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Check list autoevaluación de proveedores', 'Carpeta_Slug' => 'autoevaluacion-proveedores', 'Codigo_Archivo' => 'AP', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Hojas de seguridad', 'Carpeta_Slug' => 'hojas-seguridad', 'Codigo_Archivo' => 'HS', 'Obligatorio' => false, 'Permite_Multiples' => true, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false],
        ];

        foreach ($tipos as $tipo) {
            if (! DB::table('Tipo_Documento')->where('Nombre_Documento', $tipo['Nombre_Documento'])->exists()) {
                DB::table('Tipo_Documento')->insert([...$tipo, 'Activo' => true]);
            }
        }
    }

    protected function seedTiposDocumentoProducto(): void
    {
        $tipos = [
            ['Nombre_Documento' => 'Ficha técnica', 'Carpeta_Slug' => 'ficha-tecnica', 'Codigo_Archivo' => 'FT', 'Obligatorio' => true],
            ['Nombre_Documento' => 'Análisis de producto', 'Carpeta_Slug' => 'analisis-producto', 'Codigo_Archivo' => 'AP', 'Obligatorio' => true],
            ['Nombre_Documento' => 'Carta de alérgenos', 'Carpeta_Slug' => 'carta-alergenos', 'Codigo_Archivo' => 'CA', 'Obligatorio' => false],
        ];

        foreach ($tipos as $tipo) {
            if (! DB::table('Tipo_Documento_Producto')->where('Nombre_Documento', $tipo['Nombre_Documento'])->exists()) {
                DB::table('Tipo_Documento_Producto')->insert([...$tipo, 'Activo' => true]);
            }
        }
    }

    protected function seedUnidadesPresentacion(): void
    {
        // OJO: estos 3 nombres son exactos a propósito -> ProductoService
        // tiene un mapeo hardcodeado (MAPA_UNIDAD_BC) que espera
        // "Unidad", "Kilogramo" y "Paquete" tal cual, para la
        // sincronización automática con Business Central.
        $unidades = ['Unidad', 'Kilogramo', 'Paquete'];

        foreach ($unidades as $nombre) {
            if (! DB::table('Unidad_Presentacion')->where('Nombre_Unidad', $nombre)->exists()) {
                DB::table('Unidad_Presentacion')->insert(['Nombre_Unidad' => $nombre, 'Activo' => true]);
            }
        }
    }
}