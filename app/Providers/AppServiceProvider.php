<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Modules\Auth\Models\PersonalAccessToken;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        $this->registrarLimitesDePeticiones();

        $this->forzarDateFormatEnSqlServer();
    }

    /**
     * SET DATEFORMAT ymd en cada conexión sqlsrv, como red de seguridad.
     *
     * (La solución principal sigue siendo mandar las fechas en ISO 8601 con
     * "T", que es inequívoco sin importar el idioma de la sesión; ver
     * App\Models\BaseModel. Esto es el cinturón además de los tirantes: SET
     * DATEFORMAT es un ajuste de sesión y se pierde si el driver reconecta.)
     *
     * POR QUÉ POR EVENTO Y NO EN EL BOOT. Antes esto abría a mano las
     * conexiones 'sqlsrv' y 'sqlsrv_bc' en CADA petición. Dos problemas
     * medidos el 28-ago-2026:
     *
     *  - 'sqlsrv_bc' apunta a otro servidor con credenciales que ya no son
     *    válidas, así que fallaba SIEMPRE, y ese fallo costaba ~27 ms de los
     *    ~60 ms que tardaba una petición entera: casi la mitad del tiempo se
     *    iba en intentar una conexión que la app no usa.
     *  - Forzaba abrir la base incluso en peticiones que no la necesitan.
     *
     * Enganchado al evento, el SET DATEFORMAT se aplica solo cuando una
     * conexión se abre DE VERDAD, y sirve igual para 'sqlsrv_bc' y
     * 'sqlsrv_sigh' el día que se usen, sin listarlas a mano.
     */
    protected function forzarDateFormatEnSqlServer(): void
    {
        // 1) Para reconexiones y para cualquier conexión que se abra más
        //    tarde (sqlsrv_bc, sqlsrv_sigh): se aplica sola cuando ocurra.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $evento) {
            if ($evento->connection->getDriverName() === 'sqlsrv') {
                $this->aplicarDateFormat($evento->connection);
            }
        });

        // 2) Para la conexión por defecto, además, de una: en el arranque
        //    normal esa conexión YA está creada cuando este provider corre
        //    (la abre el store de caché, que es 'database'), así que el
        //    evento de arriba nunca llega a dispararse para ella. Verificado
        //    el 28-ago-2026: sin esta línea, '2026-03-08' se interpretaba
        //    como 3 de agosto en vez de 8 de marzo.
        try {
            $conexion = DB::connection();

            if ($conexion->getDriverName() === 'sqlsrv') {
                $this->aplicarDateFormat($conexion);
            }
        } catch (\Throwable $e) {
            // Comandos de artisan que corren sin base disponible.
        }
    }

    /**
     * OJO: unprepared() y NO statement().
     *
     * Con statement(), Laravel manda la orden como sentencia PREPARADA, y
     * SQL Server ejecuta eso dentro de sp_executesql: los SET de ese ámbito
     * se descartan al terminar el batch, así que el DATEFORMAT volvía al
     * anterior antes de la consulta siguiente. Comprobado el 28-ago-2026 y
     * es importante: significa que esta red de seguridad NUNCA estuvo
     * funcionando desde que se escribió. La app igual nunca falló por eso
     * porque quien resuelve el problema de verdad es el formato ISO con "T"
     * de App\Models\BaseModel, que es inequívoco sin depender del DATEFORMAT.
     *
     * unprepared() manda la orden como batch directo y sí queda aplicada a
     * la sesión.
     */
    protected function aplicarDateFormat(Connection $conexion): void
    {
        try {
            $conexion->unprepared('SET DATEFORMAT ymd;');
        } catch (\Throwable $e) {
            // Red de seguridad, no el mecanismo principal: que no tumbe nada.
        }
    }

    /**
     * Límite general de peticiones de la API.
     *
     * TIENE QUE SER UN LIMITADOR CON NOMBRE, no un 'throttle:120,1' suelto
     * en bootstrap/app.php. Motivo concreto: ThrottleRequests, cuando no se
     * le da un nombre, arma la clave de conteo a partir de la ruta + la IP.
     * Como algunas rutas ya tienen su propio throttle (login, olvide-password,
     * asistente...), los dos middlewares terminaban calculando LA MISMA clave
     * y cada petición se contaba dos veces -> el límite de 3 envíos de
     * /derechos-datos se agotaba a la mitad. Con nombre, cada limitador usa
     * su propio espacio de claves y los dos conviven sin pisarse.
     *
     * Se cuenta por usuario cuando hay sesión, y por IP cuando no: si fuera
     * siempre por IP, una oficina entera saliendo por la misma IP pública
     * compartiría un solo cupo.
     */
    protected function registrarLimitesDePeticiones(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $usuario = $request->user();

            // CON SESIÓN: el cupo es de la persona, no de la red. Es lo que
            // permite que 100 usuarios trabajando a la vez no compitan entre
            // sí por un mismo cupo.
            if ($usuario) {
                return Limit::perMinute((int) config('portal.limite_peticiones_autenticado'))
                    ->by('usuario:'.$usuario->getAuthIdentifier());
            }

            // SIN SESIÓN: solo queda la IP para identificar, y ahí hay que
            // ser generoso a propósito. Toda una oficina detrás de una misma
            // IP pública comparte este cupo, y la landing sola ya hace 3
            // peticiones por visita -> un límite bajo dejaría fuera a gente
            // legítima antes que a un atacante. Lo caro de verdad (login,
            // recuperar contraseña, activar cuenta) tiene además su propio
            // límite, mucho más estricto, en las rutas.
            return Limit::perMinute((int) config('portal.limite_peticiones_anonimo'))
                ->by('ip:'.$request->ip());
        });
    }
}
