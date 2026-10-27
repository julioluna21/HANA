<?php
/*
  HANA - Parámetros del sistema
  ---------------------------------------------------------------------------
  Las decisiones del negocio que el administrador cambia desde
  Configuración → Parámetros del sistema, sin tocar el código.
  Cada parámetro se define aquí (PARAMETROS: grupo, nombre, ayuda, tipo (SI_NO, NUMERO o CORREOS),
  límites y valor por defecto). La tabla `configuracion` guarda solo el valor
  que eligió el administrador, quién lo cambió y cuándo. Si un parámetro no
  tiene fila en la tabla, vale lo que diga su valor por defecto.
  Los valores se leen una sola vez por petición.

    HanaConfig::num('VACANTE_DIAS_ANS', 5)      un número
    HanaConfig::si('MODULO_ARQUEOS')            sí / no (por defecto sí)
    HanaConfig::modulo('ARQUEOS')               ¿el módulo está activo?
*/
require_once __DIR__ . "/HanaDB.php";

class HanaConfig
{
    //CLAVE => grupo, nombre, ayuda, tipo (SI_NO, NUMERO o CORREOS), mínimo, máximo y valor por defecto.
    //(Las listas de opciones van aparte, en LISTAS.)
    //El orden de esta lista es el orden en que salen en la pantalla
    const PARAMETROS = array(
        'MODULO_HOY'            => array('Módulos del reporte diario', 'Hoy en qué estás', 'Si se apaga, desaparece del menú y nadie lo puede llenar.', 'SI_NO', null, null, '1'),
        'MODULO_LISTAS'         => array('Módulos del reporte diario', 'Listas de chequeo', null, 'SI_NO', null, null, '1'),
        'MODULO_ARQUEOS'        => array('Módulos del reporte diario', 'Arqueos', null, 'SI_NO', null, null, '1'),
        'MODULO_CRONOGRAMA'     => array('Módulos del reporte diario', 'Cronograma y vehículo', null, 'SI_NO', null, null, '1'),
        'MODULO_VACANTES'       => array('Módulos del reporte diario', 'Vacantes', null, 'SI_NO', null, null, '1'),
        'MODULO_COMUNICACIONES' => array('Módulos del reporte diario', 'Comunicaciones y oficios', null, 'SI_NO', null, null, '1'),
        //El módulo de ausentismo (matriz de cubrimiento) se retiró del sistema; queda solo la promesa de valor.
        'MODULO_PROMESA'        => array('Módulos del reporte diario', 'Promesa de valor', 'Las horas prometidas y ejecutadas por peaje, cargo y día.', 'SI_NO', null, null, '1'),

        'RQ_MAX_REINTENTOS'     => array('Reglas del reporte diario', 'Veces que una RQ rechazada se puede corregir y enviar de nuevo',
                                     'Cuando se llega a ese número, si la vuelven a rechazar queda rechazada para siempre y hay que pedir una RQ nueva. Con 0 no se reenvía nunca.', 'NUMERO', 0, 20, '3'),
        'CRONO_TRANSPORTE_OBLIGATORIO' => array('Reglas del reporte diario', 'Pedir cómo se transportó cuando el vehículo no está operativo', null, 'SI_NO', null, null, '1'),
        'CRONO_VH_FINDE_DIAS'   => array('Reglas del reporte diario', 'Días hacia adelante para registrar el vehículo de sábados, domingos y festivos',
                                     'Esos días el estado del vehículo se puede dejar registrado por adelantado. Los demás días, solo hoy. Con 0 se apaga.', 'NUMERO', 0, 366, '60'),
        'CRONO_VH_FINDE_ATRAS'  => array('Reglas del reporte diario', 'Días hacia atrás para corregir el vehículo de sábados, domingos y festivos',
                                     'Después de un sábado, domingo o festivo, cuántos días quedan para registrar o corregir el estado del vehículo de ese día (por ejemplo, el lunes se corrige el fin de semana). Con 0 se apaga.', 'NUMERO', 0, 60, '7'),

        'VACANTE_DIAS_ANS'      => array('Plazos', 'Acuerdo de servicio de las vacantes (días hábiles)',
                                     'Días hábiles, sin festivos, para cubrir una vacante. Aplica a las vacantes nuevas; las registradas conservan su fecha.', 'NUMERO', 1, 60, '5'),
        'COMUNICACION_DIAS_RESPUESTA' => array('Plazos', 'Plazo para atender una comunicación (días)', null, 'NUMERO', 0, 60, '2'),
        'RQ_DIAS_APROBACION'    => array('Plazos', 'Días para aprobar o rechazar una RQ', 'Después de este plazo la RQ se marca como atrasada.', 'NUMERO', 1, 60, '3'),
        'NOV_DIAS_ALTO'         => array('Plazos', 'Días para atender una novedad de prioridad ALTO',
                                     'La fecha límite de una novedad nueva es el día en que se reporta más estos días. Las novedades ya registradas conservan su fecha.', 'NUMERO', 1, 60, '4'),
        'NOV_DIAS_MEDIO'        => array('Plazos', 'Días para atender una novedad de prioridad MEDIO', null, 'NUMERO', 1, 60, '6'),
        'NOV_DIAS_BAJO'         => array('Plazos', 'Días para atender una novedad de prioridad BAJO', null, 'NUMERO', 1, 60, '8'),

        'PROMESA_DIAS_CORREGIBLES' => array('Promesa de valor', 'Días hacia atrás que el coordinador puede registrar o corregir',
                                     'Además de hoy y de los días que vienen: promesa, ejecutado y justificación. Lo anterior queda solo para consulta. Quien tenga la casilla «Corregir días pasados» corrige cualquier día.', 'NUMERO', 0, 60, '7'),

        //Los parámetros del ausentismo (AUS_MESES_CORREGIBLES, AUS_DIAS_FUTURO) se retiraron junto con el módulo.

        'ADJUNTOS_COMUNICACIONES' => array('Archivos adjuntos', 'Permitir archivos en comunicaciones y oficios', null, 'SI_NO', null, null, '1'),
        'ADJUNTOS_LISTAS'       => array('Archivos adjuntos', 'Permitir archivos en listas de chequeo', 'Por ejemplo, fotos de evidencia de una falla.', 'SI_NO', null, null, '1'),
        'ADJUNTOS_MAX_MB'       => array('Archivos adjuntos', 'Tamaño máximo de cada archivo (MB)',
                                     null, 'NUMERO', 1, 50, '10'),

        'CLAVE_LARGO_MINIMO'    => array('Contraseñas e ingreso', 'Caracteres mínimos de una contraseña',
                                     'Aplica a las contraseñas nuevas: las que asigna el administrador y las que cambia cada persona. Las que ya existen siguen sirviendo.', 'NUMERO', 4, 30, '4'),
        'INGRESO_INTENTOS'      => array('Contraseñas e ingreso', 'Intentos con la contraseña equivocada antes de tener que esperar',
                                     'Seguidos, con el mismo usuario.', 'NUMERO', 3, 20, '5'),
        'INGRESO_MINUTOS_ESPERA' => array('Contraseñas e ingreso', 'Minutos de espera después de esos intentos', null, 'NUMERO', 1, 240, '15'),
        'CLAVE_MINUTOS_ENLACE'  => array('Contraseñas e ingreso', 'Minutos que sirve el enlace de «Olvidé mi contraseña»', null, 'NUMERO', 10, 1440, '60'),
    );

    //Las listas de opciones que el administrador edita (Parámetros del sistema → Listas).
    //Se guardan en la tabla configuracion, como las demás, con su CLAVE; el valor es la lista
    //en JSON: [["CODIGO", "Nombre"], ...]. En los registros se guarda el CODIGO, así que
    //cambiarle el nombre a una opción cambia cómo se ve en todos los registros, viejos y nuevos.
    //CLAVE => grupo, nombre, ayuda, largo máximo del código (el de la columna), códigos fijos
    //(los que el sistema usa en su lógica: se les cambia el nombre, pero no se quitan),
    //la columna donde se guarda (para no quitar una opción que ya se usó) y la lista de fábrica
    const LISTAS = array(
        'LISTA_MEDIOS'      => array('Comunicaciones y oficios', 'Medios por los que llega un oficio', null,
                                     20, array(), 'comunicacion.MEDIO',
                                     array('CORREO' => 'Correo electrónico', 'OFICIO' => 'Oficio físico', 'LLAMADA' => 'Llamada', 'OTRO' => 'Otro')),
        'LISTA_PENDIENTE'   => array('Comunicaciones y oficios', 'A quién le corresponde el siguiente paso («Pendiente de»)', null,
                                     20, array(), 'comunicacion.PENDIENTE_DE',
                                     array('COORDINADOR_OP' => 'Coordinador operativo', 'DIRECCION' => 'Dirección', 'COMUNICACIONES' => 'Comunicaciones',
                                           'GH' => 'Gestión Humana', 'CONTABLE' => 'Contable')),
        'LISTA_TRANSPORTES' => array('Cronograma y vehículo', 'Cómo se transportó el coordinador cuando el vehículo no estaba operativo', null,
                                     15, array(), 'vehiculo_dia.TRANSPORTE',
                                     array('BUS' => 'Bus', 'TAXI' => 'Taxi', 'APP' => 'Aplicación (Didi, Uber…)', 'PARTICULAR' => 'Vehículo particular',
                                           'OTRO' => 'Otro', 'NO_VIAJO' => 'No se desplazó')),
        'LISTA_TIPOS_CRONO' => array('Cronograma y vehículo', 'Tipos de actividad del cronograma',
                                     'Visita (pide el peaje), Descanso y Vacaciones tienen reglas propias: se les puede cambiar el nombre, pero no se quitan. Las nuevas funcionan como Reunión (piden una descripción).',
                                     12, array('VISITA', 'DESCANSO', 'VACACIONES'), 'cronograma.TIPO',
                                     array('VISITA' => 'Visita', 'REUNION' => 'Reunión', 'DESCANSO' => 'Descanso', 'VACACIONES' => 'Vacaciones', 'OTRO' => 'Otro')),
        'LISTA_ESTADOS_GH'  => array('Vacantes', 'Estados del proceso en Gestión Humana', null,
                                     15, array(), 'vacante.ESTADO_GH',
                                     array('RECLUTAMIENTO' => 'Reclutamiento', 'EMO' => 'EMO', 'DOCUMENTOS' => 'Documentos', 'CONTRATACION' => 'Contratación')),
        'LISTA_UNIDADES_RQ' => array('Requisiciones (RQ)', 'Unidades de medida de los ítems de una RQ',
                                     '«Unidad» es la que sale por defecto: se le puede cambiar el nombre, pero no se quita.',
                                     30, array('UNIDAD'), 'rq_detalle.UNIDAD',
                                     array('UNIDAD' => 'Unidad', 'SERVICIO' => 'Servicio', 'CAJA' => 'Caja', 'PAQUETE' => 'Paquete', 'BOLSA' => 'Bolsa',
                                           'PAR' => 'Par', 'JUEGO' => 'Juego', 'ROLLO' => 'Rollo', 'METRO' => 'Metro', 'METRO_CUADRADO' => 'Metro cuadrado',
                                           'LITRO' => 'Litro', 'GALON' => 'Galón', 'BOTELLON' => 'Botellón', 'KILO' => 'Kilo')),
    );
    const LISTA_MAX_OPCIONES = 30;     //opciones por lista
    const LISTA_LARGO_NOMBRE = 60;     //caracteres del nombre de cada opción

    private static $valores = null;

    //Lee todos los parámetros de la tabla configuracion una sola vez por petición
    private static function cargar()
    {
        if (self::$valores !== null) { return; }
        self::$valores = array();
        try {
            $filas = HanaDB::q("SELECT CLAVE, VALOR FROM configuracion");
            //Si la tabla de parámetros no existe (base sin actualizar), q() devuelve false:
            //se usan los valores por defecto, sin avisos en pantalla
            if (is_array($filas)) {
                foreach ($filas as $fila) { self::$valores[$fila['CLAVE']] = $fila['VALOR']; }
            }
        } catch (Throwable $error) {
            //Sin la tabla de parámetros: valores por defecto
            error_log('HANA - parámetros no disponibles: ' . $error->getMessage());
        }
    }

    //El valor de un parámetro tal como está guardado, o el valor por defecto
    public static function valor($clave, $defecto)
    {
        self::cargar();
        return isset(self::$valores[$clave]) ? self::$valores[$clave] : $defecto;
    }

    //El valor de un parámetro como número
    public static function num($clave, $defecto)
    {
        return (int)self::valor($clave, $defecto);
    }

    //¿El parámetro está encendido? (guardado como 1)
    public static function si($clave, $defecto = true)
    {
        return (string)self::valor($clave, $defecto ? '1' : '0') === '1';
    }

    //Los módulos del reporte diario que el administrador puede apagar
    public static function modulo($nombre)
    {
        return self::si('MODULO_' . $nombre, true);
    }

    //Una lista de opciones: [CODIGO => Nombre], en el orden que dejó el administrador.
    //Si no la ha cambiado (o lo guardado no se entiende), la de fábrica. Los códigos fijos
    //nunca faltan: si no están en lo guardado, se agregan al final con su nombre de fábrica
    public static function opciones($clave)
    {
        if (!isset(self::LISTAS[$clave])) { return array(); }
        $fabrica = self::LISTAS[$clave][6];
        $guardado = json_decode((string)self::valor($clave, ''), true);
        if (!is_array($guardado) || !count($guardado)) { return $fabrica; }
        $lista = array();
        foreach ($guardado as $par) {
            if (is_array($par) && isset($par[0], $par[1]) && is_string($par[0]) && is_string($par[1]) && $par[0] !== '' && trim($par[1]) !== '') {
                $lista[$par[0]] = $par[1];
            }
        }
        if (!count($lista)) { return $fabrica; }
        foreach (self::LISTAS[$clave][4] as $fijo) { if (!isset($lista[$fijo])) { $lista[$fijo] = $fabrica[$fijo]; } }
        return $lista;
    }

    //El nombre de una opción de una lista. Si el valor no es un código de la lista (un dato
    //viejo escrito a mano, como «Unidad» o «GAL»), se busca en mayúsculas y, si no, se deja tal cual
    public static function nombreOpcion($clave, $valor)
    {
        $opciones = self::opciones($clave);
        $valor = (string)$valor;
        if (isset($opciones[$valor])) { return $opciones[$valor]; }
        $mayus = function_exists('mb_strtoupper') ? mb_strtoupper($valor, 'UTF-8') : strtoupper($valor);
        return isset($opciones[$mayus]) ? $opciones[$mayus] : $valor;
    }

    //Olvida lo leído (después de guardar parámetros)
    public static function recargar()
    {
        self::$valores = null;
    }

    //La definición de un parámetro con los nombres de campo que usa la pantalla, o null si no existe
    public static function definicion($clave)
    {
        if (!isset(self::PARAMETROS[$clave])) { return null; }
        $parametro = self::PARAMETROS[$clave];
        return array('CLAVE' => $clave, 'GRUPO' => $parametro[0], 'NOMBRE' => $parametro[1], 'AYUDA' => $parametro[2], 'TIPO' => $parametro[3],
                     'MINIMO' => $parametro[4], 'MAXIMO' => $parametro[5], 'DEFECTO' => $parametro[6],
                     'ORDEN' => array_search($clave, array_keys(self::PARAMETROS), true) + 1);
    }

    //Todos los parámetros para la pantalla: la definición, el valor vigente y quién lo cambió por última vez
    public static function lista()
    {
        $guardados = array();
        foreach ((array)HanaDB::q("SELECT c.CLAVE, c.VALOR, c.ID_COLABORADOR_MODIFICA, c.FEC_MODIFICACION, col.NOM_COLABORADOR AS MODIFICO
                                      FROM configuracion c
                                      LEFT JOIN colaboradores col ON col.ID_COLABORADOR = c.ID_COLABORADOR_MODIFICA") as $guardado) {
            $guardados[$guardado['CLAVE']] = $guardado;
        }
        $lista = array();
        foreach (array_keys(self::PARAMETROS) as $clave) {
            $definicion = self::definicion($clave);
            $guardado = isset($guardados[$clave]) ? $guardados[$clave] : null;
            $definicion['VALOR'] = $guardado ? (string)$guardado['VALOR'] : $definicion['DEFECTO'];
            $definicion['ID_COLABORADOR_MODIFICA'] = $guardado ? $guardado['ID_COLABORADOR_MODIFICA'] : null;
            $definicion['FEC_MODIFICACION'] = $guardado ? $guardado['FEC_MODIFICACION'] : null;
            $definicion['MODIFICO'] = $guardado ? $guardado['MODIFICO'] : null;
            unset($definicion['DEFECTO']);
            $lista[] = $definicion;
        }
        return $lista;
    }

    //Guarda el valor que eligió el administrador (si el parámetro no tenía fila, la crea)
    public static function guardar($clave, $valor, $idColaborador, $ahora)
    {
        return HanaDB::q("INSERT INTO configuracion (CLAVE, VALOR, ID_COLABORADOR_MODIFICA, FEC_MODIFICACION) VALUES (?, ?, ?, ?)
                          ON DUPLICATE KEY UPDATE VALOR = VALUES(VALOR), ID_COLABORADOR_MODIFICA = VALUES(ID_COLABORADOR_MODIFICA),
                                                  FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                         'ssis', array($clave, $valor, (int)$idColaborador, $ahora));
    }
}
