<?php

namespace App\Console\Commands;

use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Notifications\DocumentoPorVencerNotification;
use App\Modules\Documentos_Proveedor\Services\VencimientoDocumentosService;
use Illuminate\Console\Command;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa por correo de los documentos que están por vencer (30 días antes) y
 * reitera el aviso cada semana mientras no se reemplacen. Van los tres
 * destinatarios que pidió el negocio: el proveedor, y los usuarios internos
 * de Calidad y Admin de esa empresa.
 *
 * Corre todos los días, pero cada documento recibe a lo sumo un aviso por
 * semana (ver VencimientoDocumentosService::documentosParaAvisar).
 *
 * Este comando NO suspende a nadie: eso es documentos:suspender-vencidos,
 * que además pasa por el interruptor de Configuraciones. Avisar siempre es
 * seguro; suspender no.
 */
class AvisarVencimientoDocumentosCommand extends Command
{
    protected $signature = 'documentos:avisar-vencimientos';

    protected $description = 'Avisa al proveedor, Calidad y Admin de los documentos por vencer (30 días antes y luego cada semana).';

    public function __construct(protected VencimientoDocumentosService $servicio)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $documentos = $this->servicio->documentosParaAvisar();

        if ($documentos->isEmpty()) {
            $this->info('No hay documentos que requieran aviso hoy.');

            return self::SUCCESS;
        }

        $avisados = 0;

        foreach ($documentos as $documento) {
            $proveedor = $documento->proveedor;

            if (! $proveedor) {
                continue;
            }

            $empresa = $proveedor->empresa;
            $nombreDocumento = $documento->tipoDocumento->Nombre_Documento ?? 'Documento';
            $nombreProveedor = $proveedor->Razon_Social ?: ($proveedor->Email ?? 'Proveedor');
            $nombreEmpresa = $empresa->Nombre_Comercial ?? $empresa->Razon_Social ?? 'Hanaska';
            $diasAtraso = $this->servicio->diasDeAtraso($documento);
            $fechaCaducidad = $documento->Fecha_Caducidad->translatedFormat('d \d\e F \d\e Y');

            $armarNotificacion = fn (?string $emailDestino = null) => new DocumentoPorVencerNotification(
                $nombreDocumento,
                $nombreProveedor,
                $nombreEmpresa,
                $fechaCaducidad,
                $diasAtraso,
                VencimientoDocumentosService::DIAS_GRACIA_SUSPENSION,
                $emailDestino,
            );

            // Al proveedor, a la casilla de su Ficha (Proveedor no es un
            // Notifiable, va por ruta anónima -> mismo patrón que los
            // correos de aprobado/rechazado).
            if ($proveedor->Email) {
                (new AnonymousNotifiable())
                    ->route('mail', $proveedor->Email)
                    ->notify($armarNotificacion($proveedor->Email));
            }

            // A Calidad y Admin de esa empresa.
            $internos = $this->servicio->internosANotificar($proveedor->Id_Empresa);

            if ($internos->isNotEmpty()) {
                Notification::send($internos, $armarNotificacion());
            }

            $this->servicio->marcarAvisado($documento);
            $avisados++;

            $estado = $diasAtraso > 0 ? "vencido hace {$diasAtraso} día(s)" : 'vence en '.abs($diasAtraso).' día(s)';
            $this->line("  {$nombreProveedor} · {$nombreDocumento} ({$estado}) -> proveedor + {$internos->count()} interno(s)");
        }

        Log::info('Avisos de vencimiento de documentos enviados', ['documentos' => $avisados]);
        $this->info("Se avisó por {$avisados} documento(s).");

        return self::SUCCESS;
    }
}
