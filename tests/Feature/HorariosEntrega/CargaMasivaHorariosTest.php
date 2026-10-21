<?php

namespace Tests\Feature\HorariosEntrega;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Horarios_Entrega\Models\HorarioEntregaProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Carga masiva del calendario desde Excel (21-oct-2026). El archivo
 * reemplaza el calendario de las clasificaciones que trae, se cargan las
 * filas válidas, y antes de aplicar hay una vista previa que no escribe.
 */
class CargaMasivaHorariosTest extends TestCase
{
    private const URL = '/api/horarios-entrega/carga-masiva';

    private function fila(string $identificador, array $extra = []): array
    {
        return [
            'clasificacion' => 'Perecibles',
            'dia_entrega' => 'LUNES',
            'identificador' => $identificador,
            'anden_puerta' => 'a',
            'hora_llegada' => '8:00:00',
            'tiempo_permanencia' => '0:30:00',
            'hora_salida' => '',
            ...$extra,
        ];
    }

    private function cargar(Usuario $usuario, Empresa $empresa, string $identificador, array $filas, bool $confirmar)
    {
        return $this->postJson(self::URL, [
            'identificador' => $identificador,
            'confirmar' => $confirmar,
            'filas' => $filas,
        ], $this->cabecerasComo($usuario, $empresa));
    }

    private function horario(Empresa $empresa, Proveedor $proveedor, string $clasificacion, string $dia, string $llegada = '10:00'): HorarioEntregaProveedor
    {
        return HorarioEntregaProveedor::create([
            'Id_Empresa' => $empresa->Id_Empresa,
            'Id_Proveedor' => $proveedor->Id_Proveedor,
            'Clasificacion' => $clasificacion,
            'Dia_Entrega' => $dia,
            'Hora_Llegada' => $llegada,
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
    }

    public function test_la_vista_previa_no_escribe_nada(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->cargar($sistemas, $empresa, 'ruc', [$this->fila($proveedor->Ruc)], confirmar: false)
            ->assertOk()
            ->assertJsonPath('aplicado', false)
            ->assertJsonPath('resumen.crear', 1);

        $this->assertFalse(HorarioEntregaProveedor::where('Id_Empresa', $empresa->Id_Empresa)->exists());
    }

    public function test_por_ruc_crea_el_horario_y_calcula_la_salida(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $this->cargar($sistemas, $empresa, 'ruc', [
            $this->fila($proveedor->Ruc, ['dia_entrega' => 'Miércoles', 'clasificacion' => 'NO PERECIBLES']),
        ], confirmar: true)->assertOk()->assertJsonPath('resumen.crear', 1);

        $horario = HorarioEntregaProveedor::where('Id_Empresa', $empresa->Id_Empresa)->sole();
        $this->assertSame('Miercoles', $horario->Dia_Entrega);
        $this->assertSame(HorarioEntregaProveedor::NO_PERECIBLES, $horario->Clasificacion);
        $this->assertSame('08:00', $horario->Hora_Llegada);
        $this->assertSame(30, (int) $horario->Tiempo_Permanencia_Min);
        $this->assertSame('08:30', $horario->Hora_Salida);
        $this->assertSame('A', $horario->Anden_Puerta);
    }

    /** Código BC: el que guarda el portal al aprobar y el de la copia de BC. */
    public function test_por_codigo_bc_encuentra_al_proveedor_en_los_dos_lugares(): void
    {
        $empresa = $this->crearEmpresa(['Empresa_BC' => 'test_'.Str::lower(Str::random(6))]);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $aprobado] = $this->crearProveedorConUsuario($empresa);
        // forceFill: Nro_Proveedor_BC no es asignable en masa (lo escribe el posteo a BC).
        $aprobado->forceFill(['Nro_Proveedor_BC' => 'PROV-T00001'])->save();
        [, $enBc] = $this->crearProveedorConUsuario($empresa);

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $empresa->Empresa_BC,
            'Nro_Proveedor' => 'PROV-T00002',
            'Nombre' => 'PRUEBA',
            'Nro_Identificacion' => $enBc->Ruc,
            'Fecha_Registro' => now(),
        ]);

        $this->cargar($sistemas, $empresa, 'codigo_bc', [
            $this->fila('prov-t00001'),
            $this->fila(' PROV-T00002 ', ['dia_entrega' => 'Martes']),
        ], confirmar: true)->assertOk()->assertJsonPath('resumen.crear', 2);

        $this->assertEqualsCanonicalizing(
            [$aprobado->Id_Proveedor, $enBc->Id_Proveedor],
            HorarioEntregaProveedor::where('Id_Empresa', $empresa->Id_Empresa)->pluck('Id_Proveedor')->all()
        );
    }

    /**
     * Reemplaza la clasificación que trae: actualiza lo que coincide, da de
     * baja lo que no viene, y no toca las otras clasificaciones.
     */
    public function test_reemplaza_solo_las_clasificaciones_del_archivo(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $a] = $this->crearProveedorConUsuario($empresa);
        [, $b] = $this->crearProveedorConUsuario($empresa);

        $sigue = $this->horario($empresa, $a, HorarioEntregaProveedor::PERECIBLES, 'Lunes');
        $seVa = $this->horario($empresa, $b, HorarioEntregaProveedor::PERECIBLES, 'Martes');
        $otraClasificacion = $this->horario($empresa, $b, HorarioEntregaProveedor::FRUVER, 'Martes');

        // Misma entrega (mismo día y hora): se actualiza, aquí el andén.
        $archivo = [$this->fila($a->Ruc, ['hora_llegada' => '10:00', 'anden_puerta' => 'Z'])];

        $previa = $this->cargar($sistemas, $empresa, 'ruc', $archivo, confirmar: false)
            ->assertOk()
            ->assertJsonPath('resumen.actualizar', 1)
            ->assertJsonPath('resumen.dar_de_baja', 1)
            ->json();
        $this->assertSame('Martes', $previa['bajas'][0]['dia_entrega']);

        $this->cargar($sistemas, $empresa, 'ruc', $archivo, confirmar: true)->assertOk();

        $this->assertSame('Z', $sigue->fresh()->Anden_Puerta);
        $this->assertTrue((bool) $sigue->fresh()->Activo);
        $this->assertFalse((bool) $seVa->fresh()->Activo);
        $this->assertTrue((bool) $otraClasificacion->fresh()->Activo);
    }

    public function test_carga_las_validas_e_informa_las_que_fallan(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        $respuesta = $this->cargar($sistemas, $empresa, 'ruc', [
            $this->fila($proveedor->Ruc),
            $this->fila('9999999999999'),
            // Misma hora el mismo día: esa sí es repetida.
            $this->fila($proveedor->Ruc, ['dia_entrega' => 'Lunes', 'hora_llegada' => '08:00']),
            $this->fila($proveedor->Ruc, ['dia_entrega' => 'Viernes', 'hora_llegada' => '25:00']),
            $this->fila($proveedor->Ruc, ['dia_entrega' => 'Feriado']),
        ], confirmar: true)->assertOk();

        $respuesta->assertJsonPath('resumen.crear', 1)->assertJsonPath('resumen.errores', 4);
        $mensajes = collect($respuesta->json('filas'))->where('estado', 'error')->pluck('mensaje')->implode(' | ');
        $this->assertStringContainsString('No hay ningún proveedor con RUC 9999999999999', $mensajes);
        $this->assertStringContainsString('Repetida', $mensajes);
        $this->assertStringContainsString('Hora de llegada', $mensajes);
        $this->assertStringContainsString('Día no reconocido', $mensajes);
        $this->assertSame(1, HorarioEntregaProveedor::where('Id_Empresa', $empresa->Id_Empresa)->count());
    }

    /** Un proveedor de otra empresa no se encuentra aunque el RUC exista. */
    public function test_no_usa_proveedores_de_otra_empresa(): void
    {
        $empresa = $this->crearEmpresa();
        $otra = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $ajeno] = $this->crearProveedorConUsuario($otra);

        $this->cargar($sistemas, $empresa, 'ruc', [$this->fila($ajeno->Ruc)], confirmar: true)
            ->assertOk()
            ->assertJsonPath('resumen.errores', 1);
    }

    public function test_solo_sistemas_hace_la_carga_masiva(): void
    {
        $empresa = $this->crearEmpresa();
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);

        foreach (['Admin', 'Compras', 'Calidad'] as $rol) {
            $this->cargar($this->crearUsuarioInterno($empresa, $rol), $empresa, 'ruc', [$this->fila($proveedor->Ruc)], confirmar: false)
                ->assertForbidden();
        }
    }

    /** El selector de la pantalla también usa el código que guarda el portal al aprobar. */
    public function test_el_selector_muestra_el_codigo_guardado_por_el_portal_y_el_ruc(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $proveedor->forceFill(['Nro_Proveedor_BC' => 'PROV-T00009'])->save();

        $fila = collect($this->getJson('/api/horarios-entrega/proveedores', $this->cabecerasComo($sistemas, $empresa))->assertOk()->json())
            ->firstWhere('id_proveedor', $proveedor->Id_Proveedor);

        $this->assertSame('PROV-T00009', $fila['codigo_bc']);
        $this->assertSame($proveedor->Ruc, $fila['ruc']);
    }

    /** Cambiar la hora es otra entrega: se crea la nueva y se da de baja la anterior. */
    public function test_cambiar_la_hora_reemplaza_la_entrega(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $vieja = $this->horario($empresa, $proveedor, HorarioEntregaProveedor::PERECIBLES, 'Lunes', '10:00');

        $this->cargar($sistemas, $empresa, 'ruc', [$this->fila($proveedor->Ruc, ['hora_llegada' => '09:15'])], confirmar: true)
            ->assertOk()
            ->assertJsonPath('resumen.crear', 1)
            ->assertJsonPath('resumen.dar_de_baja', 1);

        $this->assertFalse((bool) $vieja->fresh()->Activo);
    }

    /** Un proveedor puede entregar varias veces el mismo día, a distintas horas. */
    public function test_un_proveedor_puede_tener_varias_entregas_el_mismo_dia(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        [, $proveedor] = $this->crearProveedorConUsuario($empresa);
        $manana = $this->horario($empresa, $proveedor, HorarioEntregaProveedor::PERECIBLES, 'Lunes', '08:00');

        $this->cargar($sistemas, $empresa, 'ruc', [
            $this->fila($proveedor->Ruc, ['hora_llegada' => '08:00', 'anden_puerta' => 'C']),
            $this->fila($proveedor->Ruc, ['hora_llegada' => '15:00']),
        ], confirmar: true)
            ->assertOk()
            ->assertJsonPath('resumen.actualizar', 1)
            ->assertJsonPath('resumen.crear', 1)
            ->assertJsonPath('resumen.errores', 0)
            ->assertJsonPath('resumen.dar_de_baja', 0);

        $this->assertSame('C', $manana->fresh()->Anden_Puerta);
        $this->assertEqualsCanonicalizing(
            ['08:00', '15:00'],
            HorarioEntregaProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)->where('Activo', 1)->pluck('Hora_Llegada')->all()
        );
    }

    /** Código que existe en BC pero cuyo proveedor no tiene ficha en el portal: se dice tal cual. */
    public function test_avisa_si_el_proveedor_esta_en_bc_pero_no_en_el_portal(): void
    {
        $empresa = $this->crearEmpresa(['Empresa_BC' => 'test_'.Str::lower(Str::random(6))]);
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        DB::table('BC_Ficha_Proveedor')->insert([
            'Empresa' => $empresa->Empresa_BC,
            'Nro_Proveedor' => 'PROV-T00077',
            'Nombre' => 'SOLO EN BC S.A.',
            'Nro_Identificacion' => $this->rucFalso(),
            'Fecha_Registro' => now(),
        ]);

        $mensaje = $this->cargar($sistemas, $empresa, 'codigo_bc', [$this->fila('PROV-T00077')], confirmar: false)
            ->assertOk()
            ->json('filas.0.mensaje');

        $this->assertStringContainsString('SOLO EN BC S.A.', $mensaje);
        $this->assertStringContainsString('no está registrado en el portal', $mensaje);
    }
}
