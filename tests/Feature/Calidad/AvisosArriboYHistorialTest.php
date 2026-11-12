<?php

namespace Tests\Feature\Calidad;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Horarios_Entrega\Models\SolicitudAprobacionArribo;
use App\Modules\Horarios_Entrega\Notifications\ArriboProveedorNotification;
use App\Modules\Reclamos\Services\ReclamoService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Los pedidos del 12-nov-2026 que no son las fotos de las observaciones:
 * aviso a Calidad cuando el proveedor llega, bandeja de aprobaciones solo
 * con las solicitudes de hoy, el ítem de menú para quien resuelve esos
 * arribos, y el reporte de historial de reclamos con su PDF.
 */
class AvisosArriboYHistorialTest extends TestCase
{
    /** El horario de hoy del proveedor, que es lo que marca el Guardia. */
    private function horarioDeHoy(int $idEmpresa, int $idProveedor): HorarioEntregaProveedor
    {
        return HorarioEntregaProveedor::where('Id_Empresa', $idEmpresa)
            ->where('Id_Proveedor', $idProveedor)
            ->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Aviso de arribo
    // ------------------------------------------------------------------

    public function test_al_marcar_el_arribo_se_avisa_a_todos_los_usuarios_de_calidad(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        $calidad1 = $this->crearUsuarioInterno($empresa, 'Calidad');
        $calidad2 = $this->crearUsuarioInterno($empresa, 'Calidad');
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Hora de llegada "ahora" para que el horario no esté ya Rechazado.
        $this->crearEntrega($empresa, $proveedor, horaLlegada: now()->format('H:i'));
        $horario = $this->horarioDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $this->postJson(
            "/api/horarios-entrega/{$horario->Id_Horario_Entrega_Proveedor}/marcar-arribo",
            [],
            $this->cabecerasComo($guardia, $empresa)
        )->assertSuccessful();

        Notification::assertSentTo([$calidad1, $calidad2], ArriboProveedorNotification::class);
        Notification::assertNotSentTo($compras, ArriboProveedorNotification::class);
        Notification::assertNotSentTo($guardia, ArriboProveedorNotification::class);
    }

    public function test_si_no_hay_usuarios_de_calidad_el_arribo_se_marca_igual(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor, horaLlegada: now()->format('H:i'));
        $horario = $this->horarioDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        // Lo importante es que el proveedor quede registrado: el correo es
        // secundario y no puede dejar al Guardia sin poder trabajar.
        $this->postJson(
            "/api/horarios-entrega/{$horario->Id_Horario_Entrega_Proveedor}/marcar-arribo",
            [],
            $this->cabecerasComo($guardia, $empresa)
        )->assertSuccessful();

        Notification::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // Bandeja de aprobaciones: solo las de hoy
    // ------------------------------------------------------------------

    public function test_las_aprobaciones_de_arribo_solo_muestran_las_de_hoy(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $this->crearEntrega($empresa, $proveedor);
        $horario = $this->horarioDeHoy($empresa->Id_Empresa, $proveedor->Id_Proveedor);

        $deHoy = SolicitudAprobacionArribo::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => now()->toDateString(),
            'Solicitado_Por' => $guardia->Id_Usuario,
            'Fecha_Solicitud' => now(),
            'Estado' => 'Pendiente',
        ]);

        $deAyer = SolicitudAprobacionArribo::create([
            'Id_Horario_Entrega_Proveedor' => $horario->Id_Horario_Entrega_Proveedor,
            'Fecha' => now()->subDay()->toDateString(),
            'Solicitado_Por' => $guardia->Id_Usuario,
            'Fecha_Solicitud' => now()->subDay(),
            'Estado' => 'Pendiente',
        ]);

        $respuesta = $this->getJson('/api/horarios-entrega/aprobaciones', $this->cabecerasComo($calidad, $empresa))
            ->assertSuccessful()
            ->json();

        $ids = array_column($respuesta, 'id_solicitud_aprobacion_arribo');
        $this->assertContains($deHoy->Id_Solicitud_Aprobacion_Arribo, $ids);
        $this->assertNotContains($deAyer->Id_Solicitud_Aprobacion_Arribo, $ids);

        // La de ayer no se borra: sigue en la tabla como estaba.
        $this->assertSame('Pendiente', $deAyer->fresh()->Estado);
    }

    // ------------------------------------------------------------------
    // Ítem de menú de "Aprobaciones de arribo"
    // ------------------------------------------------------------------

    public function test_solo_el_correo_configurado_resuelve_aprobaciones_de_arribo(): void
    {
        $empresa = $this->crearEmpresa();
        $responsable = $this->crearUsuarioInterno($empresa, 'Calidad');
        $otro = $this->crearUsuarioInterno($empresa, 'Calidad');

        // Se configura con el correo del primero, escrito distinto a
        // propósito: la comparación no debe distinguir mayúsculas.
        config(['portal.email_aprobacion_arribo' => strtoupper($responsable->Email)]);

        $this->getJson('/api/auth/me', $this->cabecerasComo($responsable, $empresa))
            ->assertSuccessful()
            ->assertJsonPath('usuario.resuelve_aprobaciones_arribo', true);

        $this->getJson('/api/auth/me', $this->cabecerasComo($otro, $empresa))
            ->assertSuccessful()
            ->assertJsonPath('usuario.resuelve_aprobaciones_arribo', false);
    }

    // ------------------------------------------------------------------
    // Reporte: historial de reclamos
    // ------------------------------------------------------------------

    /** Crea un reclamo con un mensaje, y devuelve [empresa, proveedor, reclamo]. */
    private function reclamoDePrueba(): array
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $reclamo = app(ReclamoService::class)->crear(
            $compras,
            $empresa->Id_Empresa,
            $proveedor->Id_Proveedor,
            'Producto en mal estado',
            'Calidad',
            'Alto',
            'Llegó un lote con empaques rotos.',
            [['rol_contacto' => 'Calidad', 'nombre_contacto' => 'Contacto', 'email' => 'contacto@proveedor-ejemplo.com']],
        );

        return [$empresa, $proveedor, $reclamo];
    }

    public function test_calidad_ve_el_historial_de_reclamos_por_proveedor(): void
    {
        [$empresa, $proveedor, $reclamo] = $this->reclamoDePrueba();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $cabeceras = $this->cabecerasComo($calidad, $empresa);

        $proveedores = $this->getJson('/api/reportes/historial-reclamos', $cabeceras)
            ->assertSuccessful()
            ->json();

        $fila = collect($proveedores)->firstWhere('id_proveedor', $proveedor->Id_Proveedor);
        $this->assertNotNull($fila, 'El proveedor con reclamos tiene que aparecer en el reporte.');
        $this->assertSame(1, $fila['total']);
        $this->assertSame(1, $fila['abiertos']);

        $historial = $this->getJson("/api/reportes/historial-reclamos/proveedor/{$proveedor->Id_Proveedor}", $cabeceras)
            ->assertSuccessful()
            ->json();

        $this->assertCount(1, $historial['reclamos']);
        $this->assertSame($reclamo->Id_Reclamo, $historial['reclamos'][0]['id_reclamo']);
        $this->assertSame(1, $historial['reclamos'][0]['total_mensajes']);
    }

    public function test_compras_y_el_proveedor_no_entran_al_reporte(): void
    {
        [$empresa, $proveedor] = $this->reclamoDePrueba();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');
        [$usuarioProveedor] = $this->crearProveedorConUsuario($empresa);

        $this->getJson('/api/reportes/historial-reclamos', $this->cabecerasComo($compras, $empresa))
            ->assertForbidden();

        $this->getJson("/api/reportes/historial-reclamos/proveedor/{$proveedor->Id_Proveedor}", $this->cabecerasComo($usuarioProveedor, $empresa))
            ->assertForbidden();
    }

    public function test_se_descarga_el_pdf_de_un_reclamo_y_el_del_proveedor(): void
    {
        [$empresa, $proveedor, $reclamo] = $this->reclamoDePrueba();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $cabeceras = $this->cabecerasComo($sistemas, $empresa);

        $unReclamo = $this->get("/api/reportes/historial-reclamos/reclamo/{$reclamo->Id_Reclamo}/pdf", $cabeceras)
            ->assertSuccessful();
        $this->assertSame('application/pdf', $unReclamo->headers->get('content-type'));

        $delProveedor = $this->get("/api/reportes/historial-reclamos/proveedor/{$proveedor->Id_Proveedor}/pdf", $cabeceras)
            ->assertSuccessful();
        $this->assertSame('application/pdf', $delProveedor->headers->get('content-type'));
        // download() de dompdf devuelve una respuesta normal (no streamed):
        // el contenido se lee con getContent().
        $this->assertStringStartsWith('%PDF', $delProveedor->getContent());
    }

    public function test_un_reclamo_de_otra_empresa_no_se_descarga(): void
    {
        [, , $reclamo] = $this->reclamoDePrueba();
        $otraEmpresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($otraEmpresa, 'Sistemas');

        $this->get(
            "/api/reportes/historial-reclamos/reclamo/{$reclamo->Id_Reclamo}/pdf",
            $this->cabecerasComo($sistemas, $otraEmpresa)
        )->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Horario de entrega en el correo de recepciones del día
    // ------------------------------------------------------------------

    public function test_el_aviso_de_recepciones_del_dia_lleva_el_horario_de_entrega(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Al proveedor le toca recepción hoy y tiene horario cargado.
        $this->crearEntrega($empresa, $proveedor, horaLlegada: '08:30');
        HorarioEntregaProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
            ->update(['Anden_Puerta' => '3']);

        // El aviso solo sale en día laborable (ver esDiaDeAviso): si la
        // suite corre un feriado o un fin de semana, no hay nada que
        // comprobar y la prueba se omite en vez de fallar por el almanaque.
        $agenda = app(\App\Modules\Auditorias\Services\AgendaRecepcionService::class);

        if (! $agenda->esDiaDeAviso(now())) {
            $this->markTestSkipped('Hoy no es día de aviso de recepciones (fin de semana o feriado).');
        }

        $this->artisan('auditorias:avisar-recepciones-del-dia')->assertSuccessful();

        Notification::assertSentTo(
            $calidad,
            \App\Modules\Auditorias\Notifications\RecepcionesDelDiaNotification::class,
            function ($notificacion) use ($calidad, $proveedor) {
                $mail = $notificacion->toMail($calidad);
                $fila = collect($mail->proveedores)->firstWhere('razon_social', $proveedor->Razon_Social);

                return $fila !== null
                    && ! empty($fila['horarios'])
                    && str_contains($fila['horarios'][0], '08:30')
                    && str_contains($fila['horarios'][0], 'andén 3');
            }
        );
    }

    /** El usuario de Calidad se crea activo: si no, no recibiría avisos. */
    public function test_los_usuarios_de_calidad_creados_quedan_activos(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $this->assertTrue((bool) Usuario::find($calidad->Id_Usuario)->Activo);
    }
}
