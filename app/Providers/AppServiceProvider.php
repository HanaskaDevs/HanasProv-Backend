<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Modules\Auth\Models\PersonalAccessToken;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // EJECUTAR SET DATEFORMAT DIRECTAMENTE AL ABRIR LA CONEXIÓN.
        // OJO: esto es una red de seguridad adicional, NO la solución principal.
        // La solución principal es enviar las fechas en formato ISO 8601 con "T"
        // (ver SincronizacionPedidosService), que es inequívoco sin importar el
        // DATEFORMAT/idioma de la sesión. SET DATEFORMAT es un ajuste de sesión:
        // si el driver reconecta a mitad de un request, se pierde y nadie lo
        // vuelve a aplicar, por eso no basta por sí solo.
        // Cada conexión en su propio try/catch: si 'sqlsrv' falla, no debe
        // impedir que se configure 'sqlsrv_bc' (y viceversa).
        try {
            DB::connection('sqlsrv')->statement("SET DATEFORMAT ymd;");
        } catch (\Exception $e) {
            // Evita caídas si corres comandos artisan sin conexión activa
        }

        try {
            DB::connection('sqlsrv_bc')->statement("SET DATEFORMAT ymd;");
        } catch (\Exception $e) {
            // Evita caídas si corres comandos artisan sin conexión activa
        }
    }
}