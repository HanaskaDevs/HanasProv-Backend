<?php

namespace Database\Seeders;

use App\Modules\Auditorias\Models\AuditoriaPregunta;
use App\Modules\Auditorias\Models\AuditoriaSeccion;
use App\Modules\Auditorias\Models\TipoAuditoria;
use Illuminate\Database\Seeder;

/**
 * Catálogo de auditorías (tipos -> secciones -> preguntas con su puntaje
 * máximo). Es idempotente: se puede correr varias veces sin duplicar nada
 * (usa firstOrCreate/updateOrCreate en base al Nombre / Numero).
 *
 * Tipo "Proveedores": 9 secciones, preguntas 1-59, Puntaje Total Posible =
 * 590 (coincide con el resumen de la hoja original).
 *
 * Tipo "Proveedores Mataderos": 5 secciones, preguntas 1-64, Puntaje Total
 * Posible = 500. Algunas preguntas tienen "Subseccion" (ej. "Corrales de
 * Recepción" dentro de "Mantenimiento e Instalaciones") -> es solo una
 * etiqueta de agrupación visual, no tiene puntaje propio ni afecta el
 * cálculo.
 */
class AuditoriaCatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrarTipo('Proveedores', 1, [
            [
                'nombre' => 'Auditoría del Sistema de Gestión de Calidad',
                'preguntas' => [
                    [1, 'Manual de BPMs', 20],
                    [2, 'Plan de HACCP', 10],
                    [3, 'Procedimiento de Higiene del Personal', 20],
                    [4, 'Procedimiento de Control de Plagas', 15],
                    [5, 'Procedimientos de Limpieza', 20],
                    [6, 'Procedimiento de Control de Químicos', 15],
                    [7, 'Sistemas de Rastreabilidad', 15],
                    [8, 'Certificado de Envases Aprobados', 15],
                ],
            ],
            [
                'nombre' => 'Auditoría de BPMs',
                'preguntas' => [
                    [10, 'Mantiene permiso de funcionamiento actualizado', 15],
                    [11, 'Las superficies y materiales que están en contacto con los alimentos no son tóxicos, materiales desprendibles, óxido y no son de madera. Además son fáciles de limpiar y desinfectar.', 15],
                    [12, 'Los pisos, paredes y techos permiten una adecuada limpieza.', 10],
                    [13, 'Los drenajes están cubiertos y permiten una adecuada limpieza.', 5],
                    [14, 'Las ventanas están cerradas y sin huecos para evitar la entrada de plagas, polvo u otros contaminantes', 5],
                    [15, 'Las puertas evitan la entrada de plagas, polvo u otros contaminantes al área de procesamiento.', 5],
                    [16, 'En las áreas críticas las uniones entre pared y pisos son cóncavas.', 5],
                    [17, 'Las lámparas que están suspendidas sobre los alimentos tienen una protección en caso de rotura.', 15],
                    [18, 'Existen suficientes servicios higiénicos, duchas y vestuarios para mantener una adecuada higiene personal de los empleados.', 10],
                    [19, 'Hay lavamanos en el área de producción, con dispensadores de jabón y desinfectante de manos, e implementos para el secado de manos.', 10],
                    [20, 'Hay carteles o avisos de la obligatoriedad del lavado de manos.', 5],
                    [21, 'El área de procesamiento tiene un flujo hacia delante evitando contaminaciones cruzadas.', 15],
                ],
            ],
            [
                'nombre' => 'Control de Desechos y de Pestes',
                'preguntas' => [
                    [22, 'Los basureros están ubicados en lugares donde no provocarán contaminación de los alimentos.', 10],
                    [23, 'Los basureros están cubiertos con funda plástica, tienen tapa y son evacuados con la frecuencia necesaria para evitar contaminaciones.', 5],
                    [24, 'Los contenedores de basura industriales están en el exterior.', 5],
                    [25, 'Materias primas, productos y material de empaque están libres de plagas.', 15],
                    [26, 'Las áreas de almacenamiento y producción están libres de plagas.', 10],
                ],
            ],
            [
                'nombre' => 'Equipos y Utensilios',
                'preguntas' => [
                    [27, 'Los equipos y utensilios que tienen contacto con alimentos son de materiales no tóxicos, que no reaccionan, no transmiten olores al alimento y no son corrosivos.', 15],
                    [28, 'Los equipos y utensilios permiten una fácil limpieza y desinfección.', 15],
                    [29, 'Los equipos que requieren, tienen los instrumentos para su operación, control y mantenimiento. (Ejm: termómetros).', 5],
                    [30, 'Los termómetros no son de vidrio ni de mercurio.', 10],
                    [31, 'Existe un sistema de calibración de equipos, maquinarias e instrumentos de control. Hay registros de la Calibración.', 5],
                ],
            ],
            [
                'nombre' => 'Personal',
                'preguntas' => [
                    [32, 'El personal ha recibido capacitación en Higiene y Seguridad Alimentaria. Hay registros de la capacitación.', 10],
                    [33, 'El personal utiliza uniformes adecuados (cofias, zapatos cerrados, vestimenta o delantales) y limpios para las operaciones productivas.', 5],
                    [34, 'El personal se lava y desinfecta las manos antes de comenzar a trabajar, cada vez que sale y regresa al área de producción, después de ir al baño, después de manipular un material que es un riesgo para contaminar el alimento, al cambiar de actividad.', 15],
                    [35, 'El personal utiliza mascarilla u otros tipos de protección (guantes) en sitios de procesamiento donde el alimento ya no recibirá tratamiento térmico.', 5],
                    [36, 'El personal mantiene el cabello cubierto con cofias o gorras.', 5],
                    [37, 'El personal mantiene uñas cortas y sin esmalte, no usa joyas, no usa maquillaje, ni usa barba ni bigote descubierto en el área de producción.', 5],
                    [38, 'El personal no come, bebe, mastica chicle o fuma en las áreas de producción.', 5],
                    [39, 'Las pertenencias personales de los empleados no se guardan en las áreas de producción y empaque.', 5],
                    [40, 'Los visitantes entran al área de producción con las debidas protecciones.', 5],
                    [41, 'El personal que manipula alimentos tiene certificados de salud actualizados, no presentan síntomas de enfermedades infectocontagiosas ni tiene heridas.', 10],
                ],
            ],
            [
                'nombre' => 'Materias Primas, Operaciones y Productos',
                'preguntas' => [
                    [42, 'Existe alguna especificación de las materias primas. Se controla las materias primas que se reciben.', 5],
                    [43, 'El área de recepción de materias primas está separada del área de producción.', 5],
                    [44, 'El alimento elaborado cumple con las especificaciones correspondientes.', 5],
                    [45, 'Se controla los procesos de tiempo y temperatura para asegurar su efectividad.', 10],
                ],
            ],
            [
                'nombre' => 'Limpieza y Desinfección',
                'preguntas' => [
                    [46, 'Las superficies que tienen contacto con alimentos están limpias.', 15],
                    [47, 'Las superficies que no tienen contacto con alimentos están limpias.', 10],
                    [48, 'Los químicos utilizados para la limpieza y desinfección están aprobadas para el uso en plantas de alimentos. Se tiene la ficha técnica de los mismos.', 15],
                ],
            ],
            [
                'nombre' => 'Envasado y Etiquetado',
                'preguntas' => [
                    [49, 'Los empaques utilizados son inocuos y apropiados para alimentos.', 15],
                    [50, 'Los alimentos envasados tienen identificación codificada que permita conocer el número de lote, la fecha de producción e identificación del fabricante.', 5],
                    [51, 'La etiqueta determina la fecha de caducidad del producto.', 5],
                ],
            ],
            [
                'nombre' => 'Almacenamiento, Transporte y Distribución',
                'preguntas' => [
                    [52, 'Las materias primas, productos, insumos y material de empaque están almacenados a 30 cm del piso.', 10],
                    [53, 'Las materias primas, productos, insumos y material de empaque están almacenados de manera adecuada para evitar la contaminación cruzada entre ellos.', 15],
                    [54, 'Los químicos están almacenados separados de los alimentos y fuera del área de producción.', 15],
                    [55, 'El almacenamiento mantiene la cadena de frío cuando lo requiere.', 15],
                    [56, 'Hay registros de la temperatura de los cuartos fríos, o área de procesamiento (en caso de estar refrigerados).', 5],
                    [57, 'El área de almacenamiento está limpio.', 10],
                    [58, 'La rotación de las materias primas y productos se realiza de acuerdo al sistema PEPS.', 5],
                    [59, 'Se transportan los alimentos manteniendo las condiciones sanitarias y la temperatura establecida para garantizar la conservación de la calidad del producto.', 15],
                ],
            ],
        ]);

        $this->sembrarTipo('Proveedores Mataderos', 2, [
            [
                'nombre' => 'Control de Plagas',
                'preguntas' => [
                    [1, 'Existe un programa establecido de control de plagas. El operador de control de plagas tiene licencia, está asegurado y cuenta con personal capacitado para esta función.', 20],
                    [2, 'Todos los pesticidas están debidamente etiquetados y almacenados.', 15],
                    [3, 'Los reportes de servicio del controlador de plagas se encuentran al corriente e incluyen los productos y dosificaciones utilizadas por áreas. Se encuentran al corriente y existe un reporte de tendencia.', 15],
                    [4, 'No hay evidencia de actividad de plagas INTERNA.', 15],
                    [5, 'No hay evidencia de actividad de plagas EXTERNA', 15],
                    [6, 'La localización interna y externa y el número de los implementos del control de plagas previene posibles contaminaciones de producto, materiales de empaque o equipo.', 10],
                    [7, 'Existen barreras físicas en el perímetro', 10],
                ],
            ],
            [
                'nombre' => 'Limpieza y Sanitización',
                'preguntas' => [
                    [8, 'Existe un programa maestro de limpieza y sanitización que incluya prácticas y procedimientos de limpieza', 15],
                    [9, 'Existe un programa escrito de entrenamiento en limpieza y sanitización', 10],
                    [10, 'Las herramientas utilizadas para la limpieza son suficientes, de plástico duro o de acero inoxidable.', 15],
                    [11, 'Se cuenta con recipientes identificados en cada área para depositar desperdicios de la matanza', 10],
                    [12, 'El agua que se utiliza para la limpieza es a presión.', 10],
                    [13, 'Están aprobados los químicos usados para limpieza, y están almacenados adecuadamente.', 10],
                    [14, 'Están visiblemente limpias las instalaciones y todas las áreas que NO que no estén en uso.', 10],
                    [15, 'Se llevan a cabo y están documentadas, inspecciones operativas visuales para confirmar que las instalaciones estén limpias. No existe evidencia de que la limpieza es inefectiva.', 10],
                    [16, 'Existen y se llevan a cabo, cuando es necesario, acciones correctivas para mostrar un mejor desempeño contantemente.', 10],
                ],
            ],
            [
                'nombre' => 'Mantenimiento e Instalaciones',
                'preguntas' => [
                    [17, 'Existe un programa de mantenimiento relacionado con las instalaciones para asegurar condiciones aceptables de funcionamiento.', 10, 'Mantenimiento'],
                    [18, 'En la infraestructura I tipo de reparación que se tiene no es temporal (PE. Cuerdas no aprobadas, sogas, alambres, látex, etc).', 10, 'Mantenimiento'],
                    [19, 'El flujo de aire en las instalaciones es adecuado sin olores o contaminantes de aire que podrían pasarse al producto.', 5, 'Mantenimiento'],
                    [20, 'Cuentan con pisos antiresbaladizos de cemento, con buenos desniveles y sistema de desagüe.', 5, 'Corrales de Recepción'],
                    [21, 'Tienen todos los corrales grifos de agua con buen caudal y presión para facilitar una buena limpieza.', 5, 'Corrales de Recepción'],
                    [22, 'Los corrales de cerdos están techados para evitar el estrés de los cerdos allí depositados.', 5, 'Corrales de Recepción'],
                    [23, 'Los corrales no están sobre poblados permitiendo un movimiento adecuado de los animales.', 5, 'Corrales de Recepción'],
                    [24, 'Se usan láminas de acero inoxidable, hierro galvanizado, fibra de vidrio o PVC para la construcción de los techos de las diferentes áreas.', 5, 'Paredes y Techos'],
                    [25, 'Las paredes están hechas de materiales que se pueden limpiar y mantener limpios, en el caso de azulejos las uniones deben estar rellenas para evitar acumulación de residuos.', 5, 'Paredes y Techos'],
                    [26, 'Las paredes de las áreas y las cámaras de frío tienen protectores construidos con tubos de hierro galvanizado rellenos de cemento, o de acero inoxidable para evitar el deterioro por posibles golpes con los carros.', 5, 'Paredes y Techos'],
                    [27, 'Las uniones entre paredes y de éstas con el piso, tienen ángulo sanitario, eliminando los ángulos rectos.', 5, 'Paredes y Techos'],
                    [28, 'Se encuentran recubiertas con laminas de protección que eviten que en caso de ruptura caigan pedazos de vidrio u otro material sobre las áreas y canales.', 5, 'Ventanas'],
                    [29, 'Se cuenta con un sistema de extracción de aire y gases utilizando extractores industriales especialmente diseñados.', 5, 'Ventanas'],
                    [30, 'Están construidos con materiales resistentes y antiresbaladizos.', 5, 'Pisos'],
                    [31, 'Los pisos de áreas están diseñados con declives para evitar estancamiento de líquidos, que faciliten el fácil drenaje y limpieza.', 5, 'Pisos'],
                    [32, 'Están elaborados con acero inoxidable o hierro fundido y contienen rejillas que retengan partículas sólidas para evitar posibles obstrucciones de las cañerías.', 5, 'Desagües'],
                    [33, 'Todas las áreas de depósito y proceso cuentan con suficientes fuentes de luz, para facilitar las tareas operativas a cualquier hora del día y permitir ver los contaminantes de las reses como materias fecales, pelos, etc.', 5, 'Iluminación'],
                    [34, 'Todos los portalámparas, están protegidos de acrílico para evitar la caída de lámparas o tubos sobre las materias primas y los operadores.', 5, 'Iluminación'],
                ],
            ],
            [
                'nombre' => 'Buenas Prácticas de Manufactura',
                'preguntas' => [
                    [35, 'Existe un manual y un programa de buenas prácticas de manufactura, incluyendo visitantes y programas de entrenamiento.', 15],
                    [36, 'Las instalaciones cuentan con agua potable y todos los implementos necesarios para el aseo del personal.', 15],
                    [37, 'Los empleados respetan normas de higiene', 10],
                    [38, 'Existe establecida una política sobre uniformes.', 10],
                    [39, 'Los objetos personales son almacenados fuera del área de proceso.', 10],
                    [40, 'No hay personal enfermo laborando en la zona de proceso.', 10],
                    [41, 'Las estaciones de lavado de manos son adecuadas y están en uso.', 10],
                    [42, 'Existen señalamientos respaldando el lavado de manos en buen estado y en el lugar correcto.', 10],
                    [43, 'Las áreas de trabajo están ordenadas, con herramientas e implementos de trabajo debidamente almacenados.', 10],
                ],
            ],
            [
                'nombre' => 'Operaciones de Producción y Transporte',
                'preguntas' => [
                    [44, 'Se emplean camiones adecuados con separadores para evitar caída y pisoteo de los animales', 5, 'Transporte al matadero'],
                    [45, 'El transporte se hace preferentemente en horarios en que la temperatura es menor', 5, 'Transporte al matadero'],
                    [46, 'Si el camino es muy largo se ducha a los animales en un descanso en el camino', 5, 'Transporte al matadero'],
                    [47, 'Los corrales de recepción del ganado son higiénicos, con disponibilidad de agua abundante', 5, 'Transporte al matadero'],
                    [48, 'Se realiza un examen cuidadoso de todos los animales vivos que ingresan a la playa de matanza', 5, 'Inspección Antemortem'],
                    [49, 'Se cuenta con instalaciones para el resguardo de animales sospechosos, hasta que el veterinario responsable autorice su matanza.', 5, 'Inspección Antemortem'],
                    [50, 'Los animales son bañados con aspersores de agua fría colocados en la rampa de ingreso.', 5, 'Duchazo al ingreso'],
                    [51, 'Para la insensibilización en vacunos, se emplea una pistola neumática o accionada con fulminante', 5, 'Insensibilización'],
                    [52, 'Se procura que el tiempo entre la insensibilización del animal y el degollado sea mínimo', 5, 'Insensibilización'],
                    [53, 'El tiempo de desangrado es el suficiente para asegurar la eliminación de la mayor cantidad de sangre', 5, 'Desangrado'],
                    [54, 'Se lavan las canales con una manguera de alta presión con agua fría, para eliminar el aserrín del corte y disminuir la temperatura de las canales.', 5, 'Lavado e inspección de las canales'],
                    [55, 'Las canales se orean para que siga bajando la temperatura y luego se introducen en una cámara de frío', 5, 'Lavado e inspección de las canales'],
                    [56, 'Las canales permanecen por lo menos ocho horas en cámara fría, debiendo alcanzar una temperatura de 4°C en el interior de los músculos', 5, 'Lavado e inspección de las canales'],
                    [57, 'Se separa vísceras rojas (corazón, riñones, pulmones, médulas, tráqueas y estómagos de cerdo) de las vísceras llamadas verdes (intestinos, estómagos de las demás especies)', 2.5, 'Tratamiento de las Vísceras'],
                    [58, 'Para la limpieza de las vísceras se cuenta con mesas de vísceras, construidas en acero inoxidable y duchas con abundante agua fría. El agua debe correr.', 2.5, 'Tratamiento de las Vísceras'],
                    [59, 'Se aplica enfriamiento de las canales inmediatamente terminado el proceso de faenamiento.', 5, 'Transporte de canales'],
                    [60, 'El interior de las cajas del transporte está construido de materiales fácilmente lavables como acero inoxidable, fibra de vidrio, aluminio o chapa galvanizada', 5, 'Transporte de canales'],
                    [61, 'Cuenta el transporte con rieles para el colgado de las canales y con separación suficiente entre ellos y estar a la altura necesaria para evitar que las canales hagan contacto con el piso', 5, 'Transporte de canales'],
                    [62, 'Las menudencias y productos ajenos a las canales se transportan en recipientes cerrados y correctamente identificados', 5, 'Transporte de canales'],
                    [63, 'El transporte cuenta con equipo equipo de frío que mantenga las condiciones adecuadas de refrigeración', 5, 'Transporte de canales'],
                    [64, 'Se lava y desinfecta el camión antes y después de cada transporte', 5, 'Transporte de canales'],
                ],
            ],
        ]);
    }

    protected function sembrarTipo(string $nombre, int $ordenTipo, array $secciones): void
    {
        $tipo = TipoAuditoria::firstOrCreate(
            ['Nombre' => $nombre],
            ['Orden' => $ordenTipo, 'Activo' => true]
        );

        foreach ($secciones as $ordenSeccion => $datosSeccion) {
            $seccion = AuditoriaSeccion::updateOrCreate(
                ['Id_Tipo_Auditoria' => $tipo->Id_Tipo_Auditoria, 'Nombre_Seccion' => $datosSeccion['nombre']],
                ['Orden' => $ordenSeccion + 1, 'Activo' => true]
            );

            foreach ($datosSeccion['preguntas'] as $ordenPregunta => $item) {
                [$numero, $descripcion, $puntajeMax, $subseccion] = array_pad($item, 4, null);

                AuditoriaPregunta::updateOrCreate(
                    ['Id_Auditoria_Seccion' => $seccion->Id_Auditoria_Seccion, 'Numero' => $numero],
                    [
                        'Subseccion' => $subseccion,
                        'Descripcion' => $descripcion,
                        'Puntaje_Max' => $puntajeMax,
                        'Orden' => $ordenPregunta + 1,
                        'Activo' => true,
                    ]
                );
            }
        }
    }
}