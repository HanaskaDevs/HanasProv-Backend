<?php

namespace Tests\Feature\Asistente;

use Anthropic\Messages\Usage;
use App\Modules\Asistente\Models\AsistenteConsumo;
use App\Modules\Asistente\Services\AsistentePresupuestoService;
use Tests\TestCase;

/**
 * El tope de gasto del asistente. Es la clase donde un error se paga en
 * dólares, así que se prueba el corte en los dos límites, el borde exacto y
 * que el gasto viejo no cuente.
 *
 * OJO con la base compartida: estos tests corren dentro de una transacción
 * que se revierte (ver Tests\TestCase), pero la tabla Asistente_Consumo
 * puede tener filas reales de uso del bot. Por eso cada test parte del
 * gasto YA EXISTENTE y suma lo que necesita, en vez de asumir que arranca
 * en cero.
 */
class PresupuestoAsistenteTest extends TestCase
{
    private function servicio(): AsistentePresupuestoService
    {
        return app(AsistentePresupuestoService::class);
    }

    /**
     * Usage con solo los campos que nos interesan. Usage::with() exige los
     * nueve argumentos sin valores por defecto, así que se envuelve acá una
     * vez en vez de repetir el ruido en cada test.
     */
    private function uso(int $entrada, int $salida, int $cacheEscritura = 0, int $cacheLectura = 0): Usage
    {
        return Usage::with(
            cacheCreation: null,
            cacheCreationInputTokens: $cacheEscritura,
            cacheReadInputTokens: $cacheLectura,
            inferenceGeo: null,
            inputTokens: $entrada,
            outputTokens: $salida,
            outputTokensDetails: null,
            serverToolUse: null,
            serviceTier: null,
        );
    }

    /** Inserta una fila de consumo con el costo dado, fechada cuando se pida. */
    private function gastar(float $costoUsd, ?string $fecha = null): void
    {
        AsistenteConsumo::create([
            'Modelo' => 'claude-haiku-4-5',
            'Tokens_Entrada' => 1000,
            'Tokens_Salida' => 100,
            'Costo_Usd' => $costoUsd,
            'Origen' => 'Mensaje',
            'Fecha_Creacion' => $fecha ?? now()->format('Y-m-d\TH:i:s'),
        ]);
    }

    private function gastoSemanalActual(): float
    {
        return $this->servicio()->estado()['semanal']['gastado'];
    }

    public function test_sin_alcanzar_los_topes_no_bloquea(): void
    {
        $this->assertNull($this->servicio()->motivoDeBloqueo());
    }

    public function test_bloquea_al_alcanzar_el_tope_semanal(): void
    {
        $limite = (float) config('services.anthropic.limites_usd.semanal');
        $falta = $limite - $this->gastoSemanalActual();

        // Justo por debajo del tope: todavía pasa.
        $this->gastar(max(0, $falta - 0.01));
        $this->assertNull($this->servicio()->motivoDeBloqueo());

        // Se cruza el tope: corta.
        $this->gastar(0.02);
        $motivo = $this->servicio()->motivoDeBloqueo();

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('semanal', $motivo);
    }

    public function test_bloquea_al_alcanzar_el_tope_mensual_aunque_la_semana_este_limpia(): void
    {
        $limiteMensual = (float) config('services.anthropic.limites_usd.mensual');

        // El gasto se fecha al inicio del mes. Cuando el mes arrancó antes
        // que esta semana, además comprueba que el corte mensual funciona
        // con la ventana semanal limpia; si hoy es la primera semana del
        // mes las dos ventanas coinciden y el test sigue siendo válido (lo
        // que se verifica es que el tope mensual corta).
        $gastoMensual = $this->servicio()->estado()['mensual']['gastado'];

        $this->gastar(
            $limiteMensual - $gastoMensual + 0.01,
            now()->startOfMonth()->format('Y-m-d\TH:i:s')
        );

        $motivo = $this->servicio()->motivoDeBloqueo();

        $this->assertNotNull($motivo);
        $this->assertStringContainsString('mensual', $motivo);
    }

    public function test_el_gasto_de_meses_anteriores_no_cuenta(): void
    {
        $limiteMensual = (float) config('services.anthropic.limites_usd.mensual');

        // Un gasto enorme, pero del mes pasado: no debe bloquear nada.
        $this->gastar($limiteMensual * 5, now()->subMonths(2)->format('Y-m-d\TH:i:s'));

        $this->assertNull($this->servicio()->motivoDeBloqueo());
    }

    public function test_el_costo_se_calcula_con_las_tarifas_de_configuracion(): void
    {
        // 1.000.000 de tokens de entrada y 1.000.000 de salida = 1 * precio
        // de entrada + 1 * precio de salida.
        $uso = $this->uso(entrada: 1_000_000, salida: 1_000_000);

        $precios = config('services.anthropic.precios_usd_por_millon');
        $esperado = round((float) $precios['entrada'] + (float) $precios['salida'], 6);

        $this->assertSame($esperado, $this->servicio()->calcularCosto($uso));
    }

    public function test_los_tokens_de_cache_se_cobran_a_su_propia_tarifa(): void
    {
        $precios = config('services.anthropic.precios_usd_por_millon');

        $uso = $this->uso(entrada: 0, salida: 0, cacheEscritura: 1_000_000, cacheLectura: 1_000_000);

        $esperado = round((float) $precios['cache_escritura'] + (float) $precios['cache_lectura'], 6);

        $this->assertSame($esperado, $this->servicio()->calcularCosto($uso));
    }

    public function test_registrar_guarda_los_tokens_y_devuelve_el_costo(): void
    {
        $uso = $this->uso(entrada: 3000, salida: 200);

        $costo = $this->servicio()->registrar($uso, idUsuario: null, idEmpresa: null, origen: 'Mensaje');

        // 3000/1M * 1.00 + 200/1M * 5.00 = 0.003 + 0.001 = 0.004
        $this->assertSame(0.004, $costo);

        $fila = AsistenteConsumo::orderByDesc('Id_Asistente_Consumo')->first();
        $this->assertSame(3000, $fila->Tokens_Entrada);
        $this->assertSame(200, $fila->Tokens_Salida);
        $this->assertSame('claude-haiku-4-5', $fila->Modelo);
    }

    public function test_el_estado_reporta_lo_gastado_y_lo_disponible(): void
    {
        $estado = $this->servicio()->estado();

        foreach (['semanal', 'mensual'] as $tramo) {
            $this->assertArrayHasKey('gastado', $estado[$tramo]);
            $this->assertArrayHasKey('limite', $estado[$tramo]);
            $this->assertArrayHasKey('disponible', $estado[$tramo]);
            $this->assertArrayHasKey('porcentaje', $estado[$tramo]);
            $this->assertSame(
                round($estado[$tramo]['limite'] - $estado[$tramo]['gastado'], 6),
                $estado[$tramo]['disponible']
            );
        }

        $this->assertSame(5.0, $estado['semanal']['limite']);
        $this->assertSame(20.0, $estado['mensual']['limite']);
    }
}
