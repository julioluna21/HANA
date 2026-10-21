<?php
/*
  HANA - Parámetros del sistema
  ---------------------------------------------------------------------------
  Las decisiones del negocio que el administrador cambia desde
  Configuración → Parámetros del sistema, sin tocar el código.
  Cada parámetro se define aquí (PARAMETROS: grupo, nombre, ayuda, tipo,
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
    //CLAVE => grupo, nombre, ayuda, tipo (SI_NO o NUMERO), mínimo, máximo y valor por defecto.
    //El orden de esta lista es el orden en que salen en la pantalla
    const PARAMETROS = array(
        'MODULO_HOY'            => array('Módulos del reporte diario', 'Hoy en qué estás', 'Si se apaga, desaparece del menú y nadie lo puede llenar.', 'SI_NO', null, null, '1'),
        'MODULO_LISTAS'         => array('Módulos del reporte diario', 'Listas de chequeo', null, 'SI_NO', null, null, '1'),
        'MODULO_ARQUEOS'        => array('Módulos del reporte diario', 'Arqueos', null, 'SI_NO', null, null, '1'),
        'MODULO_CRONOGRAMA'     => array('Módulos del reporte diario', 'Cronograma y vehículo', null, 'SI_NO', null, null, '1'),
        'MODULO_VACANTES'       => array('Módulos del reporte diario', 'Vacantes', null, 'SI_NO', null, null, '1'),
        'MODULO_COMUNICACIONES' => array('Módulos del reporte diario', 'Comunicaciones y oficios', null, 'SI_NO', null, null, '1'),
        'MODULO_AUSENTISMO'     => array('Módulos del reporte diario', 'Ausentismo', null, 'SI_NO', null, null, '1'),

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

        'AUS_MESES_CORREGIBLES' => array('Ausentismo', 'Meses anteriores que se pueden corregir',
                                     'Además del mes actual, cuántos meses atrás se pueden corregir mientras no estén cerrados.', 'NUMERO', 0, 12, '1'),
        'AUS_DIAS_FUTURO'       => array('Ausentismo', 'Días hacia adelante en que se puede registrar una ausencia',
                                     'Para dejar anotada una incapacidad, un permiso o unas vacaciones que ya se saben. Con 0 solo se registra hasta hoy.', 'NUMERO', 0, 31, '7'),
        'AUS_JEFES_EDITAN'      => array('Ausentismo', 'Los jefes de peaje registran el ausentismo de su peaje',
                                     'Cada jefe edita solo el peaje que tiene asignado en Centros de operación. El coordinador y quien tenga 25M editan todo el proyecto.', 'SI_NO', null, null, '1'),

        'ADJUNTOS_COMUNICACIONES' => array('Archivos adjuntos', 'Permitir archivos en comunicaciones y oficios', null, 'SI_NO', null, null, '1'),
        'ADJUNTOS_LISTAS'       => array('Archivos adjuntos', 'Permitir archivos en listas de chequeo', 'Por ejemplo, fotos de evidencia de una falla.', 'SI_NO', null, null, '1'),
        'ADJUNTOS_MAX_MB'       => array('Archivos adjuntos', 'Tamaño máximo de cada archivo (MB)',
                                     null, 'NUMERO', 1, 50, '10'),
    );

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
