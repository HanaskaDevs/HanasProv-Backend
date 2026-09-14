<?php

namespace App\Modules\Auth\Services;

use App\Models\Empresa;
use App\Models\Rol;
use App\Modules\Auth\Models\CodigoActivacion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Auth\Models\UsuarioBodega;
use App\Modules\Auth\Models\UsuarioEmpresa;
use App\Modules\Auth\Notifications\CodigoActivacionNotification;
use App\Modules\Proveedores\Models\EstadoProveedor;
use App\Modules\Proveedores\Models\Proveedor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class UsuarioService
{
    /**
     * Mismo texto para "ese correo no tiene cuenta" y para "el código está
     * mal". Ver activarCuenta: con mensajes distintos, este endpoint sirve
     * para averiguar qué correos están registrados en el portal.
     */
    public const MENSAJE_CODIGO_INVALIDO = 'El código no es válido o ya fue utilizado.';

    /**
     * Minutos que vale un código, según para qué se emitió.
     *
     * Antes era una sola constante de 20 minutos para los dos casos. Se
     * separó porque son situaciones opuestas: el de activación se le manda a
     * alguien que no está esperando nada (puede tardar días en abrir el
     * correo), y el de reset se lo manda alguien que lo acaba de pedir y
     * está mirando la bandeja.
     *
     * Los valores viven en config/portal.php, donde está explicado el
     * porqué de cada plazo.
     */
    protected function minutosVigenciaCodigo(string $tipo): int
    {
        return $tipo === 'Reset'
            ? (int) config('portal.vigencia_codigo_reset_minutos', 20)
            : (int) config('portal.vigencia_codigo_activacion_minutos', 4320);
    }

    /**
     * Crea un usuario interno (staff) en una o varias empresas donde quien
     * lo crea tenga rol "Sistemas". El propio usuario completa
     * Nombre_Completo/Cargo/Telefono al activar su cuenta con el código.
     */
    public function crearUsuarioInterno(array $data, Usuario $creador): Usuario
    {
        if (! $creador->esSistemasGlobal()) {
    throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden crear usuarios internos.');
}
$idRolProveedor = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');

if ((int) $data['id_rol'] === (int) $idRolProveedor) {
    throw ValidationException::withMessages([
        'id_rol' => ['El rol Proveedor no puede asignarse a un usuario interno.'],
    ]);
}

        return DB::transaction(function () use ($data, $creador) {
            $usuario = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['email'],
                'Tipo_Usuario' => 'Interno',
            ], $creador);

            foreach ($data['id_empresas'] as $idEmpresa) {
                UsuarioEmpresa::create([
                    'Id_Usuario' => $usuario->Id_Usuario,
                    'Id_Empresa' => $idEmpresa,
                    'Id_Rol' => $data['id_rol'],
                    'Activo' => true,
                    'Creado_Por' => $creador->Id_Usuario,
                    'Fecha_Creacion' => now(),
                ]);
            }

            $this->generarYEnviarCodigo($usuario, tipo: 'Bienvenida', creadoPor: $creador->Id_Usuario);

            return $usuario;
        });
    }

    /**
     * Lista los usuarios internos (Tipo_Usuario = Interno) vinculados a la
     * empresa activa, con su rol dentro de esa empresa ya cargado.
     * Solo rol "Sistemas" puede ver este panel.
     */
    public function listarInternos(int $idEmpresa, Usuario $solicitante): Collection
    {
        $this->verificarAccesoPanelInternos($solicitante, $idEmpresa);

        return Usuario::where('Tipo_Usuario', 'Interno')
            ->whereHas('usuarioEmpresas', fn ($q) => $q->where('Id_Empresa', $idEmpresa))
            ->with(['usuarioEmpresas' => fn ($q) => $q->where('Id_Empresa', $idEmpresa)->with('rol')])
            ->orderBy('Nombre_Completo')
            ->get();
    }

    public function verificarAccesoPanelInternos(Usuario $solicitante, int $idEmpresa): void
    {
        if (! $solicitante->esSistemas($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden ver el panel de usuarios internos.');
        }
    }

    /**
     * Crea un usuario externo (Proveedor) en una o varias empresas.
     * El Proveedor (la empresa proveedora en sí) todavía NO existe en este
     * punto -> se crea uno por cada empresa recién cuando el usuario activa
     * su cuenta por primera vez.
     * Permitido para rol "Sistemas" o "Admin" en cada empresa seleccionada.
     */
    public function crearUsuarioProveedor(array $data, Usuario $creador): Usuario
    {
        $idRolProveedor = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');

        if (! $idRolProveedor) {
            throw ValidationException::withMessages([
                'email' => ['No existe el rol "Proveedor" configurado en el sistema.'],
            ]);
        }

        foreach ($data['id_empresas'] as $idEmpresa) {
            if (! $creador->esSistemas($idEmpresa) && ! $creador->esAdmin($idEmpresa)) {
                throw new AccessDeniedHttpException('No tiene permisos para crear proveedores en una de las empresas seleccionadas.');
            }
        }

        return DB::transaction(function () use ($data, $creador, $idRolProveedor) {
            $usuario = $this->crearUsuarioBase([
                'Email' => $data['email'],
                'Nombre_Completo' => $data['email'],
                'Tipo_Usuario' => 'Proveedor',
            ], $creador);

            foreach ($data['id_empresas'] as $idEmpresa) {
                UsuarioEmpresa::create([
                    'Id_Usuario' => $usuario->Id_Usuario,
                    'Id_Empresa' => $idEmpresa,
                    'Id_Rol' => $idRolProveedor,
                    'Activo' => true,
                    'Creado_Por' => $creador->Id_Usuario,
                    'Fecha_Creacion' => now(),
                ]);
            }

            $this->generarYEnviarCodigo($usuario, tipo: 'Bienvenida', creadoPor: $creador->Id_Usuario);

            return $usuario;
        });
    }
    /**
     * CARGA MASIVA de usuarios externos (Proveedores) desde un Excel.
     *
     * Por qué existe: dar de alta proveedores de a uno no escala. Compras
     * recibe listas de decenas de proveedores y el formulario individual
     * obliga a repetir correo + empresas una por una.
     *
     * Tres decisiones de diseño que conviene entender antes de tocar esto:
     *
     * 1. SOLO ROL SISTEMAS. El alta individual la puede hacer Sistemas o
     *    Admin, pero la masiva no: un archivo mal armado crea decenas de
     *    cuentas y dispara decenas de correos de una sola vez, así que se
     *    exige Sistemas tanto en la empresa activa (para poder llamar al
     *    endpoint) como en CADA empresa de destino del archivo.
     *
     * 2. UNA TRANSACCIÓN POR FILA, no una para todo el archivo. Con una
     *    sola transacción, una fila con un correo repetido tiraría abajo
     *    las 79 filas buenas y quien cargó el archivo no sabría cuál
     *    corregir. Cada fila se procesa y se reporta por separado.
     *
     * 3. NO LANZA EXCEPCIÓN por filas malas -> devuelve un REPORTE. Una
     *    carga masiva casi nunca sale perfecta; lo útil no es un 422 seco
     *    sino saber fila por fila qué pasó. Solo se lanza excepción si
     *    falla algo global (permisos, rol Proveedor inexistente).
     *
     * El código de proveedor del Excel NO se guarda: la tabla Proveedor no
     * tiene esa columna y el código real vive en Business Central, resuelto
     * por RUC (ver HorarioEntregaService::resolverCodigosBc). Viaja solo
     * para devolverlo en el reporte y que Compras pueda cuadrar su archivo.
     *
     * @param  array  $filas  Filas del Excel ya parseadas por el frontend.
     * @return array{resumen: array<string,int>, filas: array<int,array<string,mixed>>}
     */
    public function crearUsuariosProveedorEnLote(array $filas, Usuario $creador, int $idEmpresaActiva): array
    {
        if (! $creador->esSistemas($idEmpresaActiva)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden hacer carga masiva de proveedores.');
        }

        $idRolProveedor = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');

        if (! $idRolProveedor) {
            throw ValidationException::withMessages([
                'filas' => ['No existe el rol "Proveedor" configurado en el sistema.'],
            ]);
        }

        $empresasPermitidas = $this->empresasDondeEsSistemas($creador);

        if ($empresasPermitidas === []) {
            throw new AccessDeniedHttpException('No tiene rol Sistemas en ninguna empresa activa.');
        }

        $resultados = [];

        // Correos ya vistos EN ESTE ARCHIVO: email normalizado => nº de fila.
        // Sin esto, un correo repetido dentro del mismo Excel entra dos
        // veces al proceso y la segunda choca contra la fila que acaba de
        // crear la primera, con un mensaje ("ya existe") que hace pensar en
        // un problema de la base y no en un duplicado del archivo.
        $emailsVistos = [];

        foreach ($filas as $indice => $fila) {
            // El frontend manda la fila real del Excel. El +2 del fallback
            // es porque la fila 1 es la cabecera: el índice 0 del arreglo
            // es la fila 2 de la hoja.
            $numeroFila = (int) ($fila['numero_fila'] ?? $indice + 2);
            $codigoProveedor = trim((string) ($fila['codigo_proveedor'] ?? ''));
            $codigoProveedor = $codigoProveedor !== '' ? $codigoProveedor : null;
            $email = mb_strtolower(trim((string) ($fila['email'] ?? '')));

            $base = [
                'numero_fila' => $numeroFila,
                'codigo_proveedor' => $codigoProveedor,
                'email' => $email,
                'empresas' => [],
            ];

            // Formato del correo: se valida ACÁ y no en el FormRequest a
            // propósito (ver el bloque de CrearUsuariosProveedorLoteRequest).
            // Con la regla en el Request, un correo mal escrito en la fila
            // 57 devolvía 422 y no se procesaba ninguna de las 79 filas
            // buenas. El tope de 150 es el mismo de la columna Email y el
            // del alta individual.
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                $resultados[] = [...$base, 'estado' => 'error', 'mensaje' => $email === ''
                    ? 'La fila no tiene correo.'
                    : 'El correo no tiene un formato válido (o pasa de 150 caracteres).'];

                continue;
            }

            if (isset($emailsVistos[$email])) {
                $resultados[] = [...$base, 'estado' => 'error', 'mensaje' => sprintf(
                    'Correo repetido dentro del archivo: ya venía en la fila %d.',
                    $emailsVistos[$email]
                )];

                continue;
            }

            $emailsVistos[$email] = $numeroFila;

            [$idEmpresas, $noResueltas] = $this->resolverEmpresasDelExcel(
                (array) ($fila['empresas'] ?? []),
                $empresasPermitidas
            );

            $base['empresas'] = array_map(
                fn (int $id) => $this->nombreEmpresa($empresasPermitidas[$id]),
                $idEmpresas
            );

            if ($noResueltas !== []) {
                $resultados[] = [...$base, 'estado' => 'error', 'mensaje' => sprintf(
                    'No se reconoció la empresa "%s". Revisa que esté escrita igual que en la hoja "Empresas" de la plantilla y que tengas rol Sistemas en ella.',
                    implode('", "', $noResueltas)
                )];

                continue;
            }

            if ($idEmpresas === []) {
                $resultados[] = [...$base, 'estado' => 'error', 'mensaje' => 'La fila no indica ninguna empresa con acceso.'];

                continue;
            }

            try {
                $resultado = $this->procesarFilaProveedor($email, $idEmpresas, $creador, $idRolProveedor, $empresasPermitidas);
                $resultados[] = [...$base, ...$resultado];
            } catch (ValidationException $e) {
                // Mensaje de negocio (el correo se tomó entre medio, etc.):
                // se muestra tal cual porque le sirve a quien corrige el Excel.
                $primerError = collect($e->errors())->flatten()->first();

                $resultados[] = [...$base, 'estado' => 'error', 'mensaje' => $primerError ?? $e->getMessage()];
            } catch (\Throwable $e) {
                // Cualquier otro fallo (base caída, correo mal encolado) se
                // reporta en esa fila y se sigue con el resto del archivo,
                // que es justamente el punto de procesar fila por fila.
                report($e);

                $resultados[] = [...$base, 'estado' => 'error', 'mensaje' => 'Error inesperado al procesar esta fila. Quedó registrado en el log del servidor.'];
            }
        }

        $contar = fn (string $estado): int => count(array_filter($resultados, fn (array $r) => $r['estado'] === $estado));

        return [
            'resumen' => [
                'total' => count($resultados),
                'creados' => $contar('creado'),
                'acceso_agregado' => $contar('acceso_agregado'),
                'omitidos' => $contar('omitido'),
                'con_error' => $contar('error'),
            ],
            'filas' => $resultados,
        ];
    }

    /**
     * Procesa UNA fila: crea el usuario, o le completa los accesos si el
     * correo ya estaba registrado.
     *
     * Qué hace con un correo que ya existe (decisión explícita): le agrega
     * las empresas del Excel que le falten y NO le reenvía el código de
     * activación. Reenviarlo le mandaría un correo de activación que no
     * pidió a alguien que ya usa el portal, y encima invalidaría su código
     * vigente: generarYEnviarCodigo marca como usados los códigos
     * anteriores de ese correo.
     *
     * @param  array<int, \App\Models\Empresa>  $empresasPermitidas
     * @return array{estado: string, mensaje: string}
     */
    protected function procesarFilaProveedor(
        string $email,
        array $idEmpresas,
        Usuario $creador,
        int $idRolProveedor,
        array $empresasPermitidas,
    ): array {
        $existente = Usuario::where('Email', $email)->first();

        if (! $existente) {
            DB::transaction(function () use ($email, $idEmpresas, $creador, $idRolProveedor) {
                $usuario = $this->crearUsuarioBase([
                    'Email' => $email,
                    // Igual que en el alta individual: hasta que la persona
                    // activa su cuenta no hay nombre, así que se usa el
                    // correo como marcador.
                    'Nombre_Completo' => $email,
                    'Tipo_Usuario' => 'Proveedor',
                ], $creador);

                foreach ($idEmpresas as $idEmpresa) {
                    UsuarioEmpresa::create([
                        'Id_Usuario' => $usuario->Id_Usuario,
                        'Id_Empresa' => $idEmpresa,
                        'Id_Rol' => $idRolProveedor,
                        'Activo' => true,
                        'Creado_Por' => $creador->Id_Usuario,
                        'Fecha_Creacion' => now(),
                    ]);
                }

                // Encolado (CodigoActivacionNotification es ShouldQueue), así
                // que el SMTP no ocurre dentro de esta petición. Es lo que
                // hace viable mandar decenas de correos en una sola carga
                // sin que el portal quede sin atender a nadie.
                $this->generarYEnviarCodigo($usuario, tipo: 'Bienvenida', creadoPor: $creador->Id_Usuario);
            });

            return [
                'estado' => 'creado',
                // "Quedó encolado" y no "se envió": lo único que este código
                // garantiza es que el correo entró en la cola. El envío real
                // lo hace el worker contra el servidor de correo, que puede
                // rechazarlo (por ejemplo con "450 too much mail", que ya pasa
                // en este servidor cuando salen muchos de golpe). Decir
                // "se envió" hacía creer que el proveedor ya lo tenía.
                'mensaje' => 'Usuario creado. El correo de activación quedó encolado.',
            ];
        }

        if ($existente->Tipo_Usuario !== 'Proveedor') {
            return [
                'estado' => 'error',
                'mensaje' => 'Ese correo ya pertenece a un usuario interno del portal. No se le puede dar acceso como proveedor.',
            ];
        }

        $yaTiene = $existente->usuarioEmpresas()
            ->where('Activo', true)
            ->pluck('Id_Empresa')
            ->map(fn ($id) => (int) $id)
            ->all();

        $faltantes = array_values(array_diff($idEmpresas, $yaTiene));

        if ($faltantes === []) {
            return [
                'estado' => 'omitido',
                'mensaje' => 'El correo ya estaba registrado y ya tenía acceso a esas empresas. No se hizo ningún cambio.',
            ];
        }

        foreach ($faltantes as $idEmpresa) {
            // Se reutiliza el método del alta manual a propósito: ya sabe
            // reactivar un vínculo que quedó inactivo (quitarAccesoEmpresa
            // hace soft-delete, así que un create() choca con la UNIQUE KEY).
            $this->otorgarAccesoEmpresa($existente, $idEmpresa, $creador);
        }

        $nombres = array_map(fn (int $id) => $this->nombreEmpresa($empresasPermitidas[$id]), $faltantes);

        return [
            'estado' => 'acceso_agregado',
            'mensaje' => sprintf(
                'El correo ya estaba registrado. Se le agregó acceso a: %s. No se reenvió el correo de activación.',
                implode(', ', $nombres)
            ),
        ];
    }

    /**
     * Estado de la cola de correo: cuántos envíos fallaron últimamente.
     *
     * Existe para avisar ANTES de una carga masiva. El servidor de correo de
     * Hanaska limita el volumen ("450 4.7.1 too much mail from ...") y una
     * carga de decenas de filas es justo el patrón que lo dispara. Cuando eso
     * pasa, el trabajo cae en failed_jobs y el proveedor NUNCA recibe su
     * código, pero la pantalla ya dijo que la fila salió bien: el alta sí se
     * hizo, lo que falló fue el correo.
     *
     * Sin este aviso, esa diferencia solo se descubre cuando un proveedor
     * llama diciendo que no le llegó nada.
     *
     * @return array{fallidos_recientes: int, dias: int, ultimo_fallo: ?string}
     */
    public function estadoColaCorreo(Usuario $solicitante, int $idEmpresa, int $dias = 7): array
    {
        if (! $solicitante->esSistemas($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden ver el estado de la cola de correo.');
        }

        $desde = now()->subDays($dias);

        // failed_jobs es de Laravel: guarda el payload serializado del trabajo.
        // Se filtra por nombre de clase dentro del payload para contar solo
        // los correos y no cualquier otro trabajo que falle.
        $consulta = DB::table('failed_jobs')
            ->where('failed_at', '>=', $desde)
            ->where(function ($q) {
                $q->where('payload', 'like', '%Notification%')
                    ->orWhere('payload', 'like', '%Mail%');
            });

        return [
            'fallidos_recientes' => (int) (clone $consulta)->count(),
            'dias' => $dias,
            'ultimo_fallo' => (clone $consulta)->max('failed_at'),
        ];
    }

    /**
     * Empresas activas donde el usuario tiene rol Sistemas, indexadas por
     * Id_Empresa. Es la lista contra la que se resuelven los nombres del
     * Excel: lo que no esté acá no se puede cargar, así que el archivo no
     * sirve para crear proveedores en empresas ajenas.
     *
     * @return array<int, \App\Models\Empresa>
     */
    protected function empresasDondeEsSistemas(Usuario $creador): array
    {
        $ids = $creador->usuarioEmpresas()
            ->where('Activo', true)
            ->whereHas('rol', fn ($q) => $q->where('Nombre_Rol', 'Sistemas'))
            ->pluck('Id_Empresa')
            ->all();

        if ($ids === []) {
            return [];
        }

        return Empresa::whereIn('Id_Empresa', $ids)
            ->where('Activo', true)
            ->get()
            ->keyBy('Id_Empresa')
            ->all();
    }

    protected function nombreEmpresa(Empresa $empresa): string
    {
        return $empresa->Nombre_Comercial ?: $empresa->Razon_Social;
    }

    /**
     * Convierte lo que se escribió en la celda "empresas_con_acceso" en
     * Id_Empresa reales.
     *
     * SEPARADOR: punto y coma (`;`). Se aceptan además `|` y el salto de
     * línea (Alt+Enter dentro de la celda). La coma NO es separador, y es
     * deliberado: las razones sociales la llevan seguido
     * ("COMERCIAL X S.A., CIA. LTDA."), así que partir por coma cortaría un
     * nombre en dos y la fila fallaría sin motivo aparente.
     *
     * Este mismo parseo está en el frontend (ModalCargaMasivaExternos), que
     * lo necesita para la vista previa, pero el que manda es este: la API es
     * pública y puede recibir la celda entera sin partir.
     *
     * @param  array<int, \App\Models\Empresa>  $empresasPermitidas
     * @return array{0: array<int,int>, 1: array<int,string>}  [ids resueltos, textos no reconocidos]
     */
    protected function resolverEmpresasDelExcel(array $entradas, array $empresasPermitidas): array
    {
        // Una empresa se puede escribir por nombre comercial, razón social
        // o código de Business Central: los tres apuntan al mismo id.
        $indice = [];

        foreach ($empresasPermitidas as $idEmpresa => $empresa) {
            foreach ([$empresa->Nombre_Comercial, $empresa->Razon_Social, $empresa->Empresa_BC] as $alias) {
                $clave = $this->normalizarNombreEmpresa((string) $alias);

                if ($clave !== '') {
                    $indice[$clave] = (int) $idEmpresa;
                }
            }
        }

        $ids = [];
        $noResueltas = [];

        foreach ($entradas as $entrada) {
            foreach (preg_split('/[;|\r\n]+/', (string) $entrada) as $nombre) {
                $nombre = trim($nombre);

                if ($nombre === '') {
                    continue;
                }

                $clave = $this->normalizarNombreEmpresa($nombre);

                if (isset($indice[$clave])) {
                    // Clave = valor para que dos alias de la misma empresa
                    // escritos en la misma celda no la dupliquen.
                    $ids[$indice[$clave]] = $indice[$clave];
                } else {
                    $noResueltas[$nombre] = $nombre;
                }
            }
        }

        return [array_values($ids), array_values($noResueltas)];
    }

    /**
     * Normaliza un nombre de empresa para compararlo sin que un detalle de
     * tipeo rompa la carga: sin mayúsculas, sin tildes y sin puntuación ni
     * espacios. Así "Peña & Compañía Cía. Ltda.", "PENA & COMPANIA CIA LTDA"
     * y "peña&compañia cia ltda" son el mismo nombre.
     *
     * Las tildes se cambian con un mapa explícito y no con
     * iconv('ASCII//TRANSLIT'): esa conversión depende del locale del
     * sistema y en algunos servidores devuelve "?" en vez de la letra, con
     * lo que la ñ no coincidiría nunca.
     */
    protected function normalizarNombreEmpresa(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        $texto = strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        return (string) preg_replace('/[^a-z0-9]+/', '', $texto);
    }


    /**
     * Lista los usuarios externos (Tipo_Usuario = Proveedor) vinculados a la
     * empresa activa, con su rol dentro de esa empresa ya cargado.
     * Solo rol "Sistemas" o "Admin" pueden ver este panel.
     */
    public function listarExternos(int $idEmpresa, Usuario $solicitante): Collection
    {
        $this->verificarAccesoPanelExternos($solicitante, $idEmpresa);

        return Usuario::where('Tipo_Usuario', 'Proveedor')
            ->whereHas('usuarioEmpresas', fn ($q) => $q->where('Id_Empresa', $idEmpresa))
            ->with([
    'usuarioEmpresas' => fn ($q) => $q->where('Id_Empresa', $idEmpresa)->with('rol'),
    'proveedores' => fn ($q) => $q->where('Id_Empresa', $idEmpresa),
])
            ->orderBy('Nombre_Completo')
            ->get();
    }

    public function verificarAccesoPanelExternos(Usuario $solicitante, int $idEmpresa): void
    {
        if (! $solicitante->esSistemas($idEmpresa) && ! $solicitante->esAdmin($idEmpresa)) {
            throw new AccessDeniedHttpException('No tiene permisos para ver el panel de usuarios externos.');
        }
    }

    /**
     * Crea el registro base del usuario con un Password_Hash NO utilizable
     * (nadie conoce este valor). El usuario solo obtiene una contraseña real
     * al consumir el código de activación en activarCuenta().
     */
    protected function crearUsuarioBase(array $datos, Usuario $creador): Usuario
    {
        if (Usuario::where('Email', $datos['Email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe un usuario registrado con este correo.'],
            ]);
        }

        return Usuario::create([
            ...$datos,
            'Password_Hash' => Hash::make(Str::random(40)),
            'Requiere_Cambio_Password' => true,
            'Activo' => true,
            'Creado_Por' => $creador->Id_Usuario,
            'Fecha_Creacion' => now(),
        ]);
    }

    /**
     * Genera un código nuevo y reenvía el correo de "Bienvenido" a un
     * usuario que todavía no activó su cuenta (típicamente porque el
     * correo original no llegó, el código venció, o se perdió el
     * email). No tiene sentido para un usuario que ya
     * activó -> ese caso usa el flujo de "olvidé mi contraseña", no
     * este.
     */
    public function reenviarActivacion(Usuario $usuario, Usuario $solicitante): void
    {
        if (! $usuario->Requiere_Cambio_Password) {
            throw ValidationException::withMessages([
                'usuario' => ['Este usuario ya activó su cuenta. Usa "Olvidé mi contraseña" si necesita restablecerla.'],
            ]);
        }

        $this->generarYEnviarCodigo($usuario, tipo: 'Bienvenida', creadoPor: $solicitante->Id_Usuario);
    }

    /**
     * Genera un código de un solo uso para el email dado, invalidando
     * cualquier código previo sin usar, y lo envía por correo.
     *
     * La vigencia depende del tipo (ver minutosVigenciaCodigo): 3 días para
     * activación, 20 minutos para restablecer contraseña. El mismo número
     * viaja al correo, así que el texto que lee el proveedor siempre dice el
     * plazo real y no queda desfasado si se cambia la config.
     */
    protected function generarYEnviarCodigo(
        Usuario $usuario,
        string $tipo,
        ?int $creadoPor = null,
    ): CodigoActivacion {
        CodigoActivacion::where('Email', $usuario->Email)
            ->where('Usado', false)
            ->update(['Usado' => true, 'Fecha_Uso' => now()->format('Y-m-d\TH:i:s')]);

        $codigo = $this->generarCodigo();
        $minutosVigencia = $this->minutosVigenciaCodigo($tipo);

        $codigoActivacion = CodigoActivacion::create([
            'Email' => $usuario->Email,
            'Tipo' => $tipo,
            'Codigo' => $codigo,
            'Fecha_Expiracion' => now()->addMinutes($minutosVigencia),
            'Usado' => false,
            'Creado_Por' => $creadoPor,
            'Fecha_Creacion' => now(),
        ]);

        $usuario->notify(new CodigoActivacionNotification(
            $codigo,
            esReset: $tipo === 'Reset',
            minutosVigencia: $minutosVigencia,
        ));

        return $codigoActivacion;
    }

    /**
     * Flujo "olvidé mi contraseña". Reglas explícitas del negocio:
     * - Email no existe -> error "el usuario no existe".
     * - Usuario existe pero inactivo -> no se envía nada, error de inactivo.
     * - Usuario existe y activo -> nuevo código de activación (tipo Reset) por correo.
     */
    /**
     * NUNCA revela si el correo tiene cuenta o no.
     *
     * Antes contestaba "El usuario no existe" para los desconocidos y 200
     * para los válidos: con eso, cualquiera podía ir probando correos y
     * armarse la lista de los que sí tienen cuenta en el portal, que es el
     * primer paso de un ataque dirigido. Ahora las tres salidas (no existe,
     * inactivo, bloqueado) terminan igual que el caso bueno, en silencio.
     *
     * El controller siempre responde el mismo texto genérico.
     */
    public function olvidePassword(string $email): void
    {
        $usuario = Usuario::where('Email', $email)->first();

        // Sin cuenta, inactiva o bloqueada: se corta acá sin avisar nada.
        // Una cuenta bloqueada por intentos fallidos tampoco se destraba por
        // esta vía a propósito -> si no, el bloqueo no serviría de nada.
        if (! $usuario || ! $usuario->Activo || $usuario->Bloqueado_Por_Intentos) {
            return;
        }

        $this->generarYEnviarCodigo($usuario, tipo: 'Reset');

        $this->cerrarSesionesYTokens($usuario);
    }

    /**
     * Consume un código de activación/reset y define la contraseña real del usuario.
     * Usado tanto para la primera activación de cuenta como para "olvidé mi contraseña".
     * No requiere autenticación: el código + email son la prueba de identidad.
     *
     * $datosPerfil (nombre_completo, cargo, telefono) solo se aplican y son
     * obligatorios cuando el código es de tipo "Bienvenida" (primera activación).
     * En un "Reset" de contraseña, esos datos ya existen y se ignoran.
     */
    /**
     * Comprueba el par correo+código y devuelve [Usuario, CodigoActivacion].
     *
     * Se extrajo para que lo usen los DOS endpoints: el de activar de
     * verdad y el que valida el código en el paso 1 de la pantalla. Así no
     * hay dos versiones de "¿este código sirve?" que puedan separarse.
     *
     * Un correo sin cuenta da EXACTAMENTE el mismo error que un código
     * equivocado: si dijera "el usuario no existe", esto se volvería un
     * buscador de correos registrados (ver olvidePassword).
     *
     * @return array{0: Usuario, 1: CodigoActivacion}
     */
    protected function resolverCodigo(string $email, string $codigo): array
    {
        $usuario = Usuario::where('Email', $email)->first();

        $codigoActivacion = $usuario
            ? CodigoActivacion::where('Email', $email)
                ->where('Codigo', $codigo)
                ->where('Usado', false)
                ->orderByDesc('Fecha_Creacion')
                ->first()
            : null;

        if (! $usuario || ! $codigoActivacion) {
            throw ValidationException::withMessages([
                'codigo' => [self::MENSAJE_CODIGO_INVALIDO],
            ]);
        }

        if ($codigoActivacion->Fecha_Expiracion->isPast()) {
            throw ValidationException::withMessages([
                'codigo' => ['El código expiró. Solicita uno nuevo.'],
            ]);
        }

        return [$usuario, $codigoActivacion];
    }

    /**
     * Paso 1 de la pantalla de activación: valida el código y dice si a esta
     * persona hay que pedirle además los datos de su empresa.
     *
     * Existe para no hacerle llenar tres pantallas a alguien cuyo código ya
     * venció, y para saber si mostrar RUC y Razón Social: solo se le piden a
     * un usuario Proveedor en su PRIMERA activación, que es cuando se le
     * crea la ficha. A un usuario interno no se le pide nada de eso.
     *
     * @return array{requiere_datos_proveedor: bool}
     */
    public function validarCodigoActivacion(string $email, string $codigo): array
    {
        [$usuario] = $this->resolverCodigo($email, $codigo);

        return [
            'requiere_datos_proveedor' => $usuario->Tipo_Usuario === 'Proveedor'
                && (bool) $usuario->Requiere_Cambio_Password
                && $usuario->proveedores()->count() === 0,
        ];
    }

    public function activarCuenta(string $email, string $codigo, string $passwordNueva, array $datosPerfil = []): Usuario
    {
        [$usuario, $codigoActivacion] = $this->resolverCodigo($email, $codigo);

        // "Primera activación" se determina por el ESTADO REAL del usuario
        // (nunca completó ninguna activación todavía), NO por el tipo de
        // código usado para lograrlo.
        $esPrimeraActivacion = (bool) $usuario->Requiere_Cambio_Password;

        if ($esPrimeraActivacion) {
            // El frontend ya los pide como obligatorios en este paso, pero
            // sin esto alguien podría saltarse esa pantalla y pegarle
            // directo a la API sin cargo/teléfono, dejando el perfil
            // incompleto para siempre (no hay otro momento donde se
            // vuelvan a pedir).
            $faltantes = [];
            if (empty($datosPerfil['nombre_completo'])) $faltantes['nombre_completo'] = ['El nombre completo es requerido.'];
            if (empty($datosPerfil['cargo'])) $faltantes['cargo'] = ['El cargo es requerido.'];
            if (empty($datosPerfil['telefono'])) $faltantes['telefono'] = ['El teléfono es requerido.'];

            if ($faltantes) {
                throw ValidationException::withMessages($faltantes);
            }
        }

        // Datos de la EMPRESA del proveedor. Se piden en la activación (y no
        // después, en la Ficha) porque hasta ahora la ficha se creaba vacía:
        // el proveedor aparecía en los listados sin nombre ni RUC, imposible
        // de identificar para quien lo tenía que revisar.
        $creaFichaDeProveedor = $esPrimeraActivacion
            && $usuario->Tipo_Usuario === 'Proveedor'
            && $usuario->proveedores()->count() === 0;

        if ($creaFichaDeProveedor) {
            $faltantesProveedor = [];
            if (empty($datosPerfil['ruc'])) $faltantesProveedor['ruc'] = ['El RUC es requerido.'];
            if (empty($datosPerfil['razon_social'])) $faltantesProveedor['razon_social'] = ['La razón social es requerida.'];

            if ($faltantesProveedor) {
                throw ValidationException::withMessages($faltantesProveedor);
            }

            $this->verificarRucDisponible($usuario, $datosPerfil['ruc']);
        }

        return DB::transaction(function () use ($usuario, $codigoActivacion, $passwordNueva, $datosPerfil, $esPrimeraActivacion, $creaFichaDeProveedor) {
            $usuario->forceFill([
                'Password_Hash' => Hash::make($passwordNueva),
                'Requiere_Cambio_Password' => false,
                ...($esPrimeraActivacion ? [
                    'Nombre_Completo' => $datosPerfil['nombre_completo'],
                    'Cargo' => $datosPerfil['cargo'],
                    'Telefono' => $datosPerfil['telefono'],
                ] : []),
            ])->save();

            // Primera activación de un usuario externo: se crea un "cascarón"
            // de Proveedor por CADA empresa a la que tiene acceso, para que
            // pueda empezar a llenar su Ficha en cada una por separado.
            if ($creaFichaDeProveedor) {
                $idsEmpresas = $usuario->usuarioEmpresas()->where('Activo', true)->pluck('Id_Empresa');

                foreach ($idsEmpresas as $idEmpresa) {
                    // Si un intento de activación anterior (con un código que
                    // luego expiró) ya alcanzó a crear el cascarón pero no
                    // llegó a vincularlo al usuario, lo reutilizamos en vez de
                    // crear uno nuevo (chocaría con la restricción UNIQUE de
                    // Id_Empresa+Ruc, ya que Ruc sigue en NULL).
                    $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)
                        ->where('Email', $usuario->Email)
                        ->whereNull('Ruc')
                        ->first();

                    $datosFicha = [
                        'Ruc' => $datosPerfil['ruc'],
                        'Razon_Social' => $datosPerfil['razon_social'],
                    ];

                    if ($proveedor) {
                        // Cascarón de un intento anterior: se completa en vez
                        // de crear otro (chocaría con UQ_Proveedor_Empresa_Ruc).
                        $proveedor->forceFill($datosFicha)->save();
                    } else {
                        $proveedor = Proveedor::create([
                            'Id_Empresa' => $idEmpresa,
                            'Email' => $usuario->Email,
                            'Id_Estado_Proveedor' => EstadoProveedor::ASPIRANTE,
                            'Seccion_Actual' => 1,
                            'Porcentaje_Completado_Ficha' => 0,
                            'Fecha_Postulacion' => now(),
                            'Activo' => true,
                            'Fecha_Creacion' => now(),
                            ...$datosFicha,
                        ]);
                    }

                    $usuario->proveedores()->attach($proveedor->Id_Proveedor);
                }
            }

            $codigoActivacion->forceFill([
                'Usado' => true,
                'Fecha_Uso' => now(),
            ])->save();

            return $usuario;
        });
    }

    /**
     * Cambio voluntario de contraseña para un usuario ya autenticado
     * (no relacionado a códigos de activación ni "olvidé mi contraseña").
     * Requiere conocer la contraseña actual.
     */
    public function cambiarPassword(Usuario $usuario, string $passwordActual, string $passwordNueva): void
    {
        if (! Hash::check($passwordActual, $usuario->Password_Hash)) {
            throw ValidationException::withMessages([
                'password_actual' => ['La contraseña actual no es correcta.'],
            ]);
        }

        $usuario->forceFill([
            'Password_Hash' => Hash::make($passwordNueva),
        ])->save();

        $tokenActualId = $usuario->currentAccessToken()?->id;

        $usuario->sesiones()
            ->where('Activa', true)
            ->when($tokenActualId, fn ($q) => $q->where('Token', '!=', (string) $tokenActualId))
            ->update(['Activa' => false]);

        $usuario->tokens()
            ->when($tokenActualId, fn ($q) => $q->where('id', '!=', $tokenActualId))
            ->delete();
    }

    /**
     * Reenvía un código de activación a un usuario que todavía no ha
     * completado su primera activación (Requiere_Cambio_Password = true).
     * Los mismos permisos que crear ese tipo de usuario: internos ->
     * solo Sistemas; externos -> Sistemas o Admin.
     */
    public function reenviarCodigoActivacion(Usuario $usuario, Usuario $solicitante, int $idEmpresa): void
    {
        if (! $usuario->Requiere_Cambio_Password) {
            throw ValidationException::withMessages([
                'email' => ['Este usuario ya activó su cuenta. Si olvidó su contraseña, use la opción "Olvidé mi contraseña".'],
            ]);
        }

        $puedeGestionar = $usuario->Tipo_Usuario === 'Interno'
            ? $solicitante->esSistemas($idEmpresa)
            : ($solicitante->esSistemas($idEmpresa) || $solicitante->esAdmin($idEmpresa));

        if (! $puedeGestionar) {
            throw new AccessDeniedHttpException('No tiene permisos para reenviar el código a este usuario.');
        }

        $this->generarYEnviarCodigo(
            $usuario,
            tipo: 'Bienvenida',
            creadoPor: $solicitante->Id_Usuario,
        );
    }

    /**
     * Solo rol "Sistemas" puede inactivar usuarios.
     */
    public function inactivar(Usuario $usuario, Usuario $ejecutor, int $idEmpresa): void
    {
        if (! $ejecutor->esSistemas($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden inactivar usuarios.');
        }

        $usuario->forceFill([
            'Activo' => false,
            'Modificado_Por' => $ejecutor->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        $this->cerrarSesionesYTokens($usuario);
    }

    /**
     * Ningún proveedor puede activarse con un RUC que ya está registrado en
     * alguna de sus empresas (decisión del negocio, 28-ago-2026: se rechaza,
     * no se vincula a la ficha existente).
     *
     * Se comprueba ANTES de tocar nada, y no se deja que reviente la
     * restricción UQ_Proveedor_Empresa_Ruc: ese error llega como un fallo de
     * base de datos, sale un 500 y el proveedor no entiende qué pasó. Acá
     * sale como un error de validación sobre el campo 'ruc', que la pantalla
     * ya sabe mostrar junto al campo.
     *
     * Se miran TODAS las empresas del usuario porque en la activación se le
     * crea una ficha en cada una: con que el RUC esté tomado en una sola, la
     * operación no puede completarse entera.
     */
    protected function verificarRucDisponible(Usuario $usuario, string $ruc): void
    {
        $idsEmpresas = $usuario->usuarioEmpresas()->where('Activo', true)->pluck('Id_Empresa');

        $yaExiste = Proveedor::whereIn('Id_Empresa', $idsEmpresas)
            ->where('Ruc', $ruc)
            ->exists();

        if ($yaExiste) {
            throw ValidationException::withMessages([
                'ruc' => ['Ya existe un proveedor registrado con este RUC. Contacta al administrador del portal.'],
            ]);
        }
    }

    public function cerrarSesionesYTokens(Usuario $usuario): void
    {
        $usuario->sesiones()->where('Activa', true)->update(['Activa' => false]);
        $usuario->tokens()->delete();
    }

    protected function generarCodigo(): string
    {
        return Str::upper(Str::random(4)) . '-' . random_int(1000, 9999);
    }

    /**
     * Reactiva una cuenta, venga de una inactivación manual o de un bloqueo
     * automático por intentos fallidos (ver AuthService::login).
     *
     * SIEMPRE se le manda un código nuevo y tiene que definir otra
     * contraseña. El motivo es el caso que originó el bloqueo: si la cuenta
     * se trabó fue justamente porque alguien estuvo probando contraseñas
     * contra ella, así que devolverle el acceso con la misma clave sería
     * reactivar la cuenta y dejarla igual de expuesta que antes. Decisión
     * del negocio, 28-ago-2026.
     */
    public function reactivar(Usuario $usuario, Usuario $ejecutor, int $idEmpresa): void
    {
        if (! $ejecutor->esSistemas($idEmpresa)) {
            throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden reactivar usuarios.');
        }

        $usuario->forceFill([
            'Activo' => true,
            'Bloqueado_Por_Intentos' => false,
            'Fecha_Bloqueo' => null,
            'Modificado_Por' => $ejecutor->Id_Usuario,
            'Fecha_Modificacion' => now(),
        ])->save();

        // Tipo 'Reset' y no 'Bienvenida': la cuenta ya existe y ya tiene
        // perfil cargado, solo hace falta que fije una contraseña nueva.
        $this->generarYEnviarCodigo($usuario, tipo: 'Reset', creadoPor: $ejecutor->Id_Usuario);

        // Por si el atacante alcanzó a entrar antes del bloqueo.
        $this->cerrarSesionesYTokens($usuario);
    }

public function actualizarEmail(Usuario $usuario, string $nuevoEmail, Usuario $ejecutor, int $idEmpresa): void
{
    $puedeGestionar = $usuario->Tipo_Usuario === 'Interno'
        ? $ejecutor->esSistemas($idEmpresa)
        : ($ejecutor->esSistemas($idEmpresa) || $ejecutor->esAdmin($idEmpresa));

    if (! $puedeGestionar) {
        throw new AccessDeniedHttpException('No tiene permisos para editar este usuario.');
    }

    if (Usuario::where('Email', $nuevoEmail)->where('Id_Usuario', '!=', $usuario->Id_Usuario)->exists()) {
        throw ValidationException::withMessages([
            'email' => ['Ya existe otro usuario con ese correo.'],
        ]);
    }

    $usuario->forceFill([
        'Email' => $nuevoEmail,
        'Modificado_Por' => $ejecutor->Id_Usuario,
        'Fecha_Modificacion' => now(),
    ])->save();
}

public function actualizarRolEnEmpresa(Usuario $usuario, int $idEmpresa, int $idRol, Usuario $ejecutor): void
{
    if (! $ejecutor->esSistemas($idEmpresa)) {
        throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden cambiar roles.');
    }

    $vinculo = $usuario->usuarioEmpresas()->where('Id_Empresa', $idEmpresa)->where('Activo', true)->first();

    if (! $vinculo) {
        throw ValidationException::withMessages([
            'id_empresa' => ['El usuario no tiene acceso activo a esa empresa.'],
        ]);
    }

    $vinculo->forceFill([
        'Id_Rol' => $idRol,
        'Modificado_Por' => $ejecutor->Id_Usuario,
        'Fecha_Modificacion' => now(),
    ])->save();
}

/**
 * Reemplaza por completo las bodegas asignadas a un usuario Compras dentro
 * de una empresa (sync: borra las que ya no vengan en la lista, crea las
 * nuevas). Solo tiene efecto real sobre el rol Compras -> Admin/Sistemas
 * ignoran esto (siempre ven las 3 bodegas), pero no se bloquea la llamada
 * para permitir asignar bodegas ANTES de que Sistemas cambie el rol.
 */
public function actualizarBodegasAsignadas(Usuario $usuario, int $idEmpresa, array $codigosBodega, Usuario $ejecutor): void
{
    if (! $ejecutor->esSistemas($idEmpresa)) {
        throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden asignar bodegas.');
    }

    $vinculo = $usuario->usuarioEmpresas()->where('Id_Empresa', $idEmpresa)->where('Activo', true)->first();

    if (! $vinculo) {
        throw ValidationException::withMessages([
            'id_empresa' => ['El usuario no tiene acceso activo a esa empresa.'],
        ]);
    }

    $codigosValidos = ['CD-0001', 'CD-0002', 'CD-0003'];
    $codigosInvalidos = array_diff($codigosBodega, $codigosValidos);

    if (! empty($codigosInvalidos)) {
        throw ValidationException::withMessages([
            'codigos_bodega' => ['Código(s) de bodega inválido(s): ' . implode(', ', $codigosInvalidos)],
        ]);
    }

    DB::transaction(function () use ($usuario, $idEmpresa, $codigosBodega, $ejecutor) {
        UsuarioBodega::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Id_Empresa', $idEmpresa)
            ->whereNotIn('Cod_Almacen', $codigosBodega)
            ->delete();

        $existentes = UsuarioBodega::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Id_Empresa', $idEmpresa)
            ->pluck('Cod_Almacen')
            ->all();

        foreach (array_diff($codigosBodega, $existentes) as $codigo) {
            UsuarioBodega::create([
                'Id_Usuario' => $usuario->Id_Usuario,
                'Id_Empresa' => $idEmpresa,
                'Cod_Almacen' => $codigo,
                'Activo' => true,
                'Creado_Por' => $ejecutor->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);
        }
    });
}

public function quitarAccesoEmpresa(Usuario $usuario, int $idEmpresa, Usuario $ejecutor): void
{
    $puedeGestionar = $usuario->Tipo_Usuario === 'Interno'
        ? $ejecutor->esSistemas($idEmpresa)
        : ($ejecutor->esSistemas($idEmpresa) || $ejecutor->esAdmin($idEmpresa));

    if (! $puedeGestionar) {
        throw new AccessDeniedHttpException('No tiene permisos para modificar el acceso de este usuario en esa empresa.');
    }

    $vinculo = $usuario->usuarioEmpresas()->where('Id_Empresa', $idEmpresa)->where('Activo', true)->first();

    if (! $vinculo) {
        throw ValidationException::withMessages([
            'id_empresa' => ['El usuario no tiene acceso activo a esa empresa.'],
        ]);
    }

    if ($usuario->usuarioEmpresas()->where('Activo', true)->count() <= 1) {
        throw ValidationException::withMessages([
            'id_empresa' => ['No puede quitar el único acceso que le queda. Inactive el usuario completo en su lugar.'],
        ]);
    }

    $vinculo->forceFill(['Activo' => false])->save();
}

/**
 * Da acceso a un usuario EXISTENTE a una empresa adicional.
 * - Interno: requiere id_rol explícito, solo Sistemas puede otorgarlo.
 * - Proveedor: el rol siempre es "Proveedor"; Sistemas o Admin pueden otorgarlo.
 *   Si el usuario ya activó su cuenta, se crea de inmediato el "cascarón"
 *   del Proveedor para esa empresa (si aún no activó, se crea después,
 *   junto con las demás, al momento de activarCuenta()).
 */
public function otorgarAccesoEmpresa(Usuario $usuario, int $idEmpresa, Usuario $creador, ?int $idRol = null): void
{
    if ($usuario->Tipo_Usuario === 'Interno') {
    if (! $creador->esSistemasGlobal()) {
        throw new AccessDeniedHttpException('Solo usuarios con rol Sistemas pueden otorgar acceso a usuarios internos.');
    }
    if (! $idRol) {
        throw ValidationException::withMessages(['id_rol' => ['El rol es requerido para un usuario interno.']]);
    }

    } else {
        if (! $creador->esSistemas($idEmpresa) && ! $creador->esAdmin($idEmpresa)) {
            throw new AccessDeniedHttpException('No tiene permisos para otorgar acceso de proveedor en esa empresa.');
        }
        $idRol = Rol::where('Nombre_Rol', 'Proveedor')->value('Id_Rol');
    }

    $yaTieneAcceso = $usuario->usuarioEmpresas()
        ->where('Id_Empresa', $idEmpresa)
        ->where('Activo', true)
        ->exists();

    if ($yaTieneAcceso) {
        throw ValidationException::withMessages(['id_empresa' => ['El usuario ya tiene acceso a esa empresa.']]);
    }

    DB::transaction(function () use ($usuario, $idEmpresa, $idRol, $creador) {
        // Si ya existía un vínculo INACTIVO (el usuario tuvo acceso antes y
        // se lo quitaron), lo reactivamos en vez de intentar crear uno
        // nuevo. quitarAccesoEmpresa() hace soft-delete (Activo=false, la
        // fila nunca se borra), así que un create() de nuevo siempre choca
        // con la UNIQUE KEY (Id_Usuario, Id_Empresa).
        $vinculo = UsuarioEmpresa::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Id_Empresa', $idEmpresa)
            ->first();

        if ($vinculo) {
            $vinculo->forceFill([
                'Id_Rol' => $idRol,
                'Activo' => true,
                'Modificado_Por' => $creador->Id_Usuario,
                'Fecha_Modificacion' => now(),
            ])->save();
        } else {
            UsuarioEmpresa::create([
                'Id_Usuario' => $usuario->Id_Usuario,
                'Id_Empresa' => $idEmpresa,
                'Id_Rol' => $idRol,
                'Activo' => true,
                'Creado_Por' => $creador->Id_Usuario,
                'Fecha_Creacion' => now(),
            ]);
        }

        if ($usuario->Tipo_Usuario === 'Proveedor' && ! $usuario->Requiere_Cambio_Password) {
            // Reutilizamos el Proveedor de esa empresa+email si YA existe,
            // sea porque:
            // - un intento anterior (doble clic, etc.) ya creó el cascarón
            //   sin RUC y no llegó a vincularlo (mismo criterio que
            //   activarCuenta()), o
            // - el proveedor ya había tenido acceso a esta empresa antes
            //   (quitarAccesoEmpresa() solo desactiva Usuario_Empresa, el
            //   Proveedor -con o sin RUC ya cargado- nunca se borra) y se le
            //   está reactivando el acceso.
            // Sin este chequeo se creaba un cascarón vacío duplicado al
            // reactivar a alguien que ya había completado su Ficha.
            $proveedor = Proveedor::where('Id_Empresa', $idEmpresa)
                ->where('Email', $usuario->Email)
                ->first();

            if (! $proveedor) {
                $proveedor = Proveedor::create([
                    'Id_Empresa' => $idEmpresa,
                    'Email' => $usuario->Email,
                    'Id_Estado_Proveedor' => EstadoProveedor::ASPIRANTE,
                    'Seccion_Actual' => 1,
                    'Porcentaje_Completado_Ficha' => 0,
                    'Fecha_Postulacion' => now(),
                    'Activo' => true,
                    'Fecha_Creacion' => now(),
                ]);
            }

            // syncWithoutDetaching en vez de attach(): si el vínculo en
            // Usuario_Proveedor ya existía (proveedor que recupera acceso),
            // attach() intentaría insertarlo de nuevo y chocaría con la
            // UNIQUE KEY (Id_Usuario, Id_Proveedor).
            $usuario->proveedores()->syncWithoutDetaching([$proveedor->Id_Proveedor]);
        }
    });
}
}
