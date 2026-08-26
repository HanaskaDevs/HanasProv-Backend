<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'repositorio_proveedores' => [
            'driver' => 'local',
            'root' => env('REPOSITORIO_BASE_PATH', storage_path('app/repositorio-proveedores')),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // Plantillas .docx en blanco que el proveedor puede descargar,
        // llenar y volver a subir (ej. Autoevaluación, Carta de
        // Garantía) -> archivos fijos del catálogo, NO por-proveedor,
        // por eso van en su propio disco separado del repositorio de
        // documentos ya cargados por cada proveedor.
        'plantillas' => [
            'driver' => 'local',
            'root' => storage_path('app/plantillas'),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // Multimedia de Configuraciones (imagen de login, banners/videos del home).
        // Público por diseño: se muestra en Login/Landing SIN autenticación.
        // Se sirve como archivo estático real vía symlink (ver 'links' abajo),
        // no por un endpoint de Laravel, para que Apache/Nginx lo entregue directo.
        'multimedia' => [
            'driver' => 'local',
            'root' => env('REPOSITORIO_BASE_PATH', storage_path('app/repositorio')).'/multimedia',
            // POR DÓNDE SALEN LOS ARCHIVOS: '/media' es el symlink estático
            // (public/media -> el repositorio) y '/api/media' es
            // MediaStreamController.
            //
            // POR DEFECTO, EL ESTÁTICO. Se probó servirlos por PHP para
            // ganar caché inmutable y soporte de Range, y en este servidor
            // salió peor: 'php artisan serve' atiende UNA petición a la vez,
            // así que cada archivo pasaba a bloquear al resto de la página.
            // Medido sobre las tres peticiones que hace la landing (video +
            // poster + slides): 340 ms por PHP contra 117 ms por el estático,
            // y eso en localhost sin competencia. El video del home se notaba
            // más lento que antes.
            //
            // Lo que se pierde con el estático: el servidor embebido no manda
            // Cache-Control (el navegador revalida en cada visita) ni responde
            // Range (204 -> devuelve 200 con el archivo entero). Ninguna de
            // las dos rompe estos archivos: son MP4 cortos con faststart y ya
            // se venían sirviendo así.
            //
            // MEDIA_POR_PHP=true vuelve al controlador. Vale la pena detrás de
            // un servidor de verdad solo si por algún motivo no puede servir
            // la carpeta; con nginx lo correcto es que nginx sirva /media con
            // sus propios headers de expiración.
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/')
                .(env('MEDIA_POR_PHP', false) ? '/api/media' : '/media'),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Imágenes adjuntas de Reclamos. Privado: solo se ven vía endpoint
        // autenticado (ReclamoController::verImagen / ReclamoProveedorController::verImagen).
        'reclamos' => [
            'driver' => 'local',
            'root' => env('REPOSITORIO_BASE_PATH', storage_path('app/repositorio')).'/reclamos',
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
        public_path('media') => env('REPOSITORIO_BASE_PATH', storage_path('app/repositorio')).'/multimedia',
    ],

];