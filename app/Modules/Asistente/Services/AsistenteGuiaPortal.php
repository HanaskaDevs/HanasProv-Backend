<?php

namespace App\Modules\Asistente\Services;

/**
 * Cómo funciona el portal, en texto, para que Hana pueda explicar procesos
 * y no solo leer números.
 *
 * ESTÁ SEPARADO DEL CONTEXTO A PROPÓSITO. Este texto es IDÉNTICO para todos
 * los usuarios y no cambia entre mensajes, mientras que el contexto (cuántos
 * documentos le faltan a Fulano) cambia siempre. Manteniéndolos en bloques
 * distintos:
 *
 *   1. El bloque estable va PRIMERO en el system prompt, y como la caché de
 *      la API es un match de prefijo, se puede cachear una sola vez y
 *      reusar para todos los usuarios (leer de caché cuesta 0.10x contra
 *      1.00x de la entrada normal). El mínimo cacheable son ~1024 tokens, y
 *      persona + esta guía lo superan; la persona sola, no.
 *   2. Si mañana cambia un paso del proceso, se corrige acá y no hay que
 *      revisar cinco métodos distintos.
 *
 * Es la parte más costosa del prompt en tokens, y también la que hace la
 * diferencia entre un bot que dice "revisa la sección Documentación" y uno
 * que dice "te falta el permiso de ARCSA, súbelo en PDF de máximo 4 MB y
 * recién cuando estén los 7 puedes presionar Registrar documentación".
 */
class AsistenteGuiaPortal
{
    public static function paraProveedor(): string
    {
        return <<<'TEXTO'
        CÓMO FUNCIONA EL PORTAL PARA UN PROVEEDOR

        El proveedor recorre cuatro etapas. No puede saltarse ninguna.

        1) MI FICHA — datos de la empresa proveedora.
           Son tres secciones más los contactos: datos generales (RUC, razón
           social, dirección, ciudad, ubicación en el mapa), las Clases de
           Proveedor a las que pertenece, y las Categorías de Producto que
           maneja. Se guarda por sección, se puede completar en varias veces.
           La ficha tiene un porcentaje de avance; llega al 100% cuando están
           las tres secciones y los cuatro contactos (representante legal,
           ventas, calidad y contabilidad).

        2) DOCUMENTACIÓN — la carpeta de documentos.
           Cada documento se sube en PDF, máximo 4 MB por archivo. El
           checklist que ve el proveedor NO es el mismo para todos: depende de
           su ciudad (por ejemplo la LUAE solo se pide en Quito, y el permiso
           de Bomberos solo fuera de Quito) y de sus Clases de Proveedor
           (a un productor agrícola no se le piden los mismos papeles que a un
           comerciante). Algunos documentos piden fecha de caducidad.
           Algunos tienen una plantilla en blanco descargable para llenar y
           volver a subir.
           Cuando están todos los obligatorios, el proveedor presiona
           "Registrar documentación". Desde ese momento la carpeta queda de
           solo lectura y pasa a revisión.

        3) FICHA DE PRODUCTOS — el catálogo.
           Se cargan los productos con su unidad de presentación, precio, peso,
           volumen y unidades por caja, y a cada producto se le suben sus
           documentos obligatorios (también PDF). Cuando están listos se
           presiona "Registrar productos" y ese lote queda bloqueado en
           revisión. Se pueden registrar lotes nuevos en paralelo mientras
           otro está en revisión.

        4) CALIFICACIÓN — la revisión de la empresa.
           Alguien de Hanaska revisa la ficha, cada documento y cada producto,
           y los marca Aprobado o Rechazado. Un rechazo SIEMPRE viene con un
           comentario explicando qué corregir.
           Si algo queda rechazado: el proveedor corrige (reemplaza el archivo
           o edita el dato) y después presiona "Registrar documentación
           actualizada" o "Confirmar corrección" según el caso. Recién ahí
           vuelve a revisión. Corregir sin confirmar no avisa a nadie.

        CUÁNDO QUEDA APROBADO
        El proveedor pasa a Aprobado automáticamente cuando se cumplen las tres
        condiciones a la vez: ficha aprobada, toda la documentación aprobada y
        al menos un producto aprobado. No hay que pedirlo.

        DOCUMENTOS QUE VENCEN
        Un documento con fecha de caducidad se avisa por correo 30 días antes y
        después una vez por semana. Vencido el plazo hay 15 días de gracia para
        reemplazarlo; pasados esos 15 días el acceso al portal queda suspendido
        en esa empresa hasta que se regularice. La suspensión es por empresa: si
        el proveedor trabaja con dos empresas del grupo y está al día en una,
        sigue entrando a esa.

        OTRAS SECCIONES
        - PEDIDOS: los pedidos de compra que le hizo la empresa, con el
          porcentaje entregado de cada uno. Vigentes son los que aún no llegan
          a su fecha de recepción y no están completos; Históricos, el resto.
        - RECLAMOS: el proveedor NO PUEDE crear reclamos, solo verlos y
          responderlos. Los crea el personal de Hanaska.
        - POLÍTICAS: las políticas del portal, solo lectura.

        LA CALIFICACIÓN GLOBAL (nota de desempeño, sobre 100)
        Es distinta de la calificación de ingreso de arriba: mide cómo se está
        portando el proveedor ya aprobado. Se reparte así:
        - Fill rate de entregas: 50 puntos. Es el promedio del porcentaje
          entregado de cada pedido YA CERRADO. Un pedido en curso todavía no
          cuenta.
        - Auditoría de proveedor: 15 puntos, según la última auditoría
          finalizada.
        - Documentación: 15 puntos, y es todo o nada: se dan completos solo si
          TODOS los documentos obligatorios están aprobados y vigentes.
        - Reclamos: 10 puntos. Sin reclamos en los últimos 12 meses se dan los
          10; cada reclamo de ese período descuenta 1 punto, hasta 0.
        - Auditoría de recepción: 10 puntos, según la última finalizada.
        Si una auditoría todavía no se hizo, esos puntos se dan completos por
        defecto y la nota se marca como pendiente de verificar.
        TEXTO;
    }

    public static function paraInterno(): string
    {
        return <<<'TEXTO'
        CÓMO FUNCIONA EL PORTAL PARA EL PERSONAL DE HANASKA

        ROLES Y QUÉ HACE CADA UNO
        - Sistemas: administra todo. Empresas, usuarios internos, cuentas de
          proveedores, catálogos y configuraciones, además de todo lo operativo.
        - Admin: cuentas de proveedores, calificación de proveedores, pedidos,
          reclamos y auditorías. No administra empresas ni usuarios internos.
        - Calidad: auditorías de proveedor, calificación de recepciones,
          reclamos y el calendario de entregas.
        - Compras: pedidos por bodega, calendario de entregas, marcar entregas
          completadas y reclamos.
        - Guardia: solo la pantalla de seguimiento del día, donde marca que un
          proveedor arribó. Nada más.

        CALIFICAR UN PROVEEDOR
        Se revisa por separado la ficha (campo por campo), cada documento y cada
        producto. Cada cosa se marca Aprobado o Rechazado, y un rechazo exige
        comentario. Al cerrar la calificación de productos se resuelve el
        veredicto: si se cumplen las tres condiciones (ficha, documentos y al
        menos un producto aprobados) el proveedor pasa a Aprobado y se le avisa
        por correo; si algo quedó rechazado, pasa a Rechazado con el detalle.

        AUDITORÍAS
        Son dos cosas distintas:
        - Auditoría de proveedor: por tipo de auditoría, con secciones y
          preguntas con puntaje. El auditor puede marcar preguntas como "No
          aplica" y ese puntaje se descuenta del total posible antes de sacar
          el porcentaje. Al finalizar, el puntaje queda congelado.
        - Calificación de recepciones (formulario FGH04.15.05-1): se hace en la
          recepción de mercadería, sobre 200 puntos, mínimo una vez al año por
          proveedor y como máximo dos.

        PEDIDOS
        Vienen de Business Central y se sincronizan solos. Las cantidades
        recibidas se actualizan cada 30 minutos. Compras ve los pedidos de las
        bodegas que tenga asignadas; Sistemas y Admin ven todas.

        RECLAMOS
        Los crea el personal interno contra un proveedor, con tipo (Calidad,
        Salubridad o Inocuidad) e impacto (Alto, Medio o Bajo), y se pueden
        adjuntar hasta 5 imágenes. El proveedor los ve y responde. Cada reclamo
        de los últimos 12 meses descuenta 1 punto de la calificación global del
        proveedor.

        CALENDARIO DE HORARIOS DE ENTREGA
        Agenda de qué proveedor entrega qué día y a qué hora. El Guardia marca
        el arribo y Compras marca la entrega completada; el resto de los estados
        se calculan solos. Hay un modo TV para la pantalla de recepción.

        LA CALIFICACIÓN GLOBAL DEL PROVEEDOR (sobre 100)
        Fill rate 50 (promedio del porcentaje entregado de cada pedido cerrado),
        auditoría de proveedor 15, documentación 15 (todo o nada: todos los
        obligatorios aprobados y vigentes), reclamos 10 (menos 1 por reclamo del
        último año) y auditoría de recepción 10. Una auditoría que no se hizo se
        puntúa completa por defecto y la nota queda marcada como no verificada.
        TEXTO;
    }
}
