<?php

namespace App\Modules\Proveedores\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Calificación global (de desempeño) del proveedor.
 *
 * Dos vistas de lo mismo:
 *  - El proveedor ve SU nota  -> GET /mi-calificacion-global
 *  - El personal interno ve la de un proveedor de su empresa activa
 *                             -> GET /proveedores/{proveedor}/calificacion-global
 *
 * El id del proveedor NO se resuelve con route-model binding: llega como
 * entero y se busca acotado a la empresa activa. El binding directo devuelve
 * el modelo sin importar a qué empresa pertenece, y eso rompe el
 * aislamiento entre empresas del grupo.
 */
class CalificacionGlobalController extends Controller
{
    public function __construct(protected CalificacionGlobalService $calificacionService)
    {
    }

    /**
     * La nota del propio proveedor autenticado, en su empresa activa.
     */
    public function miCalificacion(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo un usuario proveedor tiene calificación propia.');
        }

        $proveedor = $usuario->proveedores()
            ->where('Proveedor.Id_Empresa', $idEmpresa)
            ->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene un Proveedor asociado a la empresa activa.');
        }

        return response()->json([
            ...$this->calificacionService->calcular($proveedor),
            // Para saber si hay que mostrar el cartel de "ya es proveedor
            // aprobado". Va acá y no en /mi-ficha porque el cartel vive en
            // esta misma pantalla y así no hace falta una petición extra.
            'felicitacion_pendiente' => (int) $proveedor->Id_Estado_Proveedor === EstadoProveedor::APROBADO
                && ! $proveedor->Felicitacion_Calificacion_Mostrada,
        ]);
    }

    /**
     * Marca que al proveedor ya se le mostró el cartel de felicitación, para
     * que no vuelva a aparecer.
     *
     * Se marca cuando el cartel SE MOSTRÓ, no cuando el proveedor lo cierra:
     * si esperáramos el cierre, quien navega rápido sin cerrarlo lo volvería
     * a ver en cada visita, que es justamente lo que se está arreglando. El
     * riesgo del otro lado (que se pierda un cartel que nadie llegó a leer)
     * es menor: es una felicitación, no una notificación con información que
     * el proveedor necesite.
     */
    public function marcarFelicitacionVista(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        if ($usuario->Tipo_Usuario !== 'Proveedor') {
            throw new AccessDeniedHttpException('Solo un usuario proveedor tiene este cartel.');
        }

        $proveedor = $usuario->proveedores()
            ->where('Proveedor.Id_Empresa', $idEmpresa)
            ->first();

        if (! $proveedor) {
            throw new NotFoundHttpException('Este usuario todavía no tiene un Proveedor asociado a la empresa activa.');
        }

        $proveedor->forceFill(['Felicitacion_Calificacion_Mostrada' => true])->save();

        return response()->json(['message' => 'Listo.']);
    }

    /**
     * La nota de un proveedor puntual, para el personal interno.
     */
    public function mostrar(Request $request, int $proveedor): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->verificarPuedeVer($request->user(), $idEmpresa);

        $modelo = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->find($proveedor);

        if (! $modelo) {
            throw new NotFoundHttpException('El proveedor no existe en la empresa activa.');
        }

        return response()->json([
            'id_proveedor' => $modelo->Id_Proveedor,
            'razon_social' => $modelo->Razon_Social,
            'nombre_comercial' => $modelo->Nombre_Comercial,
            ...$this->calificacionService->calcular($modelo),
        ]);
    }

    /**
     * Notas de TODOS los proveedores activos de la empresa activa, de una
     * sola vez.
     *
     * Existe para que la pantalla de Calificación de Proveedores y el
     * reporte comparativo no tengan que pedir la nota proveedor por
     * proveedor (con 20 proveedores serían 20 peticiones y 20 spinners).
     *
     * OJO CON EL COSTO: calcular una nota son ~7 consultas, así que esto es
     * O(n) en consultas sobre la cantidad de proveedores. Con las decenas de
     * proveedores que maneja una empresa del grupo es instantáneo; si algún
     * día son cientos, acá es donde hay que meter cacheo o precálculo, no en
     * el service.
     */
    public function lote(Request $request): JsonResponse
    {
        $idEmpresa = (int) $request->attributes->get('id_empresa_activa');

        $this->verificarPuedeVer($request->user(), $idEmpresa);

        $proveedores = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->orderBy('Razon_Social')
            ->get();

        return response()->json(
            $proveedores->map(fn (Proveedor $proveedor) => [
                'id_proveedor' => $proveedor->Id_Proveedor,
                'razon_social' => $proveedor->Razon_Social,
                'nombre_comercial' => $proveedor->Nombre_Comercial,
                'ruc' => $proveedor->Ruc,
                ...$this->calificacionService->calcular($proveedor),
            ])->values()
        );
    }

    /**
     * Quién puede ver la nota de un proveedor: Sistemas y Admin porque
     * administran, Calidad porque las auditorías y la documentación son
     * suyas, y Compras porque el fill rate (la mitad de la nota) es
     * justamente lo que negocia. Guardia queda fuera: solo marca arribos.
     */
    protected function verificarPuedeVer(Usuario $usuario, int $idEmpresa): void
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            throw new AccessDeniedHttpException('Solo usuarios internos pueden ver la calificación de un proveedor.');
        }

        $puede = $usuario->esSistemas($idEmpresa)
            || $usuario->esAdmin($idEmpresa)
            || $usuario->esCalidad($idEmpresa)
            || $usuario->esCompras($idEmpresa);

        if (! $puede) {
            throw new AccessDeniedHttpException('No tienes permisos para ver la calificación de proveedores.');
        }
    }
}
