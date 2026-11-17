<?php
/*
  HANA - Trazabilidad: qué se registró, cambió, anuló o envió, quién y cuándo
  ---------------------------------------------------------------------------
  No tiene tabla propia (no se cambia la base). Junta en una sola lista los
  rastros que el sistema ya guarda en cada tabla:

    historial           RQ (registro, cambios de estado y correcciones con los ítems de antes),
                        vacantes (quién deja el puesto),
                        novedades (respuestas), oficios y temas (seguimiento, cierre, reapertura)
                        y las versiones del reporte a la Gerencia
    bitacora_sistema    correos enviados (y los que fallaron), ingresos al sistema
    reporte_hoy, lista_chequeo, arqueo, cronograma, vehiculo_dia, vacante, comunicacion,
    novedades_hallazgos, pv_dia, adjunto
                        cuándo se registró o se modificó cada cosa y quién (FEC_REGISTRO,
                        FEC_MODIFICACION, ID_COLABORADOR...)
    dia_habilitado, configuracion, arqueo_fondo, usuarios_sistema
                        lo que cambia el administrador

  Cada evento sale con: FECHA, MODULO, ACCION, el proyecto y el peaje (si los
  tiene), QUIEN (la persona, si se sabe) y un DETALLE corto. Donde la tabla no
  guarda quién hizo el cambio (por ejemplo la última modificación de una
  vacante) QUIEN queda vacío: se sabe cuándo, no quién.

  Alcance: el ADMIN TEC (24M) ve todo. Los demás ven solo los eventos de sus
  peajes (o de sus proyectos, cuando el evento no es de un peaje); lo que no es
  de ningún peaje ni proyecto (ingresos, parámetros, usuarios) es solo del ADMIN TEC.
*/
require_once __DIR__ . "/HanaDB.php";

class Trazabilidad
{
    //Módulos, como salen en el filtro: [código => nombre]
    public static $MODULOS = array(
        'RQ' => 'Requisiciones (RQ)', 'NOVEDADES' => 'Novedades', 'COMUNICACIONES' => 'Comunicaciones y oficios',
        'TEMAS' => 'Temas con el cliente y Regency', 'GERENCIA' => 'Reporte a la Gerencia', 'HOY' => 'Hoy en qué estás',
        'LISTAS' => 'Listas de chequeo', 'ARQUEOS' => 'Arqueos', 'CRONOGRAMA' => 'Cronograma', 'VEHICULO' => 'Estado del vehículo',
        'VACANTES' => 'Vacantes', 'PROMESA' => 'Promesa de valor', 'ARCHIVOS' => 'Archivos adjuntos', 'CORREOS' => 'Correos enviados',
        'INGRESOS' => 'Ingresos al sistema', 'DIAS' => 'Días habilitados', 'PARAMETROS' => 'Parámetros y avisos',
        'FONDOS' => 'Fondos de caja', 'USUARIOS' => 'Usuarios'
    );

    //Tipos de acción, para el filtro (cada evento cae en uno): [código => nombre]
    public static $ACCIONES = array(
        'REGISTRO' => 'Registró', 'MODIFICACION' => 'Modificó o corrigió', 'ESTADO' => 'Cambió de estado',
        'SEGUIMIENTO' => 'Seguimiento o respuesta', 'CIERRE' => 'Cerró o finalizó', 'ANULACION' => 'Anuló o quitó',
        'CORREO' => 'Envió un correo', 'INGRESO' => 'Ingreso al sistema', 'CONFIGURACION' => 'Configuración'
    );

    //Las partes de la lista: cada una es un SELECT con las mismas columnas
    //(FECHA, MODULO, ACCION, TEXTO_ACCION, ID_CENTRO, ID_PROYECTO, ID_COLAB, DETALLE, REF).
    //$d y $h son las fechas ya validadas ('AAAA-MM-DD 00:00:00' y el día siguiente), entre comillas
    private static function partes($d, $h)
    {
        $estadoRq = function ($col) {
            return "CASE $col WHEN 1 THEN 'Solicitada' WHEN 3 THEN 'Aprobada' WHEN 7 THEN 'Rechazada' WHEN 8 THEN 'Anulada' ELSE CONCAT('Estado ', $col) END";
        };
        $estadoNov = "CASE h.ESTADO_NUEVO WHEN 1 THEN 'Registrada' WHEN 2 THEN 'En proceso' WHEN 3 THEN 'Finalizada' WHEN 4 THEN 'Cerrada' ELSE NULL END";
        return array(
            //RQ: registro, cambios de estado y correcciones (con los ítems de antes, ver RQControl op=editar)
            'RQ' => "SELECT h.FECHA, 'RQ' AS MODULO,
                        CASE WHEN h.TIPO = 'CORRECCION' THEN 'MODIFICACION' WHEN h.ESTADO_ANTERIOR IS NULL THEN 'REGISTRO' ELSE 'ESTADO' END AS ACCION,
                        CASE WHEN h.TIPO = 'CORRECCION' THEN 'Corrigió' WHEN h.ESTADO_ANTERIOR IS NULL THEN 'Registró'
                             ELSE CONCAT('De ', " . $estadoRq('h.ESTADO_ANTERIOR') . ", ' a ', " . $estadoRq('h.ESTADO_NUEVO') . ") END AS TEXTO_ACCION,
                        r.ID_CENTRO_OP AS ID_CENTRO, NULL AS ID_PROYECTO, h.ID_COLABORADOR AS ID_COLAB,
                        CONCAT('RQ ', r.NUMERO_RQ, IF(h.TEXTO IS NULL OR h.TEXTO = '', '', CONCAT(' · ', h.TEXTO))) AS DETALLE, CONCAT('RQ-', r.ID_RQ) AS REF
                   FROM historial h INNER JOIN rq r ON r.ID_RQ = h.ID_REGISTRO
                  WHERE h.MODULO = 'RQ' AND h.FECHA >= $d AND h.FECHA < $h",
            //Novedades: registro, respuestas y finalización
            'NOVEDADES' => "SELECT n.FEC_CREACION, 'NOVEDADES', 'REGISTRO', 'Reportó', n.ID_CENTRO_OP, NULL, n.ID_COLABORADOR_REPORTA,
                        CONCAT('Novedad #', n.ID_NOVEDADES_HALLAZGOS, ' · ', t.NOM_TITULO_NOVEDADES_HALLAZGOS, ' · ', LEFT(n.DESCRIPCION, 200)), CONCAT('NOV-', n.ID_NOVEDADES_HALLAZGOS)
                   FROM novedades_hallazgos n LEFT JOIN titulo_novedades_hallazgos t ON t.ID_TITULO_NOVEDADES_HALLAZGOS = n.ID_TITULO
                  WHERE n.FEC_CREACION >= $d AND n.FEC_CREACION < $h
                  UNION ALL
                 SELECT h.FECHA, 'NOVEDADES', 'SEGUIMIENTO', IFNULL(CONCAT('Respondió (quedó ', $estadoNov, ')'), 'Respondió'), n.ID_CENTRO_OP, NULL, h.ID_COLABORADOR,
                        CONCAT('Novedad #', n.ID_NOVEDADES_HALLAZGOS, IF(h.TEXTO IS NULL OR h.TEXTO = '', '', CONCAT(' · ', h.TEXTO))), CONCAT('NOV-', n.ID_NOVEDADES_HALLAZGOS)
                   FROM historial h INNER JOIN novedades_hallazgos n ON n.ID_NOVEDADES_HALLAZGOS = h.ID_REGISTRO
                  WHERE h.MODULO = 'NOVEDAD' AND h.FECHA >= $d AND h.FECHA < $h",
            //Oficios y temas: registro, modificación, anulación y su conversación (historial)
            'COMUNICACIONES' => "SELECT m.FEC_REGISTRO, IF(m.TIPO = 'TEMA_CLIENTE', 'TEMAS', 'COMUNICACIONES'), 'REGISTRO', 'Registró', m.ID_CENTRO_OP, m.ID_PROYECTO, m.ID_COLABORADOR_REGISTRA,
                        CONCAT(IF(m.TIPO = 'TEMA_CLIENTE', IF(m.RADICADO = 'REGENCY', 'Tema con Regency', 'Tema con el cliente'), 'Oficio'), ' #', m.ID_COMUNICACION, ' · ', LEFT(m.ASUNTO, 200)), CONCAT('COM-', m.ID_COMUNICACION)
                   FROM comunicacion m WHERE m.FEC_REGISTRO >= $d AND m.FEC_REGISTRO < $h
                  UNION ALL
                 SELECT m.FEC_MODIFICACION, IF(m.TIPO = 'TEMA_CLIENTE', 'TEMAS', 'COMUNICACIONES'), IF(m.ESTADO = 0, 'ANULACION', 'MODIFICACION'),
                        IF(m.ESTADO = 0, 'Anuló', 'Modificó (último cambio)'), m.ID_CENTRO_OP, m.ID_PROYECTO, NULL,
                        CONCAT(IF(m.TIPO = 'TEMA_CLIENTE', 'Tema', 'Oficio'), ' #', m.ID_COMUNICACION, ' · ', LEFT(m.ASUNTO, 200),
                               IF(m.ESTADO = 0 AND m.MOTIVO_ANULACION IS NOT NULL, CONCAT(' · Motivo: ', m.MOTIVO_ANULACION), '')), CONCAT('COM-', m.ID_COMUNICACION)
                   FROM comunicacion m WHERE m.FEC_MODIFICACION IS NOT NULL AND m.FEC_MODIFICACION >= $d AND m.FEC_MODIFICACION < $h
                  UNION ALL
                 SELECT h.FECHA, IF(m.TIPO = 'TEMA_CLIENTE', 'TEMAS', 'COMUNICACIONES'),
                        CASE h.TIPO WHEN 'RESUELTA' THEN 'CIERRE' WHEN 'REABIERTA' THEN 'ESTADO' ELSE 'SEGUIMIENTO' END,
                        CASE h.TIPO WHEN 'RESUELTA' THEN 'Cerró' WHEN 'REABIERTA' THEN 'Reabrió' WHEN 'RESPUESTA' THEN 'Respondió' ELSE 'Escribió en qué va' END,
                        m.ID_CENTRO_OP, m.ID_PROYECTO, h.ID_COLABORADOR,
                        CONCAT(IF(m.TIPO = 'TEMA_CLIENTE', 'Tema', 'Oficio'), ' #', m.ID_COMUNICACION, ' · ', LEFT(m.ASUNTO, 120),
                               IF(h.TEXTO IS NULL OR h.TEXTO = '', '', CONCAT(' · ', LEFT(h.TEXTO, 300)))), CONCAT('COM-', m.ID_COMUNICACION)
                   FROM historial h INNER JOIN comunicacion m ON m.ID_COMUNICACION = h.ID_REGISTRO
                  WHERE h.MODULO = 'COMUNICACION' AND h.FECHA >= $d AND h.FECHA < $h",
            //Reporte a la Gerencia: cada versión guardada
            //(si es de un rango de días, el primer día va al comienzo del texto: "desde":"AAAA-MM-DD")
            'GERENCIA' => "SELECT h.FECHA, 'GERENCIA', 'MODIFICACION', 'Guardó una versión', NULL, NULL, h.ID_COLABORADOR,
                        CONCAT('Reporte ',
                               IF(LOCATE('\"desde\":\"', LEFT(h.TEXTO, 120)) > 0
                                  AND SUBSTRING(h.TEXTO, LOCATE('\"desde\":\"', h.TEXTO) + 9, 10) < CONCAT(SUBSTRING(h.ID_REGISTRO, 1, 4), '-', SUBSTRING(h.ID_REGISTRO, 5, 2), '-', SUBSTRING(h.ID_REGISTRO, 7, 2)),
                                  CONCAT('del periodo ', DATE_FORMAT(SUBSTRING(h.TEXTO, LOCATE('\"desde\":\"', h.TEXTO) + 9, 10), '%d/%m/%Y'), ' al '), 'del '),
                               SUBSTRING(h.ID_REGISTRO, 7, 2), '/', SUBSTRING(h.ID_REGISTRO, 5, 2), '/', SUBSTRING(h.ID_REGISTRO, 1, 4)), CONCAT('GER-', h.ID_REGISTRO)
                   FROM historial h WHERE h.MODULO = 'GERENCIA' AND h.FECHA >= $d AND h.FECHA < $h",
            //Hoy en qué estás (el peaje: el primero que marcó ese día)
            'HOY' => "SELECT r.FEC_REGISTRO, 'HOY', 'REGISTRO', 'Registró su día', (SELECT MIN(c.ID_CENTRO_OP) FROM reporte_hoy_centro c WHERE c.ID_REPORTE_HOY = r.ID_REPORTE_HOY), NULL, r.ID_COLABORADOR,
                        CONCAT('Día ', DATE_FORMAT(r.FECHA, '%d/%m/%Y'), ' · ', r.SITUACION), CONCAT('HOY-', r.ID_REPORTE_HOY)
                   FROM reporte_hoy r WHERE r.FEC_REGISTRO >= $d AND r.FEC_REGISTRO < $h
                  UNION ALL
                 SELECT r.FEC_MODIFICACION, 'HOY', 'MODIFICACION', 'Corrigió su día (último cambio)', (SELECT MIN(c.ID_CENTRO_OP) FROM reporte_hoy_centro c WHERE c.ID_REPORTE_HOY = r.ID_REPORTE_HOY), NULL, r.ID_COLABORADOR,
                        CONCAT('Día ', DATE_FORMAT(r.FECHA, '%d/%m/%Y'), ' · ', r.SITUACION), CONCAT('HOY-', r.ID_REPORTE_HOY)
                   FROM reporte_hoy r WHERE r.FEC_MODIFICACION IS NOT NULL AND r.FEC_MODIFICACION <> r.FEC_REGISTRO AND r.FEC_MODIFICACION >= $d AND r.FEC_MODIFICACION < $h",
            'LISTAS' => "SELECT l.FEC_REGISTRO, 'LISTAS', 'REGISTRO', 'Diligenció', l.ID_CENTRO_OP, NULL, l.ID_COLABORADOR,
                        CONCAT('Lista #', l.ID_LISTA_CHEQUEO, ' · ', g.NOM_GRUPO_LISTA_CHEQUEO), CONCAT('LIS-', l.ID_LISTA_CHEQUEO)
                   FROM lista_chequeo l INNER JOIN grupo_lista_chequeo g ON g.ID_GRUPO_LISTA_CHEQUEO = l.ID_GRUPO_LISTA_CHEQUEO
                  WHERE l.FEC_REGISTRO >= $d AND l.FEC_REGISTRO < $h",
            'ARQUEOS' => "SELECT a.FEC_REGISTRO, 'ARQUEOS', 'REGISTRO', 'Hizo el arqueo', a.ID_CENTRO_OP, NULL, a.ID_COLABORADOR_ARQUEA,
                        CONCAT(REPLACE(a.TIPO, '_', ' '), IF(a.CASETA IS NULL, '', CONCAT(' ', a.CASETA)), ' del ', DATE_FORMAT(a.FECHA, '%d/%m/%Y'),
                               IF(a.ESTADO = 0, CONCAT(' · ANULADO', IF(a.MOTIVO_ANULACION IS NULL, '', CONCAT(': ', a.MOTIVO_ANULACION))), '')), CONCAT('ARQ-', a.ID_ARQUEO)
                   FROM arqueo a WHERE a.FEC_REGISTRO >= $d AND a.FEC_REGISTRO < $h",
            'CRONOGRAMA' => "SELECT c.FEC_REGISTRO, 'CRONOGRAMA', 'REGISTRO', 'Programó', c.ID_CENTRO_OP, NULL, c.ID_COLABORADOR,
                        CONCAT(c.TIPO, ' el ', DATE_FORMAT(c.FECHA, '%d/%m/%Y'), IF(c.DESCRIPCION IS NULL, '', CONCAT(' · ', c.DESCRIPCION)), ' · hoy está ', c.ESTADO,
                               IF(c.OBSERVACION IS NULL, '', CONCAT(' (', c.OBSERVACION, ')'))), CONCAT('CRO-', c.ID_CRONOGRAMA)
                   FROM cronograma c WHERE c.FEC_REGISTRO >= $d AND c.FEC_REGISTRO < $h",
            'VEHICULO' => "SELECT v.FEC_REGISTRO, 'VEHICULO', 'REGISTRO', 'Registró el estado', NULL, vh.ID_PROYECTO, v.ID_COLABORADOR,
                        CONCAT(vh.PLACA, ' el ', DATE_FORMAT(v.FECHA, '%d/%m/%Y'), ' · ', v.ESTADO_VH, IF(v.TRANSPORTE IS NULL, '', CONCAT(' · se movió en ', v.TRANSPORTE))), CONCAT('VH-', v.ID_VEHICULO_DIA)
                   FROM vehiculo_dia v INNER JOIN vehiculo vh ON vh.ID_VEHICULO = v.ID_VEHICULO
                  WHERE v.FEC_REGISTRO >= $d AND v.FEC_REGISTRO < $h",
            'VACANTES' => "SELECT v.FEC_REGISTRO, 'VACANTES', 'REGISTRO', 'Registró', v.ID_CENTRO_OP, NULL, v.ID_COLABORADOR_REGISTRA,
                        CONCAT(v.CARGO, ' · ', v.MOTIVO), CONCAT('VAC-', v.ID_VACANTE)
                   FROM vacante v WHERE v.FEC_REGISTRO >= $d AND v.FEC_REGISTRO < $h
                  UNION ALL
                 SELECT v.FEC_MODIFICACION, 'VACANTES', IF(v.ESTADO_VACANTE = 'ABIERTA', 'MODIFICACION', 'CIERRE'),
                        IF(v.ESTADO_VACANTE = 'ABIERTA', 'Actualizó (último cambio)', CONCAT('Quedó ', LOWER(v.ESTADO_VACANTE))), v.ID_CENTRO_OP, NULL, NULL,
                        CONCAT(v.CARGO, IF(v.NOMBRE_REEMPLAZO IS NULL OR v.NOMBRE_REEMPLAZO = '', '', CONCAT(' · la cubre: ', v.NOMBRE_REEMPLAZO))), CONCAT('VAC-', v.ID_VACANTE)
                   FROM vacante v WHERE v.FEC_MODIFICACION IS NOT NULL AND v.FEC_MODIFICACION >= $d AND v.FEC_MODIFICACION < $h
                  UNION ALL
                 SELECT h.FECHA, 'VACANTES', 'MODIFICACION', 'Anotó quién deja el puesto', v.ID_CENTRO_OP, NULL, h.ID_COLABORADOR,
                        CONCAT(v.CARGO, ' · ', IF(h.TEXTO IS NULL OR h.TEXTO = '', 'quitó el nombre', CONCAT('lo deja: ', h.TEXTO))), CONCAT('VAC-', v.ID_VACANTE)
                   FROM historial h INNER JOIN vacante v ON v.ID_VACANTE = h.ID_REGISTRO
                  WHERE h.MODULO = 'VACANTE' AND h.TIPO = 'SALE_CARGO' AND h.FECHA >= $d AND h.FECHA < $h",
            'PROMESA' => "SELECT p.FEC_MODIFICACION, 'PROMESA', 'MODIFICACION',
                        IF(p.PROMESA_HORAS IS NULL AND p.EJECUTADO_HORAS IS NULL AND p.JUSTIFICACION IS NULL, 'Vació la casilla', 'Último cambio de la casilla'),
                        p.ID_CENTRO_OP, NULL, p.ID_COLABORADOR,
                        CONCAT(a.NOMBRE, ' · ', DATE_FORMAT(p.FECHA, '%d/%m/%Y'), ' · promesa ', IFNULL(REPLACE(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM p.PROMESA_HORAS)), '.', ','), '—'), ' h, ejecutado ', IFNULL(REPLACE(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM p.EJECUTADO_HORAS)), '.', ','), '—'), ' h',
                               IF(p.JUSTIFICACION IS NULL OR p.JUSTIFICACION = '', '', CONCAT(' · ', p.JUSTIFICACION))), CONCAT('PV-', p.ID_PV_DIA)
                   FROM pv_dia p INNER JOIN aus_cargo a ON a.ID_CARGO_AUS = p.ID_CARGO_AUS
                  WHERE p.FEC_MODIFICACION >= $d AND p.FEC_MODIFICACION < $h",
            'ARCHIVOS' => "SELECT j.FEC_REGISTRO, 'ARCHIVOS', 'REGISTRO', 'Subió un archivo',
                        CASE j.MODULO WHEN 'LISTA' THEN (SELECT l.ID_CENTRO_OP FROM lista_chequeo l WHERE l.ID_LISTA_CHEQUEO = j.ID_REGISTRO)
                                      WHEN 'RQ' THEN (SELECT r.ID_CENTRO_OP FROM rq r WHERE r.ID_RQ = j.ID_REGISTRO)
                                      WHEN 'COMUNICACION' THEN (SELECT m.ID_CENTRO_OP FROM comunicacion m WHERE m.ID_COMUNICACION = j.ID_REGISTRO) ELSE NULL END,
                        CASE j.MODULO WHEN 'COMUNICACION' THEN (SELECT m.ID_PROYECTO FROM comunicacion m WHERE m.ID_COMUNICACION = j.ID_REGISTRO) ELSE NULL END,
                        j.ID_COLABORADOR, CONCAT(j.MODULO, ' #', j.ID_REGISTRO, ' · ', j.NOMBRE), CONCAT('ADJ-', j.ID_ADJUNTO)
                   FROM adjunto j WHERE j.FEC_REGISTRO >= $d AND j.FEC_REGISTRO < $h
                  UNION ALL
                 SELECT j.FEC_MODIFICACION, 'ARCHIVOS', IF(j.ESTADO = 0, 'ANULACION', 'MODIFICACION'), IF(j.ESTADO = 0, 'Quitó el archivo', 'Cambió la descripción'),
                        CASE j.MODULO WHEN 'LISTA' THEN (SELECT l.ID_CENTRO_OP FROM lista_chequeo l WHERE l.ID_LISTA_CHEQUEO = j.ID_REGISTRO)
                                      WHEN 'RQ' THEN (SELECT r.ID_CENTRO_OP FROM rq r WHERE r.ID_RQ = j.ID_REGISTRO)
                                      WHEN 'COMUNICACION' THEN (SELECT m.ID_CENTRO_OP FROM comunicacion m WHERE m.ID_COMUNICACION = j.ID_REGISTRO) ELSE NULL END,
                        CASE j.MODULO WHEN 'COMUNICACION' THEN (SELECT m.ID_PROYECTO FROM comunicacion m WHERE m.ID_COMUNICACION = j.ID_REGISTRO) ELSE NULL END,
                        NULL, CONCAT(j.MODULO, ' #', j.ID_REGISTRO, ' · ', j.NOMBRE), CONCAT('ADJ-', j.ID_ADJUNTO)
                   FROM adjunto j WHERE j.FEC_MODIFICACION IS NOT NULL AND j.FEC_MODIFICACION >= $d AND j.FEC_MODIFICACION < $h",
            //Correos: el peaje sale de la lista que lo disparó, si fue una lista
            'CORREOS' => "SELECT b.FECHA, 'CORREOS', 'CORREO', IF(b.OK = 1, 'Enviado', 'No salió'),
                        (SELECT l.ID_CENTRO_OP FROM lista_chequeo l WHERE l.ID_LISTA_CHEQUEO = b.ID_LISTA_CHEQUEO), NULL, b.ID_COLABORADOR,
                        CONCAT(b.MODULO, ' · ', IFNULL(b.ASUNTO, ''), ' · para ', LEFT(b.QUIEN, 200), IF(b.OK = 1 OR b.ERROR IS NULL, '', CONCAT(' · ', LEFT(b.ERROR, 150)))), CONCAT('BIT-', b.ID_BITACORA)
                   FROM bitacora_sistema b WHERE b.TIPO = 'CORREO' AND b.FECHA >= $d AND b.FECHA < $h",
            'INGRESOS' => "SELECT b.FECHA, 'INGRESOS', 'INGRESO', IF(b.OK = 1, IF(b.MODULO = 'LOGIN', 'Entró', 'Cambió su clave'), IF(b.MODULO = 'LOGIN', 'Intento fallido', 'Clave: falló')), NULL, NULL, b.ID_COLABORADOR,
                        CONCAT('Usuario ', b.QUIEN, ' · desde ', b.DETALLE), CONCAT('BIT-', b.ID_BITACORA)
                   FROM bitacora_sistema b WHERE b.TIPO = 'INGRESO' AND b.FECHA >= $d AND b.FECHA < $h",
            'DIAS' => "SELECT x.FEC_HABILITA, 'DIAS', 'CONFIGURACION', 'Habilitó un día', NULL, NULL, x.ID_COLABORADOR_HABILITA,
                        CONCAT(c.NOM_COLABORADOR, ' · ', DATE_FORMAT(x.FECHA, '%d/%m/%Y'), IF(x.MOTIVO IS NULL OR x.MOTIVO = '', '', CONCAT(' · ', x.MOTIVO))), CONCAT('DIA-', x.ID_DIA_HABILITADO)
                   FROM dia_habilitado x INNER JOIN colaboradores c ON c.ID_COLABORADOR = x.ID_COLABORADOR
                  WHERE x.FEC_HABILITA >= $d AND x.FEC_HABILITA < $h
                  UNION ALL
                 SELECT x.FEC_DESHABILITA, 'DIAS', 'CONFIGURACION', 'Deshabilitó un día', NULL, NULL, x.ID_COLABORADOR_DESHABILITA,
                        CONCAT(c.NOM_COLABORADOR, ' · ', DATE_FORMAT(x.FECHA, '%d/%m/%Y')), CONCAT('DIA-', x.ID_DIA_HABILITADO)
                   FROM dia_habilitado x INNER JOIN colaboradores c ON c.ID_COLABORADOR = x.ID_COLABORADOR
                  WHERE x.FEC_DESHABILITA IS NOT NULL AND x.FEC_DESHABILITA >= $d AND x.FEC_DESHABILITA < $h",
            'PARAMETROS' => "SELECT p.FEC_MODIFICACION, 'PARAMETROS', 'CONFIGURACION', IF(p.CLAVE LIKE 'AVISO_PREGUNTA_%', 'Cambió un aviso por respuesta', 'Cambió un parámetro'),
                        NULL, NULL, p.ID_COLABORADOR_MODIFICA, CONCAT(p.CLAVE, ' = ', LEFT(p.VALOR, 200)), CONCAT('PAR-', p.ID_CONFIGURACION)
                   FROM configuracion p WHERE p.FEC_MODIFICACION IS NOT NULL AND p.FEC_MODIFICACION >= $d AND p.FEC_MODIFICACION < $h",
            'FONDOS' => "SELECT f.FEC_MODIFICACION, 'FONDOS', 'CONFIGURACION', 'Cambió el fondo autorizado', f.ID_CENTRO_OP, NULL, f.ID_COLABORADOR_MODIFICA,
                        CONCAT(REPLACE(f.TIPO, '_', ' '), ': $ ', FORMAT(f.VALOR_AUTORIZADO, 0, 'de_DE')), CONCAT('FON-', f.ID_FONDO)
                   FROM arqueo_fondo f WHERE f.FEC_MODIFICACION IS NOT NULL AND f.FEC_MODIFICACION >= $d AND f.FEC_MODIFICACION < $h",
            'USUARIOS' => "SELECT u.FEC_CREACION, 'USUARIOS', 'CONFIGURACION', 'Se creó el usuario', NULL, NULL, NULL,
                        CONCAT(u.USUARIO, ' · ', c.NOM_COLABORADOR), CONCAT('USU-', u.ID_USUARIO_SISTEMA)
                   FROM usuarios_sistema u INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                  WHERE u.FEC_CREACION >= $d AND u.FEC_CREACION < $h
                  UNION ALL
                 SELECT u.FEC_MODIFICACION, 'USUARIOS', 'CONFIGURACION', 'Cambió el usuario, el rol, la clave o el acceso (último cambio)', NULL, NULL, NULL,
                        CONCAT(u.USUARIO, ' · ', c.NOM_COLABORADOR, IF(u.ESTADO = 0, ' · acceso bloqueado', '')), CONCAT('USU-', u.ID_USUARIO_SISTEMA)
                   FROM usuarios_sistema u INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR
                  WHERE u.FEC_MODIFICACION IS NOT NULL AND u.FEC_MODIFICACION >= $d AND u.FEC_MODIFICACION < $h"
        );
    }

    //Los eventos que cumplen los filtros, del más reciente al más viejo.
    //  $f: desde, hasta (AAAA-MM-DD), modulo, accion, proyecto, centro, quien, texto
    //  $alcance: null = todo (ADMIN TEC); o [idsCentros, idsProyectos] de la persona
    //  Devuelve [total, filas] (filas: hasta $limite, desde $inicio)
    public static function buscar($f, $alcance, $inicio = 0, $limite = 300)
    {
        $d = "'" . $f['desde'] . " 00:00:00'";
        $h = "'" . date('Y-m-d', strtotime($f['hasta'] . ' +1 day')) . " 00:00:00'";
        $partes = self::partes($d, $h);
        //Solo las partes del módulo pedido (las de oficios traen también los temas)
        if ($f['modulo'] !== '') {
            $clave = $f['modulo'] === 'TEMAS' ? 'COMUNICACIONES' : $f['modulo'];
            $partes = isset($partes[$clave]) ? array($partes[$clave]) : array();
        }
        if (!count($partes)) { return array(0, array()); }
        //La primera fila (vacía) solo pone los nombres de las columnas: así da igual qué partes vayan
        $nombres = "SELECT NULL AS FECHA, NULL AS MODULO, NULL AS ACCION, NULL AS TEXTO_ACCION, NULL AS ID_CENTRO, NULL AS ID_PROYECTO,
                           NULL AS ID_COLAB, NULL AS DETALLE, NULL AS REF FROM DUAL WHERE 1 = 0";
        //Las partes van seguidas, sin paréntesis (algunas ya traen su propio UNION ALL)
        $union = $nombres . " UNION ALL " . implode(" UNION ALL ", array_values($partes));

        $donde = array('1 = 1'); $tipos = ''; $params = array();
        if ($f['modulo'] !== '') { $donde[] = 'e.MODULO = ?'; $tipos .= 's'; $params[] = $f['modulo']; }
        if ($f['accion'] !== '') { $donde[] = 'e.ACCION = ?'; $tipos .= 's'; $params[] = $f['accion']; }
        if ((int)$f['centro'] > 0) { $donde[] = 'e.ID_CENTRO = ?'; $tipos .= 'i'; $params[] = (int)$f['centro']; }
        if ((int)$f['proyecto'] > 0) { $donde[] = 'COALESCE(ce.ID_PROYECTO, e.ID_PROYECTO) = ?'; $tipos .= 'i'; $params[] = (int)$f['proyecto']; }
        if ((int)$f['quien'] > 0) { $donde[] = 'e.ID_COLAB = ?'; $tipos .= 'i'; $params[] = (int)$f['quien']; }
        if ($f['texto'] !== '') {
            $donde[] = "CONCAT_WS(' ', e.DETALLE, e.TEXTO_ACCION, col.NOM_COLABORADOR, ce.NOM_CENTRO_OP, pr.NOM_PROYECTO) LIKE ?";
            $tipos .= 's'; $params[] = '%' . $f['texto'] . '%';
        }
        if ($alcance !== null) {
            list($idsCentros, $idsProyectos) = $alcance;
            $centros = count($idsCentros) ? implode(',', array_map('intval', $idsCentros)) : '0';
            $proyectos = count($idsProyectos) ? implode(',', array_map('intval', $idsProyectos)) : '0';
            $donde[] = "(e.ID_CENTRO IN ($centros) OR (e.ID_CENTRO IS NULL AND e.ID_PROYECTO IN ($proyectos)))";
        }
        $desde = " FROM (SELECT * FROM ($union) u0) e
                   LEFT JOIN centros_operacion ce ON ce.ID_CENTRO_OP = e.ID_CENTRO
                   LEFT JOIN proyectos pr ON pr.ID_PROYECTO = COALESCE(ce.ID_PROYECTO, e.ID_PROYECTO)
                   LEFT JOIN colaboradores col ON col.ID_COLABORADOR = e.ID_COLAB
                  WHERE " . implode(' AND ', $donde);

        $cuenta = HanaDB::fila("SELECT COUNT(*) AS N" . $desde, $tipos, $params);
        $total = $cuenta ? (int)$cuenta['N'] : 0;
        $filas = HanaDB::q("SELECT e.FECHA, e.MODULO, e.ACCION, e.TEXTO_ACCION, e.DETALLE, e.REF, e.ID_COLAB,
                                   col.NOM_COLABORADOR AS QUIEN, ce.NOM_CENTRO_OP AS CENTRO, pr.NOM_PROYECTO AS PROYECTO" . $desde .
                           " ORDER BY e.FECHA DESC, e.REF DESC LIMIT " . (int)$limite . " OFFSET " . (int)$inicio, $tipos, $params);
        $filas = is_array($filas) ? $filas : array();
        //Los textos de la base vienen codificados para HTML: la pantalla los vuelve a escapar
        foreach ($filas as $i => $fila) {
            foreach (array('TEXTO_ACCION', 'DETALLE', 'QUIEN', 'CENTRO', 'PROYECTO') as $campo) {
                if ($fila[$campo] !== null) { $filas[$i][$campo] = html_entity_decode((string)$fila[$campo], ENT_QUOTES, 'UTF-8'); }
            }
        }
        return array($total, $filas);
    }

    //Las personas para el filtro «Quién»: todas las registradas (activas primero)
    public static function personas()
    {
        $filas = HanaDB::q("SELECT ID_COLABORADOR AS id, NOM_COLABORADOR AS nombre, ESTADO FROM colaboradores ORDER BY ESTADO DESC, NOM_COLABORADOR");
        return is_array($filas) ? array_map(function ($fila) {
            return array('id' => (int)$fila['id'], 'nombre' => html_entity_decode($fila['nombre'], ENT_QUOTES, 'UTF-8') . ((int)$fila['ESTADO'] === 1 ? '' : ' (retirada)'));
        }, $filas) : array();
    }
}
