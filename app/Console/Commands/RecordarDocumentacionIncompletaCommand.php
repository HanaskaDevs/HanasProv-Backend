<?php

namespace App\Console\Commands;

use App\Modules\Documentos_Proveedor\Mail\DocumentacionIncompletaMail;
use App\Modules\Documentos_Proveedor\Services\RecordatorioDocumentacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Le recuerda por correo al proveedor que tiene la documentación incompleta,
 * con la lista de lo que le falta, cuando lleva 7 días sin subir ningún
 * documento nuevo (29-oct-2026). Las reglas de a quién y cada cuánto están
 * en RecordatorioDocumentacionService.
 *
 * --simular: solo lista a quién le saldría el correo y qué le falta; no manda
 * nada ni marca a nadie como avisado. Para probarlo contra una base con datos
 * reales sin escribirle a nadie.
 */
class RecordarDocumentacionIncompletaCommand extends Command
{
    protected $signature = 'documentos:recordar-incompletos {--simular : Solo listar, sin enviar ni marcar}';

    protected $description = 'Avisa al proveedor que lleva 7 días sin subir documentos y todavía le faltan obligatorios.';

    public function __construct(protected RecordatorioDocumentacionService $servicio)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        $pendientes = $this->servicio->proveedoresParaRecordar();

        if ($pendientes->isEmpty()) {
            $this->info('No hay proveedores a los que recordarles la documentación hoy.');

            return self::SUCCESS;
        }

        $dias = $this->servicio->diasSinNovedad();
        $urlDocumentos = rtrim(config('app.frontend_url'), '/').'/documentos';

        foreach ($pendientes as $fila) {
            $proveedor = $fila['proveedor'];
            $nombreProveedor = $proveedor->Razon_Social ?: ($proveedor->Nombre_Comercial ?: $proveedor->Email);
            $empresa = $proveedor->empresa;
            $nombreEmpresa = $empresa->Nombre_Comercial ?? $empresa->Razon_Social ?? 'Hanaska';

            $this->line(sprintf(
                '  %s <%s> · último documento %s · faltan %d: %s',
                $nombreProveedor,
                $proveedor->Email,
                $fila['ultimo_documento']->format('d/m/Y'),
                count($fila['faltantes']),
                implode(', ', $fila['faltantes']),
            ));

            if ($simular) {
                continue;
            }

            Mail::to($proveedor->Email)->send(new DocumentacionIncompletaMail(
                $nombreProveedor,
                $nombreEmpresa,
                $fila['faltantes'],
                $dias,
                $urlDocumentos,
            ));

            $this->servicio->marcarAvisado($proveedor);
        }

        if ($simular) {
            $this->info("SIMULACIÓN: se le habría avisado a {$pendientes->count()} proveedor(es). No se envió nada.");

            return self::SUCCESS;
        }

        Log::info('Recordatorios de documentación incompleta enviados', ['proveedores' => $pendientes->count()]);
        $this->info("Se le avisó a {$pendientes->count()} proveedor(es).");

        return self::SUCCESS;
    }
}
