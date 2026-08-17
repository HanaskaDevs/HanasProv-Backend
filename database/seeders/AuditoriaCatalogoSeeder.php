<?php

namespace Database\Seeders;

use App\Modules\Auditorias\Models\AuditoriaPregunta;
use App\Modules\Auditorias\Models\AuditoriaSeccion;
use App\Modules\Auditorias\Models\TipoAuditoria;
use App\Modules\Auditorias\Models\TipoAuditoriaClase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de auditorías (tipos -> secciones -> preguntas con su puntaje
 * máximo). Es idempotente: se puede correr varias veces sin duplicar nada
 * (usa updateOrCreate en base al Nombre / Numero).
 *
 * Agosto 2026 (pedido de Isak): los 2 tipos que había antes ("Proveedores"
 * 590 pts y "Proveedores Mataderos" 500 pts) no coincidían con los
 * formularios reales que usa el equipo de Calidad -> se DESACTIVAN (no se
 * borran, por si ya hay auditorías reales hechas contra ellos) y se
 * reemplazan por los 4 formularios oficiales, con el contenido EXACTO
 * (secciones, subsecciones, preguntas y puntaje máximo) de los archivos:
 *
 * - "Auditoria para Calificación de Proveedores Centros de Faenamiento"
 *   (FGH04.15.10-2): 6 secciones, 80 preguntas, 700 puntos.
 * - "Auditoria para Calificación de Proveedores Comerciantes"
 *   (FGH04.15.23): 7 secciones, 43 preguntas, 520 puntos.
 * - "Auditoria para Calificación de Proveedores Fabricantes Procesadores"
 *   (FGH04.15.09-3): 11 secciones, 84 preguntas, 925 puntos.
 * - "Auditoria para Calificación de Proveedores Productor Agrícola"
 *   (FGH04.15.13-2): 7 secciones, 55 preguntas, 750 puntos.
 *
 * Todos los totales de puntaje de cada tipo fueron verificados sumando el
 * Puntaje_Max de cada pregunta y comparando contra el "Puntaje Total
 * Posible" impreso en la hoja original.
 *
 * También siembra Tipo_Auditoria_Clase: qué Clase(s) de Proveedor
 * corresponden a cada tipo -> el wizard de auditorías (ver
 * AuditoriaService::listarProveedoresParaAuditoria) lo usa para sugerir
 * automáticamente el proveedor correcto según el tipo elegido.
 */
class AuditoriaCatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $this->desactivarTiposViejos();

        $this->sembrarTipo('Centros de Faenamiento', 1, [
            [
                'nombre' => 'Control de Plagas',
                'preguntas' => [
                    [1, 'Existe un programa documentado de Manejo Integrado de Plagas, ejecutado por un operador con licencia vigente, asegurado y con personal capacitado; el programa define responsables, métodos, productos autorizados y frecuencias.', 20],
                    [2, 'Todos los plaguicidas están registrados, debidamente etiquetados y almacenados de forma segura y separada, con fichas técnicas y hojas de seguridad disponibles.', 15],
                    [3, 'Los reportes de servicio del control de plagas están al día e incluyen productos, dosis y áreas de aplicación; se mantiene y analiza un reporte de tendencias, con acciones ante desviaciones.', 15],
                    [4, 'No existe evidencia de actividad de plagas en el interior de las instalaciones, verificado mediante inspección documentada.', 15],
                    [5, 'No existe evidencia de actividad de plagas en el exterior ni en el perímetro de las instalaciones, verificado mediante inspección documentada.', 15],
                    [6, 'La ubicación (interna y externa) y el número de los dispositivos de control de plagas están definidos en un plano, previenen la contaminación del producto, del material de empaque y del equipo, y se revisan periódicamente.', 10],
                    [7, 'El perímetro cuenta con barreras físicas que impiden el ingreso de plagas y de fauna, con inspección y mantenimiento registrados.', 10],
                ],
            ],
            [
                'nombre' => 'Limpieza y Sanitización',
                'preguntas' => [
                    [8, 'Existe un programa maestro de limpieza y sanitización (POES) que define, por área y equipo, el método, los químicos, la concentración, la frecuencia, el responsable y los registros.', 15],
                    [9, 'Existe un programa escrito de capacitación en limpieza y sanitización, con registros de ejecución y verificación de competencias.', 10],
                    [10, 'Las herramientas de limpieza son suficientes, de material no absorbente (plástico duro o acero inoxidable), están identificadas por área o zona de riesgo (código de colores) para evitar la contaminación cruzada, y se almacenan limpias.', 15],
                    [11, 'Cada área cuenta con recipientes identificados y de uso exclusivo para depositar los desperdicios y subproductos de la matanza.', 10],
                    [12, 'El agua utilizada para la limpieza cumple los requisitos definidos (potable, a presión y, cuando aplique, temperatura) según el procedimiento.', 10],
                    [13, 'Los químicos de limpieza y desinfección están aprobados para uso en plantas de alimentos, se dosifican de forma controlada y se almacenan de manera segura y separada del producto; se dispone de sus fichas técnicas.', 10],
                    [14, 'Las instalaciones, los equipos y las áreas incluidas las que no están en uso se encuentran visiblemente limpias.', 10],
                    [15, 'Se realizan y documentan inspecciones (pre-operacionales y operacionales) que confirman la limpieza de instalaciones y equipos antes del inicio; no hay evidencia de limpieza inefectiva.', 10],
                    [16, 'Se verifica la eficacia de la limpieza y sanitización (inspección visual y, cuando aplique, verificación microbiológica de superficies), se monitorea su desempeño y se toman acciones correctivas para la mejora continua.', 10],
                ],
            ],
            [
                'nombre' => 'Mantenimiento e Instalaciones',
                'preguntas' => [
                    [17, 'Existe un programa de mantenimiento preventivo de instalaciones y equipos que asegura condiciones de funcionamiento aptas para la inocuidad, con registros.', 10, 'Mantenimiento'],
                    [18, 'Las reparaciones son definitivas y con materiales aptos; no se emplean soluciones temporales o no aprobadas (cuerdas, sogas, alambres, cintas, látex) en zonas de producto.', 10, 'Mantenimiento'],
                    [19, 'El flujo y la calidad del aire en las instalaciones son adecuados, sin olores ni contaminantes que puedan transferirse al producto; cuando aplica, existe control de presión o ventilación entre áreas.', 5, 'Mantenimiento'],
                    [20, 'Los corrales cuentan con pisos antideslizantes de cemento, con desniveles adecuados y sistema de desagüe.', 5, 'Corrales de Recepción'],
                    [21, 'Todos los corrales disponen de grifos de agua con caudal y presión suficientes para una limpieza eficaz.', 5, 'Corrales de Recepción'],
                    [22, 'Los corrales cuentan con techado y condiciones que minimizan el estrés de los animales (relevante para el bienestar animal y la calidad de la carne).', 5, 'Corrales de Recepción'],
                    [23, 'Los corrales no están sobrepoblados y permiten el movimiento adecuado de los animales.', 5, 'Corrales de Recepción'],
                    [24, 'Los techos de las distintas áreas se construyen con materiales aptos y limpiables (acero inoxidable, hierro galvanizado, fibra de vidrio o PVC).', 5, 'Paredes y Techos'],
                    [25, 'Las paredes son de materiales limpiables y se mantienen limpias; en el caso de azulejos, las uniones están rellenas para evitar la acumulación de residuos.', 5, 'Paredes y Techos'],
                    [26, 'Las paredes de las áreas y de las cámaras de frío cuentan con protectores (tubos de hierro galvanizado rellenos de cemento o de acero inoxidable) que evitan el deterioro por golpes.', 5, 'Paredes y Techos'],
                    [27, 'Las uniones entre paredes, y entre pared y piso, tienen ángulo sanitario (media caña), eliminando los ángulos rectos.', 5, 'Paredes y Techos'],
                    [28, 'Las ventanas están recubiertas con láminas de protección que, en caso de rotura, evitan la caída de vidrio u otro material sobre las áreas y canales (integrado a la política de vidrios y quebradizos).', 5, 'Ventanas'],
                    [29, 'Se cuenta con un sistema de extracción de aire y gases mediante extractores industriales diseñados para ese fin.', 5, 'Ventanas'],
                    [30, 'Están construidos con materiales resistentes y antiresbaladizos.', 5, 'Pisos'],
                    [31, 'Los pisos de áreas están diseñados con declives para evitar estancamiento de líquidos, que faciliten el fácil drenaje y limpieza.', 5, 'Pisos'],
                    [32, 'Los desagües son de acero inoxidable o hierro fundido y cuentan con rejillas que retienen sólidos para evitar obstrucciones; el diseño evita el reflujo hacia las áreas.', 5, 'Desagües'],
                    [33, 'Todas las áreas de depósito y proceso cuentan con suficientes fuentes de luz, para facilitar las tareas operativas a cualquier hora del día y permitir ver los contaminantes de las reses como materias fecales, pelos, etc.', 5, 'Iluminación'],
                    [34, 'Todas las luminarias sobre las líneas de producto están protegidas (acrílico o antiestallido) para evitar la caída de vidrio sobre el producto o los operarios.', 5, 'Iluminación'],
                ],
            ],
            [
                'nombre' => 'Buenas Prácticas de Manufactura',
                'preguntas' => [
                    [35, 'Existe un manual y un programa de Buenas Prácticas de Manufactura que incluye a los visitantes y un programa de entrenamiento con registros.', 15],
                    [36, 'Las instalaciones cuentan con agua potable y con todos los implementos necesarios para el aseo e higiene del personal.', 15],
                    [37, 'Los empleados cumplen las normas de higiene definidas (lavado de manos, conducta, prohibición de comer o fumar en áreas de proceso) y se verifica su cumplimiento.', 10],
                    [38, 'Existe una política de uniformes e implementos (incluidos su limpieza, cambio y el uso del equipo de protección personal) y se cumple.', 10],
                    [39, 'Los objetos personales se almacenan fuera de las áreas de proceso.', 10],
                    [40, 'Existe una política de salud del personal: los empleados enfermos o con síntomas o heridas expuestas no laboran en contacto con el producto y reportan su condición al supervisor.', 10],
                    [41, 'Las estaciones de lavado y desinfección de manos son suficientes, están equipadas y en uso, ubicadas en los puntos requeridos.', 10],
                    [42, 'Existe señalización visible y en buen estado que refuerza el lavado de manos en los lugares correctos.', 10],
                    [43, 'Las áreas de trabajo están ordenadas, con herramientas e implementos debidamente almacenados e identificados.', 10],
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
                    [55, 'Las canales se orean para que siga bajando la temperatura y luego se introducen en una cámara de frío entre 0 y 5ºC.', 5, 'Lavado e inspección de las canales'],
                    [56, 'Las canales permanecen por lo menos ocho horas en cámara fría, debiendo alcanzar una temperatura de 4ºC en el interior de los músculos', 5, 'Lavado e inspección de las canales'],
                    [57, 'Se separan las vísceras rojas (corazón, riñones, pulmones, hígado, etc.) de las vísceras verdes (estómagos e intestinos) para evitar la contaminación cruzada, en áreas o tiempos diferenciados.', 2.5, 'Tratamiento de las Vísceras'],
                    [58, 'La limpieza de vísceras se realiza en mesas de acero inoxidable, con abundante agua fría corriente y bajo condiciones higiénicas.', 2.5, 'Tratamiento de las Vísceras'],
                    [59, 'Se aplica el enfriamiento de las canales inmediatamente después del faenamiento, respetando la cadena de frío.', 5, 'Enfriamiento y Transporte de canales'],
                    [60, 'El interior de las cajas o unidades de transporte es de materiales fácilmente lavables (acero inoxidable, fibra de vidrio, aluminio o chapa galvanizada).', 5, 'Enfriamiento y Transporte de canales'],
                    [61, 'El transporte cuenta con rieles para el colgado de las canales, con separación y altura suficientes para evitar el contacto con el piso y entre canales.', 5, 'Enfriamiento y Transporte de canales'],
                    [62, 'Las menudencias y los productos distintos de las canales se transportan en recipientes cerrados, identificados y de uso exclusivo.', 5, 'Enfriamiento y Transporte de canales'],
                    [63, 'El transporte cuenta con equipo de frío que mantiene la temperatura de refrigeración definida, con monitoreo y registro.', 5, 'Enfriamiento y Transporte de canales'],
                    [64, 'El vehículo se lava y desinfecta antes y después de cada transporte, con registros.', 5, 'Enfriamiento y Transporte de canales'],
                ],
            ],
            [
                'nombre' => 'Requisitos Adicionales',
                'preguntas' => [
                    [65, 'Sistema HACCP: plan basado en análisis de peligros con puntos críticos de control (PCC) y prerrequisitos operativos definidos, con límites críticos, monitoreo, correcciones y verificación (por ejemplo, control de contaminación fecal, cadena de frío y manejo de decomisos).', 20],
                    [66, 'Inspección veterinaria oficial ante y post mortem documentada, con criterios de dictamen, manejo de decomisos y coordinación con la autoridad sanitaria competente.', 20],
                    [67, 'Criterios microbiológicos de proceso e inocuidad: programa de muestreo de canales y superficies (por ejemplo, Enterobacteriaceae, E. coli genérica y Salmonella) frente a límites definidos, con análisis de tendencias y acciones correctivas.', 20],
                    [68, 'Gestión validada de la cadena de frío: límites de tiempo y temperatura por etapa (oreo, cámara, expedición y transporte), con verificación, registros y acciones ante desviaciones.', 10],
                    [69, 'Manejo de subproductos, materiales especificados de riesgo (MER), decomisos y residuos: segregación, identificación, contención y disposición controlada que evite la contaminación del producto.', 10],
                    [70, 'Control de peligros físicos: política de vidrio y plásticos quebradizos, control de cuchillos, hojas y agujas, y detección de metales cuando aplique, con verificación de funcionamiento.', 5],
                    [71, 'Control de químicos y de residuos de medicamentos veterinarios: gestión de los químicos de planta y verificación del cumplimiento de los LMR de fármacos veterinarios en los animales recibidos.', 20],
                    [72, 'Control de proveedores de animales en pie y de servicios: criterios de aprobación de granjas de origen, transportistas, laboratorios y servicios, con información sanitaria y de trazabilidad del lote de origen.', 10],
                    [73, 'Trazabilidad y retiro de producto: identificación del animal a la canal (un paso atrás y un paso adelante), con capacidad de retiro (recall) probada mediante simulacros.', 20],
                    [74, 'Fraude alimentario: Se ha realizado una evaluación de vulnerabilidad (sustitución de especie, origen y estatus sanitario) y plan de mitigación.', 10],
                    [75, 'Defensa alimentaria: Se ha realizado una evaluación de amenazas y control de acceso a las áreas críticas, al agua, a las cámaras y a la expedición.', 10],
                    [76, 'Se cuenta con un programa de inocuidad alimentaria con objetivos, comunicación, capacitación y medición de indicadores, respaldado por la dirección.', 10],
                    [77, 'Gestión de equipos y diseño higiénico: especificación sanitaria de equipos nuevos, mantenimiento y uso de lubricantes de grado alimentario.', 10],
                    [78, 'Bienestar animal: programa conforme a la normativa aplicable (manejo, aturdimiento eficaz y verificación de la insensibilización), con indicadores y registros.', 10],
                    [79, 'Control del agua y del hielo: potabilidad verificada mediante análisis frente a límites, con plan de muestreo y registros.', 10],
                    [80, 'Gestión del cambio y notificación al cliente, y gestión de quejas, incidentes y no conformidades con análisis de causa raíz y verificación de la eficacia.', 5],
                ],
            ],
        ]);

        $this->sembrarTipo('Comerciantes', 2, [
            [
                'nombre' => 'Programas Prerrequisitos',
                'preguntas' => [
                    [1, 'Existe documentación del sistema de gestión de inocuidad aplicable al almacenamiento y la distribución (responsabilidades, procedimientos y registros); cuando el operador manipula, reenvasa o reacondiciona producto, cuenta con un manual de BPM vigente.', 20],
                    [2, 'Mantiene vigentes el permiso de funcionamiento y las autorizaciones sanitarias aplicables a la actividad de almacenamiento y distribución.', 10],
                    [3, 'Existe un sistema de trazabilidad que permite el seguimiento un paso atrás y un paso adelante por lote, con capacidad de retiro (recall) probada.', 20],
                    [4, 'Se dispone de la documentación de respaldo de los productos que comercializa (fichas técnicas, certificados de análisis y traslado de las certificaciones del fabricante), controlada y vigente.', 15],
                    [5, 'Existe y se cumple un procedimiento de control de sustancias químicas (identificación, almacenamiento segregado y hojas de seguridad).', 20],
                ],
            ],
            [
                'nombre' => 'Instalaciones',
                'preguntas' => [
                    [6, 'Las superficies, pisos, paredes y techos de las áreas de almacenamiento son de materiales limpiables, están en buen estado y permiten una adecuada limpieza.', 15],
                    [7, 'Los drenajes están cubiertos, con diseño sanitario, y permiten una adecuada limpieza.', 15],
                    [8, 'Las ventanas están cerradas o protegidas con malla, sin huecos, para evitar el ingreso de plagas, polvo y contaminantes.', 10],
                    [9, 'Las puertas cierran correctamente y cuentan con barreras (burletes, cortinas o mallas) que evitan el ingreso de plagas y contaminantes.', 10],
                    [10, 'Las luminarias sobre las zonas de producto están protegidas (antiestallido), conforme a la política de vidrios y quebradizos.', 10],
                    [11, 'Existen servicios higiénicos y áreas para el personal en condiciones sanitarias, separados de las zonas de producto.', 5],
                    [12, 'Existen lavamanos e implementos de higiene disponibles y en uso, con señalización que refuerza el lavado de manos.', 10],
                    [13, 'Las áreas de almacenamiento están diseñadas para separar los productos por tipo y nivel de riesgo (alimentos, químicos, no conformes y devoluciones).', 15],
                ],
            ],
            [
                'nombre' => 'Personal de Almacén',
                'preguntas' => [
                    [14, 'El personal usa ropa de trabajo limpia y adecuada, y el equipo de protección personal requerido para la operación.', 10],
                    [15, 'El personal se lava y desinfecta las manos según el procedimiento cuando manipula producto o al cambiar de actividad.', 10],
                    [16, 'El personal mantiene higiene (uñas cortas, sin joyas ni maquillaje) y cubrecabello cuando manipula producto expuesto.', 10],
                    [17, 'Existe una política de salud del personal: quienes presentan enfermedad transmisible, síntomas o heridas expuestas no manipulan producto.', 15],
                    [18, 'El personal no come, bebe, masca chicle ni fuma en las áreas de almacenamiento de producto.', 10],
                    [19, 'Las pertenencias personales se guardan fuera de las áreas de producto.', 10],
                    [20, 'Los visitantes y contratistas ingresan con las protecciones requeridas y bajo las normas de higiene, con registro.', 5],
                    [21, 'El personal ha recibido capacitación en higiene, manipulación e inocuidad, con registros.', 15],
                ],
            ],
            [
                'nombre' => 'Control de Desechos y de Pestes',
                'preguntas' => [
                    [22, 'Los recipientes de desechos están identificados, cubiertos y se evacúan con la frecuencia definida para evitar la contaminación.', 10],
                    [23, 'Existe un programa de control de plagas con dispositivos mapeados, monitoreo y reportes de tendencia con acciones correctivas.', 5],
                    [24, 'No hay evidencia de actividad de plagas en las áreas de almacenamiento, verificado y registrado.', 5],
                    [25, 'Los productos y el material de empaque se mantienen protegidos y libres de plagas.', 15],
                ],
            ],
            [
                'nombre' => 'Recepción, Almacenamiento, Cadena de Frío y Distribución',
                'preguntas' => [
                    [26, 'La recepción incluye controles definidos (integridad del empaque, documentación, identificación de lote y fecha, temperatura cuando aplica y condiciones del vehículo), con criterios de aceptación o rechazo y registros.', 15],
                    [27, 'Los productos se almacenan separados del piso (mínimo definido) y de las paredes, con identificación de lote y fecha.', 15],
                    [28, 'El almacenamiento evita la contaminación cruzada entre productos, entre alimentos y químicos, y entre crudo y procesado o alérgenos, mediante una segregación definida.', 10],
                    [29, 'Se mantiene y verifica la cadena de frío cuando el producto lo requiere, con límites de temperatura por producto, monitoreo y registro.', 5],
                    [30, 'Se registran las temperaturas de las cámaras y áreas refrigeradas, con verificación o alarmas y acciones ante desviaciones.', 10],
                    [31, 'La rotación se realiza por FIFO/FEFO y se controla la vida útil; los productos vencidos o no conformes se segregan e identifican.', 10],
                    [32, 'Los vehículos de distribución están limpios, en buen estado, permiten mantener la temperatura definida y se verifican antes de la carga.', 10],
                    [33, 'La carga y la estiba evitan el daño y la contaminación; no se transportan cargas incompatibles ni sustancias peligrosas junto al alimento.', 5],
                ],
            ],
            [
                'nombre' => 'Limpieza y Desinfección',
                'preguntas' => [
                    [34, 'Las áreas, estanterías y equipos de manejo (montacargas, transpaletas) se mantienen limpios según el programa de limpieza y desinfección.', 15],
                    [35, 'Los químicos de limpieza y desinfección están aprobados para uso en plantas de alimentos, se dosifican de forma controlada y cuentan con ficha técnica.', 10],
                    [36, 'Los químicos se almacenan identificados y segregados de los productos alimenticios.', 15],
                ],
            ],
            [
                'nombre' => 'Requisitos Adicionales',
                'preguntas' => [
                    [37, 'Se tiene una evaluación de vulnerabilidad sobre la autenticidad, el origen y la adulteración de los productos comercializados, con plan de mitigación.', 15],
                    [38, 'Se cuenta con controles de acceso y custodia del producto en bodega y transporte.', 15],
                    [39, 'Trazabilidad y retiro de producto: sistema probado mediante simulacros, con un paso atrás y un paso adelante.', 15],
                    [40, 'Se tiene una segregación en el almacenamiento e integridad de la etiqueta del fabricante acorde a su riesgo alergénico.', 15],
                    [41, 'Verificación de etiquetado, lo distribuido cumple la normativa (fechas, lote, alérgenos e identificación del fabricante).', 15],
                    [42, 'Documentación de respaldo: fichas técnicas, certificados de análisis (CoA) y traslado de las certificaciones del fabricante, controladas y vigentes.', 20],
                    [43, 'Notificación de cambios de fabricante u origen que afecten la inocuidad o la legalidad del producto.', 10],
                ],
            ],
        ]);

        $this->sembrarTipo('Fabricantes Procesadores', 3, [
            [
                'nombre' => 'Programas Prerrequisitos',
                'preguntas' => [
                    [1, 'Existe un sistema de gestión de inocuidad implementado (política, objetivos, responsabilidades y compromiso de la dirección), con un manual de Buenas Prácticas de Manufactura vigente y controlado.', 20],
                    [2, 'Existe un plan HACCP basado en el análisis de peligros, con diagrama de flujo validado, PCC y prerrequisitos operativos definidos, límites críticos, monitoreo, correcciones y verificación; está actualizado y con registros.', 10],
                    [3, 'Existe y se cumple un procedimiento documentado de higiene del personal, con registros de capacitación y verificación.', 20],
                    [4, 'Existe y se cumple un programa documentado de control integrado de plagas, con responsables, mapa de dispositivos y registros.', 15],
                    [5, 'Existe y se cumple un programa maestro de limpieza y desinfección (POES) por área y equipo, con verificación de su eficacia.', 20],
                    [6, 'Existe y se cumple un procedimiento de control de sustancias químicas (aprobación, identificación, almacenamiento segregado y hojas de seguridad).', 15],
                    [7, 'Existe un sistema de trazabilidad que permite el seguimiento un paso atrás y un paso adelante por lote, con capacidad de retiro probada.', 15],
                    [8, 'Se dispone de la documentación que respalda la aptitud de los materiales de empaque en contacto con alimentos (certificados o declaraciones de conformidad y aptitud sanitaria).', 15],
                ],
            ],
            [
                'nombre' => 'Instalaciones',
                'preguntas' => [
                    [9, 'Mantiene vigentes el permiso de funcionamiento y los registros o licencias sanitarias aplicables.', 15],
                    [10, 'Las superficies y materiales en contacto con los alimentos son no tóxicos, no absorbentes, resistentes a la corrosión, libres de pintura o materiales desprendibles y no son de madera; son fáciles de limpiar y desinfectar.', 15],
                    [11, 'Los pisos, paredes y techos son de materiales limpiables, están en buen estado y permiten una adecuada limpieza y desinfección.', 10],
                    [12, 'Los drenajes están cubiertos, tienen diseño sanitario (sifón o rejilla), fluyen de la zona limpia a la sucia y permiten una adecuada limpieza.', 5],
                    [13, 'Las ventanas están cerradas o protegidas con malla, sin huecos, para evitar el ingreso de plagas, polvo y contaminantes.', 5],
                    [14, 'Las puertas cierran correctamente y cuentan con barreras (burletes, cortinas o mallas) que evitan el ingreso de plagas y contaminantes a las áreas de proceso.', 5],
                    [15, 'En las áreas críticas, las uniones entre paredes y entre pared y piso son cóncavas (media caña), eliminando los ángulos rectos.', 5],
                    [16, 'Las luminarias suspendidas sobre las líneas de producto están protegidas (antiestallido) para evitar la caída de vidrio en caso de rotura, conforme a la política de vidrios y quebradizos.', 15],
                    [17, 'Existen servicios higiénicos, duchas y vestuarios suficientes y en condiciones sanitarias, separados de las áreas de proceso.', 10],
                    [18, 'Existen lavamanos de accionamiento no manual en las áreas de proceso, dotados de jabón, desinfectante e implementos de secado.', 10],
                    [19, 'Existe señalización visible que refuerza el lavado de manos en los puntos requeridos.', 5],
                    [20, 'El flujo de proceso es hacia adelante y existe separación (física o temporal) entre zonas de distinto nivel de riesgo para evitar la contaminación cruzada.', 15],
                ],
            ],
            [
                'nombre' => 'Control de Desechos y de Pestes',
                'preguntas' => [
                    [21, 'Los recipientes de desechos están identificados, cubiertos, con tapa de accionamiento no manual y funda, y se evacúan con la frecuencia definida para evitar la contaminación.', 10],
                    [22, 'Los recipientes y contenedores de desechos se ubican de forma que no contaminan el producto, y los contenedores industriales están en el exterior en un área controlada.', 5],
                    [23, 'Existe un programa de control de plagas con dispositivos mapeados, monitoreo y reportes de tendencia con acciones correctivas.', 5],
                    [24, 'No hay evidencia de actividad de plagas en las áreas internas de almacenamiento y producción, verificado y registrado.', 15],
                    [25, 'Las materias primas, los productos y el material de empaque se mantienen protegidos y libres de plagas.', 10],
                ],
            ],
            [
                'nombre' => 'Equipos y Utensilios',
                'preguntas' => [
                    [26, 'Los equipos y utensilios son de diseño higiénico y de materiales aptos (no tóxicos, no reactivos, no corrosivos y que no transmiten olores) y permiten una fácil limpieza y desinfección.', 15],
                    [27, 'Los equipos que lo requieren cuentan con los instrumentos de operación y control (termómetros, manómetros) en buen estado.', 15],
                    [28, 'Existe un sistema de calibración de los equipos e instrumentos de medición críticos, con registros y trazabilidad metrológica.', 5],
                    [29, 'Los termómetros y dispositivos ubicados en contacto o sobre el producto no son de vidrio ni de mercurio.', 10],
                    [30, 'Existe un programa de mantenimiento de equipos que asegura condiciones higiénicas (incluye control de lubricantes de grado alimentario y gestión de piezas y roscas).', 5],
                ],
            ],
            [
                'nombre' => 'Personal e Higiene',
                'preguntas' => [
                    [31, 'El personal usa uniforme completo, limpio y apto (cofia, calzado cerrado, vestimenta o delantal, y el EPP requerido) para la operación.', 10],
                    [32, 'El personal se lava y desinfecta las manos al ingresar, al reingresar al área, después de usar el baño, tras manipular material contaminante y al cambiar de actividad; se verifica su cumplimiento.', 5],
                    [33, 'El personal mantiene uñas cortas y sin esmalte, no usa joyas ni maquillaje y controla barba y bigote en las áreas de proceso.', 15],
                    [34, 'En las áreas donde el alimento ya no recibirá tratamiento térmico (producto expuesto), el personal usa mascarilla y la protección adicional definida.', 5],
                    [35, 'El personal mantiene el cabello totalmente cubierto con cofia o redecilla.', 5],
                    [36, 'Existe una política de salud del personal: quienes presentan enfermedad transmisible, síntomas o heridas expuestas no manipulan producto y reportan su condición.', 5],
                    [37, 'El personal no come, bebe, masca chicle ni fuma en las áreas de proceso.', 5],
                    [38, 'Las pertenencias personales se guardan fuera de las áreas de proceso y empaque.', 5],
                    [39, 'Los visitantes y contratistas ingresan con las protecciones requeridas y bajo las mismas normas de higiene, con registro.', 5],
                    [40, 'El personal ha recibido capacitación en higiene, BPM e inocuidad, con registros y verificación de eficacia.', 10],
                ],
            ],
            [
                'nombre' => 'Materias Primas, Operaciones y Productos',
                'preguntas' => [
                    [41, 'Existen especificaciones documentadas de las materias primas, ingredientes y material de empaque, y se verifican en la recepción contra dichas especificaciones.', 5],
                    [42, 'La recepción incluye controles definidos (integridad, documentación y certificados, temperatura cuando aplica y condiciones del transporte), con criterios de aceptación o rechazo y registros.', 5],
                    [43, 'Las materias primas y los productos se manejan y almacenan bajo condiciones que preservan su inocuidad (temperatura, segregación, identificación y rotación FIFO/FEFO).', 5],
                    [44, 'Se controlan los parámetros de proceso (tiempo, temperatura y otros parámetros críticos) frente a límites definidos, con monitoreo y registros que aseguran su efectividad.', 10],
                    [45, 'Existe control de cuerpos extraños en el proceso (tamices, imanes, detección de metales o inspección) con verificación de funcionamiento.', 5],
                    [46, 'El producto terminado cumple las especificaciones definidas y se libera conforme a criterios establecidos, con registros.', 10],
                ],
            ],
            [
                'nombre' => 'Limpieza y Desinfección',
                'preguntas' => [
                    [47, 'Las superficies en contacto con alimentos se limpian y desinfectan según el POES y están limpias antes del inicio de operaciones.', 15],
                    [48, 'Las superficies que no contactan alimentos (estructuras y equipos auxiliares) se mantienen limpias.', 10],
                    [49, 'Los químicos de limpieza y desinfección están aprobados para uso en alimentos, se dosifican de forma controlada y cuentan con ficha técnica; se rotan cuando aplica para evitar resistencias.', 10],
                    [50, 'Los químicos se almacenan identificados, segregados del producto y fuera de las áreas de proceso.', 15],
                ],
            ],
            [
                'nombre' => 'Envasado y Etiquetado',
                'preguntas' => [
                    [51, 'Los productos envasados llevan identificación codificada que permite conocer el lote, la fecha de producción y la identificación del fabricante.', 15],
                    [52, 'El etiquetado cumple la normativa aplicable (denominación, lote, fecha de caducidad o consumo preferente, condiciones de conservación, alérgenos e identificación del fabricante) y se verifica.', 10],
                    [53, 'Los materiales de empaque son inocuos, aptos para contacto con alimentos y se almacenan protegidos; se controla el material impreso.', 5],
                    [54, 'Existe control del producto no conforme (identificación, segregación y disposición) y de las devoluciones, con registros.', 5],
                ],
            ],
            [
                'nombre' => 'Almacenamiento, Transporte y Distribución',
                'preguntas' => [
                    [55, 'El área de recepción está separada e identificada respecto a las áreas de proceso y de almacenamiento.', 10],
                    [56, 'Las materias primas, productos, insumos y material de empaque se almacenan separados del piso (mínimo definido) y de las paredes, con identificación.', 15],
                    [57, 'El almacenamiento evita la contaminación cruzada entre productos, entre crudo y procesado, y entre especies o alérgenos, mediante una segregación definida.', 15],
                    [58, 'Se mantiene y verifica la cadena de frío cuando el producto lo requiere, con límites de temperatura por producto, monitoreo y acciones correctivas.', 15],
                    [59, 'La rotación se realiza por FIFO/FEFO y se controla la vida útil.', 5],
                    [60, 'Se registran las temperaturas de las cámaras frías y áreas refrigeradas, con alarmas o verificación y acciones ante desviaciones.', 10],
                    [61, 'El transporte mantiene las condiciones sanitarias y la temperatura definida; los vehículos están limpios, en buen estado y se verifican antes de la carga.', 5],
                    [62, 'Existe control de la carga y la estiba que evita el daño y la contaminación; no se transportan cargas incompatibles ni sustancias peligrosas junto al alimento.', 15],
                ],
            ],
            [
                'nombre' => 'Consideraciones Específicas para Proveedores de Productos Cárnicos (Cortes, Medias Canales y Menudencias)',
                'preguntas' => [
                    [63, 'La carne procede de mataderos o plantas de faenamiento autorizados y habilitados por la autoridad sanitaria, con la documentación que lo respalda (habilitación, guía de movilización o certificado sanitario).', 15],
                    [64, 'En la recepción de carne (cortes, medias canales y menudencias) se verifica y registra la temperatura del producto frente a límites definidos (refrigerado y congelado), se rechazan los lotes fuera de criterio y se verifica la temperatura del vehículo y la continuidad de la cadena de frío.', 15],
                    [65, 'Se realiza inspección organoléptica en la recepción (color, olor, textura y presencia de contaminación visible o cuerpos extraños —esquirlas de hueso, agujas—) y, cuando aplica, control de pH, con criterios de aceptación o rechazo.', 10],
                    [66, 'Existe una segregación estricta que evita la contaminación cruzada entre carne cruda y productos listos para consumo, entre especies y entre producto y menudencias o subproductos, en recepción, almacenamiento y transporte.', 15],
                    [67, 'Existe trazabilidad del lote de carne desde el matadero de origen (identificación de la canal o del lote) hasta el producto despachado, con capacidad de retiro.', 10],
                    [68, 'Se dispone de criterios microbiológicos para la carne recibida o despachada (por ejemplo, Salmonella, E. coli, Enterobacteriaceae y Listeria monocytogenes cuando aplique), con plan de muestreo, límites y acciones.', 15],
                    [69, 'Dado que la carne cruda no recibe una etapa letal en el proveedor ni necesariamente en la recepción posterior, el proveedor evidencia que sus controles de higiene, temperatura y contaminación cruzada mantienen la carga microbiana bajo control (severidad de base elevada).', 10],
                    [70, 'El manejo de subproductos, decomisos y materiales especificados de riesgo (cuando aplica) se realiza con segregación, identificación y disposición controlada que evita la contaminación del producto y del ambiente.', 10],
                ],
            ],
            [
                'nombre' => 'Requisitos Adicionales',
                'preguntas' => [
                    [71, 'Se tiene una evaluación de riesgo en torno al manejo de alergenos, listado del sitio, segregación y limpieza validada, y declaración correcta en la etiqueta.', 15],
                    [72, 'Se ha realizado una evaluación de vulnerabilidad (incluida la sustitución de especie y el origen en cárnicos) y plan de mitigación.', 15],
                    [73, 'Se tiene una evaluación de amenazas y control de acceso a las áreas críticas.', 15],
                    [74, 'Cuentan con un programa de cultura de inocuidad con objetivos, comunicación, capacitación y medición, respaldado por la dirección.', 15],
                    [75, 'Se mantiene un monitoreo ambiental para producto expuesto o RTE, programa con puntos de muestreo (incluye Listeria en cárnicos), límites y acciones.', 15],
                    [76, 'Se controla el etiquetado y material impreso, gestión del arte, verificación de conformidad y control de cambios.', 10],
                    [77, 'Se mantiene una correcta gestión de equipos: especificación sanitaria y aprobación de equipos nuevos y gestión de cambios de equipo.', 10],
                    [78, 'Control de peligros físicos: política de vidrio y plástico quebradizo, control de metales, cuchillas y agujas, y detección de metales con verificación de funcionamiento.', 10],
                    [79, 'Se comunica de manera efectiva los cambios de fórmula, proceso, origen o especificación que afecten la inocuidad o la legalidad.', 15],
                    [80, 'Se maneja un control de proveedores y servicios: aprobación, evaluación y seguimiento de proveedores aguas arriba (incluidos mataderos y transportistas de frío).', 15],
                    [81, 'Trazabilidad y retiro de producto: sistema probado mediante simulacros de recall, con balance de masa.', 20],
                    [82, 'Gestión de quejas, incidentes y no conformidades con análisis de causa raíz y verificación de la eficacia.', 15],
                    [83, 'Control del agua y el hielo en contacto con el producto: potabilidad verificada mediante análisis frente a límites, con registros.', 15],
                    [84, 'Verificación del sistema: programa de auditorías internas, validación y verificación de los controles, y análisis en laboratorios competentes.', 15],
                ],
            ],
        ]);

        $this->sembrarTipo('Productor Agrícola', 4, [
            [
                'nombre' => 'Pre-Cultivo',
                'preguntas' => [
                    [1, 'Existe una evaluación documentada del uso histórico y previo del terreno que identifica usos de riesgo (pastoreo o alimentación de animales, vertedero de basura, disposición de desechos orgánicos o industriales, relleno o actividad minera) y concluye con una decisión de aptitud del sitio, firmada por el responsable y respaldada con registros.', 20],
                    [2, 'Se mantienen registros del historial del lote que documentan inundaciones y aplicaciones excesivas o inadecuadas de plaguicidas y fertilizantes; el procedimiento define las medidas de mitigación y el tiempo de espera antes de destinar el lote a cultivo para consumo humano.', 10],
                    [3, 'Se evalúa y documenta el uso actual de los terrenos adyacentes y las vías de contaminación potencial (escorrentía, deriva de aspersión, explotaciones pecuarias, aguas residuales), con las medidas de barrera o separación implementadas para controlar el riesgo microbiológico y químico.', 20],
                    [4, 'El sitio de cultivo mantiene la distancia de separación definida respecto a vertederos de desechos animales, explotaciones pecuarias, fuentes de desecho industrial y descargas de aguas residuales; cuando la separación es insuficiente, existen medidas de mitigación documentadas y verificadas.', 20],
                    [5, 'El área de cultivo cuenta con barreras físicas que impiden el ingreso de animales domésticos y silvestres; se realizan y registran inspecciones periódicas del estado de las barreras y de la evidencia de intrusión animal, con las acciones correctivas correspondientes.', 20],
                    [6, 'Existe un procedimiento estándar de limpieza y desinfección de carretones, gavetas, contenedores y herramientas de cosecha que define productos aprobados, concentración, frecuencia y responsable; se conservan los registros de ejecución y se verifica su eficacia.', 10],
                ],
            ],
            [
                'nombre' => 'Producción y Fertilizantes',
                'preguntas' => [
                    [7, 'Los abonos orgánicos y enmiendas provienen de un proceso de compostaje controlado y validado, con registros de procedencia, temperaturas alcanzadas, número de volteos para aireación y fecha de finalización del proceso que evidencien la reducción de patógenos.', 10],
                    [8, 'Se realizan análisis microbiológicos de los abonos orgánicos y enmiendas frente a criterios de aceptación definidos (por ejemplo, E. coli < 1.000 NMP/g y Salmonella ausente o < 3 NMP/4 g), en laboratorio competente, con registros y acciones ante resultados fuera de criterio; se respeta el intervalo mínimo entre su aplicación y la cosecha.', 10],
                    [9, 'Se controla la calidad del agua utilizada para riego y fertirriego, con análisis frente a límites definidos según el riesgo del cultivo y registros de las acciones correctivas ante desviaciones.', 10],
                    [10, 'Las instalaciones de almacenamiento de fertilizantes y plaguicidas están identificadas y son seguras, con piso impermeable (cemento) y contención ante derrames, y mantienen separación física entre fertilizantes, plaguicidas, producto y material de empaque.', 10],
                    [11, 'Los plaguicidas y fertilizantes están claramente identificados, con etiqueta legible y vigente, y situados lejos de los operarios, de los animales y de todas las fuentes de agua.', 10],
                    [12, 'Se respetan los Límites Máximos de Residuos (LMR) del mercado de destino y el periodo de carencia entre la última aplicación y la cosecha; existe un programa de verificación de residuos con registros.', 15],
                    [13, 'Se utilizan únicamente productos fitosanitarios registrados y autorizados para el cultivo y el mercado de destino, priorizando los de menor categoría toxicológica (p. ej. sello verde y azul) cuando exista una alternativa eficaz; se dispone de la ficha técnica y la hoja de seguridad (SDS) de cada producto.', 15],
                    [14, 'Se mantiene un registro completo de cada aplicación de plaguicidas (producto, ingrediente activo, número de registro, dosis, fecha, lote o finca, condiciones climáticas, equipo utilizado, periodo de carencia y responsable de la aplicación).', 10],
                    [15, 'El personal que manipula y aplica plaguicidas está capacitado y autorizado, cuenta con el equipo de protección personal adecuado y con procedimientos para el triple lavado y la disposición segura de envases y excedentes.', 10],
                ],
            ],
            [
                'nombre' => 'Cosecha y Control de Plagas',
                'preguntas' => [
                    [16, 'Existe un programa de Manejo Integrado de Plagas (MIP) documentado que prioriza medidas preventivas y define umbrales de acción, métodos de control autorizados y responsables.', 20],
                    [17, 'Se mantiene un registro de todas las inspecciones de monitoreo de plagas que indica fechas, áreas, problemas observados, identificación de los organismos detectados y las medidas correctivas aplicadas.', 20],
                    [18, 'Se verifica la eficacia de las medidas correctivas y preventivas del plan de control de plagas y enfermedades, con un responsable asignado y evidencia documentada del seguimiento.', 30],
                    [19, 'Los equipos de aplicación y las herramientas de cosecha se limpian y, cuando aplica, se calibran antes de cada uso, verificando que estén libres de residuos de aplicaciones anteriores para evitar la contaminación cruzada.', 20],
                    [20, 'Durante la cosecha se aplican prácticas definidas que previenen la contaminación del producto (higiene y salud de los cosechadores, contenedores limpios de uso exclusivo, y exclusión del producto que ha tenido contacto con el suelo).', 10],
                    [21, 'Se cuenta con un procedimiento para el manejo de la fauna y de los indicios de contaminación por animales en el campo (heces, madrigueras), que define la exclusión de las zonas afectadas antes de la cosecha.', 30],
                ],
            ],
            [
                'nombre' => 'Calidad de Agua / Post Cosecha',
                'preguntas' => [
                    [22, 'Se han identificado y caracterizado todas las fuentes de agua (riego, aplicación de agroquímicos y post-cosecha) y se destina el agua de mejor calidad a las operaciones de post-cosecha en contacto directo con el producto.', 20],
                    [23, 'El agua de post-cosecha es potable o está sanitizada mediante un agente antimicrobiano controlado; se monitorean y registran los parámetros del sistema (por ejemplo, cloro libre, pH y, cuando aplique, ORP), con límites de control y acciones correctivas.', 20],
                    [24, 'Las fuentes y los sistemas de conducción de agua (canales, tanques y tuberías) están protegidos frente a contaminación por desechos animales, industriales o de cualquier otra índole, y reciben mantenimiento y limpieza con una frecuencia definida y registrada.', 10],
                    [25, 'Los sistemas de post-cosecha cuentan con dispositivos (válvulas antirretorno u otros) que impiden el reflujo del agua ya utilizada hacia los canales de agua limpia.', 10],
                    [26, 'Existe un manual de Procedimientos Operativos Estándar (POE) para el recambio del agua de tanques y canales de post-cosecha, que define la frecuencia de recambio, los parámetros de control y los registros asociados.', 10],
                    [27, 'Se realizan análisis microbiológicos y fisicoquímicos del agua (agrícola y de post-cosecha) según una frecuencia basada en riesgo, frente a límites de aceptación establecidos, en laboratorio competente, con registros y acciones ante resultados fuera de límite.', 10],
                    [28, 'El área de post cosecha se encuentra visiblemente limpia y todos los equipos que no están en uso, mantienen una limpieza aceptable.', 10],
                ],
            ],
            [
                'nombre' => 'Higiene Personal',
                'preguntas' => [
                    [29, 'Existe un programa y manual de Buenas Prácticas Agrícolas y de Manufactura que incluye a los visitantes y un programa de entrenamiento con registros.', 10],
                    [30, 'Los objetos personales se almacenan fuera de las áreas de manipulación, cosecha y proceso.', 10],
                    [31, 'Los empleados están capacitados en la técnica correcta de lavado de manos y se verifica su cumplimiento.', 10],
                    [32, 'Los trabajadores se abstienen de comportamientos que puedan contaminar el producto (fumar, escupir, mascar chicle, comer, estornudar o toser sobre frutas y hortalizas no protegidas), conforme a normas de higiene definidas.', 10],
                    [33, 'Se dispone de un lugar apropiado y separado para que los empleados consuman sus alimentos.', 10],
                    [34, 'Se cuenta con un botiquín de primeros auxilios accesible y con contenido controlado.', 10],
                    [35, 'Se dispone de servicios sanitarios suficientes y en condiciones higiénicas en las áreas de cosecha y post-cosecha.', 5],
                    [36, 'Las estaciones de lavado de manos son suficientes, están equipadas (agua, jabón e implementos de secado) y en uso.', 10],
                    [37, 'Existe señalización visible y en buen estado que refuerza el lavado de manos en los lugares correctos.', 10],
                    [38, 'Los trabajadores de post-cosecha usan ropa limpia, cubrecabello (mallas o cofias) y mantienen las uñas cortas y limpias; se controla el uso de joyas.', 5],
                    [39, 'Los empleados con enfermedades transmisibles o síntomas (incluidas heridas expuestas) no tienen contacto con el producto y están capacitados para reportar sus síntomas al supervisor; existe una política de salud del personal.', 10],
                ],
            ],
            [
                'nombre' => 'Manejo Post-Cosecha',
                'preguntas' => [
                    [40, 'Existe una política escrita e implementada de vidrio y plásticos duros; las lámparas y los materiales quebradizos sobre las líneas o el producto están protegidos para prevenir la contaminación física.', 20],
                    [41, 'Existe un sistema para eliminar el calor de campo del producto tras la cosecha (hidroenfriado, cuarto frío u otro), con parámetros definidos y registros.', 20],
                    [42, 'Se definen, monitorean y registran las temperaturas de refrigeración y conservación frente a límites por producto, con acciones correctivas ante desviaciones.', 20],
                    [43, 'Los equipos de refrigeración y las cámaras se mantienen en buenas condiciones, con un programa de mantenimiento y limpieza documentado.', 20],
                    [44, 'Se respeta un perímetro mínimo de separación (por ejemplo, 45 cm) alrededor del producto almacenado y este no se coloca directamente sobre el piso.', 10],
                    [45, 'Los vehículos de transporte están construidos con materiales no tóxicos que permiten una limpieza fácil y minuciosa, se limpian entre cargas, controlan la temperatura y no se utilizan para transportar sustancias peligrosas ni cargas incompatibles.', 10],
                ],
            ],
            [
                'nombre' => 'Requisitos Adicionales',
                'preguntas' => [
                    [46, 'Existe un programa documentado de gestión del agua conforme a evaluación de riesgo: inventario y perfil de cada fuente, plan de muestreo, límites de aceptación y medidas de mitigación (riego, agroquímicos y post-cosecha).', 10],
                    [47, 'Existe una gestión de estiércol, abonos orgánicos y biosólidos: tratamiento validado, control de los proveedores de estos insumos y trazabilidad de su origen y aplicación.', 10],
                    [48, 'Fraude alimentario, se ha realizado una evaluación de vulnerabilidad sobre la autenticidad de las declaraciones (orgánico, origen, libre de residuos) y la integridad de los insumos, con plan de mitigación.', 20],
                    [49, 'Defensa alimentaria, se ha realizado una evaluación de amenazas y control de acceso a campos, bodegas de insumos y plaguicidas, y fuentes y sistemas de agua.', 20],
                    [50, 'Se cuenta con un programa de cultura de inocuidad con objetivos, comunicación, capacitación y medición de indicadores, respaldado por la dirección.', 20],
                    [51, 'Existe un control de proveedores de insumos y servicios semilla o plántula, fertilizantes, plaguicidas, agua o hielo, laboratorios y transporte, con criterios de aprobación, evaluación y seguimiento.', 10],
                    [52, 'Trazabilidad y retiro de producto: identificación por lote finca con un paso adelante y un paso atrás, y capacidad de retiro (recall) probada periódicamente mediante simulacros.', 10],
                    [53, 'Existe un programa de monitoreo ambiental en las áreas de empaque y post-cosecha de producto expuesto, con puntos de muestreo, límites y acciones ante resultados adversos.', 10],
                    [54, 'Gestión del cambio y notificación al cliente: se comunican los cambios de origen, insumo, proceso o estatus que afecten la inocuidad o la legalidad del producto.', 10],
                    [55, 'Gestión de quejas, incidentes y no conformidades con análisis de causa raíz, acciones correctivas y verificación de su eficacia; manejo y disposición de residuos y envases de agroquímicos (triple lavado) sin contaminar el producto, el agua ni el suelo.', 10],
                ],
            ],
        ]);

        $this->seedRelacionesClaseAuditoria();
    }

    /**
     * Los 2 tipos que había antes (de una versión provisional del
     * catálogo, nunca correspondieron a un formulario real de Calidad)
     * se desactivan -> NO se borran, porque si ya se hizo alguna
     * auditoría real contra ellos, esa auditoría tiene que seguir
     * siendo consultable (Auditoria.Id_Tipo_Auditoria queda intacto).
     * Una vez desactivados, ya no aparecen en listarTiposAuditoria() ni
     * se pueden elegir para auditorías nuevas.
     */
    protected function desactivarTiposViejos(): void
    {
        TipoAuditoria::whereIn('Nombre', ['Proveedores', 'Proveedores Mataderos'])
            ->update(['Activo' => false]);
    }

    protected function sembrarTipo(string $nombre, int $ordenTipo, array $secciones): void
    {
        $tipo = TipoAuditoria::updateOrCreate(
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

    /**
     * Tipo_Auditoria_Clase: qué Clase(s) de Proveedor corresponden a cada
     * uno de los 4 tipos nuevos -> "Fabricantes Procesadores" cubre 3
     * clases existentes (Procesador de Alimento, Fabricante de insumos,
     * Fabricante Otros) porque el formulario real de "Procesadores /
     * Fabricantes de Alimentos" no distingue entre esos 3 rubros; el
     * resto son 1 a 1.
     */
    protected function seedRelacionesClaseAuditoria(): void
    {
        $mapa = [
            'Centros de Faenamiento' => ['Centros de Faenamiento'],
            'Comerciantes' => ['Comerciante'],
            'Fabricantes Procesadores' => ['Procesador de Alimento', 'Fabricante de insumos', 'Fabricante Otros'],
            'Productor Agrícola' => ['Productor Agrícola'],
        ];

        foreach ($mapa as $nombreTipo => $nombresClases) {
            $idTipo = TipoAuditoria::where('Nombre', $nombreTipo)->value('Id_Tipo_Auditoria');

            if (! $idTipo) {
                continue;
            }

            foreach ($nombresClases as $nombreClase) {
                $idClase = DB::table('Clase_Proveedor')->where('Nombre_Clase', $nombreClase)->value('Id_Clase_Proveedor');

                if (! $idClase) {
                    continue;
                }

                TipoAuditoriaClase::updateOrCreate(
                    ['Id_Tipo_Auditoria' => $idTipo, 'Id_Clase_Proveedor' => $idClase],
                    ['Activo' => true]
                );
            }
        }
    }
}
