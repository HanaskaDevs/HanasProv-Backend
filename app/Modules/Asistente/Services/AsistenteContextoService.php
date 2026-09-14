<?php

namespace App\Modules\Asistente\Services;

use App\Modules\Auditorias\Models\Auditoria;
use App\Modules\Auditorias\Models\CalificacionRecepcion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Documentos_Proveedor\Models\DocumentoProveedor;
use App\Modules\Documentos_Proveedor\Services\DocumentoProveedorService;
use App\Modules\Ficha_Productos\Models\Producto;
use App\Modules\Ficha_Productos\Services\ProductoService;
use App\Modules\Pedidos\Models\PedidoCompra;
use App\Modules\Pedidos\Services\PedidoInternoService;
use App\Modules\Pedidos\Services\PedidoService;
use App\Modules\Proveedores\Models\Proveedor;
use App\Modules\Proveedores\Services\CalificacionGlobalService;
use App\Modules\Reclamos\Models\Reclamo;
use App\Modules\Reclamos\Services\ReclamoService;

/**
 * El bloque de datos REALES que se le pasa a Hana en cada mensaje.
 *
 * Dos principios que explican la forma de este archivo:
 *
 *  1. EL DATO YA RESUELTO, NO LA TABLA. El modelo no consulta nada: recibe
 *     "te faltan 2 documentos: X e Y" en vez de un listado que tenga que
 *     interpretar. Por eso alcanza un modelo económico como Haiku.
 *
 *  2. EL ALCANCE ES PARTE DEL CONTEXTO. Lo que se manda define de qué puede
 *     hablar Hana. Un proveedor solo recibe sus propios datos, y un interno
 *     solo los de su empresa activa y de lo que su rol maneja -> el modelo
 *     no puede filtrar lo que nunca vio. La instrucción de "no hables de
 *     otros" del prompt es la segunda barrera, no la primera.
 *
 * Sobre el costo en tokens: el contexto se arma con conteos y con listas
 * cortadas (nunca "todos los productos"), porque esto viaja en CADA mensaje.
 * Lo que es igual para todos los usuarios vive en AsistenteGuiaPortal, que
 * va en un bloque aparte y cacheable.
 */
class AsistenteContextoService
{
    /** Cuántos ítems como máximo se nombran en una lista antes de resumir. */
    protected const MAX_ITEMS_LISTA = 6;

    public function __construct(
        protected ProductoService $productoService,
        protected PedidoService $pedidoService,
        protected ReclamoService $reclamoService,
        protected PedidoInternoService $pedidoInternoService,
        protected DocumentoProveedorService $documentoService,
        protected CalificacionGlobalService $calificacionGlobalService,
    ) {
    }

    public function generar(Usuario $usuario, int $idEmpresaActiva): string
    {
        $bloques = [
            $this->identidadYHora($usuario),
            $this->alcance($usuario, $idEmpresaActiva),
            $usuario->Tipo_Usuario === 'Proveedor'
                ? $this->contextoProveedor($usuario, $idEmpresaActiva)
                : $this->contextoInterno($usuario, $idEmpresaActiva),
            $this->contactos(),
            $this->navegacion($usuario, $idEmpresaActiva),
        ];

        return implode("\n\n", array_filter($bloques));
    }

    /**
     * El modelo no tiene reloj propio ni sabe con quién habla salvo que se
     * lo digamos acá. APP_TIMEZONE ya está en America/Guayaquil (ver
     * config/app.php), así que now() da la hora real de Ecuador.
     */
    protected function identidadYHora(Usuario $usuario): string
    {
        $ahora = now();
        $saludo = match (true) {
            $ahora->hour < 12 => 'Buenos días',
            $ahora->hour < 19 => 'Buenas tardes',
            default => 'Buenas noches',
        };

        $lineas = ["AHORA: {$ahora->translatedFormat('l d \\d\\e F \\d\\e Y')}, {$ahora->format('H:i')} (Ecuador)."];
        $lineas[] = "Si es tu primer mensaje de la conversación, saluda con \"{$saludo}\" (no calcules la hora, usa este dato).";

        $primerNombre = self::primerNombreDe($usuario);
        if ($primerNombre !== '') {
            $lineas[] = "Hablas con: {$primerNombre}. Salúdalo por su nombre la primera vez.";
        }

        return implode("\n", $lineas);
    }

    /**
     * "Nombre_Completo" arranca igual al Email hasta que el usuario activa
     * su cuenta -> sin esto el saludo saldría "Buenos días,
     * mlopez@hanaska.com".
     */
    public static function primerNombreDe(Usuario $usuario): string
    {
        $primerNombre = trim(explode(' ', trim((string) $usuario->Nombre_Completo))[0] ?? '');

        return str_contains($primerNombre, '@') ? '' : $primerNombre;
    }

    /**
     * De qué puede hablar este usuario y de qué no. Va explícito y arriba
     * porque es la regla que más importa que el modelo respete.
     *
     * Se nombran las OTRAS empresas a las que el usuario tiene acceso para
     * que Hana pueda decir "de esa empresa te puedo hablar, pero cambia el
     * selector arriba" en vez de negarse sin más. Las empresas a las que NO
     * tiene acceso no se nombran: si Hana no sabe que existen, no puede
     * confirmar ni negar nada sobre ellas.
     */
    protected function alcance(Usuario $usuario, int $idEmpresaActiva): string
    {
        $accesos = $usuario->empresas()->wherePivot('Activo', true)->get();
        $activa = $accesos->firstWhere('Id_Empresa', $idEmpresaActiva);
        $otras = $accesos->where('Id_Empresa', '!=', $idEmpresaActiva);

        $lineas = ['ALCANCE DE ESTA CONVERSACIÓN'];
        $lineas[] = 'Empresa activa: '.($activa?->Razon_Social ?? 'no identificada')
            .'. TODOS los datos de abajo son de esta empresa y solo de ella.';

        if ($otras->isNotEmpty()) {
            $lineas[] = 'Este usuario también tiene acceso a: '.$otras->pluck('Razon_Social')->implode(', ')
                .'. Si pregunta por una de esas, dile que cambie de empresa con el selector del encabezado; '
                .'no le des datos de esa empresa desde acá, porque no los tienes.';
        }

        $lineas[] = 'Si pregunta por cualquier otra empresa o por otro proveedor que no sea el de estos datos, '
            .'dile con naturalidad que eso no está a tu alcance. No confirmes ni niegues si existe.';

        return implode("\n", $lineas);
    }

    protected function contactos(): string
    {
        $contactos = config('portal.contactos');

        $admin = $contactos['administracion'];
        $sistemas = $contactos['sistemas'];

        return "A QUIÉN DERIVAR — SOLO SI HACE FALTA\n"
            ."NO cierres tus respuestas ofreciendo estos correos. Se dan ÚNICAMENTE cuando el "
            ."usuario tiene un problema que no se resuelve dentro del portal, y solo el que "
            ."corresponda:\n"
            ."- {$admin['etiqueta']} ({$admin['email']}): cuando pregunta por {$admin['para']} y "
            ."hace falta una decisión de alguien.\n"
            ."- {$sistemas['etiqueta']} (".implode(' o ', $sistemas['emails'])."): SOLO ante una falla "
            ."técnica del portal — no puede entrar, un archivo no sube, algo da error. Si el usuario "
            ."te pide un dato o un reporte, eso NO es una falla técnica: resuélvelo o dile dónde "
            ."verlo, sin mandarlo a Sistemas.";
    }

    // ---------------------------------------------------------------- PROVEEDOR

    protected function contextoProveedor(Usuario $usuario, int $idEmpresaActiva): string
    {
        $proveedor = $usuario->proveedores()->where('Id_Empresa', $idEmpresaActiva)->first();

        if (! $proveedor) {
            return "DATOS DEL PROVEEDOR\nEste usuario es proveedor pero todavía no tiene una Ficha en la empresa activa. "
                ."Lo primero que tiene que hacer es entrar a Mi Ficha y completar la sección 1.";
        }

        $bloques = [
            $this->proveedorEncabezado($proveedor),
            $this->proveedorDocumentos($proveedor),
            $this->proveedorProductos($usuario, $idEmpresaActiva),
            $this->proveedorPedidos($usuario, $idEmpresaActiva),
            $this->proveedorReclamos($usuario, $idEmpresaActiva),
            $this->proveedorCalificacionGlobal($proveedor),
        ];

        return implode("\n\n", array_filter($bloques));
    }

    protected function proveedorEncabezado(Proveedor $proveedor): string
    {
        $lineas = ['DATOS DEL PROVEEDOR (es la empresa del usuario con el que hablas)'];
        $lineas[] = "Razón social: {$proveedor->Razon_Social}".($proveedor->Ruc ? " (RUC {$proveedor->Ruc})" : '');
        $lineas[] = 'Estado en el portal: '.($proveedor->estado->Nombre_Estado ?? 'sin estado');
        $lineas[] = "Ficha completada: {$proveedor->Porcentaje_Completado_Ficha}%"
            .($proveedor->Porcentaje_Completado_Ficha < 100 ? ' (le falta completarla)' : '');
        $lineas[] = 'Ciudad: '.($proveedor->Ciudad ?: 'no registrada')
            .' — de esto depende qué documentos se le piden.';

        if ($proveedor->Correcciones_Pendientes) {
            $lineas[] = 'ATENCIÓN: tiene correcciones de documentación sin confirmar. Después de corregir tiene que '
                .'presionar "Registrar documentación actualizada", si no, nadie se entera de que ya corrigió.';
        }

        if ($proveedor->Correcciones_Pendientes_Productos) {
            $lineas[] = 'ATENCIÓN: tiene correcciones de productos sin confirmar. Tiene que presionar '
                .'"Confirmar corrección" en cada producto corregido.';
        }

        return implode("\n", $lineas);
    }

    /**
     * Estado real de la carpeta de documentos, nombrando lo que falta y lo
     * que está rechazado. Es la pregunta número uno de un proveedor, así que
     * es el único bloque donde vale la pena gastar tokens en nombres
     * completos en vez de conteos.
     */
    protected function proveedorDocumentos(Proveedor $proveedor): string
    {
        try {
            $obligatorios = $this->documentoService->tiposObligatoriosAplicables($proveedor);

            $subidos = DocumentoProveedor::where('Id_Proveedor', $proveedor->Id_Proveedor)
                ->where('Activo', 1)
                ->with('tipoDocumento')
                ->get();

            $porTipo = $subidos->keyBy('Id_Tipo_Documento');

            $faltantes = [];
            $rechazados = [];
            $vencidos = [];
            $sinCalificar = 0;
            $aprobados = 0;

            foreach ($obligatorios as $tipo) {
                $doc = $porTipo->get($tipo->Id_Tipo_Documento);

                if (! $doc) {
                    $faltantes[] = $tipo->Nombre_Documento;

                    continue;
                }

                if ($doc->Estado_Calificacion === 'Rechazado') {
                    $rechazados[] = $tipo->Nombre_Documento
                        .($doc->Comentario_Calificacion ? " (motivo: {$doc->Comentario_Calificacion})" : '');
                } elseif ($doc->Estado_Calificacion === 'Aprobado') {
                    $aprobados++;
                } else {
                    $sinCalificar++;
                }

                if ($doc->Fecha_Caducidad !== null && $doc->Fecha_Caducidad->isPast()) {
                    $vencidos[] = $tipo->Nombre_Documento.' (venció el '.$doc->Fecha_Caducidad->format('d/m/Y').')';
                } elseif ($doc->Fecha_Caducidad !== null && $doc->Fecha_Caducidad->lte(now()->addDays(30))) {
                    $vencidos[] = $tipo->Nombre_Documento.' (vence el '.$doc->Fecha_Caducidad->format('d/m/Y').')';
                }
            }

            $lineas = ['DOCUMENTACIÓN'];
            $lineas[] = 'Documentos obligatorios que le corresponden: '.$obligatorios->count()
                .". Aprobados: {$aprobados}. En revisión: {$sinCalificar}.";
            $lineas[] = $proveedor->Fecha_Registro_Documentacion !== null
                ? 'Ya presionó "Registrar documentación" el '.$proveedor->Fecha_Registro_Documentacion->format('d/m/Y').'.'
                : 'TODAVÍA NO presionó "Registrar documentación" — hasta que lo haga, nadie revisa su carpeta.';

            $lineas[] = $faltantes === []
                ? 'No le falta subir ningún documento obligatorio.'
                : 'LE FALTA SUBIR ('.count($faltantes).'): '.$this->lista($faltantes).'.';

            if ($rechazados !== []) {
                $lineas[] = 'RECHAZADOS, tiene que reemplazarlos ('.count($rechazados).'): '.$this->lista($rechazados).'.';
            }

            if ($vencidos !== []) {
                $lineas[] = 'VENCIDOS O POR VENCER: '.$this->lista($vencidos)
                    .'. Recuerda que vencido el plazo hay 15 días de gracia y después se suspende el acceso.';
            }

            return implode("\n", $lineas);
        } catch (\Throwable $e) {
            return 'DOCUMENTACIÓN: no se pudo leer el estado de los documentos en este momento.';
        }
    }

    protected function proveedorProductos(Usuario $usuario, int $idEmpresaActiva): string
    {
        try {
            $resumen = $this->productoService->resumenRegistro($usuario, $idEmpresaActiva);

            $lineas = ['PRODUCTOS'];
            $lineas[] = "Productos registrados: {$resumen['total_productos']}.";
            $lineas[] = $resumen['productos_en_revision'] > 0
                ? "En revisión: {$resumen['productos_en_revision']} (esos están bloqueados hasta que se califiquen; el resto se puede seguir editando)."
                : 'Ninguno en revisión: puede editar y agregar libremente.';

            $incompletos = $resumen['productos_incompletos'] ?? [];
            if ($incompletos !== []) {
                $lineas[] = 'Con documentos obligatorios faltantes ('.count($incompletos).'): '.$this->lista($incompletos).'.';
            }

            return implode("\n", $lineas);
        } catch (\Throwable $e) {
            return 'PRODUCTOS: no se pudo leer el catálogo en este momento.';
        }
    }

    protected function proveedorPedidos(Usuario $usuario, int $idEmpresaActiva): string
    {
        try {
            $vigentes = $this->pedidoService->listar($usuario, $idEmpresaActiva, 'vigentes');
            $historicos = $this->pedidoService->listar($usuario, $idEmpresaActiva, 'historicos');

            $lineas = ['PEDIDOS'];
            $lineas[] = "Vigentes: {$vigentes->count()}. Históricos: {$historicos->count()}.";

            // Los tres próximos a recibir, con su fecha: es lo que un
            // proveedor realmente pregunta ("¿qué tengo que entregar?").
            $proximos = $vigentes->take(3)->map(function (PedidoCompra $pedido) {
                $fecha = ($pedido->Fecha_Recepcion_Esperada ?? $pedido->Fecha_Registro_BC)?->format('d/m/Y') ?? 'sin fecha';

                return "{$pedido->Nro_Pedido} (recepción {$fecha})";
            })->all();

            if ($proximos !== []) {
                $lineas[] = 'Próximos a entregar: '.implode('; ', $proximos).'.';
            }

            return implode("\n", $lineas);
        } catch (\Throwable $e) {
            return 'PEDIDOS: no se pudo leer el estado de pedidos en este momento.';
        }
    }

    protected function proveedorReclamos(Usuario $usuario, int $idEmpresaActiva): string
    {
        try {
            $abiertos = $this->reclamoService->listarProveedor($usuario, $idEmpresaActiva, 'Abierto')->count();

            return "RECLAMOS\nAbiertos sobre este proveedor: {$abiertos}. "
                .'Recuerda: el proveedor NO puede crear reclamos, solo verlos y responderlos.';
        } catch (\Throwable $e) {
            return 'RECLAMOS: no se pudo leer el estado de reclamos en este momento.';
        }
    }

    protected function proveedorCalificacionGlobal(Proveedor $proveedor): string
    {
        try {
            $calificacion = $this->calificacionGlobalService->calcular($proveedor);

            $lineas = ['CALIFICACIÓN GLOBAL (nota de desempeño, sobre 100)'];
            $lineas[] = 'Nota actual: '.$calificacion['puntaje_total'].' / 100'
                .($calificacion['tiene_estimados'] ? ' (incluye secciones sin medir todavía)' : '');

            foreach ($calificacion['componentes'] as $componente) {
                $lineas[] = "- {$componente['etiqueta']}: {$componente['puntaje']} de {$componente['peso']} puntos"
                    .($componente['estimado'] ? ' [sin medir aún]' : '')
                    .". {$componente['detalle']}";
            }

            return implode("\n", $lineas);
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ------------------------------------------------------------------ INTERNO

    /**
     * Contexto del personal interno, armado SEGÚN EL ROL en la empresa
     * activa. No es solo cosmético: lo que no corresponde al rol
     * directamente no se consulta ni se manda, así que Hana no puede
     * revelarlo aunque el usuario insista.
     */
    protected function contextoInterno(Usuario $usuario, int $idEmpresaActiva): string
    {
        $rol = $this->rolEn($usuario, $idEmpresaActiva);

        $bloques = ["DATOS DEL USUARIO INTERNO\nRol en la empresa activa: {$rol}."];

        // El Guardia es un caso aparte: solo marca arribos, y darle
        // cualquier otro dato de la empresa sería pasarse de su alcance.
        if ($rol === 'Guardia') {
            $bloques[] = 'Este rol solo usa la pantalla de seguimiento del día para marcar que un proveedor llegó. '
                .'No tiene acceso a proveedores, pedidos, reclamos ni calificaciones: si pregunta por eso, '
                .'explícale amablemente que su perfil no lo incluye y que puede escribir a Administración.';

            return implode("\n\n", $bloques);
        }

        $puedeVerProveedores = in_array($rol, ['Sistemas', 'Admin', 'Calidad', 'Compras'], true);
        $puedeVerCalificaciones = in_array($rol, ['Sistemas', 'Admin', 'Calidad'], true);
        $puedeVerPedidos = in_array($rol, ['Sistemas', 'Admin', 'Compras', 'Calidad'], true);
        $puedeAdministrar = $rol === 'Sistemas';

        if ($puedeVerProveedores) {
            $bloques[] = $this->internoProveedores($idEmpresaActiva);
        }

        if ($puedeVerCalificaciones) {
            $bloques[] = $this->internoPendientesDeCalificar($idEmpresaActiva);
        }

        if ($puedeVerPedidos) {
            $bloques[] = $this->internoPedidos($usuario, $idEmpresaActiva);
        }

        $bloques[] = $this->internoReclamos($idEmpresaActiva);

        if ($puedeVerCalificaciones) {
            $bloques[] = $this->internoAuditorias($idEmpresaActiva);
        }

        if ($puedeAdministrar) {
            $bloques[] = 'ADMINISTRACIÓN: este usuario es Sistemas, así que también gestiona empresas, usuarios '
                .'internos, cuentas de proveedores, catálogos y configuraciones del portal.';
        } else {
            $bloques[] = "LÍMITE DE ROL: el rol {$rol} no administra empresas ni usuarios internos. "
                .'Si pregunta por eso, dile que lo pida a Sistemas.';
        }

        return implode("\n\n", array_filter($bloques));
    }

    protected function internoProveedores(int $idEmpresaActiva): string
    {
        $porEstado = Proveedor::where('Proveedor.Id_Empresa', $idEmpresaActiva)
            ->where('Proveedor.Activo', 1)
            ->join('Estado_Proveedor', 'Estado_Proveedor.Id_Estado_Proveedor', '=', 'Proveedor.Id_Estado_Proveedor')
            ->selectRaw('Estado_Proveedor.Nombre_Estado AS estado, COUNT(*) AS total')
            ->groupBy('Estado_Proveedor.Nombre_Estado')
            ->pluck('total', 'estado');

        $detalle = $porEstado->isEmpty()
            ? 'todavía no hay proveedores cargados'
            : $porEstado->map(fn ($total, $estado) => "{$estado}: {$total}")->implode(', ');

        return "PROVEEDORES DE LA EMPRESA ACTIVA\nTotal por estado -> {$detalle}.";
    }

    protected function internoPendientesDeCalificar(int $idEmpresaActiva): string
    {
        $documentos = DocumentoProveedor::where('Activo', 1)
            ->whereNull('Estado_Calificacion')
            ->whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva)->whereNotNull('Fecha_Registro_Documentacion'))
            ->count();

        $productos = Producto::where('Activo', 1)
            ->where('Bloqueado', 1)
            ->where('Estado_Calificacion', 'Pendiente')
            ->whereHas('proveedor', fn ($q) => $q->where('Id_Empresa', $idEmpresaActiva))
            ->count();

        $fichas = Proveedor::where('Id_Empresa', $idEmpresaActiva)
            ->where('Activo', 1)
            ->where('Porcentaje_Completado_Ficha', 100)
            ->whereNull('Estado_Calificacion_Ficha')
            ->count();

        return "PENDIENTE DE CALIFICAR\nFichas completas sin revisar: {$fichas}. "
            ."Documentos registrados sin calificar: {$documentos}. Productos en revisión: {$productos}.";
    }

    protected function internoPedidos(Usuario $usuario, int $idEmpresaActiva): string
    {
        $lineas = ['PEDIDOS DE LA EMPRESA ACTIVA'];

        $abiertos = PedidoCompra::where('Id_Empresa', $idEmpresaActiva)->where('Activo', 1)->where('Estado', 'Abierto')->count();
        $cerrados = PedidoCompra::where('Id_Empresa', $idEmpresaActiva)->where('Activo', 1)->where('Estado', 'Cerrado')->count();
        $lineas[] = "Abiertos: {$abiertos}. Cerrados: {$cerrados}.";

        try {
            $pendientesHoy = $this->pedidoInternoService->proveedoresPendientesHoy($usuario, $idEmpresaActiva);

            $lineas[] = $pendientesHoy === []
                ? 'Ningún proveedor con entrega programada hoy está en 0% de recepción.'
                : count($pendientesHoy).' proveedor(es) tienen entrega programada HOY y aún no entregaron nada: '
                    .$this->lista(collect($pendientesHoy)->pluck('proveedor')->unique()->all()).'.';
        } catch (\Throwable $e) {
            // El cálculo depende de las bodegas asignadas al usuario; si
            // falla, el resto del bloque sigue siendo útil.
        }

        return implode("\n", $lineas);
    }

    protected function internoReclamos(int $idEmpresaActiva): string
    {
        $abiertos = Reclamo::where('Id_Empresa', $idEmpresaActiva)->where('Activo', 1)->where('Estado', 'Abierto')->count();

        return "RECLAMOS\nAbiertos en la empresa activa: {$abiertos}.";
    }

    protected function internoAuditorias(int $idEmpresaActiva): string
    {
        $borradores = Auditoria::where('Id_Empresa', $idEmpresaActiva)->where('Estado', 'Borrador')->count();
        $finalizadas = Auditoria::where('Id_Empresa', $idEmpresaActiva)->where('Estado', 'Finalizada')->count();

        $recepcionesAnio = CalificacionRecepcion::where('Id_Empresa', $idEmpresaActiva)
            ->where('Estado', 'Finalizada')
            ->whereYear('Fecha_Recepcion', now()->year)
            ->count();

        return "AUDITORÍAS\nDe proveedor: {$borradores} en borrador, {$finalizadas} finalizadas. "
            ."Calificaciones de recepción finalizadas este año: {$recepcionesAnio}.";
    }

    // ---------------------------------------------------------------- NAVEGACIÓN

    /**
     * Le dice al modelo EXACTAMENTE qué rutas existen para este usuario y el
     * protocolo del marcador [IR_A:/ruta] que el frontend intercepta para
     * navegar automáticamente apenas el bot responde. Nunca debe inventar
     * una ruta que no esté en esta lista.
     */
    protected function navegacion(Usuario $usuario, int $idEmpresaActiva): string
    {
        $secciones = $this->seccionesDisponibles($usuario, $idEmpresaActiva);

        if ($secciones === []) {
            return 'NAVEGACIÓN: este usuario no tiene secciones navegables, no uses el marcador [IR_A:].';
        }

        $lista = collect($secciones)->map(fn ($label, $ruta) => "- {$ruta} : {$label}")->implode("\n");

        return "NAVEGACIÓN\n"
            ."Cuando el usuario pregunte cómo hacer algo o por el estado de algo que vive en una de estas secciones, "
            ."cierra tu respuesta con el marcador [IR_A:/ruta] una sola vez, al final y sin texto después: el portal "
            ."lo lleva ahí automáticamente. En el mismo mensaje dale igual el dato concreto que pidió. "
            ."No uses el marcador si solo está conversando. Usa EXCLUSIVAMENTE estas rutas:\n"
            .$lista;
    }

    protected function seccionesDisponibles(Usuario $usuario, int $idEmpresaActiva): array
    {
        if ($usuario->Tipo_Usuario === 'Proveedor') {
            return [
                '/mi-ficha' => 'Mi Ficha (datos generales del proveedor)',
                '/documentos' => 'Documentación (subir/reemplazar documentos y ver su calificación)',
                '/productos' => 'Ficha Productos (catálogo del proveedor)',
                '/calificacion' => 'Calificación (postulación y calificación global)',
                '/pedidos' => 'Pedidos (pedidos de compra y % de entrega)',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
                '/politicas' => 'Políticas',
            ];
        }

        return match ($this->rolEn($usuario, $idEmpresaActiva)) {
            'Sistemas' => [
                '/empresas' => 'Empresas',
                '/usuarios/internos' => 'Usuarios Internos',
                '/usuarios/proveedores' => 'Cuentas de Proveedores',
                '/proveedores' => 'Proveedores',
                '/catalogo-productos' => 'Catálogo de Productos',
                '/pedidos' => 'Pedidos (vista interna por bodega)',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
                '/auditorias' => 'Auditorías',
                '/calendario' => 'Calendario de horarios de entrega',
                '/catalogos' => 'Catálogos',
                '/configuraciones' => 'Configuraciones',
            ],
            'Admin' => [
                '/usuarios/proveedores' => 'Cuentas de Proveedores',
                '/proveedores' => 'Calificación de Proveedores',
                '/catalogo-productos' => 'Catálogo de Productos',
                '/pedidos' => 'Pedidos (vista interna por bodega)',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
                '/auditorias' => 'Auditorías',
                '/calendario' => 'Calendario de horarios de entrega',
            ],
            'Calidad' => [
                '/proveedores' => 'Calificación de Proveedores',
                '/auditorias' => 'Auditorías',
                '/pedidos' => 'Pedidos (vista interna por bodega)',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
                '/calendario' => 'Calendario de horarios de entrega',
            ],
            'Compras' => [
                '/pedidos' => 'Pedidos (vista interna por bodega, % de entrega)',
                '/cambios-precio' => 'Cambios de Precio',
                '/reclamos/abiertos' => 'Reclamos Abiertos',
                '/reclamos/cerrados' => 'Reclamos Cerrados',
                '/calendario' => 'Calendario de horarios de entrega',
            ],
            'Guardia' => [
                '/calendario/seguimiento' => 'Seguimiento de hoy (marcar arribo)',
            ],
            default => [],
        };
    }

    protected function rolEn(Usuario $usuario, int $idEmpresaActiva): string
    {
        return $usuario->empresas()
            ->where('Empresa.Id_Empresa', $idEmpresaActiva)
            ->first()?->pivot->rol->Nombre_Rol ?? 'desconocido';
    }

    /**
     * Lista corta: nombra hasta MAX_ITEMS_LISTA y resume el resto. Esto viaja
     * en cada mensaje, así que un proveedor con 80 productos incompletos no
     * puede inflar el prompt con 80 nombres.
     */
    protected function lista(array $items): string
    {
        $total = count($items);

        if ($total <= self::MAX_ITEMS_LISTA) {
            return implode(', ', $items);
        }

        $restantes = $total - self::MAX_ITEMS_LISTA;

        return implode(', ', array_slice($items, 0, self::MAX_ITEMS_LISTA))." y {$restantes} más";
    }
}
