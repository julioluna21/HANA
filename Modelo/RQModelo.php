<?php
//Modelo del módulo de Requisiciones (RQ)
require_once __DIR__ . "/../Conexion/ConexionDB.php";

class Rq
{
    public function __construct() {}

    //-----------------------------------------------------------------------
    // Utilidades internas
    //-----------------------------------------------------------------------

    //Ejecuta una consulta y devuelve el resultado, o false si falló.
    //Se usa en vez de ejecutarConsulta_retornarID porque esa función devuelve
    //el último id aunque la consulta haya fallado, y eso daba falsos éxitos
    private function ejecutar($sql)
    {
        global $conexion;
        $r = $conexion->query($sql);
        if ($r === false) {
            error_log('HANA RQ - error SQL: ' . $conexion->error);
        }
        return $r;
    }

    //Inserta y devuelve el id nuevo, o 0 si falló
    private function insertarId($sql)
    {
        global $conexion;
        return $this->ejecutar($sql) ? (int)$conexion->insert_id : 0;
    }

    //Deja un texto listo para ir entre comillas en SQL
    private function txt($valor)
    {
        return "'" . limpiarCadena(trim((string)$valor)) . "'";
    }

    public function iniciar()   { global $conexion; $conexion->begin_transaction(); }
    public function confirmar() { global $conexion; $conexion->commit(); }
    public function deshacer()  { global $conexion; $conexion->rollback(); }

    //-----------------------------------------------------------------------
    // Catálogos
    //-----------------------------------------------------------------------

    //Los peajes asignados al usuario, con su proyecto. Solo esos puede usar,
    //igual que en las listas de chequeo
    //Los peajes donde el usuario puede pedir RQ: los asignados en Usuarios, los de los
    //que es jefe y los del proyecto que coordina (los coordinadores también piden RQ)
    public function centrosUsuario($idUsuario)
    {
        $idUsuario = intval($idUsuario);
        $sql = "SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, p.ID_PROYECTO, p.NOM_PROYECTO
                  FROM centros_operacion c
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                 WHERE (EXISTS (SELECT 1 FROM asoc_usuarios_sistemas_x_cop a
                                WHERE a.ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP = c.ID_CENTRO_OP
                                  AND a.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = $idUsuario)
                        OR c.ID_COLABORADOR_JEFE = (SELECT ID_COLABORADOR_USUARIOS_SISTEMA FROM usuarios_sistema WHERE ID_USUARIO_SISTEMA = $idUsuario)
                        OR p.ID_COLABORADOR_COORDINADOR = (SELECT ID_COLABORADOR_USUARIOS_SISTEMA FROM usuarios_sistema WHERE ID_USUARIO_SISTEMA = $idUsuario))
                   AND c.Estado = '1'
                 ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP";
        return $this->ejecutar($sql);
    }

    //Confirma que el peaje sea del usuario (asignado en Usuarios, jefe de ese peaje o
    //coordinador de su proyecto) y devuelve su proyecto.
    //Así nadie puede crear una RQ en un peaje ajeno modificando el formulario
    public function proyectoDeCentro($idUsuario, $idCentro)
    {
        $idUsuario = intval($idUsuario);
        $idCentro  = intval($idCentro);
        $sql = "SELECT c.ID_PROYECTO_CENTRO_OP AS ID_PROYECTO
                  FROM centros_operacion c
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO_CENTRO_OP
                 WHERE (EXISTS (SELECT 1 FROM asoc_usuarios_sistemas_x_cop a
                                WHERE a.ID_CENTRO_OP_ASOC_USUARIOS_SISTEMAS_X_COP = c.ID_CENTRO_OP
                                  AND a.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP = $idUsuario)
                        OR c.ID_COLABORADOR_JEFE = (SELECT ID_COLABORADOR_USUARIOS_SISTEMA FROM usuarios_sistema WHERE ID_USUARIO_SISTEMA = $idUsuario)
                        OR p.ID_COLABORADOR_COORDINADOR = (SELECT ID_COLABORADOR_USUARIOS_SISTEMA FROM usuarios_sistema WHERE ID_USUARIO_SISTEMA = $idUsuario))
                   AND c.ID_CENTRO_OP = $idCentro
                 LIMIT 1";
        $r = $this->ejecutar($sql);
        $f = $r ? $r->fetch_assoc() : null;
        return $f ? (int)$f['ID_PROYECTO'] : 0;
    }

    public function estados()
    {
        return $this->ejecutar("SELECT * FROM rq_estado WHERE ESTADO = 1 ORDER BY ORDEN");
    }

    public function estado($idEstado)
    {
        $idEstado = intval($idEstado);
        $r = $this->ejecutar("SELECT * FROM rq_estado WHERE ID_RQ_ESTADO = $idEstado");
        return $r ? $r->fetch_assoc() : null;
    }

    //-----------------------------------------------------------------------
    // Numeración: el número se lleva por proyecto
    //-----------------------------------------------------------------------
    public function siguienteNumero($idProyecto)
    {
        $idProyecto = intval($idProyecto);
        //El número ahora puede tener letras ("230A"): el siguiente sale de los que son solo números
        $r = $this->ejecutar("SELECT COALESCE(MAX(CAST(NUMERO_RQ AS UNSIGNED)), 0) + 1 AS n FROM rq
                               WHERE ID_PROYECTO = $idProyecto AND NUMERO_RQ REGEXP '^[0-9]+$'");
        $f = $r ? $r->fetch_assoc() : null;
        return $f ? (int)$f['n'] : 1;
    }

    public function existeNumero($idProyecto, $numero)
    {
        $idProyecto = intval($idProyecto);
        $r = $this->ejecutar("SELECT 1 FROM rq WHERE ID_PROYECTO = $idProyecto AND NUMERO_RQ = " . $this->txt((string)$numero) . " LIMIT 1");
        return $r && $r->num_rows > 0;
    }

    //-----------------------------------------------------------------------
    // Creación
    //-----------------------------------------------------------------------
    //Tipo: 'N' normal o 'U' urgente. Las RQ nuevas ya no llevan rol destino
    //(les llega a quien tenga el permiso de aprobar) ni generan novedades
    public function insertar($numero, $idProyecto, $idCentro, $fecha, $idColaborador, $observacion, $tipo)
    {
        $tipo = ($tipo === 'U') ? 'U' : 'N';
        $sql = "INSERT INTO rq (NUMERO_RQ, ID_PROYECTO, ID_CENTRO_OP, FECHA_RQ, ID_COLABORADOR_SOLICITA,
                                ID_RQ_ESTADO, FEC_ESTADO, OBSERVACION_SST, ES_RIESGO, FEC_CREACION, ESTADO,
                                ID_ROL_DESTINO, TIPO_RQ)
                VALUES (" . $this->txt((string)$numero) . ", " . intval($idProyecto) . ", " . intval($idCentro) . ",
                        " . $this->txt($fecha) . ", " . intval($idColaborador) . ",
                        1, NOW(), " . $this->txt($observacion) . ", 0, NOW(), 1, NULL, '$tipo')";
        return $this->insertarId($sql);
    }

    public function insertarDetalle($idRq, $descripcion, $justificacion, $cantidad, $unidad, $soporte)
    {
        $cantidad = is_numeric($cantidad) && $cantidad > 0 ? (float)$cantidad : 1;
        $sql = "INSERT INTO rq_detalle (ID_RQ, DESCRIPCION, JUSTIFICACION, CANTIDAD, UNIDAD, SOPORTE_ULTIMA_ADQUISICION)
                VALUES (" . intval($idRq) . ", " . $this->txt($descripcion) . ", " . $this->txt($justificacion) . ",
                        $cantidad, " . $this->txt($unidad !== '' ? $unidad : 'Unidad') . ", " . $this->txt($soporte) . ")";
        return $this->insertarId($sql);
    }

    public function insertarSeguimiento($idRq, $anterior, $nuevo, $idColaborador, $observacion)
    {
        $anterior = $anterior ? intval($anterior) : 'NULL';
        $sql = "INSERT INTO rq_seguimiento (ID_RQ, ID_ESTADO_ANTERIOR, ID_ESTADO_NUEVO, ID_COLABORADOR, OBSERVACION, FECHA)
                VALUES (" . intval($idRq) . ", $anterior, " . intval($nuevo) . ", " . intval($idColaborador) . ",
                        " . $this->txt($observacion) . ", NOW())";
        return $this->insertarId($sql);
    }

    public function insertarSoporte($idRq, $ruta, $nombreOriginal, $tipo)
    {
        $tipo = ($tipo === 'soporte') ? 'soporte' : 'evidencia';
        //Los soportes de las RQ se guardan en «adjunto», la tabla de archivos de todos los
        //módulos (MODULO = 'RQ'). El archivo sigue en la misma carpeta de siempre
        $sql = "INSERT INTO adjunto (MODULO, ID_REGISTRO, NOMBRE, ARCHIVO, TIPO, TAMANO, ID_COLABORADOR, FEC_REGISTRO, ESTADO)
                VALUES ('RQ', " . intval($idRq) . ", " . $this->txt(mb_substr((string)$nombreOriginal, 0, 200)) . ", " . $this->txt($ruta) . ",
                        '$tipo', 0, COALESCE((SELECT ID_COLABORADOR_SOLICITA FROM rq WHERE ID_RQ = " . intval($idRq) . "), 0), NOW(), 1)";
        return $this->insertarId($sql);
    }

    //-----------------------------------------------------------------------
    // Consultas
    //-----------------------------------------------------------------------

    //El listado.
    //"Vencida": lleva más días en "Solicitada" de los esperados sin que nadie la apruebe
    //$condAcceso: la condición de AccesoHelper (qué RQ puede ver este usuario)
    //Periodo: $anio = 0 es "todos los años"; $mes = 0 es "todo el año"
    public function listar($condAcceso, $idProyecto, $idCentro, $anio, $mes)
    {
        $filtro = '';
        if (intval($idProyecto) > 0) { $filtro .= " AND r.ID_PROYECTO = " . intval($idProyecto); }
        if (intval($idCentro)   > 0) { $filtro .= " AND r.ID_CENTRO_OP = " . intval($idCentro); }

        //Se filtra por un rango de fechas y no con MONTH()/YEAR(): así la base
        //puede usar el índice de FECHA_RQ y el filtro sigue rápido con muchas RQ
        $anio = intval($anio); $mes = intval($mes);
        if ($anio >= 2000 && $anio <= 2100) {
            if ($mes >= 1 && $mes <= 12) {
                $desde = sprintf('%04d-%02d-01', $anio, $mes);
                $hasta = ($mes === 12) ? sprintf('%04d-01-01', $anio + 1) : sprintf('%04d-%02d-01', $anio, $mes + 1);
            } else {
                $desde = sprintf('%04d-01-01', $anio);
                $hasta = sprintf('%04d-01-01', $anio + 1);
            }
            $filtro .= " AND r.FECHA_RQ >= '$desde' AND r.FECHA_RQ < '$hasta'";
        }

        $sql = "SELECT r.ID_RQ, r.NUMERO_RQ, r.TIPO_RQ, r.FECHA_RQ, r.ES_RIESGO, r.ID_NOVEDAD_GENERADA, r.ESTADO,
                       p.NOM_PROYECTO, c.NOM_CENTRO_OP,
                       e.ID_RQ_ESTADO, e.NOM_RQ_ESTADO, e.COLOR, e.ES_FINAL, e.DIAS_ESPERADOS,
                       col.NOM_COLABORADOR AS SOLICITA,
                       rd.NOM_ROL_USUARIO_SISTEMA AS ROL_DESTINO,
                       DATEDIFF(NOW(), r.FEC_ESTADO)   AS DIAS_EN_ESTADO,
                       DATEDIFF(NOW(), r.FEC_CREACION) AS DIAS_TOTALES,
                       (SELECT COUNT(*) FROM rq_detalle d WHERE d.ID_RQ = r.ID_RQ) AS ITEMS,
                       (SELECT GROUP_CONCAT(d.DESCRIPCION SEPARATOR ' · ')
                          FROM rq_detalle d WHERE d.ID_RQ = r.ID_RQ) AS RESUMEN,
                       CASE WHEN e.ES_FINAL = 0 AND e.DIAS_ESPERADOS > 0
                                 AND DATEDIFF(NOW(), r.FEC_ESTADO) > e.DIAS_ESPERADOS
                            THEN 1 ELSE 0 END AS VENCIDA
                  FROM rq r
                  INNER JOIN proyectos p          ON p.ID_PROYECTO = r.ID_PROYECTO
                  INNER JOIN centros_operacion c  ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                  INNER JOIN rq_estado e          ON e.ID_RQ_ESTADO = r.ID_RQ_ESTADO
                  LEFT  JOIN colaboradores col    ON col.ID_COLABORADOR = r.ID_COLABORADOR_SOLICITA
                  LEFT  JOIN rol_usuarios_sistemas rd ON rd.ID_ROL_USUARIO_SISTEMA = r.ID_ROL_DESTINO
                 WHERE $condAcceso $filtro
                 ORDER BY r.FEC_CREACION DESC";
        return $this->ejecutar($sql);
    }

    //Los años que tienen RQ, para el filtro de periodo
    public function anios()
    {
        return $this->ejecutar("SELECT DISTINCT YEAR(FECHA_RQ) AS anio FROM rq ORDER BY anio DESC");
    }

    //Una RQ, solo si pertenece a un peaje del usuario
    public function mostrar($condAcceso, $idRq)
    {
        $idRq = intval($idRq);
        $sql = "SELECT r.*, p.NOM_PROYECTO, c.NOM_CENTRO_OP,
                       e.NOM_RQ_ESTADO, e.COLOR, e.ES_FINAL, e.ORDEN, e.DIAS_ESPERADOS,
                       col.NOM_COLABORADOR AS SOLICITA,
                       rd.NOM_ROL_USUARIO_SISTEMA AS ROL_DESTINO,
                       DATEDIFF(NOW(), r.FEC_ESTADO) AS DIAS_EN_ESTADO
                  FROM rq r
                  INNER JOIN proyectos p          ON p.ID_PROYECTO = r.ID_PROYECTO
                  INNER JOIN centros_operacion c  ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                  INNER JOIN rq_estado e          ON e.ID_RQ_ESTADO = r.ID_RQ_ESTADO
                  LEFT  JOIN colaboradores col    ON col.ID_COLABORADOR = r.ID_COLABORADOR_SOLICITA
                  LEFT  JOIN rol_usuarios_sistemas rd ON rd.ID_ROL_USUARIO_SISTEMA = r.ID_ROL_DESTINO
                 WHERE r.ID_RQ = $idRq AND $condAcceso
                 LIMIT 1";
        $r = $this->ejecutar($sql);
        return $r ? $r->fetch_assoc() : null;
    }

    public function detalle($idRq)
    {
        return $this->ejecutar("SELECT * FROM rq_detalle WHERE ID_RQ = " . intval($idRq) . " ORDER BY ID_RQ_DETALLE");
    }

    public function seguimiento($idRq)
    {
        $sql = "SELECT s.FECHA, s.OBSERVACION, ea.NOM_RQ_ESTADO AS ANTERIOR, en.NOM_RQ_ESTADO AS NUEVO,
                       col.NOM_COLABORADOR AS QUIEN
                  FROM rq_seguimiento s
                  LEFT  JOIN rq_estado ea ON ea.ID_RQ_ESTADO = s.ID_ESTADO_ANTERIOR
                  INNER JOIN rq_estado en ON en.ID_RQ_ESTADO = s.ID_ESTADO_NUEVO
                  LEFT  JOIN colaboradores col ON col.ID_COLABORADOR = s.ID_COLABORADOR
                 WHERE s.ID_RQ = " . intval($idRq) . "
                 ORDER BY s.FECHA DESC, s.ID_RQ_SEGUIMIENTO DESC";
        return $this->ejecutar($sql);
    }

    public function soportes($idRq)
    {
        //Con los mismos nombres de columna de antes (ID_RQ_SOPORTE, RUTA, NOMBRE_ORIGINAL...): la pantalla no cambia
        return $this->ejecutar("SELECT ID_ADJUNTO AS ID_RQ_SOPORTE, ID_REGISTRO AS ID_RQ, ARCHIVO AS RUTA, NOMBRE AS NOMBRE_ORIGINAL,
                                       TIPO, FEC_REGISTRO AS FECHA
                                  FROM adjunto WHERE MODULO = 'RQ' AND ESTADO = 1 AND ID_REGISTRO = " . intval($idRq) . " ORDER BY ID_ADJUNTO");
    }

    //-----------------------------------------------------------------------
    // Cambio de estado
    //-----------------------------------------------------------------------

    //Flujo de un solo paso (Fase 1):
    //  Solicitada (1) -> Aprobada (3) o Rechazada (7)
    //Solo desde "Solicitada" y solo para quien tiene el permiso de aprobar.
    //Aprobada y Rechazada son finales. Los estados de compra, instalación y
    //SST quedaron desactivados en la base, pero siguen en el historial
    const SOLICITADA = 1;
    const APROBADA   = 3;
    const RECHAZADA  = 7;

    public function estadosPermitidos($idEstadoActual, $puedeAprobar)
    {
        if (!$puedeAprobar || intval($idEstadoActual) !== self::SOLICITADA) { return array(); }
        $permitidos = array();
        $r = $this->ejecutar("SELECT * FROM rq_estado
                               WHERE ID_RQ_ESTADO IN (" . self::APROBADA . ", " . self::RECHAZADA . ")
                               ORDER BY ORDEN");
        while ($r && ($f = $r->fetch_assoc())) { $permitidos[] = $f; }
        return $permitidos;
    }

    public function cambiarEstado($idRq, $nuevo)
    {
        $sql = "UPDATE rq SET ID_RQ_ESTADO = " . intval($nuevo) . ", FEC_ESTADO = NOW()
                 WHERE ID_RQ = " . intval($idRq);
        return $this->ejecutar($sql);
    }

    //-----------------------------------------------------------------------
    // Edición y retroceso
    //-----------------------------------------------------------------------

    //Cambia los datos de la cabecera. El peaje y el número no se editan: son
    //la identidad de la RQ
    public function actualizarCabecera($idRq, $fecha, $observacion, $tipo)
    {
        $tipo = ($tipo === 'U') ? 'U' : 'N';
        $sql = "UPDATE rq SET FECHA_RQ = " . $this->txt($fecha) . ",
                              OBSERVACION_SST = " . $this->txt($observacion) . ",
                              TIPO_RQ = '$tipo'
                 WHERE ID_RQ = " . intval($idRq);
        return $this->ejecutar($sql);
    }

    //Los ítems se reemplazan completos: se borran y se vuelven a escribir
    //dentro de la misma transacción, así nunca queda una RQ sin ítems
    public function borrarDetalle($idRq)
    {
        return $this->ejecutar("DELETE FROM rq_detalle WHERE ID_RQ = " . intval($idRq));
    }

    //Vuelve la RQ a "Solicitada": tiene que aprobarse de nuevo
    public function reiniciarFlujo($idRq)
    {
        return $this->ejecutar("UPDATE rq SET ID_RQ_ESTADO = 1, FEC_ESTADO = NOW() WHERE ID_RQ = " . intval($idRq));
    }

    //Al volver a "Solicitada" la RQ es una solicitud nueva para su rol: se
    //borra el "ya la leí" para que vuelva a aparecer en la campana
    public function marcarNoLeida($idRq)
    {
        return $this->ejecutar("DELETE FROM notificacion_leida WHERE TIPO = 'RQ' AND ID_REGISTRO = " . intval($idRq));
    }

    //Anular no borra nada: la RQ queda en estado Anulada y fuera de circulación
    public function anular($idRq)
    {
        $sql = "UPDATE rq SET ID_RQ_ESTADO = 8, FEC_ESTADO = NOW(), ESTADO = 0
                 WHERE ID_RQ = " . intval($idRq);
        return $this->ejecutar($sql);
    }
}
