<?php

namespace App\Modules\Proveedores\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Mail\FichaRegistradaMail;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Responsables\Services\ResponsableProveedorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "La ficha de tal proveedor ya está registrada, por favor accede para
 * calificar" (pedido explícito del usuario, 16-oct-2026).
 *
 * CUÁNDO: solo la PRIMERA vez que la ficha llega al 100%, que es cuando se
 * crea su Confirmacion_Ficha (ver FichaProveedorService::
 * registrarConfirmacionSiCorresponde). Las correcciones posteriores no
 * avisan, y los proveedores que ya habían registrado antes de esto tampoco:
 * a esos Compras los encuentra entrando al portal.
 *
 * A QUIÉN: al responsable del proveedor (módulo Responsables). Lo normal en
 * un proveedor NUEVO es que todavía no tenga responsable: el responsable se
 * asigna por el código de Business Central, y el código recién existe
 * cuando se aprueba. En ese caso va a todos los usuarios Compras de la
 * empresa más las copias fijas de portal.copias_aviso_compras.
 *
 * NUNCA FRENA EL GUARDADO: igual que los avisos de productos, un problema
 * de correo se registra en el log y el proveedor sigue con su ficha.
 */
class AvisoFichaRegistradaService
{
    public function __construct(protected ResponsableProveedorService $responsables)
    {
    }

    public function avisar(Proveedor $proveedor): void
    {
        try {
            $responsable = $this->responsables->paraProveedor($proveedor);
        } catch (\Throwable $e) {
            // Si no se pudo resolver (p. ej. falla la consulta a las tablas
            // de BC), se avisa a todo Compras antes que a nadie.
            Log::warning('Aviso de ficha registrada: no se pudo resolver el responsable.', [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'error' => $e->getMessage(),
            ]);
            $responsable = null;
        }

        $destinatarios = $responsable && ! empty($responsable['correo'])
            ? [$responsable['correo']]
            : $this->correosDeCompras($proveedor->Id_Empresa);

        if (empty($destinatarios)) {
            Log::warning('Aviso de ficha registrada: no hay a quién avisar.', [
                'id_empresa' => $proveedor->Id_Empresa,
                'id_proveedor' => $proveedor->Id_Proveedor,
            ]);

            return;
        }

        try {
            Mail::to($destinatarios)->queue(new FichaRegistradaMail(
                nombreProveedor: $proveedor->nombreVisible(),
                rucProveedor: $proveedor->Ruc ?: null,
                nombreResponsable: $responsable['nombre'] ?? null,
                // Abre la calificación de ESE proveedor directamente.
                urlAccion: rtrim((string) config('app.frontend_url'), '/').'/proveedores?calificar='.$proveedor->Id_Proveedor,
            ));
        } catch (\Throwable $e) {
            Log::error('No se pudo encolar el aviso de ficha registrada.', [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'destinatarios' => $destinatarios,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Usuarios ACTIVOS con rol Compras en la empresa, más las copias fijas
     * de configuración (las jefaturas). Mismo criterio que el aviso de
     * productos a Compras.
     *
     * @return array<int, string>
     */
    protected function correosDeCompras(int $idEmpresa): array
    {
        $correos = Usuario::where('Activo', 1)
            ->whereHas('usuarioEmpresas', fn ($q) => $q
                ->where('Id_Empresa', $idEmpresa)
                ->where('Activo', true)
                ->whereHas('rol', fn ($r) => $r->where('Nombre_Rol', 'Compras')))
            ->pluck('Email')
            ->all();

        $correos = array_merge($correos, config('portal.copias_aviso_compras', []));

        return array_values(array_unique(array_filter($correos)));
    }
}
