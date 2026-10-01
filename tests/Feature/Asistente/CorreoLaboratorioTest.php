<?php

namespace Tests\Feature\Asistente;

use App\Modules\Asistente\Services\AsistenteGuiaPortal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El correo del laboratorio es laboratorio@hanaska.com (01-oct-2026).
 *
 * Vive en tres lugares -la guía que lee Hana, el tooltip de la ficha de
 * productos en el frontend y el slide del home, que es contenido de base
 * de datos-. Lo que se protege acá son los dos que puede verificar el
 * backend; el del frontend sale de shared/config/contactos.
 */
class CorreoLaboratorioTest extends TestCase
{
    private const CORREO = 'laboratorio@hanaska.com';

    private const VIEJO = 'analisis_laboratorio@hanaska.com';

    /**
     * Hana tiene que poder contestarle al proveedor que pregunta dónde
     * conseguir el análisis. Si el correo no está en su guía, responde que
     * no sabe: no tiene de dónde sacarlo.
     */
    public function test_hana_conoce_el_correo_del_laboratorio(): void
    {
        $guia = AsistenteGuiaPortal::paraProveedor();

        $this->assertStringContainsString(self::CORREO, $guia);
        $this->assertStringNotContainsString(self::VIEJO, $guia);
    }

    /** Ni el home ni el resto del contenido editable mantienen el viejo. */
    public function test_no_queda_el_correo_viejo_en_el_contenido_editable(): void
    {
        $campos = [
            'Home_Slide' => ['Titulo', 'Descripcion'],
            'Bot_Regla' => ['Contenido'],
            'Guia_Paso' => ['Titulo', 'Texto'],
            'Politica' => ['Titulo', 'Descripcion'],
        ];

        foreach ($campos as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                $this->assertSame(
                    0,
                    DB::table($tabla)->where($columna, 'like', '%'.self::VIEJO.'%')->count(),
                    "Todavía hay filas con el correo viejo en {$tabla}.{$columna}."
                );
            }
        }
    }
}
