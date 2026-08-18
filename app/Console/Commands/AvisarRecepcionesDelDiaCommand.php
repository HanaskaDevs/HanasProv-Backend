<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Modules\Auditorias\Notifications\RecepcionesDelDiaNotification;
use App\Modules\Auditorias\Services\AgendaRecepcionService;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Cada mañana avisa por correo al equipo de Calidad de cada empresa qué
 * proveedores tienen HOY su auditoría de recepción (el reparto anual lo
 * calcula AgendaRecepcionService a partir del Id_Proveedor).
 *
 * Un solo correo por empresa con la lista completa, no uno por proveedor.
 * Los días que no le toca a nadie no se manda nada.
 */
class AvisarRecepcionesDelDiaCommand extends Command
{
    protected $signature = 'auditorias:avisar-recepciones-del-dia';

    protected $description = 'Avisa a Calidad qué proveedores tienen hoy su auditoría de recepción (formulario FGH04.15.05-1).';

    public function __construct(protected AgendaRecepcionService $agenda)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $totalAvisos = 0;

        foreach (Empresa::where('Activo', 1)->get() as $empresa) {
            $proveedores = $this->agenda->proveedoresQueTocanHoy($empresa->Id_Empresa);

            if ($proveedores->isEmpty()) {
                $this->line("{$empresa->Razon_Social}: sin auditorías de recepción hoy.");
                continue;
            }

            $destinatarios = $this->destinatarios($empresa->Id_Empresa);

            if ($destinatarios->isEmpty()) {
                // No se pierde en silencio: si nadie puede recibirlo, queda
                // en el log para que Sistemas lo note y asigne un usuario.
                Log::warning('Auditorías de recepción de hoy sin destinatarios', [
                    'id_empresa' => $empresa->Id_Empresa,
                    'proveedores' => $proveedores->pluck('Razon_Social')->all(),
                ]);
                $this->warn("{$empresa->Razon_Social}: {$proveedores->count()} auditoría(s) hoy, pero no hay usuarios de Calidad/Admin a quién avisar.");
                continue;
            }

            Notification::send($destinatarios, new RecepcionesDelDiaNotification(
                $proveedores->map(fn (Proveedor $p) => [
                    'razon_social' => $p->Razon_Social ?? 'Proveedor sin razón social',
                    'nombre_comercial' => $p->Nombre_Comercial,
                    'ruc' => $p->Ruc,
                ])->all(),
                $empresa->Nombre_Comercial ?? $empresa->Razon_Social,
            ));

            $totalAvisos++;
            $this->info("{$empresa->Razon_Social}: avisadas {$proveedores->count()} auditoría(s) a {$destinatarios->count()} usuario(s).");
        }

        $this->info("Listo. Empresas avisadas: {$totalAvisos}.");

        return self::SUCCESS;
    }

    /**
     * Calidad es el destinatario natural del aviso. Si esa empresa todavía
     * no tiene ningún usuario de Calidad, se cae a Admin/Sistemas para que
     * el aviso llegue a alguien igual, en vez de descartarse.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Usuario>
     */
    protected function destinatarios(int $idEmpresa): \Illuminate\Database\Eloquent\Collection
    {
        $porRoles = fn (array $roles) => Usuario::where('Activo', 1)
            ->whereHas('usuarioEmpresas', function ($q) use ($idEmpresa, $roles) {
                $q->where('Id_Empresa', $idEmpresa)
                    ->where('Activo', true)
                    ->whereHas('rol', fn ($r) => $r->whereIn('Nombre_Rol', $roles));
            })
            ->get();

        $calidad = $porRoles(['Calidad']);

        return $calidad->isNotEmpty() ? $calidad : $porRoles(['Admin', 'Sistemas']);
    }
}
