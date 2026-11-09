<?php

namespace Tests\Feature\HorariosEntrega;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Http\Middleware\TokenIntegracionBc;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Calendario de horarios para Business Central (09-nov-2026): token
 * compartido, empresa por nombre/código de la compañía de BC y escritura
 * en nombre del usuario interno configurado, con la misma lógica de la
 * carga masiva por Excel.
 */
class IntegracionBcHorariosTest extends TestCase
{
    private const URL = '/api/integraciones/bc/horarios-entrega';

    private const TOKEN = 'token-de-prueba-bc-0123456789-0123456789';

    private function cabeceras(?string $token = self::TOKEN): array
    {
        return $token === null ? [] : [TokenIntegracionBc::CABECERA => $token];
    }

    /** Empresa con un Empresa_BC único (crearEmpresa pone 'test' en todas). */
    private function empresaBc(): Empresa
    {
        return $this->crearEmpresa(['Empresa_BC' => 'bc_'.Str::lower(Str::random(10))]);
    }

    private function habilitar(Empresa $empresa, string $rol = 'Sistemas'): void
    {
        $usuario = $this->crearUsuarioInterno($empresa, $rol);
        config(['integraciones.bc.token' => self::TOKEN, 'integraciones.bc.usuario' => $usuario->Email]);
    }

    private function fila(string $codigoBc, array $extra = []): array
    {
        return [
            'clasificacion' => 'Fruver',
            'dia_entrega' => 'Martes',
            'identificador' => $codigoBc,
            'anden_puerta' => 'puerta b',
            'hora_llegada' => '07:30',
            'tiempo_permanencia' => '45',
            'tiempo_preparacion' => '15',
            'hora_salida' => '',
            ...$extra,
        ];
    }

    public function test_sin_token_configurado_responde_503(): void
    {
        config(['integraciones.bc.token' => null]);

        $this->postJson(self::URL.'/carga-masiva', [], $this->cabeceras())->assertStatus(503);
    }

    public function test_token_ausente_o_distinto_responde_401(): void
    {
        config(['integraciones.bc.token' => self::TOKEN]);

        $this->postJson(self::URL.'/carga-masiva', [], $this->cabeceras(null))->assertStatus(401);
        $this->postJson(self::URL.'/carga-masiva', [], $this->cabeceras('otro-token'))->assertStatus(401);
        $this->getJson(self::URL.'?empresa_bc=x', $this->cabeceras('otro-token'))->assertStatus(401);
    }

    public function test_sin_usuario_configurado_responde_503(): void
    {
        $empresa = $this->empresaBc();
        config(['integraciones.bc.token' => self::TOKEN, 'integraciones.bc.usuario' => null]);

        $this->postJson(self::URL.'/carga-masiva', [
            'empresa_bc' => trim($empresa->Empresa_BC),
            'identificador' => 'codigo_bc',
            'filas' => [],
        ], $this->cabeceras())->assertStatus(503);
    }

    public function test_empresa_desconocida_es_error_de_validacion(): void
    {
        $empresa = $this->empresaBc();
        $this->habilitar($empresa);

        $this->postJson(self::URL.'/carga-masiva', [
            'empresa_bc' => 'no-existe-'.Str::random(6),
            'identificador' => 'codigo_bc',
            'confirmar' => true,
            'filas' => [$this->fila('PROV-X')],
        ], $this->cabeceras())->assertStatus(422)->assertJsonValidationErrors(['empresa_bc']);
    }

    public function test_usuario_sin_rol_sistemas_ni_admin_recibe_403(): void
    {
        $empresa = $this->empresaBc();
        $this->habilitar($empresa, 'Compras');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->postJson(self::URL.'/carga-masiva', [
            'empresa_bc' => trim($empresa->Empresa_BC),
            'identificador' => 'ruc',
            'confirmar' => true,
            'filas' => [$this->fila($proveedor->Ruc)],
        ], $this->cabeceras())->assertStatus(403);

        $this->assertFalse(HorarioEntregaProveedor::where('Id_Empresa', $empresa->Id_Empresa)->exists());
    }

    public function test_crea_el_horario_en_nombre_del_usuario_configurado_y_lo_lista(): void
    {
        $empresa = $this->empresaBc();
        $this->habilitar($empresa, 'Admin');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        // Por código BC: el portal lo guarda en Nro_Proveedor_BC al aprobar.
        $codigo = 'PRV'.random_int(100000, 999999);
        Proveedor::whereKey($proveedor->Id_Proveedor)->update(['Nro_Proveedor_BC' => $codigo]);

        // El nombre de la compañía llega con mayúsculas y espacios: igual
        // que lo manda BC y que lo guarda la base (CHAR con relleno).
        $respuesta = $this->postJson(self::URL.'/carga-masiva', [
            'empresa_bc' => '  '.mb_strtoupper(trim($empresa->Empresa_BC)).'  ',
            'identificador' => 'codigo_bc',
            'confirmar' => true,
            'filas' => [$this->fila($codigo), $this->fila($codigo, ['hora_llegada' => '99:00'])],
        ], $this->cabeceras());

        $respuesta->assertOk()
            ->assertJsonPath('id_empresa', $empresa->Id_Empresa)
            ->assertJsonPath('aplicado', true)
            ->assertJsonPath('resumen.crear', 1)
            ->assertJsonPath('resumen.errores', 1)
            ->assertJsonPath('filas.0.estado', 'crear')
            ->assertJsonPath('filas.1.estado', 'error');

        $horario = HorarioEntregaProveedor::where('Id_Empresa', $empresa->Id_Empresa)->sole();
        $this->assertSame(HorarioEntregaProveedor::FRUVER, $horario->Clasificacion);
        $this->assertSame('Martes', $horario->Dia_Entrega);
        $this->assertSame('PUERTA B', $horario->Anden_Puerta);
        $this->assertSame('07:30', $horario->Hora_Llegada);
        $this->assertSame(45, (int) $horario->Tiempo_Permanencia_Min);
        $this->assertSame(15, (int) $horario->Tiempo_Preparacion_Min);
        $this->assertSame('08:15', $horario->Hora_Salida);
        $this->assertSame((int) Usuario::where('Email', config('integraciones.bc.usuario'))->value('Id_Usuario'), (int) $horario->Creado_Por);

        $lista = $this->getJson(self::URL.'?empresa_bc='.urlencode(trim($empresa->Empresa_BC)).'&clasificacion=Fruver', $this->cabeceras());

        $lista->assertOk()
            ->assertJsonPath('id_empresa', $empresa->Id_Empresa)
            ->assertJsonCount(1, 'horarios')
            ->assertJsonPath('horarios.0.codigo_proveedor', $codigo)
            ->assertJsonPath('horarios.0.ruc_proveedor', $proveedor->Ruc)
            ->assertJsonPath('horarios.0.hora_llegada', '07:30');
    }

    public function test_la_vista_previa_no_escribe(): void
    {
        $empresa = $this->empresaBc();
        $this->habilitar($empresa);
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->postJson(self::URL.'/carga-masiva', [
            'empresa_bc' => trim($empresa->Empresa_BC),
            'identificador' => 'ruc',
            'confirmar' => false,
            'filas' => [$this->fila($proveedor->Ruc)],
        ], $this->cabeceras())->assertOk()->assertJsonPath('aplicado', false)->assertJsonPath('resumen.crear', 1);

        $this->assertFalse(HorarioEntregaProveedor::where('Id_Empresa', $empresa->Id_Empresa)->exists());
    }

    /**
     * Caso real del 09-nov: un proveedor que no existe en BC (EQPEZ RIVERA,
     * Aspirante sin código BC) tiene un horario en el portal. BC no lo puede
     * mandar; con conservar_ids el portal no lo da de baja, y el resto de la
     * clasificación se sigue reemplazando como siempre.
     */
    public function test_conservar_ids_no_da_de_baja_lo_que_bc_no_conoce(): void
    {
        $empresa = $this->empresaBc();
        $this->habilitar($empresa);
        [, $deBc] = $this->crearProveedorConUsuario($empresa);
        [, $sinBc] = $this->crearProveedorConUsuario($empresa);
        [, $otro] = $this->crearProveedorConUsuario($empresa);

        $nuevo = fn (Proveedor $p, string $dia) => HorarioEntregaProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $p->Id_Proveedor,
            'Clasificacion' => HorarioEntregaProveedor::FRUVER,
            'Dia_Entrega' => $dia,
            'Hora_Llegada' => '07:30',
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
        $hDeBc = $nuevo($deBc, 'Martes');
        $hSinBc = $nuevo($sinBc, 'Lunes');
        $hOtro = $nuevo($otro, 'Jueves');

        $this->postJson(self::URL.'/carga-masiva', [
            'empresa_bc' => trim($empresa->Empresa_BC),
            'identificador' => 'ruc',
            'confirmar' => true,
            'filas' => [$this->fila($deBc->Ruc)],
            'conservar_ids' => [$hSinBc->Id_Horario_Entrega_Proveedor],
        ], $this->cabeceras())
            ->assertOk()
            ->assertJsonPath('resumen.actualizar', 1)
            ->assertJsonPath('resumen.conservados', 1)
            ->assertJsonPath('resumen.dar_de_baja', 1);

        $this->assertTrue((bool) $hDeBc->fresh()->Activo);
        $this->assertTrue((bool) $hSinBc->fresh()->Activo, 'El horario conservado no se debe dar de baja.');
        $this->assertFalse((bool) $hOtro->fresh()->Activo, 'Lo que no viene ni se conserva se sigue dando de baja.');
    }
}
