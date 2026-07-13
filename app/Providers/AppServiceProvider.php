<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Modules\Auth\Models\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        DB::listen(function ($query) {
            $sql = $query->sql;
            foreach ($query->bindings as $binding) {
                // Si es un objeto de fecha (Carbon), ver cómo lo está convirtiendo Laravel
                if ($binding instanceof \DateTimeInterface) {
                    $value = $binding->format('Y-m-d H:i:s.v');
                } else {
                    $value = $binding;
                }
                $value = is_string($value) ? "'$value'" : $value;
                $sql = preg_replace('/(\?)/', $value, $sql, 1);
            }

            // Esto escribirá la query idéntica en storage/logs/laravel.log
            Log::debug("DIAGNÓSTICO SQL [Conexión: {$query->connectionName}]: " . $sql);
        });
    }
}