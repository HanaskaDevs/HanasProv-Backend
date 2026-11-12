<?php

namespace App\Console\Commands;

use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use App\Modules\Horarios_Entrega\Services\SincronizacionSighService;
use Illuminate\Console\Command;

/**
 * Corre cada minuto (ver routes/console.php) y refleja en el calendario de
 * horarios de entrega lo que YA pasó en bodega, leyendo SIGH y WMS
 * (servidor 192.168.1.135, conexión 'sqlsrv_sigh', solo lectura):
 *
 *   - Arribo -> En_Recepcion: con el PRIMER producto escaneado en WMS
 *     (WMS.dbo.tmpRecepcion) de un pedido del proveedor ese día. Esa es
 *     la hora de Recepción.
 *   - En_Recepcion -> Recibido: cuando WMS cierra la recepción de todos
 *     los pedidos que el proveedor empezó a entregar ese día (ingreso en
 *     SIGH.DocumentoInv, Documento.EstadoPedido = 'N'). La hora es la del
 *     último cierre.
 *
 * Proceso y decisiones (estudio del 12-nov-2026, leído del código de WMS):
 * ver SincronizacionSighService::recepcionesEnWms y ::resumirEntrega. Hasta
 * ese día el job guardaba el CIERRE como "recepción" (y antes de eso, la
 * hora en que corría), por eso Recepción y Recibido salían iguales.
 *
 * Cada minuto vuelve a mirar TODAS las entregas de hoy con arribo y sin
 * marca manual, también las ya recibidas: si el proveedor empieza a
 * entregar otro pedido, la entrega vuelve a "En recepción" hasta que se
 * cierre ese también. Solo escribe si algo cambió.
 *
 * SIGH y Portal_Proveedores son servidores DISTINTOS -> por eso esto es un
 * job programado en Laravel y no un trigger SQL nativo cruzado entre
 * motores.
 *
 * Si SIGH no responde, el comando no revienta: registra el error y no toca
 * nada -> el próximo minuto reintenta solo. Y nunca BORRA una marca: si
 * para una entrega no encuentra nada, la deja como está (borrar marcas
 * viejas es trabajo de horarios-entrega:corregir-horas-sigh, que simula
 * primero).
 */
class SincronizarEstadosSighCommand extends Command
{
    protected $signature = 'horarios-entrega:sincronizar-sigh';

    protected $description = 'Refleja en el calendario de horarios de entrega la recepción en bodega (WMS) y su cierre (SIGH), solo lectura.';

    public function handle(HorarioEntregaService $horarios, SincronizacionSighService $sigh): int
    {
        $entregas = $horarios->estadosParaSincronizarSigh();

        if ($entregas->isEmpty()) {
            $this->line('Sin entregas con arribo para sincronizar.');

            return self::SUCCESS;
        }

        // Pedidos candidatos de cada entrega -> una sola consulta a SIGH/WMS
        // para todos.
        $documentosPorEntrega = [];
        $todos = [];

        foreach ($entregas as $estadoDiario) {
            $horario = $estadoDiario->horario;
            if (! $horario) {
                continue;
            }

            $docs = $sigh->documentosBcDelProveedor($horario->Id_Empresa, $horario->Id_Proveedor, $estadoDiario->Fecha);
            $documentosPorEntrega[$estadoDiario->Id_Horario_Entrega_Estado_Diario] = $docs;
            array_push($todos, ...$docs);
        }

        if ($todos === []) {
            $this->line('Ninguna entrega tiene pedidos BC resueltos todavía.');

            return self::SUCCESS;
        }

        try {
            $recepciones = $sigh->recepcionesEnWms($todos, now(), lanzarSiFalla: true);
        } catch (\Throwable $e) {
            // El service ya lo dejó en el log; acá solo se avisa en consola
            // (un solo registro por minuto mientras SIGH esté caído).
            $this->error('No se pudo consultar SIGH/WMS. Se reintenta en el próximo minuto.');

            return self::SUCCESS;
        }

        $actualizadas = 0;

        foreach ($entregas as $estadoDiario) {
            $docs = $documentosPorEntrega[$estadoDiario->Id_Horario_Entrega_Estado_Diario] ?? [];
            $resumen = $sigh->resumirEntrega($docs, $recepciones);

            if ($resumen && $horarios->aplicarRecepcionSigh($estadoDiario, $resumen)) {
                $actualizadas++;
            }
        }

        $this->info("Entregas actualizadas: {$actualizadas}.");

        return self::SUCCESS;
    }
}
