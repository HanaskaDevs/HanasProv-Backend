<?php

/*
|--------------------------------------------------------------------------
| Mensajes de validación en español
|--------------------------------------------------------------------------
|
| Este archivo NO EXISTÍA y por eso el portal devolvía la clave interna en
| vez del mensaje: al usuario le llegaba literalmente "validation.email" o
| "validation.password.symbols" en pantalla. Con APP_LOCALE=es y sin
| carpeta lang/, Laravel no tiene de dónde sacar el texto y muestra la
| clave.
|
| Solo están las reglas que la aplicación usa de verdad (se listaron
| recorriendo todos los FormRequest y validate() del proyecto), más las de
| :password, que son las del formato de contraseña.
|
*/

return [

    'accepted' => 'Debes aceptar el campo :attribute.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
    'array' => 'El campo :attribute debe ser una lista.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
        'file' => 'El campo :attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'confirmed' => 'La confirmación del campo :attribute no coincide.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'date_format' => 'El campo :attribute no corresponde al formato :format.',
    'different' => 'Los campos :attribute y :other deben ser distintos.',
    'digits' => 'El campo :attribute debe tener :digits dígitos.',
    'digits_between' => 'El campo :attribute debe tener entre :min y :max dígitos.',
    'distinct' => 'El campo :attribute está repetido.',
    'email' => 'El campo :attribute debe ser una dirección de correo válida.',
    'exists' => 'El valor seleccionado en el campo :attribute no existe.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'image' => 'El campo :attribute debe ser una imagen.',
    'in' => 'El valor seleccionado en el campo :attribute no es válido.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El campo :attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El campo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'not_in' => 'El valor seleccionado en el campo :attribute no es válido.',
    'numeric' => 'El campo :attribute debe ser un número.',

    /*
    | Reglas del objeto Password (ver App\Shared\ReglaPasswordSegura).
    | Los tres mensajes dicen lo MISMO y completo a propósito: quien recibe
    | el error tiene que saber de una todo lo que se le pide, no enterarse
    | de a un requisito por intento.
    */
    'password' => [
        'letters' => 'El formato de la contraseña es incorrecto: debe tener al menos 8 caracteres, un número y un carácter especial.',
        'mixed' => 'El formato de la contraseña es incorrecto: debe tener al menos 8 caracteres, un número y un carácter especial.',
        'numbers' => 'El formato de la contraseña es incorrecto: debe tener al menos 8 caracteres, un número y un carácter especial.',
        'symbols' => 'El formato de la contraseña es incorrecto: debe tener al menos 8 caracteres, un número y un carácter especial.',
        'uncompromised' => 'Esta contraseña apareció en una filtración de datos. Elige otra distinta.',
    ],

    'present' => 'El campo :attribute debe estar presente.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_with' => 'El campo :attribute es obligatorio cuando hay :values.',
    'same' => 'Los campos :attribute y :other deben coincidir.',
    'size' => [
        'array' => 'El campo :attribute debe tener :size elementos.',
        'file' => 'El campo :attribute debe pesar :size kilobytes.',
        'numeric' => 'El campo :attribute debe ser :size.',
        'string' => 'El campo :attribute debe tener :size caracteres.',
    ],
    'string' => 'El campo :attribute debe ser texto.',
    'unique' => 'El campo :attribute ya está registrado.',
    'url' => 'El campo :attribute no es una dirección web válida.',

    /*
    | Nombres legibles de los campos. Sin esto el mensaje sale con el nombre
    | técnico ("El campo password_nueva es obligatorio").
    */
    'attributes' => [
        'email' => 'correo',
        'password' => 'contraseña',
        'password_actual' => 'contraseña actual',
        'password_nueva' => 'contraseña nueva',
        'password_nueva_confirmation' => 'confirmación de la contraseña',
        'codigo' => 'código',
        'nombre_completo' => 'nombre completo',
        'cargo' => 'cargo',
        'telefono' => 'teléfono',
        'ruc' => 'RUC',
        'razon_social' => 'razón social',
        'nombre_comercial' => 'nombre comercial',
        'direccion' => 'dirección',
        'ciudad' => 'ciudad',
        'archivo' => 'archivo',
        'imagen' => 'imagen',
        'imagenes' => 'imágenes',
        'media' => 'archivo',
        'pdf' => 'PDF',
        'activa' => 'interruptor',
        'id_rol' => 'rol',
        'id_empresa' => 'empresa',
        'id_proveedor' => 'proveedor',
        'motivo' => 'motivo',
        'mensaje' => 'mensaje',
        'clasificacion' => 'clasificación',
        'dia_entrega' => 'día de entrega',
        'hora_llegada' => 'hora de llegada',
        'hora_salida' => 'hora de salida',
        'anden_puerta' => 'andén o puerta',
    ],

];
