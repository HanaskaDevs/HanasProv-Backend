<?php

/*
|--------------------------------------------------------------------------
| Datos del portal que son de NEGOCIO, no de infraestructura
|--------------------------------------------------------------------------
|
| Van en un config y no incrustados en un service porque son datos que
| cambian por decisión de la empresa (se va una persona, cambia un correo)
| y no debería hacer falta tocar código ni desplegar para eso.
|
| Los consume el asistente (Hana) para poder decirle a un proveedor a quién
| escribirle, y el footer para la información de contacto.
|
*/

return [

    /*
    | A quién derivar según el tipo de problema. Hana usa esto cuando el
    | usuario pide hablar con alguien; sin esta lista el modelo inventaría
    | una dirección de correo, que es exactamente lo que no queremos.
    */
    'contactos' => [
        'administracion' => [
            'etiqueta' => 'Administración',
            'email' => env('PORTAL_CONTACTO_ADMIN', 'mlopez@hanaska.com'),
            'para' => 'dudas sobre la calificación, aprobaciones, reclamos y temas comerciales',
        ],
        'sistemas' => [
            'etiqueta' => 'Sistemas',
            'emails' => array_filter(explode(',', (string) env(
                'PORTAL_CONTACTO_SISTEMAS',
                'iflores@hanaska.com,amanito@hanaska.com'
            ))),
            'para' => 'problemas técnicos: no puede entrar, un archivo no sube, algo del portal falla',
        ],
    ],

    /*
    | Delegado de Protección de Datos Personales. Es el destinatario del
    | formulario de atención de derechos y el contacto que se publica en la
    | política. La LOPDP exige que este canal exista y esté disponible.
    */
    /*
    | Feriados que NO se pueden derivar del calendario: los puentes que el
    | Ejecutivo traslada por decreto cada año (Ley de Optimización de
    | Feriados) y los feriados locales del cantón.
    |
    | Los nacionales fijos y los que dependen de Pascua (Viernes Santo y
    | Carnaval) ya los calcula App\Shared\FeriadosEcuador: acá van SOLO los
    | agregados. Formato Y-m-d.
    */
    'feriados_adicionales' => array_filter(
        explode(',', (string) env('PORTAL_FERIADOS_ADICIONALES', ''))
    ),

    /*
    | A quién se le escala cuando un proveedor entregó y pasó una hora sin
    | que nadie registre la calificación de esa recepción. Ver
    | AvisarRecepcionesSinCalificarCommand.
    |
    | Va en configuración y no en el comando porque es una lista de personas:
    | cambia cuando alguien entra o sale del equipo, y eso no debería
    | necesitar un despliegue.
    */
    'alertas_recepcion_sin_calificar' => array_filter(array_map('trim', explode(',', (string) env(
        'PORTAL_ALERTAS_RECEPCION',
        'apadilla@hanaska.com,vacurio@hanaska.com,amanito@hanaska.com'
    )))),

    /*
    | Cuánto se espera, después de marcada la entrega, antes de escalar.
    */
    'horas_para_alertar_recepcion' => (int) env('PORTAL_HORAS_ALERTA_RECEPCION', 1),

    /*
    | Techo general de peticiones por minuto de la API (ver
    | AppServiceProvider::registrarLimitesDePeticiones).
    |
    | Van en config y no escritos en el código porque el valor correcto
    | depende de cuánta gente use el portal y desde cuántas redes, y eso
    | cambia sin que cambie el código.
    |
    | Referencia para calibrarlos: la pantalla más pesada (Modo TV) hace 4
    | peticiones cada 20 s = 12 por minuto. Un usuario normal navegando
    | rara vez pasa de 30.
    */
    'limite_peticiones_autenticado' => (int) env('LIMITE_PETICIONES_AUTENTICADO', 120),
    'limite_peticiones_anonimo' => (int) env('LIMITE_PETICIONES_ANONIMO', 300),

    /*
    | Cuánto vale un código de un solo uso, en minutos.
    |
    | SON DOS PLAZOS DISTINTOS A PROPÓSITO, aunque el correo y la pantalla
    | se parezcan:
    |
    | - ACTIVACIÓN ('Bienvenida'): 3 días. Es el código que estrena la
    |   cuenta de un proveedor. Se le manda a alguien que NO está esperando
    |   el correo: puede llegarle un viernes a la tarde, caer en no
    |   deseados, o simplemente no revisar el buzón hasta el lunes. Con 20
    |   minutos, prácticamente todos vencían sin usarse y había que
    |   reenviarlos a mano uno por uno. Con la carga masiva eso se
    |   multiplica por la cantidad de filas del Excel.
    |
    | - RESTABLECER CONTRASEÑA ('Reset'): 20 minutos, como siempre. Acá SÍ
    |   hay alguien esperando el correo (acaba de pedirlo), así que un plazo
    |   corto no molesta a nadie. Y el riesgo es otro: ese código deja
    |   cambiar la contraseña de una cuenta que YA está en uso. Un código de
    |   reset vivo tres días en una bandeja es una ventana de tres días para
    |   que alguien con acceso a ese correo se quede con la cuenta.
    |
    | Si se cambian estos valores hay que actualizar también el texto de la
    | Política de Protección de Datos del frontend, que declara la vigencia.
    */
    'vigencia_codigo_activacion_minutos' => (int) env('PORTAL_VIGENCIA_CODIGO_ACTIVACION_MINUTOS', 4320),
    'vigencia_codigo_reset_minutos' => (int) env('PORTAL_VIGENCIA_CODIGO_RESET_MINUTOS', 20),

    /*
    | Desde cuándo la suspensión automática por documentación vencida
    | empieza a aplicarse de verdad.
    |
    | Decisión del negocio (2-sep-2026): hasta el 31 de diciembre de 2026 el
    | portal AVISA de los documentos vencidos pero NO suspende ni inactiva a
    | nadie -> los proveedores están terminando de cargar su documentación y
    | bloquearlos ahora los dejaría afuera por algo que todavía están
    | resolviendo. A partir del 1 de enero de 2027 el ciclo funciona
    | completo, como estaba pensado.
    |
    | Va como FECHA y no como un interruptor que alguien tiene que acordarse
    | de prender: el cambio ocurre solo el día que corresponde. El
    | interruptor manual de Configuraciones sigue existiendo y es
    | independiente -> para suspender hacen falta las dos cosas (que la
    | fecha haya llegado Y que el interruptor esté encendido).
    |
    | Los AVISOS por correo no pasan por acá: siguen saliendo igual todo
    | 2026 (ver AvisarVencimientoDocumentosCommand).
    */
    'suspension_documentos_desde' => env('SUSPENSION_DOCUMENTOS_DESDE', '2027-01-01'),

    'proteccion_datos' => [
        'email' => env('PORTAL_EMAIL_PROTECCION_DATOS', 'protecciondedatos@hanaska.com'),
    ],

    /*
    | Flujo de arribo del calendario de horarios de entrega (ver
    | HorarioEntregaService). Si el Guardia no marca "arribó" dentro de
    | 'minutos_atrasado_a_rechazado' desde la Hora_Llegada programada, el
    | horario pasa solo a Rechazado y el Guardia ya no puede marcar arribo
    | directo: solo puede mandar una Solicitud_Aprobacion_Arribo, que se
    | avisa por correo a este destinatario. En pruebas apunta a
    | iflores@hanaska.com; cuando se confirme el correo real de Valeria en
    | producción, este es el ÚNICO lugar que hay que cambiar (variable de
    | entorno, sin tocar código).
    */
    'email_aprobacion_arribo' => env('PORTAL_EMAIL_APROBACION_ARRIBO', 'iflores@hanaska.com'),

    'minutos_atrasado_a_rechazado' => (int) env('PORTAL_MINUTOS_ATRASADO_A_RECHAZADO', 30),

];
