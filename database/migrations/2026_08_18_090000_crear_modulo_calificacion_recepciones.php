<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Calificación de Recepciones": la evaluación que Calidad le hace a un
 * proveedor sobre UNA recepción concreta, según el formulario oficial
 * FGH04.15.05-1 ("Evaluación de Calidad del Proveedor"): 13 parámetros de
 * respuesta afirmativa/negativa, 200 puntos posibles.
 *
 * ¿Por qué tablas propias y no un Tipo_Auditoria más del motor genérico
 * que ya existe (Tipo_Auditoria -> Auditoria_Seccion -> Auditoria_Pregunta)?
 *
 *  - Ese motor califica cada pregunta con un puntaje LIBRE entre 0 y
 *    Puntaje_Max; acá la respuesta es binaria (cumple / no cumple) y el
 *    puntaje sale solo. Modelarlo allá obligaba a que el front finja un
 *    número donde en realidad hay un Sí/No.
 *  - Los parámetros tienen etiquetas propias (ver "Puntualidad de
 *    Entrega": "En horario con max. 15 min de retraso" / "Más de 15 min.
 *    de retraso"), no siempre "Sí" y "No".
 *  - Sumarlo como un Tipo_Auditoria lo hacía aparecer en el wizard de
 *    Auditorías de Calificación, mezclado con los 4 formularios de Isak,
 *    y había que filtrarlo a mano en varios lugares.
 *  - Ese módulo ya tiene auditorías reales cargadas; no vale la pena
 *    tocarlo para meter un formulario que se comporta distinto.
 *
 * Vive igual DENTRO del módulo Auditorias (app/Modules/Auditorias), que es
 * donde el negocio lo ubica.
 *
 * OJO con los tipos de las FK: Empresa.Id_Empresa, Proveedor.Id_Proveedor y
 * Usuario.Id_Usuario son INT en la base real (no BIGINT, aunque las
 * migraciones viejas usen $table->id()), así que las columnas que los
 * referencian van con unsignedInteger -> con unsignedBigInteger, SQL Server
 * rechaza la FK por tipos incompatibles. Mismo problema que ya documentaron
 * Tipo_Documento_Clase_Excluida y Tipo_Auditoria_Clase.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de los 13 parámetros del formulario. Sin CRUD por
        // interfaz por ahora (igual que Tipo_Auditoria): se carga con
        // RecepcionParametroSeeder y se versiona ahí.
        Schema::create('Recepcion_Parametro', function (Blueprint $table) {
            $table->id('Id_Recepcion_Parametro');
            $table->smallInteger('Orden');
            $table->string('Descripcion', 500);
            // Puntaje que suma cuando la respuesta es la afirmativa. Los
            // 13 del formulario suman 200 (30 + 15*9 + 10*2 + 15).
            $table->decimal('Puntaje', 5, 2);
            // Textos de las dos opciones. Casi siempre "Si"/"No", pero
            // Puntualidad de Entrega usa los suyos -> por eso son
            // columnas y no un booleano con etiquetas fijas en el front.
            $table->string('Etiqueta_Afirmativa', 150)->default('Si');
            $table->string('Etiqueta_Negativa', 150)->default('No');
            $table->boolean('Activo')->default(true);
        });

        Schema::create('Calificacion_Recepcion', function (Blueprint $table) {
            $table->id('Id_Calificacion_Recepcion');
            $table->unsignedInteger('Id_Empresa');
            $table->unsignedInteger('Id_Proveedor');
            $table->unsignedInteger('Id_Usuario_Auditor');
            // Fecha de la recepción evaluada (la del formulario), que no
            // tiene por qué ser la de hoy: Calidad puede cargarla después.
            $table->date('Fecha_Recepcion');
            // "Contacto" de la cabecera del formulario: quién atendió la
            // entrega. Texto libre, no un FK -> suele ser el transportista
            // o alguien que no existe como usuario del portal.
            $table->string('Contacto', 200)->nullable();
            $table->string('Estado', 20)->default('Borrador');

            // Los 3 totales se calculan y se CONGELAN al finalizar. Podrían
            // recalcularse sumando las respuestas, pero entonces cambiarle
            // el puntaje a un parámetro (o desactivarlo) reescribiría el
            // resultado de calificaciones viejas ya firmadas.
            $table->decimal('Puntaje_Total_Posible', 8, 2)->nullable();
            $table->decimal('Puntaje_Obtenido', 8, 2)->nullable();
            $table->decimal('Porcentaje_Obtenido', 5, 2)->nullable();

            $table->unsignedInteger('Creado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->useCurrent();
            $table->unsignedInteger('Modificado_Por')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Empresa', 'FK_CalifRecepcion_Empresa')
                ->references('Id_Empresa')->on('Empresa');
            $table->foreign('Id_Proveedor', 'FK_CalifRecepcion_Proveedor')
                ->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Usuario_Auditor', 'FK_CalifRecepcion_Auditor')
                ->references('Id_Usuario')->on('Usuario');

            // Se consulta seguido "las calificaciones de este proveedor en
            // este año" (para el tope de 2 por año y para saber si ya se le
            // hizo la del período).
            $table->index(['Id_Proveedor', 'Fecha_Recepcion'], 'IX_CalifRecepcion_Proveedor_Fecha');
        });

        Schema::create('Calificacion_Recepcion_Respuesta', function (Blueprint $table) {
            $table->id('Id_Calificacion_Recepcion_Respuesta');
            $table->unsignedBigInteger('Id_Calificacion_Recepcion');
            $table->unsignedBigInteger('Id_Recepcion_Parametro');
            $table->boolean('Cumple');
            // Se guarda el puntaje real que se le dio, no solo el booleano
            // -> mismo motivo que los totales de la cabecera: el histórico
            // no puede depender del puntaje que tenga hoy el catálogo.
            $table->decimal('Puntaje_Obtenido', 5, 2);
            $table->string('Observacion', 500)->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Calificacion_Recepcion', 'FK_CalifRecepcionResp_Calificacion')
                ->references('Id_Calificacion_Recepcion')->on('Calificacion_Recepcion')
                ->cascadeOnDelete();
            $table->foreign('Id_Recepcion_Parametro', 'FK_CalifRecepcionResp_Parametro')
                ->references('Id_Recepcion_Parametro')->on('Recepcion_Parametro');

            // Una sola respuesta por parámetro en cada calificación -> el
            // autoguardado usa updateOrCreate contra esta clave.
            $table->unique(
                ['Id_Calificacion_Recepcion', 'Id_Recepcion_Parametro'],
                'UQ_CalifRecepcion_Parametro'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Calificacion_Recepcion_Respuesta');
        Schema::dropIfExists('Calificacion_Recepcion');
        Schema::dropIfExists('Recepcion_Parametro');
    }
};
