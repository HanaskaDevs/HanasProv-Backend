<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\IpBloqueada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Bandeja de IPs bloqueadas por fuerza bruta. Solo Sistemas.
 *
 * Existe porque el bloqueo de IP NO caduca solo (decisión del negocio):
 * sin una pantalla para verlas y liberarlas, una IP mal bloqueada dejaría
 * afuera a quien la use hasta que alguien entre a la base de datos a mano.
 */
class IpBloqueadaController extends Controller
{
    /**
     * esSistemasGlobal() y no esSistemas($idEmpresaActiva): estas rutas
     * cuelgan del grupo /auth, que NO pasa por el middleware EmpresaActiva,
     * así que no hay una empresa activa resuelta en la petición. Además
     * corresponde: una IP bloqueada no pertenece a ninguna empresa, es del
     * portal entero, igual que el catálogo de empresas o los usuarios
     * internos (ver Usuario::esSistemasGlobal).
     */
    private function verificarSistemas(Request $request): void
    {
        if (! $request->user()->esSistemasGlobal()) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden gestionar las IP bloqueadas.');
        }
    }

    /** Las vigentes primero; las liberadas quedan como histórico. */
    public function index(Request $request): JsonResponse
    {
        $this->verificarSistemas($request);

        $ips = IpBloqueada::orderByDesc('Activa')
            ->orderByDesc('Fecha_Bloqueo')
            ->limit(200)
            ->get();

        return response()->json($ips);
    }

    public function desbloquear(Request $request, int $idIpBloqueada): JsonResponse
    {
        $this->verificarSistemas($request);

        $ip = IpBloqueada::findOrFail($idIpBloqueada);

        // No se borra la fila: se marca liberada. Así queda el registro de
        // que esa IP atacó alguna vez y de quién la dejó pasar.
        $ip->forceFill([
            'Activa' => false,
            'Desbloqueada_Por' => $request->user()->Id_Usuario,
            'Fecha_Desbloqueo' => now(),
        ])->save();

        return response()->json(['message' => 'IP desbloqueada.', 'ip' => $ip]);
    }
}
