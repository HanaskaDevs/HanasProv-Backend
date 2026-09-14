<?php

namespace Tests\Feature\Asistente;

use App\Modules\Asistente\Services\AsistenteHerramientas;
use App\Modules\Auth\Models\UsuarioBodega;
use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Las herramientas de consulta de Hana.
 *
 * LO CRÍTICO DE ESTE ARCHIVO son los tests de alcance. Una herramienta es una
 * puerta nueva a los datos, y si respeta permisos distintos a los de la
 * interfaz, el bot se convierte en la forma de leer lo que la pantalla no
 * muestra. Un usuario de Compras con una sola bodega asignada NO puede ver
 * pedidos de otra bodega preguntándole a Hana.
 */
class HerramientasAsistenteTest extends TestCase
{
    private function servicio(): AsistenteHerramientas
    {
        return app(AsistenteHerramientas::class);
    }

    /** Le asigna una bodega a un usuario de Compras. */
    private function asignarBodega(int $idUsuario, int $idEmpresa, string $codigo): void
    {
        UsuarioBodega::create([
            'Id_Usuario' => $idUsuario,
            'Id_Empresa' => $idEmpresa,
            'Cod_Almacen' => $codigo,
            'Activo' => true,
            'Fecha_Creacion' => now(),
        ]);
    }

    /**
     * La empresa real del ambiente, con sus pedidos de Business Central.
     *
     * POR QUÉ NO UNA EMPRESA DE PRUEBA: los pedidos NO viven en tablas del
     * portal, se leen de las BC_* filtradas por Empresa_BC (ver
     * PedidoInternoService::listarPorBodega). Una empresa creada por el test
     * tiene un Empresa_BC inventado, así que devuelve cero pedidos y cualquier
     * aserción sobre el filtro pasaría sin comprobar nada — que es justo lo que
     * pasó al escribir estos tests la primera vez.
     *
     * El usuario sí se crea acá y se va con la transacción; lo único que se
     * toma prestado es la empresa.
     */
    private function empresaConPedidos(): ?Empresa
    {
        return Empresa::whereNotNull('Empresa_BC')
            ->where('Empresa_BC', '!=', '')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('BC_Det_Pedido_Compra as d')
                    ->whereColumn(DB::raw('LTRIM(RTRIM(d.Empresa))'), DB::raw('LTRIM(RTRIM(Empresa.Empresa_BC))'));
            })
            ->first();
    }

    private function nombres(array $definiciones): array
    {
        return array_column($definiciones, 'name');
    }

    // ------------------------------------------------------- QUÉ HERRAMIENTAS

    public function test_un_proveedor_no_recibe_ninguna_herramienta(): void
    {
        $empresa = $this->crearEmpresa();
        [$usuario] = $this->crearProveedorConUsuario($empresa);

        $this->assertSame(
            [],
            $this->servicio()->definiciones($usuario, $empresa->Id_Empresa),
            'Un proveedor no puede tener herramientas: sus datos ya vienen en el contexto, y una '
            .'consulta libre sería una puerta lateral a datos de otros.'
        );
    }

    public function test_el_guardia_no_recibe_ninguna_herramienta(): void
    {
        $empresa = $this->crearEmpresa();
        $guardia = $this->crearUsuarioInterno($empresa, 'Guardia');

        $this->assertSame([], $this->servicio()->definiciones($guardia, $empresa->Id_Empresa));
    }

    public function test_sistemas_recibe_las_dos_herramientas(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $nombres = $this->nombres($this->servicio()->definiciones($sistemas, $empresa->Id_Empresa));

        $this->assertContains('consultar_pedidos', $nombres);
        $this->assertContains('consultar_calificaciones_proveedores', $nombres);
    }

    /** Compras ve pedidos, pero no el ranking de calificaciones. */
    public function test_compras_solo_recibe_la_de_pedidos(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $nombres = $this->nombres($this->servicio()->definiciones($compras, $empresa->Id_Empresa));

        $this->assertContains('consultar_pedidos', $nombres);
        $this->assertNotContains('consultar_calificaciones_proveedores', $nombres);
    }

    /** Calidad ve calificaciones, pero no pedidos por bodega. */
    public function test_calidad_solo_recibe_la_de_calificaciones(): void
    {
        $empresa = $this->crearEmpresa();
        $calidad = $this->crearUsuarioInterno($empresa, 'Calidad');

        $nombres = $this->nombres($this->servicio()->definiciones($calidad, $empresa->Id_Empresa));

        $this->assertContains('consultar_calificaciones_proveedores', $nombres);
        $this->assertNotContains('consultar_pedidos', $nombres);
    }

    // ------------------------------------------------------------- ALCANCE

    /**
     * EL TEST QUE MÁS IMPORTA. Compras con una sola bodega asignada pide otra:
     * la herramienta no puede devolvérsela, y además tiene que decir cuáles sí
     * tiene (si devolviera una lista vacía, el modelo diría "no hay pedidos en
     * esa bodega", que es falso y peor que negarlo).
     */
    public function test_compras_no_puede_consultar_una_bodega_que_no_tiene_asignada(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->asignarBodega($compras->Id_Usuario, $empresa->Id_Empresa, 'CD-0001');

        $resultado = $this->servicio()->ejecutar(
            'consultar_pedidos',
            ['bodega' => 'CD-0002'],
            $compras->fresh(),
            $empresa->Id_Empresa
        );

        $this->assertSame([], $resultado['filas'], 'No puede devolver ni una fila de una bodega ajena.');
        $this->assertStringContainsString('no tiene acceso', $resultado['texto']);
        $this->assertStringContainsString('CD-0001', $resultado['texto'], 'Debe decirle cuál SÍ tiene.');
        $this->assertStringNotContainsString('CD-0003', $resultado['texto']);
    }

    /**
     * Y sin pedir bodega tampoco: si consulta "todas", solo puede recibir las
     * suyas.
     */
    public function test_sin_filtro_de_bodega_compras_solo_ve_las_asignadas(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->asignarBodega($compras->Id_Usuario, $empresa->Id_Empresa, 'CD-0001');

        $resultado = $this->servicio()->ejecutar(
            'consultar_pedidos',
            [],
            $compras->fresh(),
            $empresa->Id_Empresa
        );

        foreach ($resultado['filas'] as $fila) {
            $this->assertSame(
                'CD-0001',
                $fila['Bodega'],
                'Apareció una fila de una bodega que este usuario no tiene asignada.'
            );
        }
    }

    /** Un rol sin herramientas tampoco puede ejecutarlas por nombre. */
    public function test_una_herramienta_inexistente_no_revienta(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        $resultado = $this->servicio()->ejecutar('borrar_todo', [], $sistemas, $empresa->Id_Empresa);

        $this->assertSame([], $resultado['filas']);
        $this->assertStringContainsString('No existe una herramienta', $resultado['texto']);
    }

    // ------------------------------------------------------------ CONVENIENCIA

    /**
     * El usuario escribe el código de bodega como se lo acuerda. "cd-002",
     * "CD 2" y "2" tienen que llegar a CD-0002 en vez de rebotar por formato.
     */
    public function test_normaliza_el_codigo_de_bodega_como_lo_escribe_el_usuario(): void
    {
        $empresa = $this->crearEmpresa();
        $compras = $this->crearUsuarioInterno($empresa, 'Compras');

        $this->asignarBodega($compras->Id_Usuario, $empresa->Id_Empresa, 'CD-0002');

        foreach (['cd-002', 'CD 2', 'CD0002', '2'] as $comoLoEscribio) {
            $resultado = $this->servicio()->ejecutar(
                'consultar_pedidos',
                ['bodega' => $comoLoEscribio],
                $compras->fresh(),
                $empresa->Id_Empresa
            );

            $this->assertStringNotContainsString(
                'no existe',
                $resultado['texto'],
                "'{$comoLoEscribio}' tendría que haberse entendido como CD-0002."
            );
        }
    }

    // ------------------------------------------------- FILTRO DE CUMPLIMIENTO

    /**
     * REGRESIÓN. Se pidió "los pedidos con 100% de entrega en CD-0003" y el
     * bot contestó que no había ninguno, teniendo 89. Lo que pasó: mandó
     * porcentaje_maximo=100, que significa "menores a 100", así que recibió
     * justo las 59 incompletas y concluyó de ahí que las completas no
     * existían.
     *
     * El parámetro estado_entrega existe para que ese pedido no dependa de que
     * el modelo acierte la dirección de un umbral. La propiedad que se
     * comprueba —completos + incompletos = total, y ningún completo por debajo
     * de 100— vale para cualquier cantidad de pedidos que tenga la base.
     */
    public function test_completos_e_incompletos_son_complementarios(): void
    {
        $empresa = $this->empresaConPedidos();

        if (! $empresa) {
            $this->markTestSkipped('No hay ninguna empresa con pedidos de BC en este ambiente.');
        }

        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $servicio = $this->servicio();

        // Se mide sobre UNA bodega y no sobre todas: sin filtro el resultado
        // llega recortado a MAX_FILAS (mil filas) y la suma no cerraría por el
        // tope, no por el filtro. Se toma la primera bodega que quepa entera.
        $bodega = null;
        $todos = null;

        foreach (['CD-0003', 'CD-0002', 'CD-0001'] as $codigo) {
            $resultado = $servicio->ejecutar('consultar_pedidos', ['bodega' => $codigo], $sistemas, $empresa->Id_Empresa);
            $cantidad = count($resultado['filas']);

            if ($cantidad > 0 && $cantidad < AsistenteHerramientas::MAX_FILAS) {
                $bodega = $codigo;
                $todos = $resultado;
                break;
            }
        }

        if ($bodega === null) {
            $this->markTestSkipped('Ninguna bodega de este ambiente entra completa bajo el tope de filas.');
        }

        $completos = $servicio->ejecutar('consultar_pedidos', ['bodega' => $bodega, 'estado_entrega' => 'completos'], $sistemas, $empresa->Id_Empresa);
        $incompletos = $servicio->ejecutar('consultar_pedidos', ['bodega' => $bodega, 'estado_entrega' => 'incompletos'], $sistemas, $empresa->Id_Empresa);

        $this->assertNotEmpty($completos['filas'], "La bodega {$bodega} tiene pedidos completos: el filtro tiene que encontrarlos.");

        $this->assertSame(
            count($todos['filas']),
            count($completos['filas']) + count($incompletos['filas']),
            'Completos + incompletos tiene que dar el total: si no, algún pedido se cae de los dos filtros.'
        );

        foreach ($completos['filas'] as $fila) {
            $this->assertSame(100.0, (float) $fila['% entrega'], 'Un pedido por debajo de 100 no es completo.');
        }

        foreach ($incompletos['filas'] as $fila) {
            $this->assertLessThan(100.0, (float) $fila['% entrega']);
        }
    }

    /**
     * La consulta tal como la hizo el bot: porcentaje_maximo=100 trae los
     * INCOMPLETOS. No es un bug del filtro, es lo que ese parámetro significa;
     * queda como test para que se vea por qué el modelo se confundió y por qué
     * existe estado_entrega.
     */
    public function test_porcentaje_maximo_100_trae_los_incompletos_no_los_completos(): void
    {
        $empresa = $this->empresaConPedidos();

        if (! $empresa) {
            $this->markTestSkipped('No hay ninguna empresa con pedidos de BC en este ambiente.');
        }

        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $servicio = $this->servicio();

        $porMaximo = $servicio->ejecutar('consultar_pedidos', ['porcentaje_maximo' => 100], $sistemas, $empresa->Id_Empresa);
        $incompletos = $servicio->ejecutar('consultar_pedidos', ['estado_entrega' => 'incompletos'], $sistemas, $empresa->Id_Empresa);

        $this->assertNotEmpty($porMaximo['filas']);
        $this->assertSame(count($incompletos['filas']), count($porMaximo['filas']));
    }

    /** El estado manda sobre un porcentaje contradictorio en la misma llamada. */
    public function test_el_estado_gana_sobre_un_porcentaje_contradictorio(): void
    {
        $empresa = $this->empresaConPedidos();

        if (! $empresa) {
            $this->markTestSkipped('No hay ninguna empresa con pedidos de BC en este ambiente.');
        }

        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $servicio = $this->servicio();

        // porcentaje_maximo=100 pide justo lo contrario de estado=completos.
        $mezcla = $servicio->ejecutar(
            'consultar_pedidos',
            ['estado_entrega' => 'completos', 'porcentaje_maximo' => 100],
            $sistemas,
            $empresa->Id_Empresa
        );
        $limpio = $servicio->ejecutar(
            'consultar_pedidos',
            ['estado_entrega' => 'completos'],
            $sistemas,
            $empresa->Id_Empresa
        );

        $this->assertNotEmpty($limpio['filas']);
        $this->assertSame(count($limpio['filas']), count($mezcla['filas']));
        $this->assertStringContainsString('completos', $mezcla['titulo']);
    }

    /** El modelo escribe el estado como le sale; los sinónimos se entienden. */
    public function test_entiende_sinonimos_del_estado_de_entrega(): void
    {
        $empresa = $this->empresaConPedidos();

        if (! $empresa) {
            $this->markTestSkipped('No hay ninguna empresa con pedidos de BC en este ambiente.');
        }

        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $servicio = $this->servicio();

        $referencia = count($servicio->ejecutar(
            'consultar_pedidos',
            ['estado_entrega' => 'completos'],
            $sistemas,
            $empresa->Id_Empresa
        )['filas']);

        $this->assertGreaterThan(0, $referencia);

        foreach (['completo', 'entregados', 'CERRADOS', '100%'] as $comoLoEscribio) {
            $resultado = $servicio->ejecutar(
                'consultar_pedidos',
                ['estado_entrega' => $comoLoEscribio],
                $sistemas,
                $empresa->Id_Empresa
            );

            $this->assertSame(
                $referencia,
                count($resultado['filas']),
                "'{$comoLoEscribio}' tendría que haberse entendido como completos."
            );
        }
    }

    /** Un estado que no se entiende no filtra; nunca devuelve una lista vacía. */
    public function test_un_estado_desconocido_no_filtra_nada(): void
    {
        $empresa = $this->empresaConPedidos();

        if (! $empresa) {
            $this->markTestSkipped('No hay ninguna empresa con pedidos de BC en este ambiente.');
        }

        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');
        $servicio = $this->servicio();

        $todos = $servicio->ejecutar('consultar_pedidos', [], $sistemas, $empresa->Id_Empresa);
        $raro = $servicio->ejecutar('consultar_pedidos', ['estado_entrega' => 'cualquier cosa'], $sistemas, $empresa->Id_Empresa);

        $this->assertNotEmpty($todos['filas']);
        $this->assertSame(count($todos['filas']), count($raro['filas']));
    }

    /**
     * El texto que acompaña un resultado vacío no puede sonar a "ese dato no
     * existe": es lo que llevó al bot a afirmar que la bodega no tenía pedidos
     * completos cuando lo que pasaba era que su filtro estaba al revés.
     */
    public function test_el_resultado_vacio_le_pide_al_modelo_revisar_su_filtro(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        // Un proveedor que no existe -> cero filas garantizadas.
        $resultado = $this->servicio()->ejecutar(
            'consultar_pedidos',
            ['proveedor' => 'PROVEEDOR QUE NO EXISTE EN NINGUNA PARTE'],
            $sistemas,
            $empresa->Id_Empresa
        );

        $this->assertSame([], $resultado['filas']);
        $this->assertStringContainsString('filtro', $resultado['texto']);
    }

    public function test_el_ranking_de_calificaciones_ordena_y_limita(): void
    {
        $empresa = $this->crearEmpresa();
        $sistemas = $this->crearUsuarioInterno($empresa, 'Sistemas');

        // Tres proveedores con documentación en distinto estado -> notas
        // distintas, para que el orden se pueda comprobar.
        foreach (range(1, 3) as $i) {
            $this->crearProveedorConUsuario($empresa, ['Razon_Social' => "PROVEEDOR {$i}"]);
        }

        $resultado = $this->servicio()->ejecutar(
            'consultar_calificaciones_proveedores',
            ['orden' => 'mejor', 'limite' => 2],
            $sistemas,
            $empresa->Id_Empresa
        );

        $this->assertCount(2, $resultado['filas'], 'El límite tiene que aplicarse.');

        $notas = array_map(
            fn ($f) => is_numeric($f['Nota global']) ? (float) $f['Nota global'] : -1,
            $resultado['filas']
        );

        $ordenadas = $notas;
        rsort($ordenadas);
        $this->assertSame($ordenadas, $notas, 'Con orden=mejor tiene que venir de mayor a menor.');
    }
}
