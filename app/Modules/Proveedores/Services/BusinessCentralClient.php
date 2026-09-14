<?php

namespace App\Modules\Proveedores\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP de la API OData v4 de Business Central.
 *
 * Solo se ocupa del transporte: token, URLs y errores. El armado de los
 * payloads (qué campo del portal va a qué campo de BC) vive en
 * SincronizacionProveedorBcService -> así, si BC cambia de entorno o de
 * forma de autenticar, se toca un solo archivo.
 *
 * Autenticación: OAuth2 client credentials (service-to-service). El
 * token dura ~1h; se cachea 55 min para no pedir uno nuevo en cada
 * llamada, dejando margen antes del vencimiento real.
 */
class BusinessCentralClient
{
    private const CLAVE_CACHE_TOKEN = 'bc_access_token';

    public function estaConfigurado(): bool
    {
        return (bool) (config('bc.tenant_id') && config('bc.client_id') && config('bc.client_secret'));
    }

    /**
     * @param  string  $company  Código de compañía de BC (ej. 'CF'), NO el
     *                           nombre largo -> sale de
     *                           Empresa.Codigo_Company_BC.
     */
    public function crear(string $company, string $servicio, array $datos): array
    {
        return $this->enviar('post', $company, $servicio, $datos);
    }

    /**
     * Actualiza un registro existente. BC exige el ETag en If-Match para
     * evitar pisar cambios de otro; con '*' se acepta la versión actual
     * sea cual sea, que es lo correcto acá porque el portal es la fuente
     * de verdad de estos campos.
     */
    public function actualizar(string $company, string $servicio, string $clave, array $datos): array
    {
        return $this->enviar('patch', $company, "{$servicio}({$clave})", $datos);
    }

    /** @return array<int, array<string, mixed>> */
    public function consultar(string $company, string $servicio, ?string $filtro = null): array
    {
        $url = $this->url($company, $servicio);
        $query = $filtro ? ['$filter' => $filtro] : [];

        $respuesta = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->get($url, $query);

        if ($respuesta->failed()) {
            throw new RuntimeException($this->mensajeDeError($respuesta->json(), $respuesta->status(), $url));
        }

        return $respuesta->json('value') ?? [];
    }

    private function enviar(string $metodo, string $company, string $ruta, array $datos): array
    {
        $url = $this->url($company, $ruta);

        $peticion = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30);

        if ($metodo === 'patch') {
            $peticion = $peticion->withHeaders(['If-Match' => '*']);
        }

        $respuesta = $peticion->{$metodo}($url, $datos);

        if ($respuesta->failed()) {
            throw new RuntimeException($this->mensajeDeError($respuesta->json(), $respuesta->status(), $url));
        }

        return $respuesta->json() ?? [];
    }

    private function url(string $company, string $ruta): string
    {
        $base = rtrim(config('bc.base_url'), '/');
        $tenant = config('bc.tenant_id');
        $entorno = config('bc.environment');

        return "{$base}/{$tenant}/{$entorno}/ODataV4/Company('{$company}')/{$ruta}";
    }

    /**
     * BC devuelve el detalle útil anidado en error.message; sin
     * desanidarlo, en el log solo quedaba "Client error 400" y no se
     * sabía QUÉ campo rechazó.
     */
    private function mensajeDeError(?array $cuerpo, int $estado, string $url): string
    {
        $detalle = $cuerpo['error']['message'] ?? 'sin detalle';

        return "BC respondió {$estado} en {$url}: {$detalle}";
    }

    private function token(): string
    {
        return Cache::remember(self::CLAVE_CACHE_TOKEN, now()->addMinutes(55), function () {
            $tenant = config('bc.tenant_id');

            $respuesta = Http::asForm()->timeout(30)->post(
                "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('bc.client_id'),
                    'client_secret' => config('bc.client_secret'),
                    'scope' => 'https://api.businesscentral.dynamics.com/.default',
                ]
            );

            if ($respuesta->failed()) {
                throw new RuntimeException(
                    'No se pudo obtener el token de Business Central: '
                    . ($respuesta->json('error_description') ?? $respuesta->body())
                );
            }

            return $respuesta->json('access_token');
        });
    }
}
