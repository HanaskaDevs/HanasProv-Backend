<?php

/**
 * Integración con Business Central (escritura).
 *
 * Distinto de la conexión 'sqlsrv_bc' de config/database.php: esa es el
 * ESPEJO de solo lectura (RAW_BC). Esto es la API OData v4 real, que es
 * por donde se REGISTRAN los proveedores aprobados.
 *
 * La compañía NO se configura acá: sale de Empresa.Codigo_Company_BC de
 * la empresa activa (el selector de arriba a la derecha) -> cada
 * proveedor se postea en la compañía que le corresponde.
 */
return [

    'tenant_id' => env('BC_TENANT_ID'),
    'client_id' => env('BC_CLIENT_ID'),
    'client_secret' => env('BC_CLIENT_SECRET'),

    /*
     | Entorno de BC: 'DesarrolloHanaska' para pruebas, 'Production' para
     | real. Se deja en el .env y NO se hardcodea porque es la única
     | diferencia entre escribir en un sandbox y escribir en la base
     | productiva de la empresa.
     */
    'environment' => env('BC_ENVIRONMENT', 'DesarrolloHanaska'),

    'base_url' => env('BC_BASE_URL', 'https://api.businesscentral.dynamics.com/v2.0'),

    /*
     | Nombres de los web services publicados en BC (páginas expuestas
     | como OData). Si en BC los renombran, se cambia acá y no hay que
     | tocar código.
     |
     | OJO: al 10-sep-2026, 'datos_adicionales' NO está publicado en
     | DesarrolloHanaska (da 404), solo en Production -> hay que
     | publicarlo ahí para poder probar el flujo completo.
     */
    'servicios' => [
        'ficha_proveedor' => env('BC_WS_FICHA_PROVEEDOR', 'Ficha_proveedor_Excel'),
        'banco_proveedor' => env('BC_WS_BANCO_PROVEEDOR', 'Ficha_banco_proveedor_Excel'),
        'datos_adicionales' => env('BC_WS_DATOS_ADICIONALES', 'Dat_Adicionales_Proveedor'),
    ],

    /*
     | Valores fijos para todo proveedor nacional, tomados del Excel de
     | referencia que pasó el encargado de cargarlos a mano.
     |
     | Están acá y no incrustados en el código para que Compras/Sistemas
     | los pueda ajustar sin un despliegue. Payment_Terms_Code queda
     | FUERA a propósito: varía por proveedor (5/8/30/10 días) y es una
     | negociación comercial -> se llena en BC después, no se manda desde
     | el portal (decisión explícita del usuario).
     */
    'defaults' => [
        'country_region_code' => env('BC_DEF_PAIS', 'ECU'),
        'language_code' => env('BC_DEF_IDIOMA', 'ES'),
        'format_region' => env('BC_DEF_FORMATO', 'es-EC'),
        'gen_bus_posting_group' => env('BC_DEF_GRUPO_NEG', 'NAC'),
        'vat_bus_posting_group' => env('BC_DEF_GRUPO_IVA', 'NACIONAL'),
        'vendor_posting_group' => env('BC_DEF_GRUPO_PROV', 'NAC'),
        'currency_code' => env('BC_DEF_MONEDA', 'USD'),
        'payment_method_code' => env('BC_DEF_FORMA_PAGO', '36.TR'),
        // Van en Dat_Adicionales_Proveedor.
        'forma_pago' => env('BC_DEF_FORMA_PAGO_SRI', '20'),
        'tipo_pago' => env('BC_DEF_TIPO_PAGO_SRI', '01'),
    ],

    /*
     | Interruptor general. En false, el portal aprueba proveedores
     | normalmente pero NO intenta postear a BC -> útil para levantar el
     | cambio en producción sin activar la integración el mismo día.
     */
    'habilitado' => env('BC_POSTEO_HABILITADO', false),

];
