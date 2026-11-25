<?php

namespace Tests\Feature\Auth;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Pedidos\Services\PedidoInternoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rol Bodega (25-nov-2026, pedido del usuario): entra SOLO a Pedidos (todas
 * las bodegas), Historial de proveedores, Calendario de horarios (con Modo TV)
 * y Pedidos fuera de planificación, y no cambia nada.
 *
 * El barrido de rutas GET es la prueba de fondo: si mañana alguien agrega una
 * pantalla que deja pasar a "cualquier interno", esta prueba lo avisa.
 *
 * El rol se inserta acá si la base todavía no lo tiene (la migración
 * 2026_11_25_090000_rol_bodega no se corrió): DatabaseTransactions lo deshace.
 */
class RolBodegaTest extends TestCase
{
    private Empresa $empresa;

    private Usuario $bodega;

    /** Lo que Bodega tiene que poder abrir: sus 4 pantallas y lo que usa el Modo TV. */
    private const PERMITIDAS = [
        'api/pedidos/internos',
        'api/reportes/historial-proveedores',
        'api/horarios-entrega',
        'api/horarios-entrega/fuera-planificacion',
        'api/horarios-entrega/hoy',
        'api/horarios-entrega/config-anuncios',
    ];

    /**
     * GET que responden 200 a CUALQUIER usuario con sesión, sea del rol que
     * sea (datos de la propia cuenta, listas de opciones, contenido del sitio).
     * No son pantallas de otros roles: si aparece algo nuevo acá, revisarlo.
     */
    private const DE_CUALQUIER_SESION = [
        'api/auth/me',
        'api/auth/roles',
        'api/banner-informativo',
        'api/video-tutorial',
        'api/politicas',
        'api/catalogos/bancos',
        'api/catalogos/categorias-producto',
        'api/catalogos/clases-proveedor',
        'api/catalogos/grupos-impuesto',
        'api/catalogos/grupos-producto',
        'api/asistente/bienvenida-proactiva',
        // Listas de opciones de la ficha de productos y "mi responsable"
        // (vacío para un interno): sin datos de otros.
        'api/mis-productos/unidades-presentacion',
        'api/mis-productos/tipos-documento',
        'api/mi-responsable',
    ];

    /**
     * FALLO QUE YA EXISTÍA, no es de Bodega (visto el 25-nov-2026): estas
     * lecturas no revisan el rol y responden 200 a CUALQUIER sesión, también
     * a un PROVEEDOR (comprobado ese día). buscar-proveedores lista a los
     * otros proveedores de la empresa y responsables al personal interno.
     * Va acá para que el barrido no se calle si aparece una nueva; el arreglo
     * es aparte (ver la bitácora 2026-11-25-rol-bodega.md y la rama ajena
     * fix-autorizacion-endpoints del .74). Al arreglarlas, sacarlas de acá.
     */
    private const ABIERTAS_A_CUALQUIER_SESION_PENDIENTE = [
        'api/reclamos/buscar-proveedores',
        'api/responsables',
        'api/configuraciones/home-slides',
        'api/configuraciones/login-imagen',
        'api/configuraciones/video-tutorial',
        'api/configuraciones/banner-informativo',
        'api/configuraciones/bot-reglas',
        'api/configuraciones/guia-pasos',
        'api/configuraciones/politicas',
        'api/configuraciones/suspension-documentos',
        'api/configuraciones/anuncios-voz',
        'api/configuraciones/modo-navideno',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! DB::table('Rol')->where('Nombre_Rol', 'Bodega')->exists()) {
            DB::table('Rol')->insert([
                'Nombre_Rol' => 'Bodega', 'Descripcion' => 'Prueba', 'Activo' => true, 'Fecha_Creacion' => now(),
            ]);
        }

        $this->empresa = $this->crearEmpresa(['Empresa_BC' => 'bod_'.Str::lower(Str::random(10))]);
        $this->bodega = $this->crearUsuarioInterno($this->empresa, 'Bodega');
    }

    private function cabeceras(): array
    {
        return $this->cabecerasComo($this->bodega, $this->empresa);
    }

    public function test_entra_a_sus_cuatro_pantallas_y_al_modo_tv(): void
    {
        foreach (self::PERMITIDAS as $uri) {
            $this->getJson('/'.$uri, $this->cabeceras())->assertOk();
        }
    }

    public function test_ve_todas_las_bodegas_sin_que_se_le_asignen(): void
    {
        $this->assertSame(
            PedidoInternoService::BODEGAS,
            app(PedidoInternoService::class)->obtenerBodegasPermitidas($this->bodega, (int) $this->empresa->Id_Empresa)
        );

        $respuesta = $this->getJson('/api/pedidos/internos', $this->cabeceras())->assertOk()->json();
        $this->assertEqualsCanonicalizing(PedidoInternoService::BODEGAS, array_keys($respuesta));
    }

    public function test_no_cambia_nada_del_calendario(): void
    {
        [, $proveedor] = $this->crearProveedorConUsuario($this->empresa);
        $horario = HorarioEntregaProveedor::create([
            'Id_Empresa' => $this->empresa->Id_Empresa, 'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => 'Fruver', 'Dia_Entrega' => 'Martes', 'Hora_Llegada' => '07:00', 'Activo' => 1,
        ]);
        $id = $horario->Id_Horario_Entrega_Proveedor;

        $this->postJson("/api/horarios-entrega/{$id}/marcar-arribo", [], $this->cabeceras())->assertStatus(403);
        $this->postJson("/api/horarios-entrega/{$id}/solicitar-aprobacion", [], $this->cabeceras())->assertStatus(403);
        $this->deleteJson("/api/horarios-entrega/{$id}", [], $this->cabeceras())->assertStatus(403);
        $this->getJson('/api/horarios-entrega/aprobaciones', $this->cabeceras())->assertStatus(403);

        $this->assertTrue((bool) $horario->fresh()->Activo);
    }

    public function test_no_entra_a_los_reclamos(): void
    {
        $this->getJson('/api/reclamos/abiertos', $this->cabeceras())->assertStatus(403);
        $this->getJson('/api/reclamos/cerrados', $this->cabeceras())->assertStatus(403);
    }

    /**
     * Todas las rutas GET de la API sin parámetros: fuera de sus pantallas y
     * de lo que ve cualquier sesión, Bodega tiene que recibir un "no".
     */
    public function test_barrido_de_rutas_get(): void
    {
        $abiertas = [];

        foreach (Route::getRoutes() as $ruta) {
            $uri = $ruta->uri();

            if (! in_array('GET', $ruta->methods(), true)
                || ! str_starts_with($uri, 'api/')
                || str_contains($uri, '{')
                // Sin sesión: contenido público del login y la integración con BC (token propio).
                || str_starts_with($uri, 'api/public-config/')
                || str_starts_with($uri, 'api/integraciones/')
                || in_array($uri, self::PERMITIDAS, true)
                || in_array($uri, self::DE_CUALQUIER_SESION, true)
                || in_array($uri, self::ABIERTAS_A_CUALQUIER_SESION_PENDIENTE, true)) {
                continue;
            }

            $estado = $this->getJson('/'.$uri, $this->cabeceras())->status();

            // 403 = sin permiso; 404/422 = rutas de proveedor que no encuentran
            // "su" proveedor o piden parámetros: tampoco le muestran nada.
            if (! in_array($estado, [403, 404, 422], true)) {
                $abiertas[] = "{$uri} -> {$estado}";
            }
        }

        $this->assertSame([], $abiertas, "Bodega entra a rutas que no son suyas:\n".implode("\n", $abiertas));
    }
}
