<?php

namespace App\Modules\Catalogo_Productos\Services;

use App\Models\Empresa;
use App\Modules\Auth\Models\Usuario;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Catálogo consolidado de productos: lo que en Ficha Productos ve UN
 * proveedor sobre lo suyo, acá lo ve el personal interno sobre TODOS los
 * proveedores de la empresa activa, en una sola tabla.
 *
 * OJO con el alcance por empresa: la tabla Producto NO tiene Id_Empresa
 * -> la única forma de saber a qué empresa pertenece un producto es
 * subiendo por Id_Proveedor hasta Proveedor.Id_Empresa. Por eso TODA
 * consulta de este módulo hace el join con Proveedor y filtra ahí; si
 * alguna vez alguien agrega un método sin ese join, se filtrarían
 * productos de otras empresas.
 */
class CatalogoProductoService
{
    /** Estados de calificación que se pueden filtrar desde la UI. */
    public const ESTADOS_FILTRABLES = ['Aprobado', 'Rechazado', 'Pendiente'];

    /**
     * Tope de filas por importación. No es un límite técnico sino un
     * freno: un archivo más grande que esto casi siempre significa que
     * el usuario subió el Excel equivocado.
     */
    public const MAX_FILAS_IMPORTACION = 5000;

    /**
     * Sistemas, Admin y Compras -> los tres roles internos que trabajan
     * con el catálogo. La validación es por EMPRESA ACTIVA (no global
     * como esSistemasGlobal()), porque los datos que se ven acá son de
     * la empresa activa de la sesión.
     */
    public function verificarAcceso(Usuario $usuario, int $idEmpresaActiva): void
    {
        $puedeAcceder = $usuario->esSistemas($idEmpresaActiva)
            || $usuario->esAdmin($idEmpresaActiva)
            || $usuario->esCompras($idEmpresaActiva);

        if (! $puedeAcceder) {
            throw new AccessDeniedHttpException(
                'Solo los roles Sistemas, Admin y Compras pueden acceder al catálogo de productos.'
            );
        }
    }

    /**
     * Listado paginado para la tabla. Se pagina en el servidor (y no en
     * el navegador como hace Calificación de Proveedores) porque acá el
     * universo son todos los productos de todos los proveedores, que
     * puede ser de miles de filas.
     */
    public function listar(int $idEmpresaActiva, array $filtros): LengthAwarePaginator
    {
        $porPagina = min(max((int) ($filtros['por_pagina'] ?? 15), 5), 100);

        return $this->consultaBase($idEmpresaActiva, $filtros)
            ->orderBy('pr.Razon_Social')
            ->orderBy('p.Nombre_Producto')
            ->paginate($porPagina, ['*'], 'pagina', (int) ($filtros['pagina'] ?? 1))
            ->through(fn ($fila) => $this->formatearFila($fila));
    }

    /**
     * Mismas filas que listar() pero SIN paginar -> es lo que alimenta
     * la descarga del Excel. Respeta los filtros activos: el usuario
     * descarga exactamente lo que está viendo en pantalla, no todo el
     * catálogo, así puede trabajar por tandas (ej. solo los pendientes).
     */
    public function filasParaExportar(int $idEmpresaActiva, array $filtros): Collection
    {
        return $this->consultaBase($idEmpresaActiva, $filtros)
            ->orderBy('pr.Razon_Social')
            ->orderBy('p.Nombre_Producto')
            ->get()
            ->map(fn ($fila) => $this->formatearFila($fila));
    }

    /**
     * Contadores del encabezado (total / con código BC / sin código BC).
     * Se calculan sobre TODO el catálogo de la empresa, sin aplicar los
     * filtros de la tabla -> son el panorama general, no el de la vista.
     */
    public function resumen(int $idEmpresaActiva): array
    {
        $conteos = DB::table('Producto as p')
            ->join('Proveedor as pr', 'pr.Id_Proveedor', '=', 'p.Id_Proveedor')
            ->where('pr.Id_Empresa', $idEmpresaActiva)
            ->where('p.Activo', 1)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN p.Bc_Nro_Producto IS NULL OR LTRIM(RTRIM(p.Bc_Nro_Producto)) = '' THEN 1 ELSE 0 END) as sin_codigo_bc")
            ->selectRaw("SUM(CASE WHEN p.Estado_Calificacion IS NULL THEN 1 ELSE 0 END) as pendientes")
            ->first();

        $total = (int) ($conteos->total ?? 0);
        $sinCodigoBc = (int) ($conteos->sin_codigo_bc ?? 0);

        return [
            'total' => $total,
            'con_codigo_bc' => $total - $sinCodigoBc,
            'sin_codigo_bc' => $sinCodigoBc,
            'pendientes_calificacion' => (int) ($conteos->pendientes ?? 0),
        ];
    }

    /**
     * Aplica (o solo simula, según $soloValidar) la actualización masiva
     * de Bc_Nro_Producto que viene del Excel que el usuario volvió a
     * subir.
     *
     * Reglas, en orden:
     *  1. El Id_Producto tiene que existir Y pertenecer a un proveedor de
     *     la empresa activa. Esto es lo que impide que alguien edite el
     *     Excel a mano, ponga IDs de otra empresa y los pise.
     *  2. El código tiene que existir en BC_Ficha_Producto para el
     *     Empresa_BC de la empresa activa.
     *  3. Celda vacía = "no tocar" (NO se interpreta como borrar el
     *     código). Para limpiar un código hay que hacerlo desde Ficha
     *     Productos, no desde acá.
     *  4. Varios productos SÍ pueden compartir el mismo código BC (el
     *     mismo ítem vendido por 3 proveedores distintos), así que no se
     *     valida unicidad.
     *
     * @param  array<int, array{fila:int, id_producto:int, bc_nro_producto:?string}>  $filas
     */
    public function importarCodigosBc(
        Usuario $usuario,
        int $idEmpresaActiva,
        array $filas,
        bool $soloValidar = false
    ): array {
        $empresa = Empresa::findOrFail($idEmpresaActiva);

        if (! $empresa->Empresa_BC) {
            throw new \RuntimeException('Esta empresa no tiene configurado el código Empresa_BC.');
        }

        $empresaBc = trim($empresa->Empresa_BC);

        // --- Contexto en 2 consultas, no una por fila --------------------
        $idsSolicitados = array_values(array_unique(array_column($filas, 'id_producto')));

        // Productos de ESTA empresa (vía Proveedor) -> los que no salgan
        // acá, o no existen o son de otra empresa. En ambos casos: error.
        $productosValidos = DB::table('Producto as p')
            ->join('Proveedor as pr', 'pr.Id_Proveedor', '=', 'p.Id_Proveedor')
            ->where('pr.Id_Empresa', $idEmpresaActiva)
            ->whereIn('p.Id_Producto', $idsSolicitados)
            ->pluck('p.Bc_Nro_Producto', 'p.Id_Producto');

        $codigosSolicitados = collect($filas)
            ->pluck('bc_nro_producto')
            ->map(fn ($codigo) => trim((string) $codigo))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $codigosExistentesEnBc = empty($codigosSolicitados)
            ? collect()
            : DB::table('BC_Ficha_Producto')
                ->where('Empresa', $empresaBc)
                ->whereIn('Nro_Producto', $codigosSolicitados)
                ->pluck('Nro_Producto')
                ->map(fn ($codigo) => mb_strtolower(trim($codigo)))
                ->flip();

        // --- Clasificación fila por fila ---------------------------------
        $errores = [];
        $sinCambio = 0;
        $idsVistos = [];
        /** @var array<string, array<int>> $actualizacionesPorCodigo */
        $actualizacionesPorCodigo = [];

        foreach ($filas as $fila) {
            $nroFila = $fila['fila'];
            $idProducto = $fila['id_producto'];
            $codigo = trim((string) ($fila['bc_nro_producto'] ?? ''));

            if (isset($idsVistos[$idProducto])) {
                $errores[] = $this->error($nroFila, $idProducto, $codigo, 'El producto está repetido en el archivo.');
                continue;
            }
            $idsVistos[$idProducto] = true;

            if (! $productosValidos->has($idProducto)) {
                $errores[] = $this->error(
                    $nroFila,
                    $idProducto,
                    $codigo,
                    'El producto no existe o no pertenece a la empresa activa.'
                );
                continue;
            }

            // Celda vacía -> el usuario no llenó esa fila, se salta.
            if ($codigo === '') {
                $sinCambio++;
                continue;
            }

            // Ya tenía exactamente ese código -> nada que escribir.
            if (trim((string) $productosValidos->get($idProducto)) === $codigo) {
                $sinCambio++;
                continue;
            }

            if (! $codigosExistentesEnBc->has(mb_strtolower($codigo))) {
                $errores[] = $this->error(
                    $nroFila,
                    $idProducto,
                    $codigo,
                    "El código no existe en Business Central para la empresa {$empresaBc}."
                );
                continue;
            }

            $actualizacionesPorCodigo[$codigo][] = $idProducto;
        }

        $totalAActualizar = array_sum(array_map('count', $actualizacionesPorCodigo));

        // En modo validación devolvemos el reporte sin escribir nada ->
        // es la pantalla de "esto es lo que va a pasar" antes de guardar.
        if ($soloValidar) {
            return $this->reporte(count($filas), $totalAActualizar, $sinCambio, $errores, true);
        }

        // --- Escritura ---------------------------------------------------
        // Las filas con error se descartan y las válidas SÍ se aplican
        // (importación parcial): si de 500 productos 3 tienen el código
        // mal escrito, no tiene sentido perder los otros 497.
        if ($totalAActualizar > 0) {
            // Se agrupa por código para hacer un UPDATE por código
            // distinto en vez de uno por fila.
            //
            // Se usa DB::table (no Eloquent) por volumen, así que el
            // formato de fecha hay que ponerlo A MANO en ISO con la "T":
            // esto no pasa por el $dateFormat de BaseModel, y el formato
            // por defecto de sqlsrv es ambiguo con DATEFORMAT dmy (ver
            // el comentario largo en App\Models\BaseModel).
            $ahora = now()->format('Y-m-d\TH:i:s');

            DB::transaction(function () use ($actualizacionesPorCodigo, $usuario, $idEmpresaActiva, $ahora) {
                foreach ($actualizacionesPorCodigo as $codigo => $ids) {
                    DB::table('Producto')
                        ->whereIn('Id_Producto', $ids)
                        // Cinturón y tirantes: se vuelve a acotar a la
                        // empresa activa dentro de la transacción, por si
                        // el proveedor cambiara de empresa entre la
                        // validación y el guardado.
                        ->whereIn('Id_Proveedor', function ($sub) use ($idEmpresaActiva) {
                            $sub->select('Id_Proveedor')
                                ->from('Proveedor')
                                ->where('Id_Empresa', $idEmpresaActiva);
                        })
                        ->update([
                            'Bc_Nro_Producto' => $codigo,
                            'Modificado_Por' => $usuario->Id_Usuario,
                            'Fecha_Modificacion' => $ahora,
                        ]);
                }
            });
        }

        return $this->reporte(count($filas), $totalAActualizar, $sinCambio, $errores, false);
    }

    // -------------------------------------------------------------------
    // Internos
    // -------------------------------------------------------------------

    protected function consultaBase(int $idEmpresaActiva, array $filtros): Builder
    {
        $consulta = DB::table('Producto as p')
            // INNER JOIN a propósito: un producto sin proveedor válido no
            // se puede atribuir a ninguna empresa -> no se muestra.
            ->join('Proveedor as pr', 'pr.Id_Proveedor', '=', 'p.Id_Proveedor')
            ->leftJoin('Unidad_Presentacion as u', 'u.Id_Unidad_Presentacion', '=', 'p.Id_Unidad_Presentacion')
            ->where('pr.Id_Empresa', $idEmpresaActiva)
            ->where('p.Activo', 1)
            ->select([
                'p.Id_Producto',
                'p.Nombre_Producto',
                'p.Codigo_Barras',
                'p.Bc_Nro_Producto',
                'p.Precio',
                'p.Bloqueado',
                'p.Estado_Calificacion',
                'p.Comentario_Calificacion',
                'p.Fecha_Creacion',
                'u.Nombre_Unidad',
                'pr.Id_Proveedor',
                'pr.Razon_Social',
                'pr.Nombre_Comercial',
                'pr.Ruc',
            ]);

        // Un solo buscador para todo: el usuario escribe y encuentra, sin
        // tener que elegir antes en qué campo busca. Si un mismo producto
        // lo venden 3 proveedores, buscar por nombre devuelve las 3 filas.
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));

        if ($busqueda !== '') {
            // Se escapan los comodines de LIKE de SQL Server para que
            // buscar "50%" o "AB_1" busque ese texto literal y no traiga
            // media tabla. El "[" va primero: si no, se re-escaparían
            // los corchetes que agregan las dos sustituciones siguientes.
            $patron = '%' . str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $busqueda) . '%';

            $consulta->where(function ($grupo) use ($patron) {
                $grupo->where('p.Nombre_Producto', 'like', $patron)
                    ->orWhere('p.Codigo_Barras', 'like', $patron)
                    ->orWhere('p.Bc_Nro_Producto', 'like', $patron)
                    ->orWhere('pr.Razon_Social', 'like', $patron)
                    ->orWhere('pr.Nombre_Comercial', 'like', $patron)
                    ->orWhere('pr.Ruc', 'like', $patron);
            });
        }

        $estado = $filtros['estado'] ?? null;

        if ($estado === 'Pendiente') {
            $consulta->whereNull('p.Estado_Calificacion');
        } elseif (in_array($estado, ['Aprobado', 'Rechazado'], true)) {
            $consulta->where('p.Estado_Calificacion', $estado);
        }

        // Filtro extra pensado para el flujo del Excel: dejar en pantalla
        // solo los que todavía no tienen código BC, descargar esos, y
        // volver a subirlos ya completos.
        if (($filtros['codigo_bc'] ?? null) === 'sin_codigo') {
            $consulta->where(function ($grupo) {
                $grupo->whereNull('p.Bc_Nro_Producto')->orWhere('p.Bc_Nro_Producto', '');
            });
        } elseif (($filtros['codigo_bc'] ?? null) === 'con_codigo') {
            $consulta->whereNotNull('p.Bc_Nro_Producto')->where('p.Bc_Nro_Producto', '<>', '');
        }

        return $consulta;
    }

    protected function formatearFila(object $fila): array
    {
        return [
            'id_producto' => (int) $fila->Id_Producto,
            'nombre_producto' => $fila->Nombre_Producto,
            'codigo_barras' => $fila->Codigo_Barras,
            'bc_nro_producto' => $fila->Bc_Nro_Producto,
            'precio' => $fila->Precio !== null ? (float) $fila->Precio : null,
            'unidad_presentacion' => $fila->Nombre_Unidad,
            'bloqueado' => (bool) $fila->Bloqueado,
            // null en la base = todavía nadie lo calificó. Se expone como
            // 'Pendiente' para que el front no tenga que interpretar null.
            'estado_calificacion' => $fila->Estado_Calificacion ?? 'Pendiente',
            'comentario_calificacion' => $fila->Comentario_Calificacion,
            'fecha_creacion' => $fila->Fecha_Creacion,
            'id_proveedor' => (int) $fila->Id_Proveedor,
            'razon_social' => $fila->Razon_Social,
            'nombre_comercial' => $fila->Nombre_Comercial,
            'ruc' => $fila->Ruc,
        ];
    }

    protected function error(int $fila, int $idProducto, string $codigo, string $motivo): array
    {
        return [
            'fila' => $fila,
            'id_producto' => $idProducto,
            'bc_nro_producto' => $codigo,
            'motivo' => $motivo,
        ];
    }

    protected function reporte(int $total, int $actualizados, int $sinCambio, array $errores, bool $simulacion): array
    {
        return [
            'simulacion' => $simulacion,
            'total_filas' => $total,
            'actualizados' => $actualizados,
            'sin_cambio' => $sinCambio,
            'con_error' => count($errores),
            'errores' => $errores,
        ];
    }
}