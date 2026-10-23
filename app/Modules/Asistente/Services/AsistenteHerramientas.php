<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Pedidos\Services\PedidoInternoService;
use App\Modules\Pedidos\Services\PedidoService;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Las herramientas que Hana puede usar para CONSULTAR datos, en vez de
 * responder solo con lo que le llegó en el contexto.
 *
 * POR QUÉ HACEN FALTA: el contexto que viaja en cada mensaje trae resúmenes
 * ("30 pedidos abiertos, 34 cerrados"), no filas. Cuando alguien pide "un
 * Excel de los proveedores pendientes de activar la cuenta", ningún resumen
 * alcanza, y Hana respondía "no tengo acceso a ese dato" y mandaba al
 * usuario a buscarlo a mano. Con herramientas puede ir a buscarlo.
 *
 * SOLO LECTURA. Ninguna herramienta escribe, ni siquiera de rebote: por eso
 * los productos se leen directo de la tabla y no con ProductoService::listar,
 * que en la página 1 sincroniza con Business Central y escribe.
 *
 * PERMISOS: DOS REGLAS QUE NO SE NEGOCIAN
 *
 *  1. QUIÉN PUEDE USAR CADA HERRAMIENTA está en PERMISOS_INTERNOS, copiado
 *     de quién ve la pantalla equivalente (el RoleRoute del front + el
 *     verificarAcceso del service que la sirve; la referencia va al lado de
 *     cada línea). Se verifica DOS veces: al ofrecerle las herramientas al
 *     modelo (definiciones) y otra vez al ejecutarlas (ejecutar), porque el
 *     modelo puede pedir por nombre una herramienta que no se le ofreció.
 *     Si cambia un permiso en una de esas pantallas, se cambia acá también.
 *
 *  2. EL ALCANCE NUNCA LO ELIGE EL MODELO. La empresa es siempre la activa
 *     y, para un proveedor, el Id_Proveedor sale de SU usuario
 *     (miProveedorId). Ninguna herramienta recibe un id de empresa ni de
 *     proveedor como parámetro: lo único que el modelo elige son filtros
 *     DENTRO de lo que el usuario ya puede ver. Un proveedor que le pida a
 *     Hana "los documentos de otro proveedor" no tiene con qué pedirlo.
 *
 * Pedidos internos y calificaciones siguen llamando a sus services (hacen
 * cuentas que no tiene sentido duplicar). El resto consulta las tablas
 * directo: los services de esas pantallas devuelven Resources paginados o
 * tienen efectos secundarios, y lo que el bot necesita es otra forma del
 * mismo dato (todas las filas, para el Excel).
 */
class AsistenteHerramientas
{
    /**
     * Tope de filas que van al archivo descargable.
     *
     * Es alto a propósito: estas filas NO se le mandan al modelo (de eso se
     * encarga la muestra de resultado()), viajan directo al frontend para
     * armar el Excel. O sea que subir este número NO cuesta tokens, solo
     * peso de respuesta. 1000 cubre cualquier consulta real del portal y evita
     * que un filtro amplio devuelva una respuesta HTTP enorme.
     */
    public const MAX_FILAS = 1000;

    /** Cuántas filas se leen como máximo de la base, para poder decir el total real. */
    protected const LIMITE_CONSULTA = 5000;

    /**
     * Umbral de "entrega completa". Los porcentajes vienen calculados por
     * cantidad con tope por línea (ver PedidoCompraResource), así que un
     * pedido completo da 100 exacto y no 99.9997: igual se compara con >= por
     * si en algún pedido raro la suma de decimales no cierra justo.
     */
    protected const PORCENTAJE_COMPLETO = 100.0;

    /**
     * Cuántas filas se le muestran al MODELO como ejemplo. Esto sí cuesta
     * tokens. Con 20 alcanza para que conteste preguntas sobre los datos
     * ("¿cuál es el más antiguo?") sin mandarle la tabla entera.
     */
    protected const FILAS_DE_MUESTRA = 20;

    /**
     * Herramienta -> roles internos que la pueden usar en la empresa activa.
     * El Guardia no aparece en ninguna: solo marca arribos.
     */
    protected const PERMISOS_INTERNOS = [
        // /proveedores — ProveedorService::verificarPuedeVer
        'consultar_proveedores' => ['Sistemas', 'Admin', 'Calidad', 'Compras'],
        // /usuarios/proveedores — UsuarioService::verificarAccesoPanelExternos
        'consultar_cuentas_proveedores' => ['Sistemas', 'Admin'],
        // /reportes/caducidad-documentos (ReporteCaducidadService::verificarAcceso)
        // y calificación de documentos (CalificacionProveedorService::verificarPuedeCalificar)
        'consultar_documentos_proveedores' => ['Sistemas', 'Admin', 'Calidad'],
        // /catalogo-productos (Sistemas, Admin, Compras) + /productos-revision (+ Calidad)
        'consultar_productos' => ['Sistemas', 'Admin', 'Compras', 'Calidad'],
        // /cambios-precio — SolicitudCambioPrecioService
        'consultar_cambios_precio' => ['Sistemas', 'Admin', 'Calidad'],
        // /pedidos interno — PedidoInternoService::obtenerBodegasPermitidas (Calidad recibe 403)
        'consultar_pedidos' => ['Sistemas', 'Admin', 'Compras'],
        // CalificacionGlobalController::lote
        'consultar_calificaciones_proveedores' => ['Sistemas', 'Admin', 'Calidad', 'Compras'],
        // /reclamos — ReclamoService::verificarEsInterno (todo interno menos Guardia)
        'consultar_reclamos' => ['Sistemas', 'Admin', 'Calidad', 'Compras'],
        // /auditorias y /auditorias/recepciones — AuditoriaService / CalificacionRecepcionService::verificarAcceso
        'consultar_auditorias' => ['Sistemas', 'Admin', 'Calidad'],
        // /calendario — HorarioEntregaService::verificarAccesoLectura
        'consultar_horarios_entrega' => ['Sistemas', 'Admin', 'Compras', 'Calidad'],
    ];

    /** Herramientas de un usuario proveedor: todas sobre SU propio proveedor y nada más. */
    protected const HERRAMIENTAS_PROVEEDOR = [
        'consultar_mis_documentos',
        'consultar_mis_productos',
        'consultar_mis_pedidos',
        'consultar_mis_reclamos',
        'consultar_mis_horarios',
    ];

    protected const DIAS_SEMANA = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];

    /** @var array<string, string> Rol por "usuario-empresa", para no consultarlo en cada vuelta del bucle. */
    protected array $roles = [];

    public function __construct(
        protected PedidoInternoService $pedidoInternoService,
        protected PedidoService $pedidoService,
        protected CalificacionGlobalService $calificacionGlobalService,
        protected DocumentoProveedorService $documentoService,
    ) {
    }

    /**
     * Definiciones para la API. Vacío si el usuario no debe tener ninguna.
     *
     * El orden es siempre el mismo para un mismo rol: las herramientas van
     * al principio del prompt, y cualquier cambio de orden invalidaría la
     * caché de todo lo que viene detrás.
     *
     * @return list<array<string, mixed>>
     */
    public function definiciones(Usuario $usuario, int $idEmpresaActiva): array
    {
        $permitidas = array_flip($this->permitidas($usuario, $idEmpresaActiva));

        return array_values(array_intersect_key($this->catalogo(), $permitidas));
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
        if (! in_array($nombre, $this->permitidas($usuario, $idEmpresaActiva), true)) {
            Log::warning('Asistente: el modelo pidió una herramienta que este usuario no tiene', [
                'herramienta' => $nombre,
                'id_usuario' => $usuario->Id_Usuario,
            ]);

            return $this->vacio(
                "La consulta '{$nombre}' no está disponible para este usuario. Dile con naturalidad que esa "
                .'información no está dentro de lo que su perfil puede ver, sin dar ningún dato de ella.'
            );
        }

        try {
            return match ($nombre) {
                'consultar_proveedores' => $this->consultarProveedores($entrada, $idEmpresaActiva),
                'consultar_cuentas_proveedores' => $this->consultarCuentasProveedores($entrada, $idEmpresaActiva),
                'consultar_documentos_proveedores' => $this->consultarDocumentos($entrada, $idEmpresaActiva),
                'consultar_productos' => $this->consultarProductos($entrada, $idEmpresaActiva),
                'consultar_cambios_precio' => $this->consultarCambiosPrecio($entrada, $idEmpresaActiva),
                'consultar_pedidos' => $this->consultarPedidos($entrada, $usuario, $idEmpresaActiva),
                'consultar_calificaciones_proveedores' => $this->consultarCalificaciones($entrada, $idEmpresaActiva),
                'consultar_reclamos' => $this->consultarReclamos($entrada, $idEmpresaActiva),
                'consultar_auditorias' => $this->consultarAuditorias($entrada, $idEmpresaActiva),
                'consultar_horarios_entrega' => $this->consultarHorarios($entrada, $idEmpresaActiva),
                'consultar_mis_documentos' => $this->comoProveedor($usuario, $idEmpresaActiva, fn (int $id) => $this->consultarDocumentos($entrada, $idEmpresaActiva, $id)),
                'consultar_mis_productos' => $this->comoProveedor($usuario, $idEmpresaActiva, fn (int $id) => $this->consultarProductos($entrada, $idEmpresaActiva, $id)),
                'consultar_mis_pedidos' => $this->comoProveedor($usuario, $idEmpresaActiva, fn () => $this->consultarMisPedidos($entrada, $usuario, $idEmpresaActiva)),
                'consultar_mis_reclamos' => $this->comoProveedor($usuario, $idEmpresaActiva, fn (int $id) => $this->consultarReclamos($entrada, $idEmpresaActiva, $id)),
                'consultar_mis_horarios' => $this->comoProveedor($usuario, $idEmpresaActiva, fn (int $id) => $this->consultarHorarios($entrada, $idEmpresaActiva, $id)),
            };
        } catch (\Throwable $e) {
            Log::error('Asistente: falló una herramienta', [
                'herramienta' => $nombre,
                'error' => $e->getMessage(),
            ]);

            return $this->vacio(
                'No se pudo completar la consulta por un error interno. Dile al usuario que lo intente de nuevo '
                .'en un momento o que lo revise en la sección correspondiente del portal.'
            );
        }
    }

    /** @return list<string> */
    protected function permitidas(Usuario $usuario, int $idEmpresaActiva): array
    {
        if ($usuario->Tipo_Usuario === 'Proveedor') {
            return self::HERRAMIENTAS_PROVEEDOR;
        }

        if ($usuario->Tipo_Usuario !== 'Interno') {
            return [];
        }

        $rol = $this->rolEn($usuario, $idEmpresaActiva);

        return array_keys(array_filter(
            self::PERMISOS_INTERNOS,
            fn (array $roles) => in_array($rol, $roles, true)
        ));
    }

    // ------------------------------------------------------------ DEFINICIONES

    /** @return array<string, array<string, mixed>> */
    protected function catalogo(): array
    {
        $buscarProveedor = [
            'type' => 'string',
            'description' => 'Parte de la razón social, del nombre comercial o del RUC del proveedor.',
        ];
        $desde = ['type' => 'string', 'description' => 'Fecha inicial AAAA-MM-DD (inclusive).'];
        $hasta = ['type' => 'string', 'description' => 'Fecha final AAAA-MM-DD (inclusive).'];

        $definiciones = [
            'consultar_proveedores' => [
                'description' =>
                    'Lista los proveedores de la empresa activa con su estado (Aspirante, Aprobado, Rechazado, '
                    .'Suspendido), datos de contacto, avance de la ficha, situación de la documentación y '
                    .'productos aprobados. Úsala para contar, filtrar o armar un listado/Excel de proveedores. '
                    .'En lenguaje común "activos" o "habilitados" suele querer decir estado Aprobado (los que ya '
                    .'operan): si la pregunta es ambigua, usa Aprobado, dilo en la respuesta y menciona también '
                    .'el total por estado. Si preguntan si un proveedor activó su CUENTA de acceso al portal, eso '
                    .'es consultar_cuentas_proveedores, no esta.',
                'properties' => [
                    'estado' => [
                        'type' => 'string',
                        'enum' => ['Aspirante', 'Aprobado', 'Rechazado', 'Suspendido', 'todos'],
                        'description' => 'Estado del proveedor en el portal. Por defecto todos.',
                    ],
                    'buscar' => $buscarProveedor,
                    'ciudad' => ['type' => 'string', 'description' => 'Ciudad del proveedor (coincidencia parcial).'],
                    'ficha' => [
                        'type' => 'string',
                        'enum' => ['completa', 'incompleta', 'todas'],
                        'description' => 'completa = ficha al 100%.',
                    ],
                    'documentacion' => [
                        'type' => 'string',
                        'enum' => ['registrada', 'sin_registrar', 'todas'],
                        'description' => 'registrada = ya presionó "Registrar documentación" y su carpeta pasó a revisión.',
                    ],
                    'con_correcciones_pendientes' => [
                        'type' => 'boolean',
                        'description' => 'true = solo los que tienen correcciones de documentos o productos sin confirmar.',
                    ],
                    'aprobados_desde' => ['type' => 'string', 'description' => 'Solo aprobados desde esta fecha AAAA-MM-DD.'],
                    'aprobados_hasta' => ['type' => 'string', 'description' => 'Solo aprobados hasta esta fecha AAAA-MM-DD.'],
                ],
            ],
            'consultar_cuentas_proveedores' => [
                'description' =>
                    'Lista las CUENTAS DE USUARIO de los proveedores (el acceso al portal) de la empresa activa y '
                    .'si están activadas. Úsala para "pendientes de activar", "no activaron la cuenta", "no han '
                    .'ingresado", "cuentas bloqueadas", o para saber el estado del correo con el código de '
                    .'activación. Una cuenta pendiente de activar todavía no tiene ficha de proveedor, así que '
                    .'muchas veces solo se conoce su correo.',
                'properties' => [
                    'estado_cuenta' => [
                        'type' => 'string',
                        'enum' => ['pendiente_activacion', 'activa', 'bloqueada', 'desactivada', 'todas'],
                        'description' =>
                            'pendiente_activacion = creada pero nunca activada. activa = ya ingresó. '
                            .'bloqueada = bloqueada por intentos fallidos. desactivada = dada de baja. Por defecto todas.',
                    ],
                    'buscar' => [
                        'type' => 'string',
                        'description' => 'Parte del correo, del nombre de la persona o de la razón social/RUC.',
                    ],
                    'creadas_desde' => ['type' => 'string', 'description' => 'Solo cuentas creadas desde esta fecha AAAA-MM-DD.'],
                    'creadas_hasta' => ['type' => 'string', 'description' => 'Solo cuentas creadas hasta esta fecha AAAA-MM-DD.'],
                ],
            ],
            'consultar_documentos_proveedores' => [
                'description' =>
                    'Documentos de la carpeta de los proveedores de la empresa activa: calificación (por '
                    .'calificar, aprobado, rechazado), fecha de caducidad y vencimientos, y también los '
                    .'documentos obligatorios que un proveedor TODAVÍA NO SUBIÓ (estado_calificacion=faltante). '
                    .'Úsala para "documentos vencidos", "por vencer este mes", "pendientes de revisar", '
                    .'"rechazados", "a quién le falta el RUC", etc.',
                'properties' => [
                    'estado_calificacion' => [
                        'type' => 'string',
                        'enum' => ['por_calificar', 'aprobado', 'rechazado', 'faltante', 'todos'],
                        'description' =>
                            'por_calificar = subido y enviado a revisión, sin calificar. faltante = obligatorio que '
                            .'el proveedor no subió. Por defecto todos los subidos.',
                    ],
                    'vencimiento' => [
                        'type' => 'string',
                        'enum' => ['vencidos', 'por_vencer', 'todos'],
                        'description' => 'vencidos = caducidad anterior a hoy. por_vencer = vence entre hoy y los próximos "dias".',
                    ],
                    'dias' => ['type' => 'integer', 'description' => 'Ventana para por_vencer, en días. Por defecto 30.'],
                    'documento' => ['type' => 'string', 'description' => 'Parte del nombre del tipo de documento (por ejemplo "RUC", "ARCSA").'],
                    'buscar' => $buscarProveedor,
                    'estado_proveedor' => [
                        'type' => 'string',
                        'enum' => ['Aspirante', 'Aprobado', 'Rechazado', 'Suspendido', 'todos'],
                    ],
                ],
            ],
            'consultar_productos' => [
                'description' =>
                    'Productos que registraron los proveedores de la empresa activa, con su estado de '
                    .'calificación, en qué área está la revisión (Compras o Calidad), precio y códigos. Úsala '
                    .'para catálogos, productos en revisión, rechazados, aprobados o por proveedor.',
                'properties' => [
                    'estado' => [
                        'type' => 'string',
                        'enum' => ['aprobado', 'rechazado', 'en_revision', 'borrador', 'todos'],
                        'description' => 'en_revision = registrado y bloqueado esperando calificación. borrador = cargado pero aún no registrado.',
                    ],
                    'etapa' => [
                        'type' => 'string',
                        'enum' => ['Compras', 'Calidad'],
                        'description' => 'Qué área tiene la revisión en este momento.',
                    ],
                    'producto' => ['type' => 'string', 'description' => 'Parte del nombre, código de barras o código BC del producto.'],
                    'buscar' => $buscarProveedor,
                    'precio_en_revision' => ['type' => 'boolean', 'description' => 'true = solo productos con un cambio de precio en revisión.'],
                ],
            ],
            'consultar_cambios_precio' => [
                'description' => 'Solicitudes de cambio de precio de productos, con precio anterior, nuevo y su resolución.',
                'properties' => [
                    'estado' => ['type' => 'string', 'enum' => ['Pendiente', 'Aprobado', 'Rechazado', 'todos']],
                    'producto' => ['type' => 'string', 'description' => 'Parte del nombre o código del producto.'],
                    'buscar' => $buscarProveedor,
                    'desde' => ['type' => 'string', 'description' => 'Solicitadas desde esta fecha AAAA-MM-DD.'],
                    'hasta' => ['type' => 'string', 'description' => 'Solicitadas hasta esta fecha AAAA-MM-DD.'],
                ],
            ],
            'consultar_pedidos' => [
                'description' =>
                    'Consulta los pedidos de compra por bodega de la empresa activa, con sus '
                    .'porcentajes de entrega. Úsala cuando el usuario pida un listado, un filtro o '
                    .'un reporte de pedidos (por bodega, por cumplimiento, por proveedor). '
                    .'Devuelve solo las bodegas que este usuario tiene permitidas. '
                    .'Para "completos", "al 100%", "ya entregados" usa estado_entrega=completos; '
                    .'para "pendientes" o "incompletos", estado_entrega=incompletos. '
                    .'NO uses porcentaje_maximo=100 para pedir los completos: ese filtro trae los '
                    .'que están POR DEBAJO de 100 y te dejaría justo fuera los que buscas.',
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
            'consultar_calificaciones_proveedores' => [
                'description' =>
                    'Consulta la calificación global de desempeño de los proveedores de la empresa '
                    .'activa, con el desglose por componente. Úsala cuando el usuario pida un '
                    .'ranking, una comparación o un reporte de calificaciones (por ejemplo "los 10 '
                    .'mejor calificados").',
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
            'consultar_reclamos' => [
                'description' => 'Reclamos hechos a los proveedores de la empresa activa, con tipo, impacto, estado y fechas.',
                'properties' => [
                    'estado' => ['type' => 'string', 'enum' => ['Abierto', 'Cerrado', 'todos']],
                    'tipo' => ['type' => 'string', 'enum' => ['Calidad', 'Salubridad', 'Inocuidad']],
                    'impacto' => ['type' => 'string', 'enum' => ['Alto', 'Medio', 'Bajo']],
                    'asunto' => ['type' => 'string', 'description' => 'Parte del asunto del reclamo.'],
                    'buscar' => $buscarProveedor,
                    'desde' => ['type' => 'string', 'description' => 'Creados desde esta fecha AAAA-MM-DD.'],
                    'hasta' => ['type' => 'string', 'description' => 'Creados hasta esta fecha AAAA-MM-DD.'],
                ],
            ],
            'consultar_auditorias' => [
                'description' =>
                    'Auditorías de la empresa activa. tipo=proveedor son las auditorías de proveedor (por tipo de '
                    .'auditoría, con % de cumplimiento); tipo=recepcion son las calificaciones de recepción de '
                    .'mercadería (formulario FGH04.15.05-1).',
                'properties' => [
                    'tipo' => ['type' => 'string', 'enum' => ['proveedor', 'recepcion'], 'description' => 'Por defecto proveedor.'],
                    'estado' => ['type' => 'string', 'enum' => ['Borrador', 'Finalizada', 'todos']],
                    'anio' => ['type' => 'integer', 'description' => 'Año de la auditoría o de la recepción.'],
                    'buscar' => $buscarProveedor,
                ],
            ],
            'consultar_horarios_entrega' => [
                'description' =>
                    'Calendario de horarios de entrega de los proveedores de la empresa activa: qué día, a qué '
                    .'hora y en qué andén entrega cada uno.',
                'properties' => [
                    'dia' => [
                        'type' => 'string',
                        'description' => 'Día de la semana (Lunes a Domingo) o "hoy".',
                    ],
                    'clasificacion' => ['type' => 'string', 'enum' => ['Perecibles', 'No_Perecibles', 'Fruver']],
                    'buscar' => $buscarProveedor,
                ],
            ],
            'consultar_mis_documentos' => [
                'description' =>
                    'Los documentos de la carpeta de ESTE proveedor: calificación, motivo de rechazo, caducidad y '
                    .'los obligatorios que todavía le faltan subir (estado_calificacion=faltante).',
                'properties' => [
                    'estado_calificacion' => [
                        'type' => 'string',
                        'enum' => ['por_calificar', 'aprobado', 'rechazado', 'faltante', 'todos'],
                    ],
                    'vencimiento' => ['type' => 'string', 'enum' => ['vencidos', 'por_vencer', 'todos']],
                    'dias' => ['type' => 'integer', 'description' => 'Ventana para por_vencer, en días. Por defecto 30.'],
                    'documento' => ['type' => 'string', 'description' => 'Parte del nombre del tipo de documento.'],
                ],
            ],
            'consultar_mis_productos' => [
                'description' => 'Los productos de ESTE proveedor con su estado de calificación, motivo de rechazo y precio.',
                'properties' => [
                    'estado' => ['type' => 'string', 'enum' => ['aprobado', 'rechazado', 'en_revision', 'borrador', 'todos']],
                    'producto' => ['type' => 'string', 'description' => 'Parte del nombre o código del producto.'],
                ],
            ],
            'consultar_mis_pedidos' => [
                'description' =>
                    'Los pedidos de compra que la empresa le hizo a ESTE proveedor, con fecha de recepción y % '
                    .'entregado. Vigentes = todavía por entregar; históricos = fecha pasada o entregados completos.',
                'properties' => [
                    'vista' => ['type' => 'string', 'enum' => ['vigentes', 'historicos', 'todos']],
                    'estado_entrega' => ['type' => 'string', 'enum' => ['completos', 'incompletos', 'todos']],
                ],
            ],
            'consultar_mis_reclamos' => [
                'description' => 'Los reclamos que la empresa le hizo a ESTE proveedor.',
                'properties' => [
                    'estado' => ['type' => 'string', 'enum' => ['Abierto', 'Cerrado', 'todos']],
                    'desde' => $desde,
                    'hasta' => $hasta,
                ],
            ],
            'consultar_mis_horarios' => [
                'description' => 'Los horarios de entrega asignados a ESTE proveedor (día, hora de llegada y salida, andén).',
                'properties' => [
                    'dia' => ['type' => 'string', 'description' => 'Día de la semana (Lunes a Domingo) o "hoy".'],
                ],
            ],
        ];

        $catalogo = [];

        foreach ($definiciones as $nombre => $definicion) {
            $catalogo[$nombre] = [
                'name' => $nombre,
                'description' => $definicion['description'],
                'inputSchema' => ['type' => 'object', 'properties' => $definicion['properties']],
            ];
        }

        return $catalogo;
    }

    // ------------------------------------------------------------- PROVEEDORES

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function consultarProveedores(array $entrada, int $idEmpresa): array
    {
        $consulta = DB::table('Proveedor as p')
            ->join('Estado_Proveedor as ep', 'ep.Id_Estado_Proveedor', '=', 'p.Id_Estado_Proveedor')
            ->where('p.Id_Empresa', $idEmpresa)
            ->where('p.Activo', 1)
            ->select([
                'p.Razon_Social', 'p.Nombre_Comercial', 'p.Ruc', 'ep.Nombre_Estado', 'p.Ciudad', 'p.Email',
                'p.Telefono', 'p.Porcentaje_Completado_Ficha', 'p.Fecha_Registro_Documentacion',
                'p.Correcciones_Pendientes', 'p.Correcciones_Pendientes_Productos', 'p.Fecha_Postulacion',
                'p.Fecha_Aprobacion', 'p.Nro_Proveedor_BC',
            ])
            ->selectRaw('(SELECT COUNT(*) FROM Documento_Proveedor d WHERE d.Id_Proveedor = p.Id_Proveedor AND d.Activo = 1 AND d.Estado_Calificacion IS NULL) AS Docs_Por_Calificar')
            ->selectRaw("(SELECT COUNT(*) FROM Documento_Proveedor d WHERE d.Id_Proveedor = p.Id_Proveedor AND d.Activo = 1 AND d.Estado_Calificacion = 'Rechazado') AS Docs_Rechazados")
            ->selectRaw("(SELECT COUNT(*) FROM Producto pr WHERE pr.Id_Proveedor = p.Id_Proveedor AND pr.Activo = 1 AND pr.Estado_Calificacion = 'Aprobado') AS Productos_Aprobados")
            ->orderBy('p.Razon_Social');

        $this->filtrarEstadoProveedor($consulta, $entrada['estado'] ?? null);
        $this->filtrarProveedor($consulta, $entrada['buscar'] ?? null);

        if ($ciudad = $this->texto($entrada['ciudad'] ?? null)) {
            $consulta->where('p.Ciudad', 'like', "%{$ciudad}%");
        }

        match ($this->opcion($entrada['ficha'] ?? null, ['completa', 'incompleta'])) {
            'completa' => $consulta->where('p.Porcentaje_Completado_Ficha', '>=', 100),
            'incompleta' => $consulta->where(fn ($q) => $q->where('p.Porcentaje_Completado_Ficha', '<', 100)->orWhereNull('p.Porcentaje_Completado_Ficha')),
            default => null,
        };

        match ($this->opcion($entrada['documentacion'] ?? null, ['registrada', 'sin_registrar'])) {
            'registrada' => $consulta->whereNotNull('p.Fecha_Registro_Documentacion'),
            'sin_registrar' => $consulta->whereNull('p.Fecha_Registro_Documentacion'),
            default => null,
        };

        if (($entrada['con_correcciones_pendientes'] ?? null) === true) {
            $consulta->where(fn ($q) => $q->where('p.Correcciones_Pendientes', 1)->orWhere('p.Correcciones_Pendientes_Productos', 1));
        }

        $this->filtrarFechas($consulta, 'p.Fecha_Aprobacion', $entrada['aprobados_desde'] ?? null, $entrada['aprobados_hasta'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()->map(fn ($p) => [
            'Razón social' => $p->Razon_Social,
            'Nombre comercial' => $p->Nombre_Comercial ?? '',
            'RUC' => $p->Ruc ?? '',
            'Estado' => $p->Nombre_Estado,
            'Ciudad' => $p->Ciudad ?? '',
            'Correo' => $p->Email ?? '',
            'Teléfono' => $p->Telefono ?? '',
            '% ficha' => (int) $p->Porcentaje_Completado_Ficha,
            'Documentación registrada' => $p->Fecha_Registro_Documentacion ? $this->soloFecha($p->Fecha_Registro_Documentacion) : 'No',
            'Docs por calificar' => (int) $p->Docs_Por_Calificar,
            'Docs rechazados' => (int) $p->Docs_Rechazados,
            'Productos aprobados' => (int) $p->Productos_Aprobados,
            'Correcciones pendientes' => ($p->Correcciones_Pendientes || $p->Correcciones_Pendientes_Productos) ? 'Sí' : 'No',
            'Fecha postulación' => $this->soloFecha($p->Fecha_Postulacion),
            'Fecha aprobación' => $this->soloFecha($p->Fecha_Aprobacion),
            'Nro proveedor BC' => $p->Nro_Proveedor_BC ?? '',
        ])->all();

        return $this->resultado(
            titulo: 'Proveedores'.$this->sufijo($entrada['estado'] ?? null),
            filas: $filas,
            vacioTexto: 'Ningún proveedor cumple ese filtro. Antes de afirmar que no hay ninguno, revisa si el '
                .'filtro era el correcto y prueba uno más amplio.',
            resumen: $this->conteoPor($filas, 'Estado'),
        );
    }

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function consultarCuentasProveedores(array $entrada, int $idEmpresa): array
    {
        // Mismo universo que la pantalla Cuentas de Proveedores
        // (UsuarioService::listarExternos): usuarios proveedor con acceso a
        // la empresa activa. La ficha se busca solo en ESTA empresa.
        $proveedorDeLaEmpresa = DB::table('Usuario_Proveedor as up')
            ->join('Proveedor as pr', 'pr.Id_Proveedor', '=', 'up.Id_Proveedor')
            ->where('pr.Id_Empresa', $idEmpresa)
            ->select('up.Id_Usuario', 'pr.Razon_Social', 'pr.Ruc');

        $consulta = DB::table('Usuario as u')
            ->join('Usuario_Empresa as ue', function ($join) use ($idEmpresa) {
                $join->on('ue.Id_Usuario', '=', 'u.Id_Usuario')->where('ue.Id_Empresa', '=', $idEmpresa);
            })
            ->leftJoinSub($proveedorDeLaEmpresa, 'pv', 'pv.Id_Usuario', '=', 'u.Id_Usuario')
            ->where('u.Tipo_Usuario', 'Proveedor')
            ->select([
                'u.Id_Usuario', 'u.Email', 'u.Nombre_Completo', 'u.Cargo', 'u.Telefono', 'u.Activo',
                'u.Bloqueado_Por_Intentos', 'u.Requiere_Cambio_Password', 'u.Ultimo_Acceso', 'u.Fecha_Creacion',
                'pv.Razon_Social', 'pv.Ruc',
            ])
            // El último código de bienvenida: crearCodigo invalida los
            // anteriores, así que el más nuevo es el único que importa.
            ->selectRaw("(SELECT TOP 1 ca.Fecha_Expiracion FROM Codigo_Activacion ca WHERE ca.Email = u.Email AND ca.Tipo = 'Bienvenida' ORDER BY ca.Fecha_Creacion DESC) AS Codigo_Vence")
            ->selectRaw("(SELECT TOP 1 ca.Estado_Envio FROM Codigo_Activacion ca WHERE ca.Email = u.Email AND ca.Tipo = 'Bienvenida' ORDER BY ca.Fecha_Creacion DESC) AS Estado_Envio")
            ->orderByDesc('u.Fecha_Creacion');

        $noBloqueado = fn ($q) => $q->whereRaw('ISNULL(u.Bloqueado_Por_Intentos, 0) = 0');

        // Pendiente = la regla de UsuarioService::motivoParaNoReenviar. Hace
        // falta Ultimo_Acceso NULL porque un reset de contraseña vuelve a
        // poner Requiere_Cambio_Password en 1 en una cuenta que ya se usó.
        match ($this->opcion($entrada['estado_cuenta'] ?? null, ['pendiente_activacion', 'activa', 'bloqueada', 'desactivada'])) {
            'pendiente_activacion' => $consulta->where('u.Activo', 1)->where($noBloqueado)
                ->where('u.Requiere_Cambio_Password', 1)->whereNull('u.Ultimo_Acceso'),
            'activa' => $consulta->where('u.Activo', 1)->where($noBloqueado)
                ->where(fn ($q) => $q->where('u.Requiere_Cambio_Password', 0)->orWhereNotNull('u.Ultimo_Acceso')),
            'bloqueada' => $consulta->where('u.Activo', 1)->where('u.Bloqueado_Por_Intentos', 1),
            'desactivada' => $consulta->where('u.Activo', 0),
            default => null,
        };

        if ($buscar = $this->texto($entrada['buscar'] ?? null)) {
            $consulta->where(fn ($q) => $q->where('u.Email', 'like', "%{$buscar}%")
                ->orWhere('u.Nombre_Completo', 'like', "%{$buscar}%")
                ->orWhere('pv.Razon_Social', 'like', "%{$buscar}%")
                ->orWhere('pv.Ruc', 'like', "%{$buscar}%"));
        }

        $this->filtrarFechas($consulta, 'u.Fecha_Creacion', $entrada['creadas_desde'] ?? null, $entrada['creadas_hasta'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()
            ->unique('Id_Usuario')
            ->map(function ($u) {
                $estado = $this->estadoCuenta($u);

                return [
                    'Correo' => $u->Email,
                    // Nombre_Completo arranca igual al correo hasta que la
                    // cuenta se activa: repetirlo no aporta nada.
                    'Nombre' => $u->Nombre_Completo !== $u->Email ? ($u->Nombre_Completo ?? '') : '',
                    'Cargo' => $u->Cargo ?? '',
                    'Teléfono' => $u->Telefono ?? '',
                    'Proveedor' => $u->Razon_Social ?? '',
                    'RUC' => $u->Ruc ?? '',
                    'Estado de la cuenta' => $estado,
                    'Creada el' => $this->soloFecha($u->Fecha_Creacion),
                    'Último acceso' => $this->soloFecha($u->Ultimo_Acceso),
                    'Código de activación' => $estado === 'Pendiente de activar' ? $this->estadoCodigo($u->Codigo_Vence) : '',
                    'Envío del correo' => $estado === 'Pendiente de activar' ? ($u->Estado_Envio ?? 'Sin registro') : '',
                ];
            })->values()->all();

        return $this->resultado(
            titulo: 'Cuentas de proveedores'.$this->sufijo($entrada['estado_cuenta'] ?? null),
            filas: $filas,
            vacioTexto: 'Ninguna cuenta de proveedor cumple ese filtro en la empresa activa.',
            resumen: $this->conteoPor($filas, 'Estado de la cuenta')
                .' Los códigos de activación se pueden reenviar desde Cuentas de Proveedores (/usuarios/proveedores).',
        );
    }

    // -------------------------------------------------------------- DOCUMENTOS

    /**
     * Con $idProveedor = null son los de toda la empresa (vista interna); con
     * un id, los de ese proveedor y nada más (vista del propio proveedor).
     *
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function consultarDocumentos(array $entrada, int $idEmpresa, ?int $idProveedor = null): array
    {
        $estado = $this->opcion($entrada['estado_calificacion'] ?? null, ['por_calificar', 'aprobado', 'rechazado', 'faltante']);

        if ($estado === 'faltante') {
            return $this->documentosFaltantes($entrada, $idEmpresa, $idProveedor);
        }

        $hoy = now()->startOfDay();

        $consulta = DB::table('Documento_Proveedor as d')
            ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'd.Id_Proveedor')
            ->join('Estado_Proveedor as ep', 'ep.Id_Estado_Proveedor', '=', 'p.Id_Estado_Proveedor')
            ->join('Tipo_Documento as t', 't.Id_Tipo_Documento', '=', 'd.Id_Tipo_Documento')
            ->where('p.Id_Empresa', $idEmpresa)
            ->where('p.Activo', 1)
            ->where('d.Activo', 1)
            ->select([
                'p.Razon_Social', 'p.Ruc', 'ep.Nombre_Estado', 'p.Fecha_Registro_Documentacion', 't.Nombre_Documento',
                't.Categoria', 'd.Estado_Calificacion', 'd.Comentario_Calificacion', 'd.Fecha_Caducidad',
                'd.Fecha_Calificacion', 'd.Fecha_Creacion',
            ])
            ->orderBy('p.Razon_Social')
            ->orderBy('t.Nombre_Documento');

        if ($idProveedor !== null) {
            $consulta->where('p.Id_Proveedor', $idProveedor);
        }

        match ($estado) {
            // Igual que el panel de Sistemas: "por calificar" es lo que ya se
            // envió a revisión. Lo subido sin registrar todavía no le toca a nadie.
            'por_calificar' => $consulta->whereNull('d.Estado_Calificacion')->whereNotNull('p.Fecha_Registro_Documentacion'),
            'aprobado' => $consulta->where('d.Estado_Calificacion', 'Aprobado'),
            'rechazado' => $consulta->where('d.Estado_Calificacion', 'Rechazado'),
            default => null,
        };

        $dias = $this->entero($entrada['dias'] ?? null, 30, 1, 365);

        match ($this->opcion($entrada['vencimiento'] ?? null, ['vencidos', 'por_vencer'])) {
            'vencidos' => $consulta->whereRaw('d.Fecha_Caducidad < CAST(? AS date)', [$hoy->toDateString()]),
            'por_vencer' => $consulta
                ->whereRaw('d.Fecha_Caducidad >= CAST(? AS date)', [$hoy->toDateString()])
                ->whereRaw('d.Fecha_Caducidad <= CAST(? AS date)', [$hoy->copy()->addDays($dias)->toDateString()]),
            default => null,
        };

        if ($documento = $this->texto($entrada['documento'] ?? null)) {
            $consulta->where('t.Nombre_Documento', 'like', "%{$documento}%");
        }

        $this->filtrarProveedor($consulta, $entrada['buscar'] ?? null);
        $this->filtrarEstadoProveedor($consulta, $entrada['estado_proveedor'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()->map(function ($d) use ($hoy) {
            $caducidad = $d->Fecha_Caducidad ? Carbon::parse($d->Fecha_Caducidad)->startOfDay() : null;
            $diasParaVencer = $caducidad ? (int) $hoy->diffInDays($caducidad, false) : null;

            return [
                'Proveedor' => $d->Razon_Social,
                'RUC' => $d->Ruc ?? '',
                'Estado proveedor' => $d->Nombre_Estado,
                'Documento' => $d->Nombre_Documento,
                'Categoría' => $d->Categoria ?? '',
                'Calificación' => $d->Estado_Calificacion
                    ?? ($d->Fecha_Registro_Documentacion ? 'Por calificar' : 'Sin enviar a revisión'),
                'Comentario' => $d->Comentario_Calificacion ?? '',
                'Caduca' => $caducidad?->format('d/m/Y') ?? 'Sin caducidad',
                'Días para vencer' => $diasParaVencer ?? '',
                'Situación' => match (true) {
                    $diasParaVencer === null => 'Sin caducidad',
                    $diasParaVencer < 0 => 'Vencido',
                    $diasParaVencer <= 30 => 'Por vencer',
                    default => 'Vigente',
                },
                'Subido el' => $this->soloFecha($d->Fecha_Creacion),
                'Calificado el' => $this->soloFecha($d->Fecha_Calificacion),
            ];
        })->all();

        return $this->resultado(
            titulo: 'Documentos'.$this->sufijo($estado).$this->sufijo($entrada['vencimiento'] ?? null),
            filas: $this->sinColumnasDeProveedor($filas, $idProveedor),
            vacioTexto: 'Ningún documento cumple ese filtro. Antes de afirmar que no hay, revisa si el filtro era el correcto.',
            resumen: $this->conteoPor($filas, 'Calificación').' '.$this->conteoPor($filas, 'Situación'),
        );
    }

    /**
     * Los obligatorios que el proveedor todavía no subió. No hay fila que
     * leer (el documento no existe), así que se arma con el mismo checklist
     * que ve el proveedor: tiposObligatoriosAplicables depende de su ciudad y
     * de sus clases.
     *
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function documentosFaltantes(array $entrada, int $idEmpresa, ?int $idProveedor): array
    {
        $consulta = Proveedor::where('Id_Empresa', $idEmpresa)
            ->where('Activo', 1)
            ->with('estado')
            ->orderBy('Razon_Social');

        if ($idProveedor !== null) {
            $consulta->where('Id_Proveedor', $idProveedor);
        }

        if ($buscar = $this->texto($entrada['buscar'] ?? null)) {
            $consulta->where(fn ($q) => $q->where('Razon_Social', 'like', "%{$buscar}%")
                ->orWhere('Nombre_Comercial', 'like', "%{$buscar}%")
                ->orWhere('Ruc', 'like', "%{$buscar}%"));
        }

        if ($estado = $this->estadoProveedor($entrada['estado_proveedor'] ?? null)) {
            $consulta->whereHas('estado', fn ($q) => $q->where('Nombre_Estado', $estado));
        }

        $proveedores = $consulta->get();
        $documento = $this->texto($entrada['documento'] ?? null);

        // Lo subido de todos los proveedores en UNA consulta, no una por proveedor.
        $subidos = DocumentoProveedor::whereIn('Id_Proveedor', $proveedores->pluck('Id_Proveedor'))
            ->where('Activo', 1)
            ->get(['Id_Proveedor', 'Id_Tipo_Documento'])
            ->groupBy('Id_Proveedor')
            ->map(fn ($documentos) => $documentos->pluck('Id_Tipo_Documento')->all());

        $filas = [];

        foreach ($proveedores as $proveedor) {
            $tiene = $subidos->get($proveedor->Id_Proveedor, []);

            foreach ($this->documentoService->tiposObligatoriosAplicables($proveedor) as $tipo) {
                if (in_array($tipo->Id_Tipo_Documento, $tiene)) {
                    continue;
                }
                if ($documento !== null && ! str_contains(mb_strtolower($tipo->Nombre_Documento), mb_strtolower($documento))) {
                    continue;
                }

                $filas[] = [
                    'Proveedor' => $proveedor->Razon_Social,
                    'RUC' => $proveedor->Ruc ?? '',
                    'Estado proveedor' => $proveedor->estado?->Nombre_Estado ?? '',
                    'Ciudad' => $proveedor->Ciudad ?? '',
                    'Documento faltante' => $tipo->Nombre_Documento,
                    'Categoría' => $tipo->Categoria ?? '',
                ];
            }
        }

        return $this->resultado(
            titulo: 'Documentos obligatorios faltantes',
            filas: $this->sinColumnasDeProveedor($filas, $idProveedor),
            vacioTexto: $idProveedor !== null
                ? 'No le falta subir ningún documento obligatorio.'
                : 'Ningún proveedor de ese filtro tiene documentos obligatorios sin subir.',
            resumen: $idProveedor === null ? 'Proveedores con faltantes: '.count(array_unique(array_column($filas, 'RUC'))).'.' : null,
        );
    }

    // --------------------------------------------------------------- PRODUCTOS

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function consultarProductos(array $entrada, int $idEmpresa, ?int $idProveedor = null): array
    {
        $consulta = DB::table('Producto as pr')
            ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'pr.Id_Proveedor')
            ->where('p.Id_Empresa', $idEmpresa)
            ->where('p.Activo', 1)
            ->where('pr.Activo', 1)
            // El proveedor no ve los productos que el personal le ocultó (23-oct-2026).
            ->when($idProveedor !== null, fn ($q) => $q->where('pr.Oculto_Proveedor', 0))
            ->select([
                'p.Razon_Social', 'p.Ruc', 'pr.Nombre_Producto', 'pr.Codigo_Barras', 'pr.Bc_Nro_Producto', 'pr.Precio',
                'pr.Estado_Calificacion', 'pr.Bloqueado', 'pr.Etapa_Aprobacion', 'pr.Comentario_Calificacion',
                'pr.Precio_En_Revision', 'pr.Fecha_Creacion', 'pr.Fecha_Calificacion',
            ])
            ->orderBy('p.Razon_Social')
            ->orderBy('pr.Nombre_Producto');

        if ($idProveedor !== null) {
            $consulta->where('p.Id_Proveedor', $idProveedor);
        }

        // Los mismos cuatro estados que muestra la pantalla (ProductoService::listar).
        match ($this->opcion($entrada['estado'] ?? null, ['aprobado', 'rechazado', 'en_revision', 'borrador'])) {
            'aprobado' => $consulta->where('pr.Estado_Calificacion', 'Aprobado'),
            'rechazado' => $consulta->where('pr.Estado_Calificacion', 'Rechazado'),
            'en_revision' => $consulta->where('pr.Estado_Calificacion', 'Pendiente')->where('pr.Bloqueado', 1),
            'borrador' => $consulta
                ->whereRaw('ISNULL(pr.Bloqueado, 0) = 0')
                ->where(fn ($q) => $q->whereNull('pr.Estado_Calificacion')->orWhere('pr.Estado_Calificacion', 'Pendiente')),
            default => null,
        };

        if ($etapa = $this->opcion($entrada['etapa'] ?? null, ['compras' => 'Compras', 'calidad' => 'Calidad'])) {
            $consulta->where('pr.Etapa_Aprobacion', $etapa);
        }

        if ($producto = $this->texto($entrada['producto'] ?? null)) {
            $consulta->where(fn ($q) => $q->where('pr.Nombre_Producto', 'like', "%{$producto}%")
                ->orWhere('pr.Codigo_Barras', 'like', "%{$producto}%")
                ->orWhere('pr.Bc_Nro_Producto', 'like', "%{$producto}%"));
        }

        if (($entrada['precio_en_revision'] ?? null) === true) {
            $consulta->where('pr.Precio_En_Revision', 1);
        }

        $this->filtrarProveedor($consulta, $entrada['buscar'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()->map(fn ($pr) => [
            'Proveedor' => $pr->Razon_Social,
            'RUC' => $pr->Ruc ?? '',
            'Producto' => $pr->Nombre_Producto,
            'Código de barras' => $pr->Codigo_Barras ?? '',
            'Código BC' => $pr->Bc_Nro_Producto ?? '',
            'Precio' => $pr->Precio !== null ? round((float) $pr->Precio, 2) : '',
            'Estado' => match (true) {
                $pr->Estado_Calificacion === 'Aprobado' => 'Aprobado',
                $pr->Estado_Calificacion === 'Rechazado' => 'Rechazado',
                (bool) $pr->Bloqueado => 'En revisión',
                default => 'Borrador',
            },
            'En revisión por' => $pr->Estado_Calificacion === 'Pendiente' ? ($pr->Etapa_Aprobacion ?? '') : '',
            'Comentario' => $pr->Comentario_Calificacion ?? '',
            'Precio en revisión' => $pr->Precio_En_Revision ? 'Sí' : 'No',
            'Creado el' => $this->soloFecha($pr->Fecha_Creacion),
            'Calificado el' => $this->soloFecha($pr->Fecha_Calificacion),
        ])->all();

        return $this->resultado(
            titulo: 'Productos'.$this->sufijo($entrada['estado'] ?? null),
            filas: $this->sinColumnasDeProveedor($filas, $idProveedor),
            vacioTexto: 'Ningún producto cumple ese filtro. Antes de afirmar que no hay, revisa si el filtro era el correcto.',
            resumen: $this->conteoPor($filas, 'Estado'),
        );
    }

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function consultarCambiosPrecio(array $entrada, int $idEmpresa): array
    {
        $consulta = DB::table('Solicitud_Cambio_Precio as s')
            ->join('Producto as pr', 'pr.Id_Producto', '=', 's.Id_Producto')
            ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'pr.Id_Proveedor')
            ->where('p.Id_Empresa', $idEmpresa)
            ->select([
                'p.Razon_Social', 'p.Ruc', 'pr.Nombre_Producto', 'pr.Bc_Nro_Producto', 's.Precio_Anterior',
                's.Precio_Nuevo', 's.Estado', 's.Fecha_Solicitud', 's.Fecha_Resolucion', 's.Comentario_Resolucion',
            ])
            ->orderByDesc('s.Fecha_Solicitud');

        if ($estado = $this->opcion($entrada['estado'] ?? null, ['pendiente' => 'Pendiente', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado'])) {
            $consulta->where('s.Estado', $estado);
        }

        if ($producto = $this->texto($entrada['producto'] ?? null)) {
            $consulta->where(fn ($q) => $q->where('pr.Nombre_Producto', 'like', "%{$producto}%")
                ->orWhere('pr.Bc_Nro_Producto', 'like', "%{$producto}%"));
        }

        $this->filtrarProveedor($consulta, $entrada['buscar'] ?? null);
        $this->filtrarFechas($consulta, 's.Fecha_Solicitud', $entrada['desde'] ?? null, $entrada['hasta'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()->map(function ($s) {
            $anterior = (float) $s->Precio_Anterior;
            $nuevo = (float) $s->Precio_Nuevo;

            return [
                'Proveedor' => $s->Razon_Social,
                'RUC' => $s->Ruc ?? '',
                'Producto' => $s->Nombre_Producto,
                'Código BC' => $s->Bc_Nro_Producto ?? '',
                'Precio anterior' => round($anterior, 2),
                'Precio nuevo' => round($nuevo, 2),
                'Variación %' => $anterior > 0 ? round(($nuevo - $anterior) / $anterior * 100, 1) : '',
                'Estado' => $s->Estado,
                'Solicitado el' => $this->soloFecha($s->Fecha_Solicitud),
                'Resuelto el' => $this->soloFecha($s->Fecha_Resolucion),
                'Comentario' => $s->Comentario_Resolucion ?? '',
            ];
        })->all();

        return $this->resultado(
            titulo: 'Cambios de precio'.$this->sufijo($entrada['estado'] ?? null),
            filas: $filas,
            vacioTexto: 'No hay solicitudes de cambio de precio con ese filtro.',
            resumen: $this->conteoPor($filas, 'Estado'),
        );
    }

    // ----------------------------------------------------------------- PEDIDOS

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
     * Los pedidos del propio proveedor, con el MISMO service y la misma
     * separación vigentes/históricos que su pantalla de Pedidos.
     *
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function consultarMisPedidos(array $entrada, Usuario $usuario, int $idEmpresaActiva): array
    {
        $vista = $this->opcion($entrada['vista'] ?? null, ['vigentes', 'historicos']);
        $estado = $this->normalizarEstadoEntrega($entrada['estado_entrega'] ?? null);
        $filas = [];

        foreach ($vista !== null ? [$vista] : ['vigentes', 'historicos'] as $cual) {
            foreach ($this->pedidoService->listar($usuario, $idEmpresaActiva, $cual) as $pedido) {
                $porcentaje = $this->porcentajeEntrega($pedido);

                if ($estado === 'completos' && $porcentaje < self::PORCENTAJE_COMPLETO) {
                    continue;
                }
                if ($estado === 'incompletos' && $porcentaje >= self::PORCENTAJE_COMPLETO) {
                    continue;
                }

                $filas[] = [
                    'Nro pedido' => $pedido->Nro_Pedido,
                    'Vista' => $cual === 'vigentes' ? 'Vigente' : 'Histórico',
                    'Fecha registro' => $this->soloFecha($pedido->Fecha_Registro_BC),
                    'Recepción esperada' => $this->soloFecha($pedido->Fecha_Recepcion_Esperada),
                    'Estado' => $pedido->Estado ?? '',
                    '% entrega' => $porcentaje,
                    'Ítems' => $pedido->lineas->count(),
                ];
            }
        }

        return $this->resultado(
            titulo: 'Mis pedidos'.$this->sufijo($vista),
            filas: $filas,
            vacioTexto: 'No hay pedidos con ese filtro.',
            resumen: $this->conteoPor($filas, 'Vista'),
        );
    }

    /** Por cantidad con tope por línea, igual que PedidoCompraResource. */
    protected function porcentajeEntrega(PedidoCompra $pedido): float
    {
        $pedida = 0.0;
        $recibida = 0.0;

        foreach ($pedido->lineas as $linea) {
            $cantidad = (float) $linea->Cantidad;
            $pedida += $cantidad;
            $recibida += min((float) ($linea->Cantidad_Recibida ?? 0), $cantidad);
        }

        return $pedida > 0 ? round($recibida / $pedida * 100, 1) : 0.0;
    }

    /**
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function consultarCalificaciones(array $entrada, int $idEmpresaActiva): array
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

    // ---------------------------------------------------- RECLAMOS Y AUDITORÍAS

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function consultarReclamos(array $entrada, int $idEmpresa, ?int $idProveedor = null): array
    {
        $consulta = DB::table('Reclamo as r')
            ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'r.Id_Proveedor')
            ->where('r.Id_Empresa', $idEmpresa)
            ->where('r.Activo', 1)
            ->select([
                'r.Id_Reclamo', 'p.Razon_Social', 'p.Ruc', 'r.Asunto', 'r.Tipo_Reclamo', 'r.Impacto_Proveedor',
                'r.Estado', 'r.Fecha_Creacion', 'r.Fecha_Cierre',
            ])
            ->orderByDesc('r.Fecha_Creacion');

        if ($idProveedor !== null) {
            $consulta->where('r.Id_Proveedor', $idProveedor);
        }

        if ($estado = $this->opcion($entrada['estado'] ?? null, ['abierto' => 'Abierto', 'cerrado' => 'Cerrado'])) {
            $consulta->where('r.Estado', $estado);
        }
        if ($tipo = $this->opcion($entrada['tipo'] ?? null, ['calidad' => 'Calidad', 'salubridad' => 'Salubridad', 'inocuidad' => 'Inocuidad'])) {
            $consulta->where('r.Tipo_Reclamo', $tipo);
        }
        if ($impacto = $this->opcion($entrada['impacto'] ?? null, ['alto' => 'Alto', 'medio' => 'Medio', 'bajo' => 'Bajo'])) {
            $consulta->where('r.Impacto_Proveedor', $impacto);
        }
        if ($asunto = $this->texto($entrada['asunto'] ?? null)) {
            $consulta->where('r.Asunto', 'like', "%{$asunto}%");
        }

        $this->filtrarProveedor($consulta, $entrada['buscar'] ?? null);
        $this->filtrarFechas($consulta, 'r.Fecha_Creacion', $entrada['desde'] ?? null, $entrada['hasta'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()->map(function ($r) {
            $creado = $r->Fecha_Creacion ? Carbon::parse($r->Fecha_Creacion) : null;
            $fin = $r->Fecha_Cierre ? Carbon::parse($r->Fecha_Cierre) : now();

            return [
                'Nro reclamo' => $r->Id_Reclamo,
                'Proveedor' => $r->Razon_Social,
                'RUC' => $r->Ruc ?? '',
                'Asunto' => $r->Asunto,
                'Tipo' => $r->Tipo_Reclamo ?? '',
                'Impacto' => $r->Impacto_Proveedor ?? '',
                'Estado' => $r->Estado,
                'Creado el' => $this->soloFecha($r->Fecha_Creacion),
                'Cerrado el' => $this->soloFecha($r->Fecha_Cierre),
                'Días abierto' => $creado ? (int) $creado->diffInDays($fin) : '',
            ];
        })->all();

        return $this->resultado(
            titulo: 'Reclamos'.$this->sufijo($entrada['estado'] ?? null),
            filas: $this->sinColumnasDeProveedor($filas, $idProveedor),
            vacioTexto: 'No hay reclamos con ese filtro.',
            resumen: $this->conteoPor($filas, 'Estado').' '.$this->conteoPor($filas, 'Tipo'),
        );
    }

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function consultarAuditorias(array $entrada, int $idEmpresa): array
    {
        $esRecepcion = $this->opcion($entrada['tipo'] ?? null, ['proveedor', 'recepcion']) === 'recepcion';
        $estado = $this->opcion($entrada['estado'] ?? null, ['borrador' => 'Borrador', 'finalizada' => 'Finalizada']);
        $anio = $this->entero($entrada['anio'] ?? null, 0, 2000, 2100);

        if ($esRecepcion) {
            $consulta = DB::table('Calificacion_Recepcion as a')
                ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'a.Id_Proveedor')
                ->where('a.Id_Empresa', $idEmpresa)
                ->select(['p.Razon_Social', 'p.Ruc', 'a.Fecha_Recepcion as Fecha', 'a.Contacto', 'a.Estado', 'a.Puntaje_Obtenido', 'a.Puntaje_Total_Posible', 'a.Porcentaje_Obtenido as Porcentaje'])
                ->orderByDesc('a.Fecha_Recepcion');
            $columnaFecha = 'a.Fecha_Recepcion';
        } else {
            $consulta = DB::table('Auditoria as a')
                ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'a.Id_Proveedor')
                ->leftJoin('Tipo_Auditoria as t', 't.Id_Tipo_Auditoria', '=', 'a.Id_Tipo_Auditoria')
                ->where('a.Id_Empresa', $idEmpresa)
                ->select(['p.Razon_Social', 'p.Ruc', 't.Nombre as Tipo', 'a.Fecha_Auditoria as Fecha', 'a.Estado', 'a.Puntaje_Obtenido', 'a.Puntaje_Total_Posible', 'a.Porcentaje_Cumplimiento as Porcentaje'])
                ->orderByDesc('a.Fecha_Auditoria');
            $columnaFecha = 'a.Fecha_Auditoria';
        }

        if ($estado !== null) {
            $consulta->where('a.Estado', $estado);
        }
        if ($anio > 0) {
            $consulta->whereRaw("YEAR({$columnaFecha}) = ?", [$anio]);
        }

        $this->filtrarProveedor($consulta, $entrada['buscar'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()->map(fn ($a) => array_filter([
            'Proveedor' => $a->Razon_Social,
            'RUC' => $a->Ruc ?? '',
            'Tipo de auditoría' => $esRecepcion ? null : ($a->Tipo ?? ''),
            'Fecha' => $this->soloFecha($a->Fecha),
            'Contacto' => $esRecepcion ? ($a->Contacto ?? '') : null,
            'Estado' => $a->Estado,
            'Puntaje' => $a->Puntaje_Obtenido !== null ? round((float) $a->Puntaje_Obtenido, 1) : '',
            'Puntaje posible' => $a->Puntaje_Total_Posible !== null ? round((float) $a->Puntaje_Total_Posible, 1) : '',
            '%' => $a->Porcentaje !== null ? round((float) $a->Porcentaje, 1) : '',
        ], fn ($valor) => $valor !== null))->all();

        return $this->resultado(
            titulo: $esRecepcion ? 'Calificaciones de recepción' : 'Auditorías de proveedor',
            filas: $filas,
            vacioTexto: 'No hay auditorías con ese filtro.',
            resumen: $this->conteoPor($filas, 'Estado'),
        );
    }

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function consultarHorarios(array $entrada, int $idEmpresa, ?int $idProveedor = null): array
    {
        $consulta = DB::table('Horario_Entrega_Proveedor as h')
            ->join('Proveedor as p', 'p.Id_Proveedor', '=', 'h.Id_Proveedor')
            ->where('h.Id_Empresa', $idEmpresa)
            ->where('h.Activo', 1)
            ->select([
                'p.Razon_Social', 'p.Ruc', 'h.Dia_Entrega', 'h.Clasificacion', 'h.Anden_Puerta', 'h.Hora_Llegada',
                'h.Hora_Salida', 'h.Tiempo_Preparacion_Min', 'h.Tiempo_Permanencia_Min',
            ]);

        if ($idProveedor !== null) {
            $consulta->where('h.Id_Proveedor', $idProveedor);
        }

        if ($dia = $this->diaSemana($entrada['dia'] ?? null)) {
            $consulta->where('h.Dia_Entrega', $dia);
        }
        if ($clasificacion = $this->opcion($entrada['clasificacion'] ?? null, ['perecibles' => 'Perecibles', 'no_perecibles' => 'No_Perecibles', 'fruver' => 'Fruver'])) {
            $consulta->where('h.Clasificacion', $clasificacion);
        }

        $this->filtrarProveedor($consulta, $entrada['buscar'] ?? null);

        $filas = $consulta->limit(self::LIMITE_CONSULTA)->get()
            ->sortBy(fn ($h) => sprintf('%d %s', array_search($h->Dia_Entrega, self::DIAS_SEMANA, true), $h->Hora_Llegada))
            ->map(fn ($h) => [
                'Proveedor' => $h->Razon_Social,
                'RUC' => $h->Ruc ?? '',
                'Día' => strtr($h->Dia_Entrega, ['Miercoles' => 'Miércoles', 'Sabado' => 'Sábado']),
                'Clasificación' => str_replace('_', ' ', (string) $h->Clasificacion),
                'Andén / puerta' => $h->Anden_Puerta ?? '',
                'Llegada' => $h->Hora_Llegada ?? '',
                'Salida' => $h->Hora_Salida ?? '',
                'Preparación (min)' => $h->Tiempo_Preparacion_Min ?? '',
                'Permanencia (min)' => $h->Tiempo_Permanencia_Min ?? '',
            ])->values()->all();

        return $this->resultado(
            titulo: 'Horarios de entrega'.($dia ? " · {$dia}" : ''),
            filas: $this->sinColumnasDeProveedor($filas, $idProveedor),
            vacioTexto: 'No hay horarios de entrega con ese filtro.',
            resumen: $this->conteoPor($filas, 'Día'),
        );
    }

    // ---------------------------------------------------------------- APOYO

    /**
     * Resuelve el proveedor del usuario en la empresa activa y recién ahí
     * corre la consulta. El id sale del usuario, nunca de lo que mande el
     * modelo: es la misma resolución que usan todas las pantallas /mi-* .
     *
     * @param  callable(int): array{texto: string, filas: list<array<string, mixed>>, titulo: string}  $consulta
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function comoProveedor(Usuario $usuario, int $idEmpresaActiva, callable $consulta): array
    {
        $proveedor = $usuario->proveedores()->where('Proveedor.Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor) {
            return $this->vacio(
                'Este usuario todavía no tiene una Ficha de proveedor en la empresa activa, así que no hay datos '
                .'que consultar. Lo primero es completar Mi Ficha.'
            );
        }

        return $consulta((int) $proveedor->Id_Proveedor);
    }

    /**
     * Empaqueta las filas. El TEXTO es lo que ve el modelo; las FILAS viajan
     * aparte hasta el frontend para el botón de descarga.
     *
     * Al modelo se le manda un recorte, no todas las filas: con más de unas
     * pocas decenas el prompt se infla y el costo se va, y de todos modos el
     * usuario va a descargar el archivo para verlas. Se le dice cuántas hay en
     * total (y el resumen por estado, si lo hay) para que no invente ni
     * prometa de menos.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{texto: string, filas: list<array<string, mixed>>, titulo: string}
     */
    protected function resultado(string $titulo, array $filas, string $vacioTexto, ?string $resumen = null): array
    {
        if ($filas === []) {
            return $this->vacio($vacioTexto);
        }

        $total = count($filas);
        $filas = array_slice($filas, 0, self::MAX_FILAS);

        $descargables = count($filas);
        $muestra = array_slice($filas, 0, self::FILAS_DE_MUESTRA);

        $texto = "Se encontraron {$total} resultado(s)"
            .($total >= self::LIMITE_CONSULTA ? ' (es el máximo que se lee de una vez: puede haber más, sugiere afinar el filtro)' : '')
            .'. '
            .($resumen ? trim($resumen).' ' : '')
            .($total > $descargables
                ? "El archivo va a contener los primeros {$descargables}; dile al usuario ese número, no el total. "
                : "El archivo va a contener los {$descargables}. ")
            ."El usuario los descarga en Excel con el botón que aparece bajo tu respuesta, "
            ."así que NO los listes todos: resume el hallazgo (cuántos son, qué se destaca) y menciona la "
            ."descarga. Abajo van ".(count($muestra) < $total ? 'solo las primeras '.count($muestra).' filas' : 'todas las filas')
            ." para que veas los datos.\n\n"
            .json_encode($muestra, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return ['texto' => $texto, 'filas' => $filas, 'titulo' => $titulo];
    }

    /** @return array{texto: string, filas: list<array<string, mixed>>, titulo: string} */
    protected function vacio(string $texto): array
    {
        return ['texto' => $texto, 'filas' => [], 'titulo' => ''];
    }

    /**
     * En la vista del propio proveedor, las columnas Proveedor/RUC son
     * siempre él mismo: se sacan del Excel.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<array<string, mixed>>
     */
    protected function sinColumnasDeProveedor(array $filas, ?int $idProveedor): array
    {
        if ($idProveedor === null) {
            return $filas;
        }

        return array_map(
            fn (array $fila) => array_diff_key($fila, array_flip(['Proveedor', 'RUC', 'Estado proveedor', 'Ciudad'])),
            $filas
        );
    }

    /** "Por Estado: Aprobado 30, Aspirante 41." para que el modelo tenga los totales sin contar filas. */
    protected function conteoPor(array $filas, string $columna): string
    {
        $conteo = array_count_values(array_map(fn ($fila) => (string) ($fila[$columna] ?? ''), $filas));
        arsort($conteo);

        return "Por {$columna}: ".implode(', ', array_map(
            fn ($valor, $cantidad) => ($valor === '' ? 'sin dato' : $valor)." {$cantidad}",
            array_keys($conteo),
            $conteo
        )).'.';
    }

    protected function filtrarProveedor(Builder $consulta, mixed $buscar): void
    {
        if ($texto = $this->texto($buscar)) {
            $consulta->where(fn ($q) => $q->where('p.Razon_Social', 'like', "%{$texto}%")
                ->orWhere('p.Nombre_Comercial', 'like', "%{$texto}%")
                ->orWhere('p.Ruc', 'like', "%{$texto}%"));
        }
    }

    /** Requiere que la consulta tenga el join a Estado_Proveedor como "ep". */
    protected function filtrarEstadoProveedor(Builder $consulta, mixed $valor): void
    {
        if ($estado = $this->estadoProveedor($valor)) {
            $consulta->where('ep.Nombre_Estado', $estado);
        }
    }

    protected function estadoProveedor(mixed $valor): ?string
    {
        return $this->opcion($valor, [
            'aspirante' => 'Aspirante', 'aprobado' => 'Aprobado', 'rechazado' => 'Rechazado', 'suspendido' => 'Suspendido',
        ]);
    }

    /**
     * Fechas como texto ISO con hora: la columna es datetime y el driver
     * sqlsrv es ambiguo con DATEFORMAT dmy (ver App\Models\BaseModel).
     */
    protected function filtrarFechas(Builder $consulta, string $columna, mixed $desde, mixed $hasta): void
    {
        if ($inicio = $this->fecha($desde)) {
            $consulta->where($columna, '>=', $inicio->startOfDay()->format('Y-m-d\TH:i:s'));
        }
        if ($fin = $this->fecha($hasta)) {
            $consulta->where($columna, '<=', $fin->endOfDay()->format('Y-m-d\TH:i:s'));
        }
    }

    protected function estadoCuenta(object $usuario): string
    {
        return match (true) {
            ! $usuario->Activo => 'Desactivada',
            (bool) $usuario->Bloqueado_Por_Intentos => 'Bloqueada por intentos',
            $usuario->Requiere_Cambio_Password && $usuario->Ultimo_Acceso === null => 'Pendiente de activar',
            default => 'Activa',
        };
    }

    protected function estadoCodigo(mixed $vence): string
    {
        if (empty($vence)) {
            return 'Sin código';
        }

        $fecha = Carbon::parse((string) $vence);

        return $fecha->isPast()
            ? 'Vencido el '.$fecha->format('d/m/Y')
            : 'Vigente hasta '.$fecha->format('d/m/Y H:i');
    }

    /** Texto libre del modelo: recortado y sin vacíos. */
    protected function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $limpio = trim(mb_substr((string) $valor, 0, 100));

        return $limpio === '' ? null : $limpio;
    }

    /**
     * Normaliza una opción del modelo. $permitidas puede ser una lista
     * (['completa', 'incompleta']) o un mapa minúscula => valor real. Sin
     * tildes y en minúscula, para aceptar "Aprobado", "aprobados" o
     * "APROBADO". Cualquier otra cosa (incluido "todos") es null = sin filtro.
     */
    protected function opcion(mixed $valor, array $permitidas): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpio = str_replace(' ', '_', mb_strtolower(trim(strtr($valor, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']))));
        $mapa = array_is_list($permitidas) ? array_combine($permitidas, $permitidas) : $permitidas;

        return $mapa[$limpio] ?? $mapa[rtrim($limpio, 's')] ?? null;
    }

    protected function entero(mixed $valor, int $porDefecto, int $minimo, int $maximo): int
    {
        if (! is_numeric($valor)) {
            return $porDefecto;
        }

        return max($minimo, min($maximo, (int) $valor));
    }

    protected function fecha(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y'] as $formato) {
            try {
                $fecha = Carbon::createFromFormat($formato, trim($valor));
            } catch (\Throwable) {
                continue;
            }

            if ($fecha->format($formato) === trim($valor)) {
                return $fecha;
            }
        }

        return null;
    }

    /** "miércoles", "MIERCOLES" o "hoy" -> "Miercoles", como se guarda Dia_Entrega. */
    protected function diaSemana(mixed $valor): ?string
    {
        if (is_string($valor) && mb_strtolower(trim($valor)) === 'hoy') {
            return self::DIAS_SEMANA[now()->dayOfWeekIso - 1];
        }

        $dias = array_combine(array_map('mb_strtolower', self::DIAS_SEMANA), self::DIAS_SEMANA);

        return $this->opcion($valor, $dias);
    }

    /** " (aprobado)" para el título del Excel; vacío si no hubo filtro. */
    protected function sufijo(mixed $filtro): string
    {
        if (! is_string($filtro) || in_array(mb_strtolower($filtro), ['', 'todos', 'todas'], true)) {
            return '';
        }

        return ' · '.str_replace('_', ' ', mb_strtolower($filtro));
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
            return Carbon::parse($valor instanceof \DateTimeInterface ? $valor : (string) $valor)->format('d/m/Y');
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
        return $this->roles[$usuario->Id_Usuario.'-'.$idEmpresaActiva] ??= $usuario->empresas()
            ->where('Empresa.Id_Empresa', $idEmpresaActiva)
            ->first()?->pivot->rol->Nombre_Rol ?? 'desconocido';
    }
}
