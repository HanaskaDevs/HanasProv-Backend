<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Asistente\Models\AsistenteConsumo;
use Anthropic\Messages\Usage;
use Carbon\CarbonInterface;

/**
 * Tope de gasto del asistente contra la API de Claude.
 *
 * Dos límites simultáneos, los dos duros: si cualquiera se alcanza, NO se
 * llama a la API y Hana responde con su texto de respaldo. Se prefiere que
 * el bot conteste "en este momento no puedo" antes que gastar de más sin
 * que nadie se entere.
 *
 * VENTANAS DE CALENDARIO, no móviles: la semana va de lunes a domingo y el
 * mes es el mes calendario. Es lo que espera quien puso los topes ("5 por
 * semana, 20 por mes") y lo que se puede conciliar contra la factura de
 * Anthropic, que también es mensual. Una ventana móvil de 7 días sería más
 * estricta contra los picos, pero nadie podría explicar por qué el bot se
 * apagó un miércoles.
 *
 * OJO con la aritmética de los topes: 5/semana durante un mes son ~21.5
 * USD, más que el tope mensual de 20. Eso NO es un error -> significa que
 * al final de un mes de uso intenso el que corta primero es el mensual, y
 * es exactamente lo que se quiere de un tope de gasto.
 */
class AsistentePresupuestoService
{
    /**
     * ¿Se puede llamar a la API ahora mismo? Devuelve null si sí, o el
     * motivo del corte si no (para poder registrarlo en el log).
     */
    public function motivoDeBloqueo(): ?string
    {
        $limites = config('services.anthropic.limites_usd');

        $gastoSemana = $this->gastoDesde($this->inicioDeSemana());
        if ($gastoSemana >= (float) $limites['semanal']) {
            return sprintf(
                'Tope semanal alcanzado: %.4f USD de %.2f USD permitidos.',
                $gastoSemana,
                $limites['semanal']
            );
        }

        $gastoMes = $this->gastoDesde($this->inicioDeMes());
        if ($gastoMes >= (float) $limites['mensual']) {
            return sprintf(
                'Tope mensual alcanzado: %.4f USD de %.2f USD permitidos.',
                $gastoMes,
                $limites['mensual']
            );
        }

        return null;
    }

    /**
     * Registra el consumo de una llamada y devuelve su costo en USD.
     *
     * Se llama SIEMPRE después de una llamada exitosa. Si esto fallara y no
     * se registrara, el gasto quedaría invisible para el tope, así que la
     * excepción se deja propagar en vez de tragarla.
     */
    public function registrar(Usage $uso, ?int $idUsuario, ?int $idEmpresa, string $origen = 'Mensaje'): float
    {
        $costo = $this->calcularCosto($uso);

        AsistenteConsumo::create([
            'Id_Usuario' => $idUsuario,
            'Id_Empresa' => $idEmpresa,
            'Modelo' => (string) config('services.anthropic.model'),
            'Tokens_Entrada' => $uso->inputTokens,
            'Tokens_Salida' => $uso->outputTokens,
            'Tokens_Cache_Escritura' => $uso->cacheCreationInputTokens ?? 0,
            'Tokens_Cache_Lectura' => $uso->cacheReadInputTokens ?? 0,
            'Costo_Usd' => $costo,
            'Origen' => $origen,
            'Fecha_Creacion' => now(),
        ]);

        return $costo;
    }

    /**
     * Costo en USD de una llamada, según las tarifas de config/services.php.
     *
     * Los tokens de caché se cobran distinto de los normales (escribir en
     * caché cuesta más que la entrada corriente, leer cuesta mucho menos),
     * así que se calculan por separado en vez de meterlos en input_tokens.
     */
    public function calcularCosto(Usage $uso): float
    {
        $precios = config('services.anthropic.precios_usd_por_millon');

        $porMillon = fn (int $tokens, float $precio): float => $tokens / 1_000_000 * $precio;

        return round(
            $porMillon($uso->inputTokens, (float) $precios['entrada'])
            + $porMillon($uso->outputTokens, (float) $precios['salida'])
            + $porMillon($uso->cacheCreationInputTokens ?? 0, (float) $precios['cache_escritura'])
            + $porMillon($uso->cacheReadInputTokens ?? 0, (float) $precios['cache_lectura']),
            6
        );
    }

    /**
     * Estado del presupuesto, para mostrarlo en el panel de Sistemas o
     * revisarlo por consola sin tener que sumar a mano.
     *
     * @return array{semanal: array{gastado: float, limite: float, disponible: float, porcentaje: float}, mensual: array{gastado: float, limite: float, disponible: float, porcentaje: float}}
     */
    public function estado(): array
    {
        $limites = config('services.anthropic.limites_usd');

        return [
            'semanal' => $this->tramo($this->gastoDesde($this->inicioDeSemana()), (float) $limites['semanal']),
            'mensual' => $this->tramo($this->gastoDesde($this->inicioDeMes()), (float) $limites['mensual']),
        ];
    }

    /** @return array{gastado: float, limite: float, disponible: float, porcentaje: float} */
    protected function tramo(float $gastado, float $limite): array
    {
        return [
            'gastado' => round($gastado, 6),
            'limite' => $limite,
            'disponible' => round(max(0, $limite - $gastado), 6),
            'porcentaje' => $limite > 0 ? round($gastado / $limite * 100, 2) : 0.0,
        ];
    }

    protected function gastoDesde(CarbonInterface $desde): float
    {
        // Formato ISO explícito: la columna es datetime y el driver sqlsrv
        // es ambiguo con DATEFORMAT dmy (ver App\Models\BaseModel).
        return (float) AsistenteConsumo::where('Fecha_Creacion', '>=', $desde->format('Y-m-d\TH:i:s'))
            ->sum('Costo_Usd');
    }

    protected function inicioDeSemana(): CarbonInterface
    {
        return now()->startOfWeek(CarbonInterface::MONDAY);
    }

    protected function inicioDeMes(): CarbonInterface
    {
        return now()->startOfMonth();
    }
}
