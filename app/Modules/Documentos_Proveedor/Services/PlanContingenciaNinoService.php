<?php

namespace App\Modules\Documentos_Proveedor\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Models\TipoDocumento;
use App\Modules\Proveedores\Models\Proveedor;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Seguimiento del "Plan de contingencia contra el fenómeno del Niño"
 * (16-oct-2026): cuántos proveedores de la empresa ya lo cargaron y
 * cuáles. Lo consume la tarjeta del panel de inicio de Sistemas, Admin y
 * Calidad y su pantalla de detalle.
 *
 * Es opcional, así que la cuenta es sobre TODOS los proveedores activos:
 * no hay clase ni ciudad a la que no le corresponda.
 */
class PlanContingenciaNinoService
{
    public function verificarAcceso(Usuario $usuario, int $idEmpresa): void
    {
        $tieneAcceso = $usuario->Tipo_Usuario === 'Interno' && (
            $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCalidad($idEmpresa)
        );

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('Solo Sistemas, Admin y Calidad pueden ver el seguimiento del plan de contingencia.');
        }
    }

    /**
     * @return array{
     *     total_proveedores: int,
     *     cargaron: int,
     *     con_plan: array<int, array<string, mixed>>,
     *     sin_plan: array<int, array<string, mixed>>
     * }
     */
    public function resumen(Usuario $usuario, int $idEmpresa): array
    {
        $this->verificarAcceso($usuario, $idEmpresa);

        $proveedores = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->with('estado')
            ->orderBy('Razon_Social')
            ->get();

        $idTipo = TipoDocumento::where('Codigo_Archivo', TipoDocumento::CODIGO_PLAN_CONTINGENCIA_NINO)
            ->value('Id_Tipo_Documento');

        // Uno por proveedor (el tipo no admite varios): el vigente.
        $planes = $idTipo === null
            ? collect()
            : DocumentoProveedor::where('Id_Tipo_Documento', $idTipo)
                ->where('Activo', 1)
                ->whereIn('Id_Proveedor', $proveedores->pluck('Id_Proveedor'))
                ->get()
                ->keyBy('Id_Proveedor');

        $fila = fn (Proveedor $proveedor) => [
            'id_proveedor' => $proveedor->Id_Proveedor,
            'razon_social' => $proveedor->Razon_Social,
            'nombre_comercial' => $proveedor->Nombre_Comercial,
            'ruc' => $proveedor->Ruc,
            'email' => $proveedor->Email,
            'estado' => $proveedor->estado?->Nombre_Estado,
        ];

        [$con, $sin] = $proveedores->partition(fn (Proveedor $p) => $planes->has($p->Id_Proveedor));

        return [
            'total_proveedores' => $proveedores->count(),
            'cargaron' => $con->count(),
            'con_plan' => $con->map(fn (Proveedor $p) => [
                ...$fila($p),
                'id_documento_proveedor' => $planes[$p->Id_Proveedor]->Id_Documento_Proveedor,
                'fecha_carga' => $planes[$p->Id_Proveedor]->Fecha_Creacion?->toIso8601String(),
            ])->values()->all(),
            'sin_plan' => $sin->map($fila)->values()->all(),
        ];
    }
}
