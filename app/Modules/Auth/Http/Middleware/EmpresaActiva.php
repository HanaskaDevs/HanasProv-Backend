<?php

namespace App\Modules\Auth\Http\Middleware;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Proveedores\Models\EstadoProveedor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la empresa activa de la petición y la deja en
 * $request->attributes['id_empresa_activa'].
 *
 * Orden de resolución:
 *
 *  1. Header X-Empresa-Activa, si viene. El front lo manda con la empresa de
 *     ESA pestaña (guardada en sessionStorage). Al venir por petición y no del
 *     estado del servidor, cada pestaña puede estar en una empresa distinta al
 *     mismo tiempo compartiendo un solo token.
 *
 *  2. Sesion.Id_Empresa_Activa, como respaldo. Cubre el primer arranque y
 *     cualquier cliente que no mande el header (Postman, un script, etc.).
 *
 * En los dos casos se verifica el acceso real contra la tabla pivote. El header
 * lo pone el navegador, así que NO es de fiar: sin esa verificación, cambiar un
 * número en la petición bastaría para leer los datos de otra empresa.
 */
class EmpresaActiva
{
    public function __construct(protected AuthService $authService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $accessToken = $usuario?->currentAccessToken();

        $sesion = $accessToken ? $this->authService->sesionActual($usuario, $accessToken) : null;

        if (! $sesion) {
            return response()->json([
                'message' => 'No se encontró una sesión activa válida.',
            ], 401);
        }

        // trim() por si el header llega con espacios: sin él, ctype_digit
        // fallaría y se caería al respaldo de la sesión sin avisar, haciendo que
        // la pestaña muestre una empresa distinta a la que pidió.
        $idEmpresaHeader = trim((string) $request->header('X-Empresa-Activa', ''));

        $idEmpresa = ctype_digit($idEmpresaHeader) && (int) $idEmpresaHeader > 0
            ? (int) $idEmpresaHeader
            : (int) ($sesion->Id_Empresa_Activa ?? 0);

        if ($idEmpresa <= 0) {
            return response()->json([
                'message' => 'Debe seleccionar una empresa activa para continuar.',
                'empresas_disponibles' => $usuario->empresas()
                    ->wherePivot('Activo', true)
                    ->get(['Empresa.Id_Empresa', 'Razon_Social']),
            ], 409);
        }

        $tieneAcceso = $usuario->empresas()
            ->where('Empresa.Id_Empresa', $idEmpresa)
            ->wherePivot('Activo', true)
            ->exists();

        if (! $tieneAcceso) {
            return response()->json([
                'message' => 'No tiene acceso a la empresa seleccionada.',
            ], 403);
        }

        // Proveedor suspendido EN ESTA EMPRESA (documentación vencida sin
        // regularizar, ver VencimientoDocumentosService): se corta acá, así
        // el bloqueo vale para todos los endpoints de una sola vez.
        //
        // Es por empresa a propósito: el mismo usuario externo puede trabajar
        // con varias empresas del grupo, y estar al día en una y vencido en
        // otra -> se le bloquea solo la que corresponde, no la cuenta entera
        // (el login solo se niega del todo si NO le queda ninguna, ver
        // AuthService::tieneAlgunaEmpresaDisponible).
        if ($usuario->Tipo_Usuario === 'Proveedor') {
            $proveedor = $usuario->proveedores()
                ->where('Proveedor.Id_Empresa', $idEmpresa)
                ->first();

            if ($proveedor && (int) $proveedor->Id_Estado_Proveedor === EstadoProveedor::SUSPENDIDO) {
                return response()->json([
                    'message' => AuthService::MENSAJE_ACCESO_SUSPENDIDO,
                    'proveedor_suspendido' => true,
                ], 403);
            }
        }

        $request->attributes->set('id_empresa_activa', $idEmpresa);

        return $next($request);
    }
}