<?php

namespace App\Modules\Ficha_Productos\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Ficha_Productos\Models\GrupoProducto;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Models\UnidadPresentacion;
use App\Shared\FormatoPrecio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Excel de los productos que ESPERAN A CATÁLOGO en "Productos por revisar"
 * (18-nov-2026, pedido del usuario).
 *
 * Catálogo revisa el primer paso de los productos que ya tienen código de
 * Business Central (ver Producto::revisorPrimerPaso). Son cientos, y
 * corregirles los datos de a uno desde el formulario no escala. Con esto:
 *
 *   1. descargan un Excel con TODO lo que les espera (proveedor + datos del
 *      producto);
 *   2. corrigen lo que haga falta en el archivo;
 *   3. vuelven a subir el MISMO archivo y el portal se actualiza.
 *
 * Decisiones del usuario (18-nov-2026):
 *
 *  - SE EDITAN los mismos campos que Catálogo ya edita en el formulario del
 *    producto. El ID, el proveedor, el RUC y el código BC van solo de
 *    referencia: lo que se escriba ahí se ignora.
 *  - LO USAN Catálogo, Admin y Sistemas.
 *  - SOLO ACTUALIZA DATOS: el producto sigue esperando a Catálogo, que
 *    después lo aprueba, lo devuelve o lo retira desde la pantalla como
 *    siempre.
 *
 * El .xlsx lo arma y lo lee el navegador (SheetJS), igual que el de códigos
 * BC del Catálogo de productos: acá llegan y salen filas en JSON y el
 * backend nunca toca un binario de Office.
 *
 * QUÉ SIGNIFICA CADA CELDA al volver a subir el archivo:
 *
 *  - Columna que NO viene en el archivo (la borraron o le cambiaron el
 *    encabezado) = ese dato no se toca en ningún producto.
 *  - Celda VACÍA de una columna que sí viene = el dato queda vacío. Si es un
 *    dato obligatorio (nombre, categoría, unidad), la fila da error.
 *  - Fila idéntica a lo que ya está guardado = sin cambios, ni se valida.
 *
 * Una fila con cambios pasa por las MISMAS reglas que el formulario
 * (GuardarProductoRequest): lo que entra por Excel es tan válido como lo que
 * entra por pantalla. Las filas con error se descartan y las válidas se
 * guardan igual (importación parcial, mismo criterio que
 * CatalogoProductoService::importarCodigosBc).
 */
class RevisionCatalogoExcelService
{
    /** Tope de filas por carga: un archivo más grande casi seguro es el equivocado. */
    public const MAX_FILAS = 2000;

    /** Categoría tal como se escribe en el Excel (mismas etiquetas que la pantalla). */
    public const CATEGORIAS = [
        Producto::PERECIBLES => 'Perecible',
        Producto::NO_PERECIBLES => 'No perecible',
        Producto::FRUVER => 'Fruver',
    ];

    /**
     * Columnas editables: clave que viaja en el JSON => nombre con el que se
     * le habla al usuario en el reporte. El orden es el del Excel.
     */
    public const CAMPOS = [
        'nombre_producto' => 'Producto',
        'codigo_barras' => 'Cód. barras',
        'categoria' => 'Categoría',
        'unidad' => 'Unidad',
        'precio' => 'Precio',
        'peso' => 'Peso (kg)',
        'unidad_por_caja' => 'Unidades x masterpack',
        'contenido_paquete' => 'Contenido x paquete',
        'masterpack_largo_cm' => 'Masterpack largo (cm)',
        'masterpack_ancho_cm' => 'Masterpack ancho (cm)',
        'masterpack_alto_cm' => 'Masterpack alto (cm)',
        'unidad_largo_cm' => 'Unidad largo (cm)',
        'unidad_ancho_cm' => 'Unidad ancho (cm)',
        'unidad_alto_cm' => 'Unidad alto (cm)',
        'grupos' => 'Grupos',
    ];

    /** Las seis medidas en cm: DECIMAL(8,2). */
    private const MEDIDAS = [
        'masterpack_largo_cm' => 'Masterpack_Largo_Cm',
        'masterpack_ancho_cm' => 'Masterpack_Ancho_Cm',
        'masterpack_alto_cm' => 'Masterpack_Alto_Cm',
        'unidad_largo_cm' => 'Unidad_Largo_Cm',
        'unidad_ancho_cm' => 'Unidad_Ancho_Cm',
        'unidad_alto_cm' => 'Unidad_Alto_Cm',
    ];

    public function __construct(private ProductoService $productos)
    {
    }

    /** Catálogo, Admin y Sistemas de la empresa activa (decisión del usuario, 18-nov-2026). */
    public function verificarAcceso(Usuario $usuario, int $idEmpresaActiva): void
    {
        $puede = $usuario->Tipo_Usuario === 'Interno' && (
            $usuario->esCatalogo($idEmpresaActiva)
            || $usuario->esAdmin($idEmpresaActiva)
            || $usuario->esSistemas($idEmpresaActiva)
        );

        if (! $puede) {
            throw new AccessDeniedHttpException(
                'Solo los roles Catálogo, Admin y Sistemas descargan y cargan el Excel de productos por revisar.'
            );
        }
    }

    /**
     * Las filas del Excel: todo lo que espera a Catálogo en la empresa
     * activa, más los valores que se pueden escribir en las columnas de
     * lista (para la hoja de ayuda del archivo).
     *
     * @return array{filas: array<int, array<string, string|int>>, valores_validos: array<string, mixed>}
     */
    public function exportar(Usuario $usuario, int $idEmpresaActiva): array
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        $filas = $this->esperandoACatalogo($idEmpresaActiva)
            ->with(['proveedor', 'unidadPresentacion', 'grupos'])
            ->get()
            // Por proveedor y, dentro, por producto: Catálogo revisa "lo de
            // Cornucopia", no productos sueltos de doce proveedores mezclados.
            ->sortBy(fn (Producto $p) => [
                mb_strtolower($p->proveedor->nombreVisible()),
                mb_strtolower((string) $p->Nombre_Producto),
            ])
            ->values()
            ->map(fn (Producto $p) => [
                'id_producto' => (int) $p->Id_Producto,
                'proveedor' => $p->proveedor->nombreVisible(),
                'ruc' => (string) $p->proveedor->Ruc,
                'bc_nro_producto' => trim((string) $p->Bc_Nro_Producto),
                ...$this->comoTexto($this->valoresActuales($p), $p),
            ])
            ->all();

        return [
            'filas' => $filas,
            'valores_validos' => [
                'categorias' => array_values(self::CATEGORIAS),
                'unidades' => UnidadPresentacion::where('Activo', 1)->orderBy('Nombre_Unidad')->pluck('Nombre_Unidad')->all(),
                'grupos' => GrupoProducto::where('Activo', 1)->orderBy('Orden')->orderBy('Codigo')
                    ->get(['Codigo', 'Nombre'])
                    ->map(fn (GrupoProducto $g) => ['codigo' => $g->Codigo, 'nombre' => $g->Nombre])
                    ->all(),
            ],
        ];
    }

    /**
     * Aplica (o solo simula, según $soloValidar) el Excel que el usuario
     * volvió a subir. Devuelve el reporte: qué cambia en cada producto, qué
     * filas quedan igual y cuáles se descartan y por qué.
     *
     * @param  array<int, array<string, mixed>>  $filas  cada una con 'fila', 'id_producto' y las columnas que traía el archivo
     */
    public function importar(Usuario $usuario, int $idEmpresaActiva, array $filas, bool $soloValidar = false): array
    {
        $this->verificarAcceso($usuario, $idEmpresaActiva);

        $ids = array_values(array_unique(array_map(fn ($f) => (int) $f['id_producto'], $filas)));

        // SOLO los que esperan a Catálogo en esta empresa: lo que no salga
        // acá, o no existe, o es de otra empresa, o ya lo resolvió alguien.
        // De a 1000 ids por el tope de 2100 parámetros de SQL Server.
        $productos = collect($ids)->chunk(1000)->flatMap(
            fn (Collection $tanda) => $this->esperandoACatalogo($idEmpresaActiva)
                ->whereIn('Id_Producto', $tanda->all())
                ->with(['proveedor', 'unidadPresentacion', 'grupos'])
                ->get()
        )->keyBy('Id_Producto');

        $catalogos = $this->catalogos();

        $errores = [];
        $cambios = [];
        $sinCambio = 0;
        $vistos = [];
        /** @var array<int, array{producto: Producto, data: array<string, mixed>}> $aGuardar */
        $aGuardar = [];

        foreach ($filas as $fila) {
            $nroFila = (int) $fila['fila'];
            $idProducto = (int) $fila['id_producto'];
            /** @var Producto|null $producto */
            $producto = $productos->get($idProducto);
            $nombre = $producto?->Nombre_Producto ?? trim((string) ($fila['nombre_producto'] ?? ''));

            if (isset($vistos[$idProducto])) {
                $errores[] = $this->error($nroFila, $idProducto, $nombre, 'El producto está repetido en el archivo.');

                continue;
            }
            $vistos[$idProducto] = true;

            if (! $producto) {
                $errores[] = $this->error(
                    $nroFila,
                    $idProducto,
                    $nombre,
                    'El producto ya no está esperando la revisión de Catálogo (o no pertenece a la empresa activa).'
                );

                continue;
            }

            try {
                $actual = $this->valoresActuales($producto);
                $nuevo = $this->leerFila($fila, $actual, $producto, $catalogos);
                $diferencias = $this->diferencias($actual, $nuevo, $producto, $catalogos);

                if ($diferencias === []) {
                    $sinCambio++;

                    continue;
                }

                $this->validarResultado($nuevo, $actual, $producto, $catalogos);
            } catch (\DomainException $e) {
                $errores[] = $this->error($nroFila, $idProducto, $nombre, $e->getMessage());

                continue;
            }

            $cambios[] = [
                'fila' => $nroFila,
                'id_producto' => $idProducto,
                'producto' => $producto->Nombre_Producto,
                'proveedor' => $producto->proveedor->nombreVisible(),
                'cambios' => $diferencias,
            ];

            $data = $nuevo;
            // Los grupos solo se sincronizan si de verdad cambiaron.
            if ($nuevo['grupos'] === $actual['grupos']) {
                unset($data['grupos']);
            }
            $aGuardar[] = ['producto' => $producto, 'data' => $data];
        }

        if (! $soloValidar && $aGuardar !== []) {
            // Todo o nada para las filas válidas: si la base falla a mitad de
            // camino no queda medio archivo aplicado.
            DB::transaction(function () use ($aGuardar, $usuario) {
                foreach ($aGuardar as $item) {
                    $this->productos->guardarDatosSinCambiarEstado($usuario, $item['producto'], $item['data']);
                }
            });

            Log::info('Productos por revisar: datos actualizados por Excel.', [
                'id_usuario' => $usuario->Id_Usuario,
                'id_empresa' => $idEmpresaActiva,
                'productos' => array_map(fn ($item) => $item['producto']->Id_Producto, $aGuardar),
            ]);
        }

        return [
            'simulacion' => $soloValidar,
            'total_filas' => count($filas),
            'actualizados' => count($cambios),
            'sin_cambio' => $sinCambio,
            'con_error' => count($errores),
            'errores' => $errores,
            'cambios' => $cambios,
        ];
    }

    // -------------------------------------------------------------------
    // Internos
    // -------------------------------------------------------------------

    /**
     * Productos que esperan el primer paso Y tienen código BC, o sea los que
     * en la bandeja dicen "Esperando a Catálogo". Misma condición que
     * RevisionComprasProductoService::pendientes para el rol Catálogo.
     */
    private function esperandoACatalogo(int $idEmpresaActiva): Builder
    {
        return Producto::query()
            ->whereNotNull('Bc_Nro_Producto')
            ->whereRaw("LTRIM(RTRIM(Bc_Nro_Producto)) <> ''")
            ->whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)->where('Activo', 1))
            ->where('Activo', 1)
            ->where('Etapa_Aprobacion', Producto::ETAPA_COMPRAS)
            ->where('Estado_Calificacion', 'Pendiente');
    }

    /**
     * Unidades y grupos contra los que se resuelve lo que viene escrito.
     *
     * @return array{unidades: array<string, int>, nombres_unidad: array<int, string>, id_paquete: ?int, grupos: array<string, int>, codigos_grupo: array<int, string>}
     */
    private function catalogos(): array
    {
        $unidades = UnidadPresentacion::all();
        $grupos = GrupoProducto::all();

        $idPaquete = $unidades->firstWhere('Nombre_Unidad', 'Paquete')?->Id_Unidad_Presentacion;

        return [
            // Solo las ACTIVAS se pueden elegir (igual que el <select>).
            'unidades' => $unidades->where('Activo', true)
                ->mapWithKeys(fn ($u) => [$this->clave($u->Nombre_Unidad) => (int) $u->Id_Unidad_Presentacion])->all(),
            'nombres_unidad' => $unidades
                ->mapWithKeys(fn ($u) => [(int) $u->Id_Unidad_Presentacion => $u->Nombre_Unidad])->all(),
            'id_paquete' => $idPaquete !== null ? (int) $idPaquete : null,
            // Solo los ACTIVOS se pueden asignar (igual que GuardarProductoRequest).
            'grupos' => $grupos->where('Activo', true)
                ->mapWithKeys(fn ($g) => [$this->clave($g->Codigo) => (int) $g->Id_Grupo_Producto])->all(),
            'codigos_grupo' => $grupos
                ->mapWithKeys(fn ($g) => [(int) $g->Id_Grupo_Producto => $g->Codigo])->all(),
        ];
    }

    /**
     * Lo que el producto tiene guardado hoy, con las mismas claves que
     * GuardarProductoRequest. Los decimales van como texto ya normalizado
     * ("1.25", no "1.2500") para poder compararlos sin ruido de flotantes.
     *
     * @return array<string, mixed>
     */
    private function valoresActuales(Producto $producto): array
    {
        $codigoBarras = trim((string) $producto->Codigo_Barras);

        $actual = [
            // trim: las descripciones que vienen de BC a veces traen espacios
            // al final, y sin esto cada fila parecería "cambiada" con un
            // antes y un después que se ven idénticos.
            'nombre_producto' => trim((string) $producto->Nombre_Producto),
            'codigo_barras' => $codigoBarras !== '' ? $codigoBarras : null,
            'clasificacion' => $producto->Clasificacion ?: null,
            'id_unidad_presentacion' => (int) $producto->Id_Unidad_Presentacion,
            'precio' => $this->decimalGuardado($producto->Precio, FormatoPrecio::DECIMALES),
            'peso' => $this->decimalGuardado($producto->Peso, 3),
            'unidad_por_caja' => $producto->Unidad_Por_Caja !== null ? (int) $producto->Unidad_Por_Caja : null,
            'contenido_paquete' => $producto->Contenido_Paquete !== null ? (int) $producto->Contenido_Paquete : null,
        ];

        foreach (self::MEDIDAS as $campo => $columna) {
            $actual[$campo] = $this->decimalGuardado($producto->{$columna}, 2);
        }

        $actual['grupos'] = $producto->grupos->pluck('Id_Grupo_Producto')->map(fn ($id) => (int) $id)->sort()->values()->all();

        return $actual;
    }

    /**
     * Convierte la fila del Excel en valores listos para guardar, partiendo
     * de lo que el producto ya tiene: la columna que no vino en el archivo
     * conserva su valor.
     *
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $actual
     * @return array<string, mixed>
     */
    private function leerFila(array $fila, array $actual, Producto $producto, array $catalogos): array
    {
        $nuevo = $actual;
        $vino = fn (string $campo) => array_key_exists($campo, $fila);
        $texto = fn (string $campo) => trim((string) ($fila[$campo] ?? ''));

        if ($vino('nombre_producto')) {
            $nuevo['nombre_producto'] = mb_strtoupper($texto('nombre_producto'), 'UTF-8');
        }

        if ($vino('codigo_barras')) {
            $nuevo['codigo_barras'] = $texto('codigo_barras') !== '' ? $texto('codigo_barras') : null;
        }

        if ($vino('categoria')) {
            $nuevo['clasificacion'] = $this->leerCategoria($texto('categoria'));
        }

        if ($vino('unidad')) {
            $nuevo['id_unidad_presentacion'] = $this->leerUnidad($texto('unidad'), $actual, $catalogos);
        }

        if ($vino('precio')) {
            $nuevo['precio'] = $this->leerDecimal($texto('precio'), 'Precio', FormatoPrecio::DECIMALES, FormatoPrecio::MAXIMO);
        }

        if ($vino('peso')) {
            $nuevo['peso'] = $this->leerDecimal($texto('peso'), 'Peso', 3, 9999999.999);
        }

        foreach (['unidad_por_caja', 'contenido_paquete'] as $campo) {
            if ($vino($campo)) {
                $nuevo[$campo] = $this->leerEntero($texto($campo), self::CAMPOS[$campo]);
            }
        }

        foreach (array_keys(self::MEDIDAS) as $campo) {
            if ($vino($campo)) {
                $nuevo[$campo] = $this->leerDecimal($texto($campo), self::CAMPOS[$campo], 2, 999999.99);
            }
        }

        if ($vino('grupos')) {
            $nuevo['grupos'] = $this->leerGrupos($texto('grupos'), $actual, $catalogos);
        }

        return $nuevo;
    }

    /**
     * Reglas del formulario (GuardarProductoRequest) sobre el resultado de
     * una fila que SÍ cambia algo.
     *
     * @param  array<string, mixed>  $nuevo
     * @param  array<string, mixed>  $actual
     */
    private function validarResultado(array $nuevo, array $actual, Producto $producto, array $catalogos): void
    {
        if ($nuevo['nombre_producto'] === '') {
            throw new \DomainException('El nombre del producto no puede quedar vacío.');
        }

        if (mb_strlen($nuevo['nombre_producto']) > 200) {
            throw new \DomainException('El nombre del producto no puede superar los 200 caracteres.');
        }

        if ($nuevo['codigo_barras'] !== null && mb_strlen($nuevo['codigo_barras']) > 50) {
            throw new \DomainException('El código de barras no puede superar los 50 caracteres.');
        }

        if ($nuevo['clasificacion'] === null) {
            throw new \DomainException('Falta la categoría: escribe Perecible, No perecible o Fruver.');
        }

        if ($nuevo['id_unidad_presentacion'] === $catalogos['id_paquete'] && $nuevo['contenido_paquete'] === null) {
            throw new \DomainException('La unidad es Paquete: indica cuántas unidades trae en "Contenido x paquete".');
        }

        if ($producto->Precio_En_Revision && $nuevo['precio'] !== $actual['precio']) {
            throw new \DomainException(
                'Este producto tiene un cambio de precio pendiente de aprobación; el precio no se puede editar hasta que se resuelva.'
            );
        }
    }

    /**
     * Qué cambia entre lo guardado y lo que trae la fila, ya en el texto que
     * se le muestra al usuario. Vacío = la fila no cambia nada.
     *
     * @return array<int, array{campo: string, antes: string, despues: string}>
     */
    private function diferencias(array $actual, array $nuevo, Producto $producto, array $catalogos): array
    {
        $antes = $this->comoTexto($actual, $producto, $catalogos);
        $despues = $this->comoTexto($nuevo, $producto, $catalogos);

        // Se compara el VALOR (no el texto): "5" y "5.00" son el mismo precio.
        $claves = [
            'nombre_producto' => 'nombre_producto',
            'codigo_barras' => 'codigo_barras',
            'categoria' => 'clasificacion',
            'unidad' => 'id_unidad_presentacion',
            'precio' => 'precio',
            'peso' => 'peso',
            'unidad_por_caja' => 'unidad_por_caja',
            'contenido_paquete' => 'contenido_paquete',
            ...array_combine(array_keys(self::MEDIDAS), array_keys(self::MEDIDAS)),
            'grupos' => 'grupos',
        ];

        $diferencias = [];

        foreach ($claves as $campo => $clave) {
            if ($actual[$clave] !== $nuevo[$clave]) {
                $diferencias[] = [
                    'campo' => self::CAMPOS[$campo],
                    'antes' => $antes[$campo],
                    'despues' => $despues[$campo],
                ];
            }
        }

        return $diferencias;
    }

    /**
     * Los valores tal como se escriben en las celdas del Excel (y en el
     * reporte de cambios).
     *
     * @param  array<string, mixed>  $valores
     * @return array<string, string>
     */
    private function comoTexto(array $valores, Producto $producto, ?array $catalogos = null): array
    {
        $nombreUnidad = $catalogos['nombres_unidad'][$valores['id_unidad_presentacion']]
            ?? ($valores['id_unidad_presentacion'] === (int) $producto->Id_Unidad_Presentacion
                ? (string) $producto->unidadPresentacion?->Nombre_Unidad
                : '');

        $codigosGrupo = $catalogos !== null
            ? array_map(fn (int $id) => $catalogos['codigos_grupo'][$id] ?? '', $valores['grupos'])
            : $producto->grupos->pluck('Codigo')->all();

        $texto = [
            'nombre_producto' => $valores['nombre_producto'],
            'codigo_barras' => (string) $valores['codigo_barras'],
            'categoria' => self::CATEGORIAS[$valores['clasificacion']] ?? '',
            'unidad' => $nombreUnidad,
            'precio' => FormatoPrecio::mostrar($valores['precio']),
            'peso' => (string) $valores['peso'],
            'unidad_por_caja' => (string) $valores['unidad_por_caja'],
            'contenido_paquete' => (string) $valores['contenido_paquete'],
        ];

        foreach (array_keys(self::MEDIDAS) as $campo) {
            $texto[$campo] = (string) $valores[$campo];
        }

        $texto['grupos'] = implode(', ', array_filter($codigosGrupo));

        return $texto;
    }

    private function leerCategoria(string $texto): ?string
    {
        if ($texto === '') {
            // Vacía: queda sin categoría. Si la fila cambia otra cosa,
            // validarResultado la exige; si no cambia nada, no molesta (hay
            // productos viejos en revisión que nunca la tuvieron).
            return null;
        }

        // "Perecible", "PERECIBLES", "No perecible", "No_Perecibles"... todo
        // termina en la misma clave: sin tildes, sin separadores, sin la s.
        $clave = rtrim(str_replace(' ', '', $this->clave(str_replace(['_', '-'], ' ', $texto))), 'S');

        return match ($clave) {
            'PERECIBLE' => Producto::PERECIBLES,
            'NOPERECIBLE' => Producto::NO_PERECIBLES,
            'FRUVER' => Producto::FRUVER,
            default => throw new \DomainException(
                "La categoría \"{$texto}\" no existe. Escribe Perecible, No perecible o Fruver."
            ),
        };
    }

    private function leerUnidad(string $texto, array $actual, array $catalogos): int
    {
        if ($texto === '') {
            throw new \DomainException('La unidad no puede quedar vacía.');
        }

        $clave = $this->clave($texto);

        if (isset($catalogos['unidades'][$clave])) {
            return $catalogos['unidades'][$clave];
        }

        // La que el producto ya tenía se respeta aunque Sistemas la haya
        // dado de baja: no elegirla de nuevo no es lo mismo que perderla.
        $nombreActual = $catalogos['nombres_unidad'][$actual['id_unidad_presentacion']] ?? null;

        if ($nombreActual !== null && $this->clave($nombreActual) === $clave) {
            return $actual['id_unidad_presentacion'];
        }

        throw new \DomainException(
            "La unidad \"{$texto}\" no existe. Las disponibles están en la hoja \"Valores validos\" del archivo."
        );
    }

    /**
     * Grupos escritos por código, separados por coma: "EK, CD".
     *
     * @return array<int, int> ids ordenados
     */
    private function leerGrupos(string $texto, array $actual, array $catalogos): array
    {
        if ($texto === '') {
            return [];
        }

        // Los que el producto ya tenía valen aunque el grupo esté inactivo.
        $yaAsignados = [];
        foreach ($actual['grupos'] as $id) {
            $yaAsignados[$this->clave($catalogos['codigos_grupo'][$id] ?? '')] = $id;
        }

        $ids = [];

        foreach (preg_split('/[,;\/]+/', $texto) as $codigo) {
            $codigo = trim($codigo);

            if ($codigo === '') {
                continue;
            }

            $clave = $this->clave($codigo);
            $id = $catalogos['grupos'][$clave] ?? $yaAsignados[$clave] ?? null;

            if ($id === null) {
                throw new \DomainException(
                    "El grupo \"{$codigo}\" no existe. Los disponibles están en la hoja \"Valores validos\" del archivo."
                );
            }

            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Un número con decimales escrito en una celda. Acepta coma o punto
     * decimal ("1,2575" o "1.2575"): según cómo esté configurado Excel, el
     * usuario escribe uno u otro. Devuelve el número normalizado como texto
     * (sin ceros de relleno), o null si la celda está vacía.
     */
    private function leerDecimal(string $texto, string $etiqueta, int $decimales, float $maximo): ?string
    {
        $texto = str_replace([' ', '$'], '', $texto);

        if ($texto === '') {
            return null;
        }

        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            throw new \DomainException("{$etiqueta}: \"{$texto}\" no es un número válido. Escríbelo sin separador de miles.");
        }

        $numero = str_replace(',', '.', $texto);

        if (! preg_match('/^\d+(\.\d+)?$/', $numero)) {
            throw new \DomainException("{$etiqueta}: \"{$texto}\" no es un número válido.");
        }

        $numero = $this->sinCerosDeRelleno($numero);
        $escritos = str_contains($numero, '.') ? strlen(substr($numero, strpos($numero, '.') + 1)) : 0;

        if ($escritos > $decimales) {
            throw new \DomainException("{$etiqueta}: admite hasta {$decimales} decimales (\"{$texto}\").");
        }

        if ((float) $numero > $maximo) {
            throw new \DomainException("{$etiqueta}: \"{$texto}\" es demasiado grande.");
        }

        return $numero;
    }

    /** Una cantidad: entero de 1 en adelante, o null si la celda está vacía. */
    private function leerEntero(string $texto, string $etiqueta): ?int
    {
        $texto = str_replace(' ', '', $texto);

        if ($texto === '') {
            return null;
        }

        // "12", y también "12.0" o "12,00" si Excel lo formateó así.
        if (! preg_match('/^(\d+)([.,]0+)?$/', $texto, $partes) || (int) $partes[1] < 1 || strlen($partes[1]) > 9) {
            throw new \DomainException("{$etiqueta}: \"{$texto}\" tiene que ser un número entero de 1 en adelante.");
        }

        return (int) $partes[1];
    }

    /** Decimal leído de la base ("5.0000") -> "5"; null si no hay. */
    private function decimalGuardado(mixed $valor, int $decimales): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $this->sinCerosDeRelleno(number_format((float) $valor, $decimales, '.', ''));
    }

    /** "5.2500" -> "5.25", "5.0000" -> "5", "007" -> "7". */
    private function sinCerosDeRelleno(string $numero): string
    {
        if (str_contains($numero, '.')) {
            $numero = rtrim(rtrim($numero, '0'), '.');
        }

        $numero = ltrim($numero, '0');

        if ($numero === '' || $numero[0] === '.') {
            $numero = '0'.$numero;
        }

        return $numero;
    }

    /** Para comparar textos escritos a mano: sin tildes, en mayúsculas y sin espacios de más. */
    private function clave(string $texto): string
    {
        $sinTildes = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $sinTildes)), 'UTF-8');
    }

    private function error(int $fila, int $idProducto, string $producto, string $motivo): array
    {
        return [
            'fila' => $fila,
            'id_producto' => $idProducto,
            'producto' => $producto,
            'motivo' => $motivo,
        ];
    }
}
