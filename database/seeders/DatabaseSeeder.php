<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolEstadoProveedorSeeder::class);
        $this->call(CatalogosSeeder::class);
        // Depende de CatalogosSeeder (necesita que exista la Clase_Proveedor
        // "Centros de Faenamiento" para poder vincular Tipo_Auditoria_Clase)
        // -> por eso va después. No se estaba llamando desde acá antes de
        // agosto 2026 (quedó huérfano cuando se creó el módulo de
        // Auditorías), por eso el catálogo de auditorías nunca llegó a
        // sembrarse en instalaciones nuevas.
        $this->call(AuditoriaCatalogoSeeder::class);
    }
}