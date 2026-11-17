<?php
/*
  HANA - Avisos por respuesta en las listas de chequeo
  ---------------------------------------------------------------------------
  En «Editar listas», cada pregunta puede tener un aviso: «si alguien responde
  esto, mándale un correo a estas personas o a estos roles». Por ejemplo: si a
  «¿La talanquera funciona?» responden No, le llega un correo al jefe de
  mantenimiento.

  Sirve para las preguntas de respuesta fija:
    si/no   1 = Sí, 2 = No, 3 = No aplica (así se guardan en detalle_lista_chequeo)
    lista   Bueno, Regular, Malo, No Aplica

  NO TIENE TABLA PROPIA (no se cambia la base): cada aviso es una fila de la
  tabla `configuracion`, la misma de los parámetros del sistema:
    CLAVE  = 'AVISO_PREGUNTA_' + número de la pregunta (ID_DETALLE_GRUPO_LISTA_CHEQUEO)
    VALOR  = JSON corto: {"on":1,"r":["2"],"p":[12,45],"o":[3]}
               on  1 = el aviso está encendido, 0 = apagado (no se borra: queda quién lo apagó)
               r   las respuestas que lo disparan
               p   personas (ID_COLABORADOR) a las que les llega
               o   roles (ID_ROL_USUARIO_SISTEMA): les llega a las personas de ese rol
                   que tienen asignado el peaje de la lista (o que ven todo, 24M)
    ID_COLABORADOR_MODIFICA y FEC_MODIFICACION: quién lo cambió por última vez y cuándo.
  VALOR tiene hasta 255 caracteres: alcanza para unas 30 personas y roles por pregunta.
  Estas claves no son parámetros: la pantalla de Parámetros solo muestra las de
  Modelo/HanaConfig.php, así que no salen allá.

  Cada correo que sale queda en bitacora_sistema (MODULO = 'AVISO_RESPUESTA', con
  la lista que lo disparó), y se ve en Trazabilidad.
*/
require_once __DIR__ . "/HanaDB.php";

class AvisoRespuesta
{
    const PREFIJO = 'AVISO_PREGUNTA_';
    const LARGO = 255; //configuracion.VALOR

    //Las respuestas posibles de cada tipo de pregunta: [valor guardado => texto que ve la gente]
    public static $RESPUESTAS = array(
        'si/no' => array('1' => 'Sí', '2' => 'No', '3' => 'No aplica'),
        'lista' => array('Bueno' => 'Bueno', 'Regular' => 'Regular', 'Malo' => 'Malo', 'No Aplica' => 'No aplica')
    );

    //Las respuestas posibles de una pregunta, o un arreglo vacío si su tipo no tiene respuestas fijas
    public static function respuestasDeTipo($tipo)
    {
        return isset(self::$RESPUESTAS[$tipo]) ? self::$RESPUESTAS[$tipo] : array();
    }

    //Una pregunta con su lista y su tipo de respuesta, o null si no existe
    public static function pregunta($idPregunta)
    {
        return HanaDB::fila("SELECT d.ID_DETALLE_GRUPO_LISTA_CHEQUEO AS ID, d.PREGUNTA, d.TIPO_RESPUESTA, d.ID_GRUPO_LISTA_CHEQUEO, g.NOM_GRUPO_LISTA_CHEQUEO
                               FROM detalle_grupo_lista_chequeo d
                               INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = d.ID_GRUPO_LISTA_CHEQUEO
                              WHERE d.ID_DETALLE_GRUPO_LISTA_CHEQUEO = ?", 'i', array((int)$idPregunta));
    }

    //Pasa el VALOR guardado a un arreglo limpio (on, r, p, o)
    private static function leer($valor)
    {
        $datos = json_decode((string)$valor, true);
        if (!is_array($datos)) { $datos = array(); }
        return array(
            'on' => !empty($datos['on']) ? 1 : 0,
            'r'  => isset($datos['r']) && is_array($datos['r']) ? array_values(array_map('strval', $datos['r'])) : array(),
            'p'  => isset($datos['p']) && is_array($datos['p']) ? array_values(array_map('intval', $datos['p'])) : array(),
            'o'  => isset($datos['o']) && is_array($datos['o']) ? array_values(array_map('intval', $datos['o'])) : array()
        );
    }

    //El aviso de una pregunta, con quién lo cambió por última vez. Sin aviso: apagado y vacío
    public static function obtener($idPregunta)
    {
        $fila = HanaDB::fila("SELECT c.VALOR, c.FEC_MODIFICACION, col.NOM_COLABORADOR AS MODIFICO
                                FROM configuracion c LEFT JOIN colaboradores col ON col.ID_COLABORADOR = c.ID_COLABORADOR_MODIFICA
                               WHERE c.CLAVE = ?", 's', array(self::PREFIJO . (int)$idPregunta));
        $aviso = self::leer($fila ? $fila['VALOR'] : '');
        $aviso['modifico'] = $fila ? $fila['MODIFICO'] : null;
        $aviso['fecha'] = $fila ? $fila['FEC_MODIFICACION'] : null;
        return $aviso;
    }

    //Guarda el aviso de una pregunta (encendido o apagado). Devuelve true, o un texto con el problema
    public static function guardar($idPregunta, $on, $respuestas, $personas, $roles, $idColaborador, $ahora)
    {
        $valor = json_encode(array('on' => $on ? 1 : 0, 'r' => array_values($respuestas),
                                   'p' => array_values(array_map('intval', $personas)), 'o' => array_values(array_map('intval', $roles))),
                             JSON_UNESCAPED_UNICODE);
        if (strlen($valor) > self::LARGO) { return 'Son demasiadas personas y roles para un solo aviso. Usa un rol en vez de tantas personas.'; }
        $ok = HanaDB::q("INSERT INTO configuracion (CLAVE, VALOR, ID_COLABORADOR_MODIFICA, FEC_MODIFICACION) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE VALOR = VALUES(VALOR), ID_COLABORADOR_MODIFICA = VALUES(ID_COLABORADOR_MODIFICA),
                                                 FEC_MODIFICACION = VALUES(FEC_MODIFICACION)",
                        'ssis', array(self::PREFIJO . (int)$idPregunta, $valor, (int)$idColaborador, $ahora));
        return $ok === true ? true : 'No se pudo guardar el aviso.';
    }

    //Los avisos encendidos de las preguntas de una lista (plantilla): [idPregunta => aviso]
    public static function deGrupo($idGrupo)
    {
        $filas = HanaDB::q("SELECT d.ID_DETALLE_GRUPO_LISTA_CHEQUEO AS ID, c.VALOR
                              FROM detalle_grupo_lista_chequeo d
                              INNER JOIN configuracion c ON c.CLAVE = CONCAT(?, d.ID_DETALLE_GRUPO_LISTA_CHEQUEO)
                             WHERE d.ID_GRUPO_LISTA_CHEQUEO = ?", 'si', array(self::PREFIJO, (int)$idGrupo));
        $avisos = array();
        foreach (is_array($filas) ? $filas : array() as $fila) {
            $aviso = self::leer($fila['VALOR']);
            if ($aviso['on'] && count($aviso['r']) && (count($aviso['p']) || count($aviso['o']))) { $avisos[(int)$fila['ID']] = $aviso; }
        }
        return $avisos;
    }

    //Para elegir a quién: las personas activas con correo y los roles activos
    public static function personas()
    {
        $filas = HanaDB::q("SELECT ID_COLABORADOR AS id, NOM_COLABORADOR AS nombre FROM colaboradores
                             WHERE ESTADO = 1 AND MAIL_COLABORADOR IS NOT NULL AND MAIL_COLABORADOR <> '' ORDER BY NOM_COLABORADOR");
        return is_array($filas) ? $filas : array();
    }
    public static function roles()
    {
        $filas = HanaDB::q("SELECT ID_ROL_USUARIO_SISTEMA AS id, NOM_ROL_USUARIO_SISTEMA AS nombre FROM rol_usuarios_sistemas
                             WHERE ESTADO = 1 ORDER BY NOM_ROL_USUARIO_SISTEMA");
        return is_array($filas) ? $filas : array();
    }

    //Los correos a los que les llega un aviso en un peaje: las personas elegidas (activas) y las de los
    //roles elegidos que tienen ese peaje asignado o que ven todo (24M), con acceso a HANA.
    //Devuelve [correo => nombre]
    public static function destinatarios($aviso, $idCentro)
    {
        $correos = array();
        if (count($aviso['p'])) {
            $marcas = implode(',', array_fill(0, count($aviso['p']), '?'));
            $filas = HanaDB::q("SELECT MAIL_COLABORADOR AS CORREO, NOM_COLABORADOR AS NOMBRE FROM colaboradores
                                 WHERE ESTADO = 1 AND ID_COLABORADOR IN ($marcas)", str_repeat('i', count($aviso['p'])), $aviso['p']);
            foreach (is_array($filas) ? $filas : array() as $fila) { $correos[trim($fila['CORREO'])] = $fila['NOMBRE']; }
        }
        if (count($aviso['o'])) {
            $marcas = implode(',', array_fill(0, count($aviso['o']), '?'));
            $filas = HanaDB::q("SELECT c.MAIL_COLABORADOR AS CORREO, c.NOM_COLABORADOR AS NOMBRE
                                  FROM usuarios_sistema u
                                  INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR AND c.ESTADO = 1
                                  INNER JOIN rol_usuarios_sistemas r ON r.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA
                                 WHERE u.ESTADO = 1 AND u.ID_ROL_USUARIO_SISTEMA IN ($marcas)
                                   AND (FIND_IN_SET('24M', REPLACE(r.PERMISOS, ' ', '')) > 0
                                        OR EXISTS (SELECT 1 FROM asoc_usuarios_sistemas_x_cop a WHERE a.ID_COLABORADOR = c.ID_COLABORADOR AND a.ID_CENTRO_OP = ?))",
                               str_repeat('i', count($aviso['o'])) . 'i', array_merge($aviso['o'], array((int)$idCentro)));
            foreach (is_array($filas) ? $filas : array() as $fila) { $correos[trim($fila['CORREO'])] = $fila['NOMBRE']; }
        }
        foreach (array_keys($correos) as $correo) { if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) { unset($correos[$correo]); } }
        return $correos;
    }

    //Lo que hace falta de una lista diligenciada para revisar sus avisos: la cabecera y las respuestas
    public static function lista($idLista)
    {
        $cabecera = HanaDB::fila("SELECT lc.ID_LISTA_CHEQUEO, lc.ID_CENTRO_OP, lc.ID_GRUPO_LISTA_CHEQUEO, lc.FEC_REGISTRO,
                                         co.NOM_CENTRO_OP, p.NOM_PROYECTO, c.NOM_COLABORADOR, g.NOM_GRUPO_LISTA_CHEQUEO
                                    FROM lista_chequeo lc
                                    INNER JOIN centros_operacion co ON co.ID_CENTRO_OP = lc.ID_CENTRO_OP
                                    INNER JOIN proyectos p ON p.ID_PROYECTO = co.ID_PROYECTO
                                    INNER JOIN colaboradores c ON c.ID_COLABORADOR = lc.ID_COLABORADOR
                                    INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = lc.ID_GRUPO_LISTA_CHEQUEO
                                   WHERE lc.ID_LISTA_CHEQUEO = ?", 'i', array((int)$idLista));
        if (!$cabecera) { return null; }
        $filas = HanaDB::q("SELECT r.ID_PREGUNTA, r.RESPUESTA, d.PREGUNTA, d.TIPO_RESPUESTA
                              FROM detalle_lista_chequeo r
                              INNER JOIN detalle_grupo_lista_chequeo d ON d.ID_DETALLE_GRUPO_LISTA_CHEQUEO = r.ID_PREGUNTA
                             WHERE r.ID_LISTA_CHEQUEO = ? ORDER BY d.ORDEN, d.ID_DETALLE_GRUPO_LISTA_CHEQUEO", 'i', array((int)$idLista));
        $cabecera['respuestas'] = is_array($filas) ? $filas : array();
        return $cabecera;
    }
}
