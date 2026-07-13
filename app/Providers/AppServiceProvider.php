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

        // EJECUTAR SET DATEFORMAT DIRECTAMENTE AL ABRIR LA CONEXIÓN
        try {
            DB::connection('sqlsrv')->statement("SET DATEFORMAT ymd;");
            DB::connection('sqlsrv_bc')->statement("SET DATEFORMAT ymd;");
        } catch (\Exception $e) {
            // Evita caídas si corres comandos artisan sin conexión activa
        }
    }
}