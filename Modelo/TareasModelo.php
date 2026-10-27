<?php
/*
  HANA - Recordatorios y avisos automáticos (lo que se puede configurar de cada uno)
  ---------------------------------------------------------------------------
  Cada tarea automática tiene su configuración en la tabla configuracion, con la
  clave TAREA_<NOMBRE> y un JSON en el valor (no hay una tabla aparte):
      activo   1 = se hace; 0 = no
      hora     la hora del día (0 a 23, hora de Colombia) a partir de la cual se hace
      dias     los días de la semana (1 = lunes ... 7 = domingo)
      roles    a qué roles les llega (ID_ROL_USUARIO_SISTEMA). Vacío = a ningún rol
               (los destinatarios se eligen en la pantalla; no hay valor por defecto desde el código)
      personas a qué personas puntuales les llega (ID_COLABORADOR), además de los roles
      momento  el aviso de la promesa: INMEDIATO (en cuanto se registra) o SIGUIENTE_DIA (desde la hora)
      correo   1 = el aviso de la promesa también sale por correo en el momento en que se registra
      items    qué se revisa (solo los recordatorios)
      correos  correos adicionales que reciben el resumen (solo el de la promesa)
      coordinador  1 = al coordinador del proyecto (solo el aviso de la promesa)
      nota     un texto opcional que se agrega al correo
  La pantalla es Reporte diario → Correos automáticos (solo el ADMIN TEC, 24M).
  Las corre tareas/programador.php, que cron llama cada hora; cada vez que una tarea
  corre queda en bitacora_sistema (TIPO 'TAREA', MODULO = su nombre).
*/
require_once __DIR__ . "/HanaDB.php";
require_once __DIR__ . "/HanaConfig.php";

class Tareas
{
    //Las tareas: nombre, qué hace, cómo se entrega (CORREO o CAMPANA), qué se puede configurar
    //y sus valores de fábrica
    const DEFINICIONES = array(
        'RECORDATORIO_MANANA' => array(
            'nombre' => 'Recordatorio de la mañana',
            'descripcion' => 'A cada persona le llega lo que le falta del día. Si está al día, no le llega nada.',
            'entrega' => 'CORREO', 'campos' => array('hora', 'dias', 'roles', 'items', 'nota'),
            'items' => array('HOY' => 'Hoy en qué estás', 'CRONOGRAMA' => 'Cronograma y estado del vehículo',
                             'VACANTES' => 'Vacantes abiertas que no ha revisado hoy', 'COMUNICACIONES' => 'Comunicaciones sin atender que no ha revisado hoy',
                             'PROMESA' => 'Promesa de valor de ayer por debajo, sin justificar'),
            'fabrica' => array('activo' => 1, 'hora' => 9, 'dias' => array(1, 2, 3, 4, 5, 6), 'roles' => array(),
                               'items' => array('HOY', 'CRONOGRAMA', 'VACANTES', 'COMUNICACIONES', 'PROMESA'), 'nota' => '')),
        'RECORDATORIO_TARDE' => array(
            'nombre' => 'Recordatorio de la tarde',
            'descripcion' => 'Lo mismo, con lo que se llena al terminar el día.',
            'entrega' => 'CORREO', 'campos' => array('hora', 'dias', 'roles', 'items', 'nota'),
            'items' => array('LISTAS' => 'Listas de chequeo', 'ARQUEOS' => 'Arqueos'),
            'fabrica' => array('activo' => 1, 'hora' => 17, 'dias' => array(1, 2, 3, 4, 5, 6), 'roles' => array(),
                               'items' => array('LISTAS', 'ARQUEOS'), 'nota' => '')),
        'PROMESA_AVISO' => array(
            'nombre' => 'Aviso: promesa de valor por debajo',
            'descripcion' => 'Cuando un peaje queda por debajo de la promesa, el aviso aparece en la campana de las personas elegidas (y, si se marca, les llega por correo), con los cargos y las horas. Mientras no se cambie, le llega a la dirección.',
            'entrega' => 'CAMPANA', 'campos' => array('momento', 'hora', 'coordinador', 'roles', 'personas', 'correo'),
            'fabrica' => array('activo' => 1, 'momento' => 'INMEDIATO', 'hora' => 8, 'coordinador' => 1, 'roles' => array(), 'personas' => array(), 'correo' => 0)),
        'PROMESA_RESUMEN' => array(
            'nombre' => 'Correo con el resultado de la promesa de valor',
            'descripcion' => 'El balance de ayer de cada peaje: si se cumplió, si quedó por debajo o por encima, y las justificaciones. Mientras no se cambie, les llega a los roles de director.',
            'entrega' => 'CORREO', 'campos' => array('hora', 'dias', 'roles', 'personas', 'correos', 'nota'),
            'fabrica' => array('activo' => 1, 'hora' => 1, 'dias' => array(1, 2, 3, 4, 5, 6, 7), 'roles' => array(), 'personas' => array(), 'correos' => '', 'nota' => '')),
        'RESUMEN_LISTAS_DIARIO' => array(
            'nombre' => 'Resumen diario de las listas de chequeo',
            'descripcion' => 'Las listas que se llenaron ayer. A quién le llega se elige en cada aviso de abajo (frecuencia «Resumen diario»).',
            'entrega' => 'CORREO', 'campos' => array('hora', 'dias'),
            'fabrica' => array('activo' => 1, 'hora' => 6, 'dias' => array(1, 2, 3, 4, 5, 6, 7))),
        'RESUMEN_LISTAS_SEMANAL' => array(
            'nombre' => 'Resumen semanal de las listas de chequeo',
            'descripcion' => 'Las listas de los últimos 7 días. A quién le llega se elige en cada aviso de abajo (frecuencia «Resumen semanal»).',
            'entrega' => 'CORREO', 'campos' => array('hora', 'dias'),
            'fabrica' => array('activo' => 1, 'hora' => 6, 'dias' => array(1))),
    );
    const HORAS_TOLERANCIA = 3; //si cron no corrió a la hora exacta, la tarea se hace hasta 3 horas después

    //La configuración vigente de una tarea: la guardada sobre la de fábrica
    public static function config($clave)
    {
        if (!isset(self::DEFINICIONES[$clave])) { return null; }
        $config = self::DEFINICIONES[$clave]['fabrica'];
        //El correo del resultado de la promesa estaba antes en Parámetros del sistema (PROMESA_CORREO_RESUMEN):
        //si ya se había escrito allí, se toma como punto de partida
        if ($clave === 'PROMESA_RESUMEN') { $config['correos'] = (string)HanaConfig::valor('PROMESA_CORREO_RESUMEN', ''); }
        $guardado = json_decode((string)HanaConfig::valor('TAREA_' . $clave, ''), true);
        //El balance diario y el aviso de la promesa son para la dirección de operaciones: mientras nadie
        //los configure, les llegan a los roles que tienen DIRECTOR en el nombre
        if (($clave === 'PROMESA_RESUMEN' || $clave === 'PROMESA_AVISO') && !is_array($guardado)) {
            foreach (self::roles() as $rol) { if (stripos($rol['NOMBRE'], 'DIRECTOR') !== false) { $config['roles'][] = (int)$rol['ID']; } }
        }
        if (is_array($guardado)) {
            foreach ($config as $campo => $valor) { if (array_key_exists($campo, $guardado)) { $config[$campo] = $guardado[$campo]; } }
        }
        $config['activo'] = (int)!empty($config['activo']);
        $config['hora'] = max(0, min(23, (int)$config['hora']));
        if (isset($config['dias'])) { $config['dias'] = array_values(array_intersect(array(1, 2, 3, 4, 5, 6, 7), array_map('intval', (array)$config['dias']))); }
        if (isset($config['roles'])) { $config['roles'] = array_values(array_unique(array_map('intval', (array)$config['roles']))); }
        if (isset($config['personas'])) { $config['personas'] = array_values(array_unique(array_map('intval', (array)$config['personas']))); }
        if (isset($config['momento'])) { $config['momento'] = $config['momento'] === 'SIGUIENTE_DIA' ? 'SIGUIENTE_DIA' : 'INMEDIATO'; }
        if (isset($config['correo'])) { $config['correo'] = (int)!empty($config['correo']); }
        if (isset($config['items'])) { $config['items'] = array_values(array_intersect(array_keys(self::DEFINICIONES[$clave]['items']), (array)$config['items'])); }
        return $config;
    }

    //Guarda la configuración de una tarea (ya revisada por el controlador)
    public static function guardar($clave, $config, $idColaborador, $ahora)
    {
        return HanaConfig::guardar('TAREA_' . $clave, json_encode($config, JSON_UNESCAPED_UNICODE), $idColaborador, $ahora);
    }

    //Los roles activos, para elegir a quién le llega
    public static function roles()
    {
        $filas = HanaDB::q("SELECT ID_ROL_USUARIO_SISTEMA AS ID, NOM_ROL_USUARIO_SISTEMA AS NOMBRE FROM rol_usuarios_sistemas WHERE ESTADO = 1 ORDER BY NOM_ROL_USUARIO_SISTEMA");
        return is_array($filas) ? $filas : array();
    }

    //Los roles a los que les llega un recordatorio: EXACTAMENTE los que estén marcados en la
    //configuración. Si no hay ninguno marcado, no le llega a ningún rol (todo se controla desde
    //la pantalla, sin destinatarios por defecto puestos desde el código).
    public static function rolesDe($config)
    {
        return !empty($config['roles']) ? $config['roles'] : array();
    }

    //Las personas activas, con usuario activo, de unos roles
    public static function personasDeRoles($idsRoles)
    {
        $idsRoles = array_values(array_filter(array_map('intval', (array)$idsRoles)));
        if (!count($idsRoles)) { return array(); }
        $marcas = implode(',', array_fill(0, count($idsRoles), '?'));
        $filas = HanaDB::q("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR
                              FROM usuarios_sistema u INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                             WHERE u.ESTADO = 1 AND c.ESTADO = 1 AND u.ID_ROL_USUARIO_SISTEMA IN ($marcas)
                             ORDER BY c.NOM_COLABORADOR", str_repeat('i', count($idsRoles)), $idsRoles);
        return is_array($filas) ? $filas : array();
    }

    //Las personas que se pueden elegir una por una: activas y con usuario activo, con su rol
    public static function personasActivas()
    {
        $filas = HanaDB::q("SELECT c.ID_COLABORADOR AS ID, c.NOM_COLABORADOR AS NOMBRE, r.NOM_ROL_USUARIO_SISTEMA AS ROL, c.MAIL_COLABORADOR AS CORREO
                              FROM usuarios_sistema u
                             INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                             INNER JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                             WHERE u.ESTADO = 1 AND c.ESTADO = 1
                             ORDER BY c.NOM_COLABORADOR");
        return is_array($filas) ? $filas : array();
    }

    //A quién le llega un aviso o un correo: las personas de los roles elegidos más las personas
    //elegidas una por una (sin repetir). [ID_COLABORADOR => fila con NOM_COLABORADOR y MAIL_COLABORADOR]
    public static function destinatarios($config)
    {
        $lista = array();
        foreach (self::personasDeRoles(isset($config['roles']) ? $config['roles'] : array()) as $persona) { $lista[(int)$persona['ID_COLABORADOR']] = $persona; }
        $ids = array_values(array_filter(array_map('intval', isset($config['personas']) ? (array)$config['personas'] : array())));
        if (count($ids)) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $filas = HanaDB::q("SELECT c.ID_COLABORADOR, c.NOM_COLABORADOR, c.MAIL_COLABORADOR
                                  FROM usuarios_sistema u INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                                 WHERE u.ESTADO = 1 AND c.ESTADO = 1 AND c.ID_COLABORADOR IN ($marcas)", str_repeat('i', count($ids)), $ids);
            foreach (is_array($filas) ? $filas : array() as $persona) { $lista[(int)$persona['ID_COLABORADOR']] = $persona; }
        }
        return $lista;
    }

    //¿Le toca hoy y a esta hora? (y no ha corrido hoy)
    public static function leToca($clave, $momento)
    {
        $config = self::config($clave);
        if (!$config || !$config['activo'] || self::DEFINICIONES[$clave]['entrega'] !== 'CORREO') { return false; }
        if (!in_array((int)date('N', $momento), $config['dias'], true)) { return false; }
        $hora = (int)date('G', $momento);
        if ($hora < $config['hora'] || $hora > $config['hora'] + self::HORAS_TOLERANCIA) { return false; }
        return !self::corrioHoy($clave, date('Y-m-d', $momento));
    }

    //¿Ya corrió ese día? (enviando de verdad, no en simulación)
    public static function corrioHoy($clave, $dia)
    {
        $siguiente = date('Y-m-d', strtotime("$dia +1 day"));
        return (bool)HanaDB::fila("SELECT 1 AS ok FROM bitacora_sistema WHERE TIPO = 'TAREA' AND MODULO = ? AND FECHA >= ? AND FECHA < ?",
                                  'sss', array($clave, $dia, $siguiente));
    }

    //Deja constancia de que corrió: quién la lanzó, cuántos correos salieron y si hubo errores
    public static function anotar($clave, $quien, $enviados, $fallos, $resumen, $ahora)
    {
        return HanaDB::q("INSERT INTO bitacora_sistema (TIPO, FECHA, MODULO, QUIEN, DETALLE, OK, ERROR, FILAS) VALUES ('TAREA', ?, ?, ?, ?, ?, ?, ?)",
                         'ssssisi', array($ahora, $clave, mb_substr((string)$quien, 0, 500, 'UTF-8'), mb_substr((string)$resumen, 0, 60, 'UTF-8'),
                                          $fallos ? 0 : 1, $fallos ? mb_substr($fallos . ' correo(s) no salieron', 0, 500, 'UTF-8') : null, (int)$enviados));
    }

    //La última vez que corrió cada tarea: [clave => fila]
    public static function ultimas()
    {
        $filas = HanaDB::q("SELECT b.MODULO, b.FECHA, b.QUIEN, b.DETALLE, b.OK, b.FILAS FROM bitacora_sistema b
                             INNER JOIN (SELECT MODULO, MAX(ID_BITACORA) AS ULTIMA FROM bitacora_sistema WHERE TIPO = 'TAREA' GROUP BY MODULO) u
                                     ON u.ULTIMA = b.ID_BITACORA");
        $ultimas = array();
        foreach (is_array($filas) ? $filas : array() as $fila) { $ultimas[$fila['MODULO']] = $fila; }
        return $ultimas;
    }
}
