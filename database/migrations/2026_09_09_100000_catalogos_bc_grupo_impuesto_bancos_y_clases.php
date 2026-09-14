<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tres catálogos para poder registrar al proveedor en Business Central
 * con los mismos valores que hoy se cargan a mano por Excel (pedido
 * explícito del usuario, 09-sep-2026; el Excel de referencia es
 * FICHA_PROVEEDORES_BC_EJEMPLO.xlsx que mandó el encargado de cargarlos).
 *
 * 1. Grupo_Impuesto_BC: la "Clase de contribuyente" de la ficha era texto
 *    libre (y ya tenía basura de pruebas tipo "vdvvs" guardada). Pasa a
 *    ser un selector: el proveedor VE la Descripción ("Persona Natural")
 *    y se guarda el Código ("PERSONA NATURAL"), que es lo que BC espera
 *    en su campo "Grupo de impuesto".
 *
 * 2. Banco: los 222 bancos ACTIVOS de la hoja "CODIGO BANCOS". Se
 *    descartaron los 6 ELIMINADO y los 2 INACTIVO -> no tiene sentido
 *    ofrecerle al proveedor un banco que BC no va a aceptar. Codigo_BC
 *    es lo que va en "Cód. sucursal banco" y NUNCA se le muestra al
 *    proveedor, solo se postea.
 *
 * 3. Proveedor_Cuenta_Bancaria: los datos que el proveedor declara junto
 *    con el PDF del certificado bancario. Con estos 3 campos se llenan
 *    los 5 que pide la Ficha de Bancos de BC (validado contra el Excel):
 *      Nombre              <- Banco.Nombre_Banco
 *      Cód. sucursal banco <- Banco.Codigo_BC
 *      Cód. cuenta banco   <- Nro_Cuenta
 *      Código              <- Nro_Cuenta (así lo usaron en el ejemplo)
 *      Tipo de cuenta      <- Tipo_Cuenta (AHO/CTE)
 *
 * Además, Clase_Proveedor gana Codigo_BC: el portal maneja sus propios
 * nombres de clase, pero BC solo acepta los 4 de su enum. "Productor" y
 * "Productor Agrícola" mapean AMBOS a "Productor", y "Servicio" mapea a
 * NULL (en BC el campo queda en blanco) -> decisión explícita del
 * usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------
        // 1. Grupo de impuesto (Clase de contribuyente)
        // ------------------------------------------------------------
        Schema::create('Grupo_Impuesto_BC', function (Blueprint $table) {
            $table->id('Id_Grupo_Impuesto_BC');
            // El Código es el valor real que viaja a BC.
            $table->string('Codigo', 50)->unique();
            $table->string('Descripcion', 100);
            $table->boolean('Activo')->default(true);
        });

        DB::table('Grupo_Impuesto_BC')->insert([
            ['Codigo' => 'CONTRIB ESPECIAL',   'Descripcion' => 'Especial',               'Activo' => 1],
            ['Codigo' => 'EXP HABITUAL',       'Descripcion' => 'Exportador Habitual',    'Activo' => 1],
            ['Codigo' => 'EXTERIOR',           'Descripcion' => 'Exterior',               'Activo' => 1],
            ['Codigo' => 'GRAN CONTRIBUYENTE', 'Descripcion' => 'Grandes Contribuyentes', 'Activo' => 1],
            ['Codigo' => 'INST DEL ESTADO',    'Descripcion' => 'Publicas',               'Activo' => 1],
            ['Codigo' => 'PERSONA NATURAL',    'Descripcion' => 'Persona Natural',        'Activo' => 1],
            ['Codigo' => 'RIMPE EMPRENDEDOR',  'Descripcion' => 'Rimpe Emprendedor',      'Activo' => 1],
            ['Codigo' => 'RIMPE NPO',          'Descripcion' => 'Rimpe NPO',              'Activo' => 1],
            ['Codigo' => 'SOCIEDAD',           'Descripcion' => 'Sociedad',               'Activo' => 1],
        ]);

        // La Clase_Contribuyente vieja era texto libre y NO coincide con
        // ningún Código -> se limpia para que el proveedor la vuelva a
        // elegir del selector en vez de arrastrar un valor que BC va a
        // rechazar. Son 2 registros, ambos de prueba.
        DB::table('Proveedor')
            ->whereNotNull('Clase_Contribuyente')
            ->whereNotIn('Clase_Contribuyente', DB::table('Grupo_Impuesto_BC')->pluck('Codigo')->all())
            ->update(['Clase_Contribuyente' => null]);

        // ------------------------------------------------------------
        // 2. Bancos
        // ------------------------------------------------------------
        Schema::create('Banco', function (Blueprint $table) {
            $table->id('Id_Banco');
            // Codigo_BC = "Cód. sucursal banco" en BC. Interno, nunca se
            // le muestra al proveedor.
            $table->string('Codigo_BC', 10)->unique();
            $table->string('Nombre_Banco', 150);
            $table->boolean('Activo')->default(true);
        });

        $bancos = [
            ['0036', 'PRODUBANCO'],
            ['0034', 'AMAZONAS'],
            ['0037', 'BOLIVARIANO'],
            ['0024', 'CITIBANK'],
            ['0017', 'GUAYAQUIL'],
            ['0029', 'LOJA'],
            ['0043', 'DEL LITORAL'],
            ['0030', 'PACIFICO'],
            ['0010', 'PICHINCHA'],
            ['0042', 'RUMINAHUI'],
            ['0032', 'INTERNACIONAL'],
            ['0039', 'COMERCIAL DE MANABI'],
            ['0025', 'MACHALA'],
            ['0035', 'AUSTRO'],
            ['0001', 'BANCO CENTRAL'],
            ['0059', 'SOLIDARIO'],
            ['0086', 'MUTUALISTA PICHINCHA'],
            ['0140', 'ECUATORIANO DE LA VIVIENDA'],
            ['0088', 'MUTUALISTA AZUAY'],
            ['0087', 'MUTUALISTA AMBATO'],
            ['0085', 'MUTUALISTA IMBABURA'],
            ['9967', 'DINERS CLUB'],
            ['9969', 'COOP. TULCAN'],
            ['9970', 'COOP. PABLO MUNOZ VEGA'],
            ['9971', 'COOP. CALCETA LTDA.'],
            ['9972', 'COOP DE AH Y CR CONSTRUCCION COMERCIO Y PRODUCCION'],
            ['9974', 'COOP. JUVENTUD ECUATORIANA PROGRESISTA LTDA.'],
            ['9976', 'COOP. AHO Y CREDITO SANTA ANA'],
            ['9977', 'COOP. ALIANZA DEL VALLE LTDA.'],
            ['9979', 'COOP. RIOBAMBA'],
            ['9980', 'COOP. COMERCIO LTDA PORTOVIEJO'],
            ['9981', 'COOP. CHONE LTDA.'],
            ['9982', 'COOP. CACPECO'],
            ['9983', 'COOP. ATUNTAQUI'],
            ['9984', 'COOP. GUARANDA'],
            ['9986', 'COOP. AHO Y CREDITO EL SAGRARIO'],
            ['9987', 'COOP. OSCUS'],
            ['9988', 'COOP. LA DOLOROSA'],
            ['9994', 'COOP. COTOCOLLAO'],
            ['9995', 'COOP. 29 DE OCTUBRE'],
            ['9997', 'COOP. PEQ. EMPRESA DE PASTAZA'],
            ['9998', 'COOP. ANDALUCIA'],
            ['9999', 'COOP. PREVISION AHORRO Y DESARROLLO'],
            ['0060', 'BANCO PROCREDIT'],
            ['0062', 'BANCO PARA LA ASISTENCIA COMUNITARIA FINCA S.A.'],
            ['9965', 'COOP. AHORRO Y CREDITO COOPROGRESO'],
            ['9964', 'FINANCIERA FINANCOOP'],
            ['9993', 'COOP. AHO Y CREDITO JARDIN AZUAYO'],
            ['9992', 'COOP. AHO Y CREDITO SAN FRANCISCO'],
            ['9991', 'COOP AHO Y CREDITO SAN JOSE'],
            ['9989', 'COOP AHO Y CREDITO MANUEL GODOY'],
            ['9985', 'COOP AHO Y CREDITO SANTA ROSA'],
            ['9975', 'COOP. AHO Y CREDITO 23 DE JULIO'],
            ['9963', 'CORPORACION FINANCIERA'],
            ['0061', 'BANCO CAPITAL'],
            ['0109', 'COOP. DE AHORRO Y CREDITO SAN FRANCISCO DE ASIS LT'],
            ['0100', 'COOP. AHORRO Y CREDITO 15 DE ABRIL LTDA'],
            ['0102', 'COOP. AHORRO Y CREDITO CARIAMANGA LTDA.'],
            ['0103', 'COOP. AHORRO Y CREDITO PUELLARO LTDA'],
            ['0105', 'COOP. DE A. Y C. 16 DE JUNIO'],
            ['0106', 'COOP. DE A. Y C. 20 DE FEBRERO LTDA.'],
            ['0107', 'COOP. DE A. Y C. 23 DE MAYO LTDA.'],
            ['0108', 'COOP. DE A. Y C. MAQUITA CUSHUNCHIC LTDA.'],
            ['0124', 'COOP. AHORRO Y CREDITO MANANTIAL DE ORO LTDA.'],
            ['0125', 'COOP. AHORRO Y CREDITO JUAN DE SALINAS LTDA.'],
            ['0126', 'COOP. AHORRO Y CREDITO NUEVA JERUSALEN'],
            ['0129', 'COOP. AHORRO Y CREDITO AGRARIA MUSHUK KAWSAY LTDA.'],
            ['0130', 'COOP. AHORRO Y CREDITO TENA LTDA.'],
            ['0131', 'COOP. AHORRO Y CREDITO DE LA PEQUENA EMPRESA GUALA'],
            ['0132', 'COOP. AHORRO Y CREDITO MI TIERRA'],
            ['0133', 'COOP. DE AHORRO Y CREDITO DE LA PEQ. EMP. CACPE YA'],
            ['0134', 'COOP. AHORRO Y CREDITO FUNDESARROLLO'],
            ['0135', 'COOP. A Y C DE LA PEQ. EMP. CACPE ZAMORA LTDA.'],
            ['0139', 'COOP. AHO Y CRED ALIANZA MINAS LTDA.'],
            ['0143', 'COOPERATIVA 9 DE OCTUBRE LTDA'],
            ['0144', 'COOPERATIVA CACPE BIBLIAN LTDA'],
            ['0155', 'COOP. DE AH Y CR ONCE DE JUNIO'],
            ['0161', 'COOP DE AH Y CR DE SERV PUBLIC MINISTERIO EDUC Y C'],
            ['0168', 'COOP AH Y CR POLICIA NACIONAL'],
            ['0183', 'BANCO D MIRO SA'],
            ['0185', 'Coop Ah y Cr de la Pequena Empresa de Loja CACPE L'],
            ['0187', 'COOP. DE AHORRO Y CREDITO SR. DE GIRON'],
            ['0204', 'Coop Ah y Credito Ambato Ltda'],
            ['0205', 'COOPE AHO Y CRED PADRE JULIAN LORENTE LTDA'],
            ['1015', 'DINERS/VISA INTERDIN/DISCOVER - Transferencias EnL'],
            ['0214', 'COOP AHO Y CRED EDUCADORES DE CHIMBORAZO'],
            ['0221', 'COOP DE AHO Y CRED SAN MIGUEL DE LOS BANCOS'],
            ['0222', 'COOP AH Y CR SAN ANTONIO LTDA.'],
            ['0229', 'COOP AH Y CRJUAN PIO MORA LTDA'],
            ['0230', 'COOP AH Y CR EDUCADORES DE PASTAZA LTDA'],
            ['0233', 'COOPE.CAMARA DE COMERCIO DE AMBATO'],
            ['0257', 'COOP AH Y CR COCA LTDA'],
            ['0263', 'COOP. DE AHORRO Y CREDITO MUSHUC RUNA LTDA.'],
            ['0270', 'COOP AH Y CR CREDIAMIGO'],
            ['0275', 'COOP. DE AHORRO Y CREDITO NUEVA HUANCAVILCA LTDA.'],
            ['0291', 'COOP. DE AH Y CR LA INMACULADA DE SAN PLACIDO LTDA'],
            ['0293', 'COOP. DE A Y C. LUZ DEL VALLE'],
            ['0295', 'COOPERATIVA DE AHORRO Y CREDITO LA BENEFICA LTDA'],
            ['0296', 'COOPERATIVA DE AHORRO Y CREDITO FERNANDO DAQUILEMA'],
            ['0299', 'COOP DE AHORRO Y CREDITO LA MERCED LTDA'],
            ['0300', 'COOP. DE AHORRO Y CREDITO PEDRO MONCAYO LTDA.'],
            ['0301', 'COOP. DE AHORRO Y CREDITO LOS ANDES LATINOS LTDA.'],
            ['0302', 'COOP. DE A Y C GUAMOTE LTDA'],
            ['0303', 'COOP. DE A. Y C. CREDISOCIO'],
            ['0306', 'COOP. DE A. Y C. CAMARA DE COMERCIO SANTO DOMINGO'],
            ['0308', 'COOP DE A Y CR CORPORACION CENTRO LTDA'],
            ['0309', 'COOP DE A. Y C. ANTORCHA LTDA'],
            ['0311', 'COOP AHORRO Y CREDITO SAN GABRIEL LTDA'],
            ['0312', 'COOP AHORRO Y CREDI MUJERES UNIDAS TANTANAKUSHKA W'],
            ['0313', 'COOPERATIVA DE AHORRO Y CREDITO ARTESANOS LTDA'],
            ['0314', 'COOPERATIVA DE AHORRO Y CREDITO SANTA ANITA LTDA'],
            ['0315', 'COOP. DE A. Y C. 13 DE ABRIL LTDA'],
            ['0316', 'COOP. DE AHORRO Y CREDITO PILAHUIN TIO LTDA.'],
            ['0317', 'COOPERATIVA DE AHORRO Y CREDITO PILAHUIN'],
            ['0318', 'COOP. DE A. Y C. COOPAC AUSTRO LTDA -MIESS'],
            ['0319', 'COOPERATIVA DE AHORRO Y CREDITO CREA LTDA -MIES'],
            ['0320', 'COOP. DE A. Y C. CHIBULEO LTDA.'],
            ['0322', 'COOP.DE AHORRO Y CREDITO HUAYCO PUNGO LTDA.'],
            ['0323', 'COOP. DE AHORRO Y CREDITO 4 DE OCTUBRE LTDA.'],
            ['0324', 'COOP AHORRO Y CREDITO SAN ISIDRO LTDA'],
            ['0327', 'COOP. AHORRO Y CREDITO SEMILLA DEL PROGRESO LTDA.'],
            ['0328', 'COOP. DE AHORRO Y CREDITO HUAICANA LTDA'],
            ['0331', 'COOP. DE A. Y C. ABDON CALDERON LTDA.'],
            ['0338', 'COOPERATIVA DE AHORRO Y CREDITO PUCARA LTDA'],
            ['0341', 'COOPERATIVA DE AHORRO Y CREDITO MULTIEMPRESARIAL L'],
            ['0342', 'COOP DE A Y C SAN JUAN DE COTOGCHOA'],
            ['0353', 'COOPERATIVA DE AHORRO Y CREDITO MINGA LTDA'],
            ['0354', 'COOP DE AH Y CR ERCO LTDA.'],
            ['0355', 'COOP DE AHORRO Y CREDITO SANTA ISABEL LTDA'],
            ['0359', 'COOP. DE A. Y C. LUCHA CAMPESINA LTDA.'],
            ['0368', 'COOP AH Y CR ANDINA LTDA'],
            ['0372', 'COOP DE AH Y CR NUEVA ESPERANZA'],
            ['0373', 'COOP. DE AH Y CR FORTUNA - MIES'],
            ['0395', 'COOP DE AHORRO Y CREDITO PROVIDA'],
            ['0397', 'COOPERATIVA DE AHORRO Y CREDITO LAS LAGUNAS-MIESS'],
            ['0404', 'COOP DE A Y C FUTURO LAMANENSE'],
            ['0405', 'COOPERATIVA DE AHORO Y CREDITO VISION DE LOS ANDES'],
            ['0406', 'COOP A Y C UNION EL EJIDO'],
            ['0407', 'COOP A Y C VILCABAMBA CACVIL'],
            ['0408', 'COOP A Y C CADECOG GONZANAMA'],
            ['0426', 'COOP. DE AHORRO Y CREDITO SAN MIGUEL DE PALLATANGA'],
            ['0431', 'COOP DE A Y C SANTA ANA DE NAYON'],
            ['0432', 'COOP A Y C ESPERANZA Y PROGRESO DEL VALLE'],
            ['0435', 'COOP DE AH Y CR AGRICOLA JUNIN LTDA'],
            ['0440', 'COOP. DE AH Y CR 1 DE JULIO'],
            ['0446', 'COOP.DE AHORRO Y CREDITO MICROEMPRESARIAL SUCRE'],
            ['0457', 'COOP. DE AH Y CR TEXTIL 14 DE MARZO'],
            ['0461', 'COOP. DE AH Y CR CAMARA DE COMERCIO RIOBAMBA'],
            ['0066', 'BANECUADOR B.P.'],
            ['0467', 'COOP. DE AH Y CR INDIGENA SAC LTDA'],
            ['0470', 'COOP. DE AH Y CR FOCLA'],
            ['0479', 'COOP. DE AH Y CR SANTA LUCIA LTDA'],
            ['0486', 'COOP. DE AH Y CR CACPE CELICA'],
            ['0064', 'BANCO COOPNACIONAL SA'],
            ['0065', 'BANCO DESARROLLO DE LOS PUEBLOS S.A.'],
            ['0487', 'COOP. DE AH Y CR CACEC LTDA. COTOPAXI'],
            ['0488', 'COOP. DE AH Y CR MAGISTERIO MANABITA LIMITADA'],
            ['0489', 'COOP. DE AH Y CR ALFONSO JARAMILLO C.C.C.'],
            ['0498', 'COOP. DE AH Y CR PARA LA VIVIENDA ORDEN Y SEGURIDA'],
            ['0499', 'COOP. DE AH Y CR ECUAFUTURO LTDA.'],
            ['0506', 'COOP. DE AH Y CR VIRGEN DEL CISNE'],
            ['0508', 'COOP. DE AH Y CR SANTA MARIA DE LA MANGA DEL CURA'],
            ['0509', 'COOP. DE AH Y CR 16 DE JULIO LTDA'],
            ['0510', 'COOP. DE AH Y CR METROPOLIS LTDA.'],
            ['0511', 'COOP. DE AH. Y CR. SAN MARTIN DE TISALEO LTDA.'],
            ['0512', 'COOP. DE AH Y CR EDUCADORES TULCAN LTDA'],
            ['0513', 'COOP. DE AH Y CR EDUCADORES DE ZAMORA CHINCHIPE'],
            ['0514', 'COOP. DE AH Y CR COOPARTAMOS LTDA'],
            ['0517', 'COOP. DE AH Y CR LIMITADA MIFEX'],
            ['0520', 'COOP. DE AH Y CR SERVIDORES MUNICIPALES DE CUENCA'],
            ['0522', 'COOP. DE AH Y CR EDUCADORES DE LOJA'],
            ['0524', 'COOP. DE AH Y CR GRAMEEN AMAZONAS'],
            ['0525', 'COOP. DE AH Y CR PUERTO FRANCISCO DE ORELLANA'],
            ['0526', 'COOP. DE AH Y CR CAMARA DE COMERCIO JOYA DE LOS SA'],
            ['0027', 'DELBANK'],
            ['0529', 'COOP. DE AH Y CR PADRE VICENTE PONCE RUBIO'],
            ['0533', 'COOP. DE AH Y CR ESPERANZA DEL FUTURO LTDA'],
            ['0534', 'COOP. AH Y CR SUMAK KAWSAY'],
            ['0535', 'COOP. AH Y CR VISIONFUND ECUADOR S. A.'],
            ['0537', 'COOP. AH Y CR SAN ANTONIO LTDA. - IMBABURA (MIES)'],
            ['0538', 'COOP DE AH Y CR ECUACREDITOS LTDA'],
            ['0539', 'BANCO DEL INSTITUTO ECUATORIANO DE SEGURIDAD SOCIA'],
            ['0546', 'COOPERATIVA CREDIMAS'],
            ['0547', 'COOP. AH Y CR SAN MIGUEL DE SIGCHOS'],
            ['0550', 'COOP. DE AH Y CR SAN JOSE S.J.'],
            ['0552', 'COOP. DE AH Y CR LA FLORESTA LTDA.'],
            ['0553', 'COOP. DE AH Y CR UNIOTAVALO LTDA.'],
            ['0554', 'COOP. DE AH Y CR SOL DE LOS ANDES LTDA.'],
            ['0555', 'COOP. DE AH Y CR SIERRA CENTRO'],
            ['0560', 'COOP. DE AH Y CR SINCHI RUNA LTDA'],
            ['0561', 'COOP. DE AH Y CR INDIGENAS GALAPAGOS LTDA'],
            ['0564', 'COOP. DE AH Y CR ACCION IMBABURAPAK LTDA.'],
            ['0567', 'COOP. DE AH Y CR KULLKI WASI LTDA.'],
            ['0568', 'COOP. DE AH Y CR ACCION TUNGURAHUA LTDA'],
            ['0589', 'CASA DE VALORES SMARTCAPITAL S. A. MV'],
            ['0572', 'COOP. DE AH Y CR DON BOSCO'],
            ['0573', 'COOP. DE AH Y CR VENCEDORES DE TUNGURAHUA'],
            ['0575', 'COOP. DE AH Y CR FONDO PARA EL DESARROLLO Y LA VID'],
            ['0576', 'COOP. DE AH Y CR 17 DE MARZO LTDA'],
            ['0577', 'COOP. DE AH Y CR BASE DE TAURA'],
            ['0579', 'COOPERATIVA DE AH Y CR CANAR LTDA.'],
            ['0581', 'COOP. DE AH Y CR MUSHUK - YUYAY (CANAR)'],
            ['0582', 'COOP. DE AH Y CR EL MOLINO LTDA.'],
            ['0583', 'COOP. DE AH Y CR PUSHAK RUNA HOMBRE LIDER'],
            ['0585', 'COOP. DE AH Y CR OCCIDENTAL'],
            ['0586', 'COOP. DE AH Y CR. SIMON BOLIVAR'],
            ['0587', 'COOP. DE AH Y CR UNIBLOCK Y SERVICIOS LTDA.'],
            ['0590', 'COOP AH Y CR DE INDIGENAS CHUCHUQUI LTDA'],
            ['0591', 'COOP DE AH Y CR GANANSOL LTDA'],
            ['0592', 'COOP AH Y CR CHUNCHI LTDA'],
            ['0593', 'COOP DE AH Y CR NACIONAL LLANO GRANDE LTDA.'],
            ['0594', 'COOP DE AH Y CR OBRAS PUBLICAS FISCALES DE LOJA Y'],
            ['0595', 'COOP. DE AH Y CR FUTURO Y PROGRESO DE GALAPAGOS LT'],
            ['0596', 'COOP. DE AH Y CR EDUC. DEL TUNGURAHUA LTDA.'],
            ['0597', 'COOP DE AH Y CR SAN MIGUEL LTDA.'],
            ['0598', 'COOP DE AH Y CR CREDI YA LTDA.'],
            ['0601', 'CCU EP CNT EP CARCHI'],
            ['0602', 'COOP. DE AH Y CR CAMARA DE COMERCIO DEL CANTON BOL'],
            ['0603', 'COOP. DE AH Y CR CASAG LTDA'],
            ['0604', 'COOP. DE AH Y CR SANTA ROSA DE PATUTAN LTDA.'],
            ['0605', 'COOP. DE AH Y CR LOS RIOS'],
            ['0606', 'COOP. DE AH Y CR CREDISUR LTDA.'],
        ];

        DB::table('Banco')->insert(
            collect($bancos)->map(fn ($b) => [
                'Codigo_BC' => $b[0],
                'Nombre_Banco' => $b[1],
                'Activo' => 1,
            ])->all()
        );

        // ------------------------------------------------------------
        // 3. Cuenta bancaria declarada por el proveedor
        // ------------------------------------------------------------
        Schema::create('Proveedor_Cuenta_Bancaria', function (Blueprint $table) {
            $table->id('Id_Proveedor_Cuenta_Bancaria');
            // Uno a uno: es LA cuenta donde se le paga. Si algún día se
            // necesitan varias, hay que quitar el unique y decidir cuál
            // es la principal antes de postear a BC.
            $table->unsignedInteger('Id_Proveedor')->unique();
            $table->unsignedBigInteger('Id_Banco');
            // AHO (ahorros) | CTE (corriente) -> mismos códigos que BC.
            $table->string('Tipo_Cuenta', 3);
            $table->string('Nro_Cuenta', 30);
            $table->unsignedInteger('Registrado_Por')->nullable();
            $table->dateTime('Fecha_Creacion')->nullable();
            $table->dateTime('Fecha_Modificacion')->nullable();

            $table->foreign('Id_Proveedor')->references('Id_Proveedor')->on('Proveedor');
            $table->foreign('Id_Banco')->references('Id_Banco')->on('Banco');
            $table->foreign('Registrado_Por')->references('Id_Usuario')->on('Usuario');
        });

        // ------------------------------------------------------------
        // 4. Clase_Proveedor: mapeo al enum de BC + solo las que BC acepta
        // ------------------------------------------------------------
        Schema::table('Clase_Proveedor', function (Blueprint $table) {
            // NULL a propósito para "Servicio": en BC el Tipo Proveedor
            // queda EN BLANCO, no es que falte el dato.
            $table->string('Codigo_BC', 50)->nullable()->after('Nombre_Clase');
        });

        // Las clases del portal que BC no reconoce se desactivan (no se
        // borran): hay reglas de auditoría (Tipo_Auditoria_Clase) y de
        // documentos (Tipo_Documento_Clase_Excluida) colgando de ellas, y
        // borrarlas dejaría esa configuración huérfana. Desactivadas ya
        // no se ofrecen al postular, pero nada existente se rompe.
        DB::table('Clase_Proveedor')->update(['Activo' => 0]);

        // "Productor Agrícola" se RENOMBRA a "Agrícola" (en vez de
        // desactivarla y crear una nueva) para conservar su
        // Id_Clase_Proveedor = 1 y con él las reglas de auditoría y de
        // documentos que ya tiene asociadas.
        DB::table('Clase_Proveedor')
            ->where('Nombre_Clase', 'Productor Agrícola')
            ->update(['Nombre_Clase' => 'Agrícola']);

        $clases = [
            ['Nombre_Clase' => 'Comercializador',    'Codigo_BC' => 'Comercializador'],
            ['Nombre_Clase' => 'Productor',          'Codigo_BC' => 'Productor'],
            // "Agrícola" también mapea a "Productor" en BC -> son 2
            // clases distintas en el portal pero el mismo valor allá
            // (decisión explícita del usuario).
            ['Nombre_Clase' => 'Agrícola',           'Codigo_BC' => 'Productor'],
            ['Nombre_Clase' => 'Exportador Hab.',    'Codigo_BC' => 'Exportador Hab.'],
            ['Nombre_Clase' => 'Sin Fines de Lucro', 'Codigo_BC' => 'Sin Fines de Lucro'],
            // Servicio: en BC el Tipo Proveedor va EN BLANCO, y además es
            // la única clase que se aprueba SIN productos (ver
            // CalificacionProveedorService).
            ['Nombre_Clase' => 'Servicio',           'Codigo_BC' => null],
        ];

        foreach ($clases as $clase) {
            $existente = DB::table('Clase_Proveedor')->where('Nombre_Clase', $clase['Nombre_Clase'])->first();

            if ($existente) {
                DB::table('Clase_Proveedor')
                    ->where('Id_Clase_Proveedor', $existente->Id_Clase_Proveedor)
                    ->update(['Codigo_BC' => $clase['Codigo_BC'], 'Activo' => 1]);
            } else {
                DB::table('Clase_Proveedor')->insert([
                    'Nombre_Clase' => $clase['Nombre_Clase'],
                    'Codigo_BC' => $clase['Codigo_BC'],
                    'Activo' => 1,
                ]);
            }
        }

        // ------------------------------------------------------------
        // 5. Empresa: código de compañía de BC
        // ------------------------------------------------------------
        // Empresa_BC ya existía pero guarda el nombre largo
        // ("caterfood", "frozentropic") que se usa para leer el espejo
        // RAW_BC. El OData de BC, en cambio, direcciona por el CÓDIGO de
        // compañía: .../ODataV4/Company('CF')/... -> son 2 cosas
        // distintas y hacía falta un campo aparte.
        //
        // El proveedor se postea en la compañía de la EMPRESA ACTIVA de
        // la sesión (el selector de arriba a la derecha), no en una fija.
        Schema::table('Empresa', function (Blueprint $table) {
            $table->string('Codigo_Company_BC', 20)->nullable()->after('Empresa_BC');
        });

        // 'CF' confirmado por el usuario (es el que aparece en las URLs
        // de los 3 web services). El de FROZENTROPIC queda NULL a
        // propósito hasta que se confirme: con NULL, el posteo a BC de
        // esa empresa falla de forma explícita en vez de mandar los
        // proveedores a la compañía equivocada.
        DB::table('Empresa')->where('Id_Empresa', 1)->update(['Codigo_Company_BC' => 'CF']);
    }

    public function down(): void
    {
        Schema::dropIfExists('Proveedor_Cuenta_Bancaria');
        Schema::dropIfExists('Banco');
        Schema::dropIfExists('Grupo_Impuesto_BC');

        Schema::table('Clase_Proveedor', function (Blueprint $table) {
            $table->dropColumn('Codigo_BC');
        });

        Schema::table('Empresa', function (Blueprint $table) {
            $table->dropColumn('Codigo_Company_BC');
        });

        DB::table('Clase_Proveedor')
            ->where('Nombre_Clase', 'Agrícola')
            ->update(['Nombre_Clase' => 'Productor Agrícola']);

        // Se reactivan todas: es lo que había antes de esta migración.
        DB::table('Clase_Proveedor')->update(['Activo' => 1]);
    }
};
