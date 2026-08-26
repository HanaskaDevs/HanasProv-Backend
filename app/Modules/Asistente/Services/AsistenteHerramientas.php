<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Pedidos\Services\PedidoInternoService;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use Illuminate\Support\Facades\Log;

/**
 * Las herramientas que Hana puede usar para CONSULTAR datos, en vez de
 * responder solo con lo que le llegó en el contexto.
 *
 * POR QUÉ HACEN FALTA: el contexto que viaja en cada mensaje trae resúmenes
 * ("30 pedidos abiertos, 34 cerrados"), no filas. Cuando alguien pide "los
 * pedidos de la bodega CD-0002 con menos del 80% de entrega", ningún resumen
 * alcanza, y Hana respondía "no tengo acceso a los datos detallados" y mandaba
 * al usuario a buscarlo a mano. Con herramientas puede ir a buscarlo.
 *
 * LOS PERMISOS NO SE REIMPLEMENTAN ACÁ. Cada herramienta llama al MISMO
 * service que usa la pantalla equivalente, pasándole el $usuario: así las
 * bodegas que Hana puede consultar son exactamente las que ese usuario ve en
 * Pedidos, y la empresa es siempre la activa. Si mañana cambia una regla de
 * permisos en el service, Hana la hereda sin que nadie se acuerde de tocar
 * este archivo. Duplicar las reglas acá sería la forma más rápida de que el
 * bot filtre datos que la interfaz no muestra.
 *
 * SOLO PARA USUARIOS INTERNOS. Un proveedor no recibe ninguna herramienta:
 * todo lo suyo ya está en el contexto, y darle una que consulte tablas sería
 * abrirle una puerta lateral a datos de otros.
 */
class AsistenteHerramientas
{
    /**
     * Tope de filas que van al archivo descargable.
     *
     * Es alto a propósito: estas filas NO se le mandan al modelo (de eso se
     * encarga la muestra de 12 en resultado()), viajan directo al frontend
     * para armar el Excel. O sea que subir este número NO cuesta tokens, solo
     * peso de respuesta. 1000 cubre cualquier consulta real del portal y evita
     * que un filtro amplio devuelva una respuesta HTTP enorme.
     */
    public const MAX_FILAS = 1000;

    /**
     * Umbral de "entrega completa". Los porcentajes vienen calculados por
     * cantidad con tope por línea (ver PedidoCompraResource), así que un
     * pedido completo da 100 exacto y no 99.9997: igual se compara con >= por
     * si en algún pedido raro la suma de decimales no cierra justo.
     */
    protected const PORCENTAJE_COMPLETO = 100.0;

    /** Cuántas filas se le muestran al MODELO como ejemplo. Esto sí cuesta tokens. */
    protected const FILAS_DE_MUESTRA = 12;

    public function __construct(
        protected PedidoInternoService $pedidoInternoService,
        protected CalificacionGlobalService $calificacionGlobalService,
    ) {
    }

    /**
     * Definiciones para la API. Vacío si el usuario no debe tener ninguna.
     *
     * @return list<array<string, mixed>>
     */
    public function definiciones(Usuario $usuario, int $idEmpresaActiva): array
    {
        if ($usuario->Tipo_Usuario !== 'Interno') {
            return [];
        }

        $rol = $this->rolEn($usuario, $idEmpresaActiva);

        // El Guardia solo marca arribos: no tiene por qué poder consultar
        // pedidos ni calificaciones por otra vía.
        if (! in_array($rol, ['Sistemas', 'Admin', 'Compras', 'Calidad'], true)) {
            return [];
        }

        $herramientas = [];

        if (in_array($rol, ['Sistemas', 'Admin', 'Compras'], true)) {
            $herramientas[] = [
                'name' => 'consultar_pedidos',
                'description' =>
                    'Consulta los pedidos de compra por bodega de la empresa activa, con sus '
                    .'porcentajes de entrega. Úsala cuando el usuario pida un listado, un filtro o '
                    .'un reporte de pedidos (por bodega, por cumplimiento, por proveedor). '
                    .'Devuelve solo las bodegas que este usuario tiene permitidas. '
                    .'Para "completos", "al 100%", "ya entregados" usa estado_entrega=completos; '
                    .'para "pendientes" o "incompletos", estado_entrega=incompletos. '
                    .'NO uses porcentaje_maximo=100 para pedir los completos: ese filtro trae los '
                    .'que están POR DEBAJO de 100 y te dejaría justo fuera los que buscas.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'bodega' => [
                            'type' => 'string',
                            'description' => 'Código de bodega, por ejemplo CD-0001. Omitir para traer todas las permitidas.',
                        ],
                        'estado_entrega' => [
                            'type' => 'string',
                            'enum' => ['completos', 'incompletos', 'todos'],
                            'description' =>
                                'completos = entrega al 100%. incompletos = por debajo del 100%. '
                                .'todos = sin filtrar (por defecto). Es la forma correcta de pedir '
                                .'"pedidos completos" o "pedidos pendientes".',
                        ],
                        'porcentaje_maximo' => [
                            'type' => 'number',
                            'description' => 'Solo pedidos con porcentaje de entrega ESTRICTAMENTE MENOR a este valor (0-100). Para los completos usa estado_entrega, no esto.',
                        ],
                        'porcentaje_minimo' => [
                            'type' => 'number',
                            'description' => 'Solo pedidos con porcentaje de entrega MAYOR O IGUAL a este valor (0-100).',
                        ],
                        'proveedor' => [
                            'type' => 'string',
                            'description' => 'Filtra por nombre o RUC del proveedor (coincidencia parcial).',
                        ],
                    ],
                ],
            ];
        }

        if (in_array($rol, ['Sistemas', 'Admin', 'Calidad'], true)) {
            $herramientas[] = [
                'name' => 'consultar_calificaciones_proveedores',
                'description' =>
                    'Consulta la calificación global de desempeño de los proveedores de la empresa '
                    .'activa, con el desglose por componente. Úsala cuando el usuario pida un '
                    .'ranking, una comparación o un reporte de calificaciones (por ejemplo "los 10 '
                    .'mejor calificados").',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'orden' => [
                            'type' => 'string',
                            'enum' => ['mejor', 'peor'],
                            'description' => 'mejor = de mayor a menor nota. peor = de menor a mayor.',
                        ],
                        'limite' => [
                            'type' => 'integer',
                            'description' => 'Cuántos proveedores traer. Por defecto todos.',
                        ],
                    ],
                ],
            ];
        }

        return $herramientas;
    }

    /**
     * Ejecuta una herramienta y devuelve el resultado listo para mandárselo
     * al modelo.
     *
     * NUNCA lanza: un error acá tiene que volver como texto que el modelo
     * pueda explicarle al usuario, no romper la conversación entera.
     *
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    public function ejecutar(string $nombre, array $entrada, Usuario $usuario, int $idEmpresaActiva): array
    {
        try {
            return match ($nombre) {
                'consultar_pedidos' => $this->consultarPedidos($entrada, $usuario, $idEmpresaActiva),
                'consultar_calificaciones_proveedores' => $this->consultarCalificaciones($entrada, $usuario, $idEmpresaActiva),
                default => $this->vacio("No existe una herramienta llamada '{$nombre}'."),
            };
        } catch (\Throwable $e) {
            Log::error('Asistente: falló una herramienta', [
                'herramienta' => $nombre,
                'error' => $e->getMessage(),
            ]);

            return $this->vacio(
                'No se pudo completar la consulta. Dile al usuario que lo intente de nuevo '
                .'o que revise la sección correspondiente del portal.'
            );
        }
    }

    /**
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function consultarPedidos(array $entrada, Usuario $usuario, int $idEmpresaActiva): array
    {
        // EL PERMISO SE VALIDA PRIMERO, antes de ir a buscar nada. Antes se
        // consultaba y se filtraba después, y eso tenía dos problemas: se
        // pegaba a Business Central para una consulta que iba a rechazarse, y
        // si esa consulta fallaba por cualquier motivo (una empresa sin
        // código BC configurado, por ejemplo) el usuario recibía "no se pudo
        // completar" en vez de "esa bodega no es tuya, estas sí".
        $bodegasPermitidas = $this->pedidoInternoService->obtenerBodegasPermitidas($usuario, $idEmpresaActiva);
        $bodegaPedida = $this->normalizarBodega($entrada['bodega'] ?? null);

        if ($bodegaPedida !== null && ! in_array($bodegaPedida, $bodegasPermitidas, true)) {
            $disponibles = $bodegasPermitidas === []
                ? 'ninguna (este usuario no tiene bodegas asignadas)'
                : implode(', ', $bodegasPermitidas);

            return $this->vacio(
                "La bodega '{$bodegaPedida}' no existe o este usuario no tiene acceso a ella. "
                ."Bodegas disponibles para él: {$disponibles}. Dile exactamente eso; no consultes otra bodega por él."
            );
        }

        if ($bodegasPermitidas === []) {
            return $this->vacio(
                'Este usuario no tiene ninguna bodega asignada, así que no hay pedidos que pueda ver. '
                .'Dile que pida a Sistemas que le asignen sus bodegas.'
            );
        }

        $porBodega = $this->pedidoInternoService->listarPorBodega($usuario, $idEmpresaActiva, []);
        $maximo = isset($entrada['porcentaje_maximo']) ? (float) $entrada['porcentaje_maximo'] : null;
        $minimo = isset($entrada['porcentaje_minimo']) ? (float) $entrada['porcentaje_minimo'] : null;
        $proveedor = isset($entrada['proveedor']) ? mb_strtolower(trim((string) $entrada['proveedor'])) : null;
        $estado = $this->normalizarEstadoEntrega($entrada['estado_entrega'] ?? null);

        // El pedido de "los que están al 100%" llegaba como porcentaje_maximo=100,
        // que significa lo contrario (menores a 100) y devolvía cero filas
        // teniendo 89 pedidos completos en la bodega. Con estado_entrega el
        // caso queda explícito; y si igual llega el porcentaje mal usado junto
        // al estado, manda el estado.
        if ($estado === 'completos') {
            $minimo = self::PORCENTAJE_COMPLETO;
            $maximo = null;
        } elseif ($estado === 'incompletos') {
            $maximo = self::PORCENTAJE_COMPLETO;
            $minimo = null;
        }

        $filas = [];

        foreach ($porBodega as $codigo => $datos) {
            // Doble llave: el service ya devuelve solo las permitidas, y acá
            // se vuelve a verificar. Es baratísimo y es la diferencia entre
            // confiar en una capa y confiar en dos.
            if (! in_array($codigo, $bodegasPermitidas, true)) {
                continue;
            }
            if ($bodegaPedida !== null && $codigo !== $bodegaPedida) {
                continue;
            }

            foreach ($datos['pedidos'] ?? [] as $pedido) {
                $porcentaje = (float) ($pedido['porcentaje_entrega'] ?? 0);

                if ($maximo !== null && $porcentaje >= $maximo) {
                    continue;
                }
                if ($minimo !== null && $porcentaje < $minimo) {
                    continue;
                }
                if ($proveedor !== null && ! $this->coincide($pedido, $proveedor)) {
                    continue;
                }

                $filas[] = [
                    'Bodega' => $codigo,
                    'Nro pedido' => $pedido['nro_pedido'] ?? '',
                    'Proveedor' => $pedido['proveedor'] ?? '',
                    'RUC' => $pedido['ruc_proveedor'] ?? '',
                    'Fecha registro' => $this->soloFecha($pedido['fecha_registro_bc'] ?? null),
                    'Recepción esperada' => $this->soloFecha($pedido['fecha_recepcion_esperada'] ?? null),
                    'Estado BC' => $pedido['estado_pedido_bc'] ?? '',
                    '% entrega' => round($porcentaje, 1),
                    'Ítems' => count($pedido['lineas'] ?? []),
                ];
            }
        }

        // Con los completos primero: si alguien pide "los que ya están
        // entregados", el orden ascendente los dejaba al final de la lista.
        usort($filas, fn ($a, $b) => $estado === 'completos'
            ? $b['% entrega'] <=> $a['% entrega']
            : $a['% entrega'] <=> $b['% entrega']);

        return $this->resultado(
            titulo: $this->tituloPedidos($bodegaPedida, $maximo, $minimo, $estado),
            filas: $filas,
            // El texto le recuerda al modelo CON QUÉ filtró: cuando devolvía
            // solo "no hay ninguno", lo repetía como un hecho absoluto ("no
            // hay pedidos completos en esa bodega") en vez de revisar su
            // propio filtro.
            vacioTexto: 'Ningún pedido cumple ese filtro exacto. Antes de decirle al usuario que '
                .'no hay ninguno, revisa si el filtro que usaste era el correcto y, si hace falta, '
                .'vuelve a consultar sin filtro de porcentaje.'
        );
    }

    /**
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function consultarCalificaciones(array $entrada, Usuario $usuario, int $idEmpresaActiva): array
    {
        $proveedores = Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->orderBy('Razon_Social')
            ->get();

        $filas = [];

        foreach ($proveedores as $proveedor) {
            $calificacion = $this->calificacionGlobalService->calcular($proveedor);

            $porComponente = [];
            foreach ($calificacion['componentes'] as $componente) {
                $porComponente[$componente['etiqueta']] = $componente['puntaje'].' de '.$componente['peso'];
            }

            $filas[] = [
                'Proveedor' => $proveedor->Razon_Social ?? 'Sin razón social',
                'RUC' => $proveedor->Ruc ?? '',
                'Nota global' => $calificacion['puntaje_total'] ?? 'sin datos',
                'Evaluado sobre' => $calificacion['peso_evaluado'].' de 100 pts',
                ...$porComponente,
                'No evaluado' => implode(', ', array_column($calificacion['componentes_sin_datos'], 'etiqueta')) ?: '—',
            ];
        }

        $orden = ($entrada['orden'] ?? 'mejor') === 'peor' ? 1 : -1;

        usort($filas, function ($a, $b) use ($orden) {
            $notaA = is_numeric($a['Nota global']) ? (float) $a['Nota global'] : -1;
            $notaB = is_numeric($b['Nota global']) ? (float) $b['Nota global'] : -1;

            return $orden * ($notaA <=> $notaB);
        });

        if (isset($entrada['limite']) && (int) $entrada['limite'] > 0) {
            $filas = array_slice($filas, 0, (int) $entrada['limite']);
        }

        return $this->resultado(
            titulo: 'Calificación de proveedores'.(($entrada['orden'] ?? 'mejor') === 'peor' ? ' (de menor a mayor)' : ' (de mayor a menor)'),
            filas: $filas,
            vacioTexto: 'No hay proveedores activos en esta empresa.'
        );
    }

    /**
     * Empaqueta las filas. El TEXTO es lo que ve el modelo; las FILAS viajan
     * aparte hasta el frontend para el botón de descarga.
     *
     * Al modelo se le manda un recorte, no las 60 filas completas: con más de
     * unas pocas decenas el prompt se infla y el costo se va, y de todos modos
     * el usuario va a descargar el archivo para verlas. Se le dice cuántas hay
     * en total para que no invente ni prometa de menos.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function resultado(string $titulo, array $filas, string $vacioTexto): array
    {
        if ($filas === []) {
            return $this->vacio($vacioTexto);
        }

        $total = count($filas);
        $filas = array_slice($filas, 0, self::MAX_FILAS);

        $descargables = count($filas);
        $muestra = array_slice($filas, 0, self::FILAS_DE_MUESTRA);

        $texto = "Se encontraron {$total} resultado(s). "
            .($total > $descargables
                ? "El archivo va a contener los primeros {$descargables}; dile al usuario ese número, no el total. "
                : "El archivo va a contener los {$descargables}. ")
            ."El usuario los descarga en Excel con el botón que aparece bajo tu respuesta, "
            ."así que NO los listes todos: resume el hallazgo en una o dos frases (cuántos son, qué "
            ."se destaca) y menciona la descarga. Abajo van solo ".count($muestra)." filas de ejemplo "
            ."para que sepas qué columnas hay; NO son todas.\n\n"
            ."Ejemplo de los datos:\n"
            .json_encode($muestra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return ['texto' => $texto, 'filas' => $filas, 'titulo' => $titulo];
    }

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function vacio(string $texto): array
    {
        return ['texto' => $texto, 'filas' => [], 'titulo' => ''];
    }

    /**
     * "CD-002", "cd002" o "CD 2" -> "CD-0002". El usuario escribe el código
     * como se lo acuerda, y rechazarlo por el formato sería inútil.
     */
    protected function normalizarBodega(?string $bodega): ?string
    {
        if ($bodega === null || trim($bodega) === '') {
            return null;
        }

        if (preg_match('/(\d+)/', $bodega, $coincidencias)) {
            return 'CD-'.str_pad($coincidencias[1], 4, '0', STR_PAD_LEFT);
        }

        return mb_strtoupper(trim($bodega));
    }

    protected function coincide(array $pedido, string $termino): bool
    {
        foreach (['proveedor', 'ruc_proveedor', 'nro_pedido'] as $campo) {
            if (str_contains(mb_strtolower((string) ($pedido[$campo] ?? '')), $termino)) {
                return true;
            }
        }

        return false;
    }

    protected function soloFecha(mixed $valor): string
    {
        if (empty($valor)) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse((string) $valor)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $valor;
        }
    }

    protected function tituloPedidos(?string $bodega, ?float $maximo, ?float $minimo, ?string $estado = null): string
    {
        $partes = ['Pedidos'];

        if ($estado === 'completos') {
            $partes = ['Pedidos completos'];
            $maximo = null;
            $minimo = null;
        } elseif ($estado === 'incompletos') {
            $partes = ['Pedidos incompletos'];
            $maximo = null;
            $minimo = null;
        }

        if ($bodega !== null) {
            $partes[] = "bodega {$bodega}";
        }
        if ($maximo !== null) {
            $partes[] = 'entrega menor al '.rtrim(rtrim(number_format($maximo, 1, '.', ''), '0'), '.').'%';
        }
        if ($minimo !== null) {
            $partes[] = 'entrega desde el '.rtrim(rtrim(number_format($minimo, 1, '.', ''), '0'), '.').'%';
        }

        return implode(' · ', $partes);
    }

    /**
     * El modelo puede mandar el estado en singular, en plural o con otras
     * palabras ("completo", "entregados", "pendientes"). Se acepta lo que se
     * entienda y cualquier otra cosa cae en null = sin filtro.
     */
    protected function normalizarEstadoEntrega(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = mb_strtolower(trim($valor));

        return match (true) {
            in_array($limpio, ['completos', 'completo', 'entregados', 'entregado', 'cerrados', '100', '100%'], true) => 'completos',
            in_array($limpio, ['incompletos', 'incompleto', 'pendientes', 'pendiente', 'parciales', 'parcial', 'abiertos'], true) => 'incompletos',
            default => null,
        };
    }

    protected function rolEn(Usuario $usuario, int $idEmpresaActiva): string
    {
        return $usuario->empresas()
            ->where('Empresa.Id_Empresa', $idEmpresaActiva)
            ->first()?->pivot->rol->Nombre_Rol ?? 'desconocido';
    }
}
