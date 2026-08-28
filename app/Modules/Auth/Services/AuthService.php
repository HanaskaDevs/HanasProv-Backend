<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\BitacoraAcceso;
use App\Modules\Auth\Models\IpBloqueada;
use App\Modules\Auth\Models\Sesion;
use App\Modules\Auth\Models\Usuario;
use App\Modules\Proveedores\Models\EstadoProveedor;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AuthService
{
    /**
     * 24 horas (decisión del negocio, 28-ago-2026). Tiene que ir de la mano
     * con sanctum.expiration: si una fuera más corta que la otra, la más
     * corta manda y la otra sería decorativa.
     */
    protected const HORAS_VIGENCIA_SESION = 24;

    /**
     * Fallos seguidos contra UNA cuenta existente antes de bloquearla.
     * "Seguidos" = desde el último ingreso exitoso de ese usuario, sin
     * ventana de tiempo: 3 errores acumulados y queda bloqueado hasta que
     * Sistemas lo reactive (decisión del negocio, 28-ago-2026).
     */
    public const MAX_INTENTOS_POR_USUARIO = 3;

    /**
     * Intentos seguidos con correos que NO existen, desde una misma IP,
     * antes de bloquear esa IP. Es el patrón de quien está probando
     * usuarios al azar: un humano que se equivoca de correo no encadena
     * cinco correos inexistentes distintos sin acertar nunca.
     */
    public const MAX_INTENTOS_IP_INEXISTENTES = 5;

    /** Eventos de la bitácora. Ojo: Tipo_Evento es varchar(30). */
    public const EVENTO_EXITOSO = 'Login_Exitoso';
    public const EVENTO_FALLIDO = 'Login_Fallido';
    public const EVENTO_BLOQUEADO = 'Login_Bloqueado';
    public const EVENTO_USUARIO_INEXISTENTE = 'Login_Inexistente';
    public const EVENTO_USUARIO_BLOQUEADO = 'Usuario_Bloqueado';
    public const EVENTO_IP_BLOQUEADA = 'Ip_Bloqueada';

    /**
     * Único mensaje para "tu cuenta existe y la contraseña es correcta, pero
     * no podés entrar". Lo usan el login y también el middleware
     * EmpresaActiva (cuando la suspensión afecta a una sola de las empresas
     * del usuario), así el proveedor lee siempre lo mismo y no queda
     * adivinando si es un problema de contraseña.
     */
    public const MENSAJE_ACCESO_SUSPENDIDO = 'Tu acceso al portal está suspendido. Por favor contáctate con el administrador para reactivarlo.';

    /**
     * Cuenta bloqueada por intentos fallidos. Se le dice el motivo real
     * porque quien llega acá YA demostró saber el correo, así que no se le
     * revela nada nuevo, y en cambio sí necesita saber a quién pedirle la
     * reactivación en vez de seguir probando contraseñas.
     */
    public const MENSAJE_CUENTA_BLOQUEADA = 'Tu cuenta fue bloqueada por superar el número de intentos fallidos. Contáctate con el área de Sistemas para reactivarla.';

    /** La IP quedó vetada. Deliberadamente seco: no se le explica a un atacante cómo funciona el conteo. */
    public const MENSAJE_IP_BLOQUEADA = 'El acceso desde esta red fue bloqueado por actividad sospechosa. Contáctate con el área de Sistemas.';

    /**
     * Un ÚNICO mensaje para "no existe" y para "la contraseña está mal".
     * Si fueran distintos, cualquiera podría averiguar qué correos tienen
     * cuenta en el portal probándolos de a uno (enumeración de usuarios).
     */
    public const MENSAJE_CREDENCIALES = 'Las credenciales no son válidas.';

    /**
     * Autentica un usuario por email/password, emite un token Sanctum, y crea
     * el registro de Sesion correspondiente (donde se rastrea la empresa activa).
     * Si el usuario solo tiene acceso a una empresa, se autoselecciona.
     */
    public function login(string $email, string $password, ?string $ip = null, ?string $userAgent = null): array
    {
        // Ya NO se filtra por Activo en la consulta: hace falta poder
        // distinguir "no existe / contraseña mal" de "existe pero está
        // bloqueado", para poder decirle al proveedor que hable con el
        // administrador en vez del genérico "credenciales no válidas".
        // 1) La IP vetada no llega ni a que se le mire la contraseña. Va
        //    PRIMERO de todo: es lo más barato de evaluar y corta al
        //    atacante antes de gastar un Hash::check (que por diseño es
        //    lento) y antes de tocar la tabla Usuario.
        if (IpBloqueada::estaBloqueada($ip)) {
            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_IP_BLOQUEADA],
            ]);
        }

        $usuario = Usuario::where('Email', $email)->first();

        // 2) El correo no existe: candidato a barrido de usuarios. Se
        //    cuenta aparte del fallo de contraseña porque son dos ataques
        //    distintos -> este se castiga con bloqueo de IP.
        if (! $usuario) {
            $this->registrarIntento($email, $ip, exito: false, tipoEvento: self::EVENTO_USUARIO_INEXISTENTE);
            $this->evaluarBloqueoDeIp($ip);

            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_CREDENCIALES],
            ]);
        }

        // 3) Ya bloqueado por intentos previos: no se le valida la
        //    contraseña. Acertarla no lo destraba -> solo Sistemas.
        if ($usuario->Bloqueado_Por_Intentos) {
            $this->registrarIntento($email, $ip, exito: false, idUsuario: $usuario->Id_Usuario, tipoEvento: self::EVENTO_BLOQUEADO);

            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_CUENTA_BLOQUEADA],
            ]);
        }

        // 4) Contraseña equivocada sobre una cuenta que sí existe.
        if (! Hash::check($password, $usuario->Password_Hash)) {
            $this->registrarIntento($email, $ip, exito: false, idUsuario: $usuario->Id_Usuario);

            if ($this->evaluarBloqueoDeUsuario($usuario)) {
                throw ValidationException::withMessages([
                    'email' => [self::MENSAJE_CUENTA_BLOQUEADA],
                ]);
            }

            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_CREDENCIALES],
            ]);
        }

        // Desde acá la contraseña ES la correcta -> explicar el motivo real no
        // le revela nada a un desconocido (quien no sabe la clave sigue
        // recibiendo el mensaje genérico de arriba).
        if (! $usuario->Activo) {
            $this->registrarIntento($email, $ip, exito: false, idUsuario: $usuario->Id_Usuario, tipoEvento: self::EVENTO_BLOQUEADO);

            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_ACCESO_SUSPENDIDO],
            ]);
        }

        // Proveedor suspendido en TODAS sus empresas: no tiene a dónde entrar.
        // Si le queda al menos una al día, entra normal y el corte por empresa
        // lo hace el middleware EmpresaActiva.
        if ($usuario->Tipo_Usuario === 'Proveedor' && ! $this->tieneAlgunaEmpresaDisponible($usuario)) {
            $this->registrarIntento($email, $ip, exito: false, idUsuario: $usuario->Id_Usuario, tipoEvento: self::EVENTO_BLOQUEADO);

            throw ValidationException::withMessages([
                'email' => [self::MENSAJE_ACCESO_SUSPENDIDO],
            ]);
        }

        $this->registrarIntento($email, $ip, exito: true, idUsuario: $usuario->Id_Usuario);

        $usuario->forceFill(['Ultimo_Acceso' => now()])->save();

        $tokenResult = $usuario->createToken('api');
        $token = $tokenResult->plainTextToken;
        $idTokenAccess = $tokenResult->accessToken->id;

        $empresasActivas = $usuario->empresas()->wherePivot('Activo', true)->get();
        $idEmpresaActiva = $empresasActivas->count() === 1 ? $empresasActivas->first()->Id_Empresa : null;

        Sesion::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Token' => (string) $idTokenAccess,
            'Ip_Origen' => $ip,
            'Dispositivo' => $userAgent,
            'Fecha_Inicio' => now(),
            'Fecha_Expiracion' => now()->addHours(self::HORAS_VIGENCIA_SESION),
            'Activa' => true,
            'Id_Empresa_Activa' => $idEmpresaActiva,
        ]);

        return [
            'usuario' => $usuario->load(['empresas' => fn($q) => $q->wherePivot('Activo', true)]),
            'token' => $token,
            'id_empresa_activa' => $idEmpresaActiva,
        ];
    }

    /**
     * Cierra la Sesion ligada al token actual y revoca ese token Sanctum.
     */
    public function logout(Usuario $usuario, PersonalAccessToken $accessToken): void
    {
        Sesion::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Token', (string) $accessToken->id)
            ->update(['Activa' => false]);

        $accessToken->delete();
    }

    /**
     * Cambia la empresa activa de la sesión actual SIN cerrar sesión
     * (equivalente a cambiar de compañía en Business Central).
     */
    public function cambiarEmpresa(Usuario $usuario, PersonalAccessToken $accessToken, int $idEmpresa): Sesion
    {
        $tieneAcceso = $usuario->empresas()
            ->where('Empresa.Id_Empresa', $idEmpresa)
            ->wherePivot('Activo', true)
            ->exists();

        if (! $tieneAcceso) {
            throw new AccessDeniedHttpException('No tiene acceso a la empresa seleccionada.');
        }

        $sesion = Sesion::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Token', (string) $accessToken->id)
            ->where('Activa', true)
            ->firstOrFail();

        $sesion->forceFill(['Id_Empresa_Activa' => $idEmpresa])->save();

        return $sesion;
    }

    /**
     * Resuelve la Sesion asociada al token actualmente autenticado.
     */
    /**
     * Sesión asociada al token actual, SI SIGUE VIGENTE.
     *
     * El where sobre Fecha_Expiracion es nuevo y era el agujero: la columna
     * se escribía al hacer login pero después nadie la miraba, así que una
     * sesión valía para siempre y un token robado no caducaba nunca. Ahora
     * una sesión vencida se comporta igual que una cerrada -> el middleware
     * EmpresaActiva devuelve 401 y el frontend manda al login.
     */
    public function sesionActual(Usuario $usuario, PersonalAccessToken $accessToken): ?Sesion
    {
        return Sesion::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Token', (string) $accessToken->id)
            ->where('Activa', true)
            ->where('Fecha_Expiracion', '>', now()->format('Y-m-d\TH:i:s'))
            ->first();
    }

    /**
     * $tipoEvento permite distinguir en la bitácora un intento con la clave
     * equivocada ('Login_Fallido') de uno con la clave correcta pero la
     * cuenta bloqueada ('Login_Bloqueado') -> son dos problemas distintos y
     * conviene poder separarlos al revisar accesos.
     */
    /**
     * ¿Le queda al usuario proveedor alguna empresa a la que entrar?
     *
     * Cuenta como disponible una empresa donde su Proveedor NO esté
     * Suspendido, y también una donde todavía no exista Proveedor (usuario
     * recién creado que aún no completó su Ficha: no hay nada que suspender).
     */
    protected function tieneAlgunaEmpresaDisponible(Usuario $usuario): bool
    {
        $idsEmpresas = $usuario->usuarioEmpresas()->where('Activo', true)->pluck('Id_Empresa');

        if ($idsEmpresas->isEmpty()) {
            // Sin ningún vínculo de empresa el bloqueo no aplica -> ese caso
            // lo resuelve después EmpresaActiva pidiendo elegir empresa.
            return true;
        }

        $proveedoresPorEmpresa = $usuario->proveedores()
            ->whereIn('Proveedor.Id_Empresa', $idsEmpresas)
            ->get()
            ->keyBy('Id_Empresa');

        foreach ($idsEmpresas as $idEmpresa) {
            $proveedor = $proveedoresPorEmpresa->get($idEmpresa);

            if (! $proveedor || (int) $proveedor->Id_Estado_Proveedor !== EstadoProveedor::SUSPENDIDO) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Este usuario llegó al tope de fallos seguidos? Si sí, lo bloquea.
     *
     * "Seguidos" se resuelve contra la bitácora, no con un contador en la
     * tabla Usuario: la bitácora ya registra cada intento con su fecha, así
     * que basta con contar los fallos POSTERIORES al último ingreso
     * exitoso. Ventajas de hacerlo así: no hay un contador que pueda quedar
     * desincronizado, entrar bien lo reinicia solo (sin código extra), y
     * queda la evidencia de qué pasó para revisarlo después.
     *
     * @return bool true si acaba de quedar bloqueado.
     */
    protected function evaluarBloqueoDeUsuario(Usuario $usuario): bool
    {
        $ultimoExito = BitacoraAcceso::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Tipo_Evento', self::EVENTO_EXITOSO)
            ->max('Fecha_Evento');

        $fallos = BitacoraAcceso::where('Id_Usuario', $usuario->Id_Usuario)
            ->where('Tipo_Evento', self::EVENTO_FALLIDO)
            ->when($ultimoExito, fn ($q) => $q->where('Fecha_Evento', '>', $ultimoExito))
            ->count();

        if ($fallos < self::MAX_INTENTOS_POR_USUARIO) {
            return false;
        }

        $usuario->forceFill([
            'Bloqueado_Por_Intentos' => true,
            'Fecha_Bloqueo' => now(),
        ])->save();

        // Si el atacante ya había conseguido entrar antes, sus sesiones
        // abiertas quedarían vivas aunque la cuenta esté bloqueada: el
        // bloqueo solo frena logins NUEVOS. Por eso se cierran acá.
        $usuario->sesiones()->where('Activa', true)->update(['Activa' => false]);
        $usuario->tokens()->delete();

        BitacoraAcceso::create([
            'Id_Usuario' => $usuario->Id_Usuario,
            'Email_Intento' => $usuario->Email,
            'Tipo_Evento' => self::EVENTO_USUARIO_BLOQUEADO,
            'Fecha_Evento' => now(),
        ]);

        return true;
    }

    /**
     * ¿Esta IP encadenó demasiados intentos con correos inexistentes?
     *
     * Se miran los últimos intentos de esa IP EN ORDEN y se cuenta la racha
     * desde el más reciente hacia atrás. Tiene que ser una racha y no un
     * total histórico: una IP de oficina, con el tiempo, junta intentos
     * fallidos sueltos de gente que se equivocó de correo, y sumarlos todos
     * terminaría bloqueando a la empresa entera por acumulación. La racha,
     * en cambio, se corta apenas alguien de esa IP entra bien o falla con
     * un correo que sí existe -> solo sobrevive el patrón de barrido.
     */
    protected function evaluarBloqueoDeIp(?string $ip): void
    {
        if (! $ip) {
            return;
        }

        $ultimos = BitacoraAcceso::where('Ip_Origen', $ip)
            ->orderByDesc('Fecha_Evento')
            ->orderByDesc('Id_Bitacora')
            ->limit(self::MAX_INTENTOS_IP_INEXISTENTES)
            ->pluck('Tipo_Evento');

        if ($ultimos->count() < self::MAX_INTENTOS_IP_INEXISTENTES) {
            return;
        }

        $todosInexistentes = $ultimos->every(fn ($evento) => $evento === self::EVENTO_USUARIO_INEXISTENTE);

        if (! $todosInexistentes) {
            return;
        }

        // firstOrCreate y no create: si la IP ya tiene un bloqueo vigente no
        // se duplica la fila (pasaría si dos peticiones entran a la vez).
        IpBloqueada::firstOrCreate(
            ['Ip' => $ip, 'Activa' => true],
            [
                'Motivo' => self::MAX_INTENTOS_IP_INEXISTENTES.' intentos seguidos con usuarios inexistentes',
                'Intentos' => self::MAX_INTENTOS_IP_INEXISTENTES,
                'Fecha_Bloqueo' => now(),
            ]
        );

        BitacoraAcceso::create([
            'Email_Intento' => 'ip:'.$ip,
            'Tipo_Evento' => self::EVENTO_IP_BLOQUEADA,
            'Ip_Origen' => $ip,
            'Fecha_Evento' => now(),
        ]);
    }

    protected function registrarIntento(string $email, ?string $ip, bool $exito, ?int $idUsuario = null, ?string $tipoEvento = null): void
    {
        BitacoraAcceso::create([
            'Id_Usuario' => $idUsuario,
            'Email_Intento' => $email,
            'Tipo_Evento' => $tipoEvento ?? ($exito ? self::EVENTO_EXITOSO : self::EVENTO_FALLIDO),
            'Ip_Origen' => $ip,
            'Fecha_Evento' => now(),
        ]);
    }
}