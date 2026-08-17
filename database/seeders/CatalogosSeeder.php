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
            // Nueva (agosto 2026) -> no tenía equivalente entre las
            // anteriores, hacía falta para poder vincular el nuevo
            // Tipo_Auditoria "Centros de Faenamiento" (ver
            // AuditoriaCatalogoSeeder).
            'Centros de Faenamiento',
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
        // "Notificación sanitaria..." y "Hojas de seguridad" ya NO van
        // acá -> pasaron a ser documentos POR PRODUCTO (ver
        // seedTiposDocumentoProducto). "Aprobación de reglamento de
        // trabajo en el Ministerio" tampoco -> la reemplazó "Certificado
        // de afiliación al IESS" (ahora en "General", no en
        // "Certificaciones").
        $tipos = [
            ['Categoria' => 'General', 'Nombre_Documento' => 'Certificado de afiliación al IESS', 'Carpeta_Slug' => 'certificado-iess', 'Codigo_Archivo' => 'IESS', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Carta de Garantia', 'Carpeta_Slug' => 'carta-garantia', 'Codigo_Archivo' => 'CGARANTIA', 'Ruta_Plantilla' => 'carta-garantia.docx', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Permiso de funcionamiento ARCSA', 'Carpeta_Slug' => 'permiso-arcsa', 'Codigo_Archivo' => 'ARCSA', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => true, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => false],
            // Solo fuera de Quito -> en Quito se pide LUAE en su lugar.
            ['Categoria' => 'General', 'Nombre_Documento' => 'Permiso de funcionamiento Bomberos', 'Carpeta_Slug' => 'permiso-bomberos', 'Codigo_Archivo' => 'PBOMBEROS', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => true],
            ['Categoria' => 'General', 'Nombre_Documento' => 'RUC', 'Carpeta_Slug' => 'ruc', 'Codigo_Archivo' => 'RUC', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => false],
            // Solo en Quito, y no se le pide a la Clase "Productor
            // Agrícola" (ver seedExclusionesClaseDocumento).
            ['Categoria' => 'General', 'Nombre_Documento' => 'LUAE', 'Carpeta_Slug' => 'luae', 'Codigo_Archivo' => 'LUAE', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => true, 'Requiere_Excepto_Quito' => false],
            // Opcional para todos.
            ['Categoria' => 'Certificaciones', 'Nombre_Documento' => 'Certificaciones de calidad (BPM, HACCP, etc.)', 'Carpeta_Slug' => 'certificaciones-calidad', 'Codigo_Archivo' => 'CC', 'Obligatorio' => false, 'Permite_Multiples' => true, 'Requiere_Fecha_Caducidad' => true, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => false],
            // Ruta_Plantilla: nombre del archivo dentro del disco
            // 'plantillas' (storage/app/plantillas) -> ver
            // DocumentoProveedorService::descargarPlantilla().
            ['Categoria' => 'General', 'Nombre_Documento' => 'Check list autoevaluación de proveedores', 'Carpeta_Slug' => 'autoevaluacion-proveedores', 'Codigo_Archivo' => 'AP', 'Ruta_Plantilla' => 'autoevaluacion-proveedores.docx', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => false],
            ['Categoria' => 'General', 'Nombre_Documento' => 'Certificado bancario', 'Carpeta_Slug' => 'certificado-bancario', 'Codigo_Archivo' => 'CBANCARIO', 'Obligatorio' => false, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false, 'Requiere_Solo_Quito' => false, 'Requiere_Excepto_Quito' => false],
        ];

        foreach ($tipos as $tipo) {
            if (! DB::table('Tipo_Documento')->where('Nombre_Documento', $tipo['Nombre_Documento'])->exists()) {
                DB::table('Tipo_Documento')->insert([...$tipo, 'Activo' => true]);
            } elseif (isset($tipo['Ruta_Plantilla'])) {
                // Si la fila ya existía de antes (instalación previa a
                // agosto 2026), el insert de arriba no corre -> hay que
                // completarle igual la ruta de la plantilla nueva.
                DB::table('Tipo_Documento')
                    ->where('Nombre_Documento', $tipo['Nombre_Documento'])
                    ->whereNull('Ruta_Plantilla')
                    ->update(['Ruta_Plantilla' => $tipo['Ruta_Plantilla']]);
            }
        }

        $this->seedExclusionesClaseDocumento();
    }

    /**
     * Tipo_Documento_Clase_Excluida: "esta Clase de Proveedor NO
     * necesita este documento" -> hoy solo hay una regla: un Productor
     * Agrícola no necesita LUAE.
     */
    protected function seedExclusionesClaseDocumento(): void
    {
        $idLuae = DB::table('Tipo_Documento')->where('Nombre_Documento', 'LUAE')->value('Id_Tipo_Documento');
        $idAgricola = DB::table('Clase_Proveedor')->where('Nombre_Clase', 'Productor Agrícola')->value('Id_Clase_Proveedor');

        if (! $idLuae || ! $idAgricola) {
            return;
        }

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

    protected function seedTiposDocumentoProducto(): void
    {
        $tipos = [
            ['Nombre_Documento' => 'Ficha técnica', 'Carpeta_Slug' => 'ficha-tecnica', 'Codigo_Archivo' => 'FT', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false],
            ['Nombre_Documento' => 'Análisis de producto', 'Carpeta_Slug' => 'analisis-producto', 'Codigo_Archivo' => 'AP', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false],
            ['Nombre_Documento' => 'Carta de alérgenos', 'Carpeta_Slug' => 'carta-alergenos', 'Codigo_Archivo' => 'CA', 'Obligatorio' => false, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => false],
            // Se mudó desde Tipo_Documento (antes era del proveedor, no
            // del producto).
            ['Nombre_Documento' => 'Notificación sanitaria o Certificado de Inscripción de alimentos', 'Carpeta_Slug' => 'notificacion-sanitaria', 'Codigo_Archivo' => 'NSANITARIA', 'Obligatorio' => true, 'Permite_Multiples' => false, 'Requiere_Fecha_Caducidad' => true],
            ['Nombre_Documento' => 'Hojas de seguridad', 'Carpeta_Slug' => 'hojas-seguridad', 'Codigo_Archivo' => 'HS', 'Obligatorio' => false, 'Permite_Multiples' => true, 'Requiere_Fecha_Caducidad' => false],
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