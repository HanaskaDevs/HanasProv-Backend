<?php

namespace App\Console\Commands;

use App\Modules\Horarios_Entrega\Services\HorarioEntregaService;
use App\Modules\Horarios_Entrega\Services\SincronizacionSighService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Corre cada minuto (ver routes/console.php) y refleja en el calendario de
 * horarios de entrega lo que YA pasó en SIGH (servidor 192.168.1.135,
 * conexión 'sqlsrv_sigh', solo lectura):
 *
 *   - Arribo -> En_Recepcion: apenas aparece un registro en
 *     SIGH.DocumentoInv cuyo ItemPedido matchea el Item del Documento con
 *     el NroDocumentoBC de un pedido abierto de ese proveedor.
 *   - En_Recepcion -> Recibido: cuando ese mismo Documento.EstadoPedido
 *     pasa a 'N'.
 *
 * SIGH y Portal_Proveedores son servidores DISTINTOS -> por eso esto es un
 * job programado en Laravel y no un trigger SQL nativo cruzado entre
 * motores (ver config/database.php, conexión sqlsrv_sigh, y la
 * conversación con el usuario sobre por qué se eligió esta opción).
 *
 * Si SIGH no responde (red caída, mantenimiento, etc.), el comando no
 * revienta: registra el error y no toca nada -> el próximo minuto
 * reintenta solo.
 */
class SincronizarEstadosSighCommand extends Command
{
    protected $signature = 'horarios-entrega:sincronizar-sigh';

    protected $description = 'Refleja en el calendario de horarios de entrega los estados En_Recepcion/Recibido leyendo SIGH (solo lectura).';

    public function handle(HorarioEntregaService $horarios, SincronizacionSighService $sigh): int
    {
        $this->sincronizarRecepciones($horarios, $sigh);
        $this->sincronizarRecibidos($horarios, $sigh);

        return self::SUCCESS;
    }

    /** Arribo -> En_Recepcion. */
    private function sincronizarRecepciones(HorarioEntregaService $horarios, SincronizacionSighService $sigh): void
    {
        $pendientes = $horarios->estadosEnEsperaDeRecepcion();

        if ($pendientes->isEmpty()) {
            $this->line('Sin horarios en espera de recepción.');

            return;
        }

        // Junta, por cada horario pendiente, TODOS los NroDocumentoBC de
        // pedidos abiertos de su proveedor -> una sola tanda de consultas
        // a SIGH en vez de una por horario.
        $documentosPorHorario = [];
        $todosLosDocumentos = [];

        foreach ($pendientes as $estadoDiario) {
            $horario = $estadoDiario->horario;
            if (! $horario) {
                continue;
            }

            $docs = $sigh->documentosBcDelProveedor($horario->Id_Empresa, $horario->Id_Proveedor);
            $documentosPorHorario[$estadoDiario->Id_Horario_Entrega_Estado_Diario] = $docs;
            array_push($todosLosDocumentos, ...$docs);
        }

        $todosLosDocumentos = array_values(array_unique($todosLosDocumentos));

        if ($todosLosDocumentos === []) {
            $this->line('Ningún horario pendiente tiene pedidos BC resueltos todavía.');

            return;
        }

        try {
            $conRecepcion = $sigh->documentosConIngresoEnDocumentoInv($todosLosDocumentos);
        } catch (\Throwable $e) {
            Log::error('SincronizarEstadosSighCommand: fallo al consultar SIGH para recepciones.', ['error' => $e->getMessage()]);
            $this->error('No se pudo consultar SIGH (recepciones). Se reintenta en el próximo minuto.');

            return;
        }

        $marcados = 0;

        foreach ($pendientes as $estadoDiario) {
            $docs = $documentosPorHorario[$estadoDiario->Id_Horario_Entrega_Estado_Diario] ?? [];
            $documentoEncontrado = null;

            foreach ($docs as $doc) {
                if (isset($conRecepcion[$doc])) {
                    $documentoEncontrado = $doc;
                    break;
                }
            }

            if ($documentoEncontrado) {
                $horarios->marcarRecepcionAutomatica($estadoDiario, $documentoEncontrado);
                $marcados++;
            }
        }

        $this->info("En_Recepcion: {$marcados} horario(s) actualizado(s).");
    }

    /** En_Recepcion -> Recibido. */
    private function sincronizarRecibidos(HorarioEntregaService $horarios, SincronizacionSighService $sigh): void
    {
        $pendientes = $horarios->estadosEnEsperaDeRecibido();

        if ($pendientes->isEmpty()) {
            $this->line('Sin horarios en espera de cierre (Recibido).');

            return;
        }

        // Acá ya se conoce el NroDocumentoBC exacto (quedó guardado al
        // pasar a En_Recepcion) -> no hace falta volver a resolver todos
        // los pedidos del proveedor.
        $documentos = $pendientes->pluck('Nro_Documento_Bc')->filter()->unique()->values()->all();

        if ($documentos === []) {
            return;
        }

        try {
            $recibidos = $sigh->documentosRecibidos($documentos);
        } catch (\Throwable $e) {
            Log::error('SincronizarEstadosSighCommand: fallo al consultar SIGH para recibidos.', ['error' => $e->getMessage()]);
            $this->error('No se pudo consultar SIGH (recibidos). Se reintenta en el próximo minuto.');

            return;
        }

        $marcados = 0;

        foreach ($pendientes as $estadoDiario) {
            if ($estadoDiario->Nro_Documento_Bc && isset($recibidos[$estadoDiario->Nro_Documento_Bc])) {
                $horarios->marcarRecibidoAutomatico($estadoDiario);
                $marcados++;
            }
        }

        $this->info("Recibido: {$marcados} horario(s) actualizado(s).");
    }
}
