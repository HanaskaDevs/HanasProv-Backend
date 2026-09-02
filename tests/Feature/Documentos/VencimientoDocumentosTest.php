<?php

namespace Tests\Feature\Documentos;

use App\Models\Empresa;
use App\Modules\Documentos_Proveedor\Models\Archivo;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Documentos_Proveedor\Notifications\DocumentoPorVencerNotification;
use App\Modules\Documentos_Proveedor\Notifications\ProveedorSuspendidoNotification;
use App\Modules\Documentos_Proveedor\Services\VencimientoDocumentosService;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ciclo completo del vencimiento de documentos:
 * aviso a los 30 días -> reaviso semanal -> suspensión 15 días después
 * del vencimiento (solo del proveedor de ESA empresa).
 *
 * OJO con las aserciones: los tests corren contra la base de pruebas, que
 * TIENE datos reales, y estos comandos barren toda la tabla. Por eso nunca
 * se afirma "se mandaron N notificaciones en total" (el número depende de
 * cuántos documentos reales estén por vencer ese día), sino que se verifica
 * a QUIÉN le llegó y a quién no.
 */
class VencimientoDocumentosTest extends TestCase
{
    private function servicio(): VencimientoDocumentosService
    {
        return app(VencimientoDocumentosService::class);
    }

    /**
     * Corre el comando de suspensión con el reloj YA PASADO el candado por
     * fecha (portal.suspension_documentos_desde, 1-ene-2027).
     *
     * Hace falta porque durante 2026 el comando no suspende a nadie a
     * propósito, y estos tests verifican la MECÁNICA de la suspensión (que
     * respete los 15 días, que no cruce empresas, que deje historial), no
     * el candado -- ese tiene sus propios tests más arriba.
     *
     * Los documentos se crean con fechas relativas a now(), así que al
     * congelar el reloj todas se corren juntas y los plazos se mantienen.
     */
    private function correrSuspensionPasadoElCandado(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-01 08:30:00'));

        try {
            $this->artisan('documentos:suspender-vencidos')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }
    }


    /** Documento con fecha de caducidad para un proveedor. */
    private function documentoQueVence(Proveedor $proveedor, string $fechaCaducidad, int $idUsuarioCarga): DocumentoProveedor
    {
        $tipo = TipoDocumento::where('Activo', 1)->firstOrFail();

        $archivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Original' => 'permiso.pdf',
            'Ruta_Almacenamiento' => 'tests/permiso.pdf',
            'Hash_Archivo' => hash('sha256', uniqid()),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 2048,
            'Categoria_Archivo' => 'test',
            'Id_Usuario_Carga' => $idUsuarioCarga,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);

        return DocumentoProveedor::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
            'Id_Archivo' => $archivo->Id_Archivo,
            'Fecha_Caducidad' => $fechaCaducidad,
            'Estado' => 'Vigente',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Candado por fecha: hasta el 31-dic-2026 se avisa pero no se suspende
    // ---------------------------------------------------------------

    /**
     * Decisión del negocio (2-sep-2026): durante todo 2026 el portal avisa
     * de los documentos vencidos pero NO suspende ni inactiva a nadie. El
     * 1-ene-2027 el ciclo arranca solo.
     *
     * Se prueba la FRONTERA, que es donde estaría el error si lo hubiera:
     * el último día de 2026 no debe suspender y el primero de 2027 sí.
     */
    public static function fechasYSuspension(): array
    {
        return [
            'hoy (2026)' => ['2026-09-02', false],
            'un día antes del corte' => ['2026-12-31', false],
            'el día del corte' => ['2027-01-01', true],
            'bien entrado 2027' => ['2027-06-15', true],
        ];
    }

    #[DataProvider('fechasYSuspension')]
    public function test_la_suspension_recien_es_exigible_desde_2027(string $fecha, bool $esperado): void
    {
        $this->assertSame(
            $esperado,
            $this->servicio()->suspensionYaEsExigible(Carbon::parse($fecha)),
            "El {$fecha} la suspensión ".($esperado ? 'debería' : 'NO debería').' poder aplicarse.'
        );
    }

    public function test_el_comando_no_suspende_a_nadie_durante_2026(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, [
            'Id_Estado_Proveedor' => EstadoProveedor::APROBADO,
        ]);

        // Documento vencido hace mucho más que los 15 días de gracia: sin el
        // candado por fecha, este proveedor se suspendería sí o sí.
        $this->documentoQueVence($proveedor, now()->subDays(60)->toDateString(), $sistemas->Id_Usuario);

        Carbon::setTestNow(Carbon::parse('2026-12-31 08:30:00'));

        try {
            $this->artisan('documentos:suspender-vencidos')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            EstadoProveedor::APROBADO,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Durante 2026 el proveedor tiene que seguir Aprobado, con documento vencido y todo.'
        );
        Notification::assertNothingSent();
    }

    /** El mismo caso, un día después: ahí sí suspende. */
    public function test_el_comando_si_suspende_a_partir_del_1_de_enero_de_2027(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, [
            'Id_Estado_Proveedor' => EstadoProveedor::APROBADO,
        ]);

        $this->documentoQueVence($proveedor, '2026-11-01', $sistemas->Id_Usuario);

        Carbon::setTestNow(Carbon::parse('2027-01-01 08:30:00'));

        try {
            $this->artisan('documentos:suspender-vencidos')->assertSuccessful();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            EstadoProveedor::SUSPENDIDO,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Desde el 1-ene-2027 el ciclo completo tiene que volver a funcionar.'
        );
    }

    /** Los AVISOS no pasan por el candado: siguen saliendo todo 2026. */
    public function test_los_avisos_siguen_funcionando_durante_2026(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->documentoQueVence($proveedor, now()->addDays(10)->toDateString(), $sistemas->Id_Usuario);

        Carbon::setTestNow(Carbon::parse('2026-09-02 08:30:00'));

        try {
            $paraAvisar = $this->servicio()->documentosParaAvisar();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertGreaterThan(
            0,
            $paraAvisar->count(),
            'El candado es SOLO para suspender: los avisos tienen que seguir saliendo.'
        );
    }

    public function test_avisa_treinta_dias_antes_y_no_antes(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $aTiempo = $this->documentoQueVence($proveedor, now()->addDays(20)->toDateString(), $admin->Id_Usuario);
        $lejano = $this->documentoQueVence($proveedor, now()->addDays(90)->toDateString(), $admin->Id_Usuario);

        $paraAvisar = $this->servicio()->documentosParaAvisar()->pluck('Id_Documento_Proveedor');

        $this->assertContains($aTiempo->Id_Documento_Proveedor, $paraAvisar, 'Un documento a 20 días debe avisarse.');
        $this->assertNotContains($lejano->Id_Documento_Proveedor, $paraAvisar, 'Uno a 90 días todavía no.');
    }

    public function test_el_aviso_va_al_proveedor_y_a_calidad_y_admin(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->documentoQueVence($proveedor, now()->addDays(10)->toDateString(), $admin->Id_Usuario);

        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->artisan('documentos:avisar-vencimientos')->assertSuccessful();

        // Al proveedor, a su casilla de la Ficha (ruta anónima).
        Notification::assertSentOnDemand(
            DocumentoPorVencerNotification::class,
            fn ($notificacion, $canales, $notifiable) => ($notifiable->routes['mail'] ?? null) === $proveedor->Email
        );

        // A los internos de Calidad y Admin de esa empresa, y a Compras NO.
        Notification::assertSentTo($admin, DocumentoPorVencerNotification::class);
        Notification::assertSentTo($calidad, DocumentoPorVencerNotification::class);
        Notification::assertNotSentTo($compras, DocumentoPorVencerNotification::class);
    }

    public function test_no_reavisa_antes_de_una_semana_y_si_despues(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $documento = $this->documentoQueVence($proveedor, now()->addDays(10)->toDateString(), $admin->Id_Usuario);

        $servicio = $this->servicio();
        $servicio->marcarAvisado($documento);

        $this->assertNotContains(
            $documento->Id_Documento_Proveedor,
            $servicio->documentosParaAvisar()->pluck('Id_Documento_Proveedor'),
            'Recién avisado: no debe volver a avisarse hoy.'
        );

        $documento->forceFill(['Fecha_Ultima_Notificacion' => now()->subDays(8)])->save();

        $this->assertContains(
            $documento->Id_Documento_Proveedor,
            $servicio->documentosParaAvisar()->pluck('Id_Documento_Proveedor'),
            'Pasada una semana corresponde el reaviso.'
        );
    }

    public function test_suspende_recien_a_los_quince_dias_del_vencimiento(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Id_Estado_Proveedor' => EstadoProveedor::APROBADO]);

        // Vencido hace 10 días: todavía en período de gracia.
        $documento = $this->documentoQueVence($proveedor, now()->subDays(10)->toDateString(), $admin->Id_Usuario);

        $this->assertFalse(
            $this->servicio()->proveedoresParaSuspender()->contains('Id_Proveedor', $proveedor->Id_Proveedor),
            'A los 10 días de vencido todavía no corresponde suspender.'
        );

        // Vencido hace 16: se pasó de los 15 de gracia.
        $documento->forceFill(['Fecha_Caducidad' => now()->subDays(16)->toDateString()])->save();

        $this->assertTrue(
            $this->servicio()->proveedoresParaSuspender()->contains('Id_Proveedor', $proveedor->Id_Proveedor)
        );
    }

    public function test_al_suspender_deja_historial_y_avisa(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Id_Estado_Proveedor' => EstadoProveedor::APROBADO]);
        $this->documentoQueVence($proveedor, now()->subDays(20)->toDateString(), $admin->Id_Usuario);

        $this->correrSuspensionPasadoElCandado();

        $this->assertSame(EstadoProveedor::SUSPENDIDO, (int) $proveedor->fresh()->Id_Estado_Proveedor);

        Notification::assertSentOnDemand(
            ProveedorSuspendidoNotification::class,
            fn ($notificacion, $canales, $notifiable) => ($notifiable->routes['mail'] ?? null) === $proveedor->Email
        );
        Notification::assertSentTo($admin, ProveedorSuspendidoNotification::class);

        $this->assertDatabaseHas('Historial_Estado_Proveedor', [
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Estado_Anterior' => EstadoProveedor::APROBADO,
            'Id_Estado_Nuevo' => EstadoProveedor::SUSPENDIDO,
        ]);
    }

    /** El interruptor de Configuraciones tiene que frenar la suspensión. */
    public function test_el_interruptor_apagado_no_suspende_a_nadie(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Id_Estado_Proveedor' => EstadoProveedor::APROBADO]);
        $this->documentoQueVence($proveedor, now()->subDays(20)->toDateString(), $admin->Id_Usuario);

        $this->servicio()->definirSuspensionAutomatica(false, $admin->Id_Usuario);

        $this->correrSuspensionPasadoElCandado();

        $this->assertSame(
            EstadoProveedor::APROBADO,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Con el interruptor apagado nadie debe quedar suspendido.'
        );
        Notification::assertNotSentTo($admin, ProveedorSuspendidoNotification::class);

        // Y encendido de nuevo, sí suspende.
        $this->servicio()->definirSuspensionAutomatica(true, $admin->Id_Usuario);
        $this->correrSuspensionPasadoElCandado();
        $this->assertSame(EstadoProveedor::SUSPENDIDO, (int) $proveedor->fresh()->Id_Estado_Proveedor);
    }

    /**
     * La suspensión es POR EMPRESA: el proveedor de la empresa con el
     * documento vencido queda suspendido, el de la otra empresa no se toca.
     */
    public function test_la_suspension_no_alcanza_a_las_otras_empresas(): void
    {
        Notification::fake();

        $empresaA = $this->crearEmpresa();
        $empresaB = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresaA, 'Admin');

        [, $proveedorA] = $this->crearProveedorConUsuario($empresaA, ['Id_Estado_Proveedor' => EstadoProveedor::APROBADO]);
        [, $proveedorB] = $this->crearProveedorConUsuario($empresaB, ['Id_Estado_Proveedor' => EstadoProveedor::APROBADO]);

        $this->documentoQueVence($proveedorA, now()->subDays(20)->toDateString(), $admin->Id_Usuario);

        $this->correrSuspensionPasadoElCandado();

        $this->assertSame(EstadoProveedor::SUSPENDIDO, (int) $proveedorA->fresh()->Id_Estado_Proveedor);
        $this->assertSame(
            EstadoProveedor::APROBADO,
            (int) $proveedorB->fresh()->Id_Estado_Proveedor,
            'El proveedor de la otra empresa está al día y no debe tocarse.'
        );
    }

    /** Levantar la suspensión devuelve al estado que tenía antes, no a uno fijo. */
    public function test_levantar_la_suspension_restaura_el_estado_anterior(): void
    {
        Notification::fake();

        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa, ['Id_Estado_Proveedor' => EstadoProveedor::ASPIRANTE]);
        $this->documentoQueVence($proveedor, now()->subDays(20)->toDateString(), $admin->Id_Usuario);

        $this->correrSuspensionPasadoElCandado();
        $this->assertSame(EstadoProveedor::SUSPENDIDO, (int) $proveedor->fresh()->Id_Estado_Proveedor);

        $this->servicio()->levantarSuspension($proveedor->fresh(), $admin->Id_Usuario);

        $this->assertSame(
            EstadoProveedor::ASPIRANTE,
            (int) $proveedor->fresh()->Id_Estado_Proveedor,
            'Era Aspirante antes de la suspensión: no debe volver como Aprobado.'
        );
    }

    /** Ya suspendido, se deja de insistir por correo (la pelota pasó a Admin). */
    public function test_deja_de_avisar_una_vez_suspendido(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $documento = $this->documentoQueVence($proveedor, now()->subDays(40)->toDateString(), $admin->Id_Usuario);

        $this->assertNotContains(
            $documento->Id_Documento_Proveedor,
            $this->servicio()->documentosParaAvisar()->pluck('Id_Documento_Proveedor')
        );
    }

    public function test_no_avisa_por_documentos_sin_fecha_de_caducidad(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuarioInterno($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $tipo = TipoDocumento::where('Activo', 1)->firstOrFail();
        $archivo = Archivo::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Nombre_Original' => 'ruc.pdf',
            'Ruta_Almacenamiento' => 'tests/ruc.pdf',
            'Hash_Archivo' => hash('sha256', uniqid()),
            'Tipo_Mime' => 'application/pdf',
            'Tamano_Bytes' => 1024,
            'Categoria_Archivo' => 'test',
            'Id_Usuario_Carga' => $admin->Id_Usuario,
            'Fecha_Carga' => now(),
            'Activo' => 1,
        ]);
        $sinFecha = DocumentoProveedor::create([
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Id_Tipo_Documento' => $tipo->Id_Tipo_Documento,
            'Id_Archivo' => $archivo->Id_Archivo,
            'Estado' => 'Vigente',
            'Activo' => 1,
            'Fecha_Creacion' => now(),
        ]);

        $this->assertNotContains(
            $sinFecha->Id_Documento_Proveedor,
            $this->servicio()->documentosParaAvisar()->pluck('Id_Documento_Proveedor')
        );
    }

    protected function tearDown(): void
    {
        // El interruptor vive en la tabla Configuracion, que DatabaseTransactions
        // revierte igual -> esto es solo para no dejar el valor cacheado en
        // memoria entre tests si algún día se le agrega caché.
        parent::tearDown();
    }
}
