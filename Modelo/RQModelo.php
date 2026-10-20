<?php
/*
  HANA - Requisiciones (RQ) (modelo)
  ---------------------------------------------------------------------------
  Consultas de las tablas rq (encabezado) y rq_detalle (ítems), más su
  historial y sus archivos (tablas historial y adjunto). Lo usa
  Control/RQControl.php. El flujo de estados está en Modelo/RQEstados.php.
*/
require_once __DIR__ . "/../Conexion/ConexionDB.php";
require_once __DIR__ . "/RQEstados.php"; //el flujo de estados de la RQ

class Rq
{
    //No necesita preparar nada al crearse
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
        $resultado = $conexion->query($sql);
        if ($resultado === false) {
            error_log('HANA RQ - error SQL: ' . $conexion->error);
        }
        return $resultado;
    }

    //Inserta y devuelve el id nuevo, o 0 si falló
    private function insertarId($sql)
    {
        global $conexion;
        return $this->ejecutar($sql) ? (int)$conexion->insert_id : 0;
    }

    //Deja un texto listo para ir entre comillas en SQL
    private function textoSql($valor)
    {
        return "'" . limpiarCadena(trim((string)$valor)) . "'";
    }

    //Transacciones: la RQ se guarda completa (encabezado, ítems y soportes) o no se guarda
    public function iniciar()   { global $conexion; $conexion->begin_transaction(); }
    public function confirmar() { global $conexion; $conexion->commit(); }
    public function deshacer()  { global $conexion; $conexion->rollback(); }

    //-----------------------------------------------------------------------
    // Catálogos
    //-----------------------------------------------------------------------

    //De qué peajes es el usuario: los que tiene asignados en Usuarios, los peajes donde es
    //jefe y los de los proyectos que coordina. Con $todos (ADMIN TEC), todos los peajes
    private function condPeajes($idUsuario, $todos)
    {
        $idUsuario = intval($idUsuario);
        if ($todos) { return "(1 = 1)"; }
        $persona = $idUsuario; //el usuario es la misma persona (colaboradores)
        $coordina = "p.ID_COLABORADOR_COORDINADOR = $persona";
        return "(EXISTS (SELECT 1 FROM asoc_usuarios_sistemas_x_cop a
                          WHERE a.ID_CENTRO_OP = c.ID_CENTRO_OP
                            AND a.ID_COLABORADOR = $idUsuario)
                 OR c.ID_COLABORADOR_JEFE = $persona OR $coordina)";
    }

    //Los peajes del usuario, para el formulario y los filtros
    public function centrosUsuario($idUsuario, $todos = false)
    {
        $idUsuario = intval($idUsuario);
        $sql = "SELECT c.ID_CENTRO_OP, c.NOM_CENTRO_OP, p.ID_PROYECTO, p.NOM_PROYECTO
                  FROM centros_operacion c
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                 WHERE " . $this->condPeajes($idUsuario, $todos) . "
                   AND c.ESTADO = '1'
                 ORDER BY p.NOM_PROYECTO, c.NOM_CENTRO_OP";
        return $this->ejecutar($sql);
    }

    //Confirma que el peaje sea del usuario (asignado en Usuarios, jefe de ese peaje o
    //coordinador de su proyecto) y devuelve su proyecto.
    //Así nadie puede crear una RQ en un peaje ajeno modificando el formulario
    public function proyectoDeCentro($idUsuario, $idCentro, $todos = false)
    {
        $idUsuario = intval($idUsuario);
        $idCentro  = intval($idCentro);
        $sql = "SELECT c.ID_PROYECTO
                  FROM centros_operacion c
                  INNER JOIN proyectos p ON p.ID_PROYECTO = c.ID_PROYECTO
                 WHERE " . $this->condPeajes($idUsuario, $todos) . "
                   AND c.ID_CENTRO_OP = $idCentro
                 LIMIT 1";
        $resultado = $this->ejecutar($sql);
        $fila = $resultado ? $resultado->fetch_assoc() : null;
        return $fila ? (int)$fila['ID_PROYECTO'] : 0;
    }

    //-----------------------------------------------------------------------
    // Numeración: el número se lleva por proyecto
    //
    // Dos personas que crean una RQ del mismo proyecto al mismo tiempo podían
    // recibir el mismo número: las dos leían el último antes de que la otra
    // guardara. Por eso el controlador "aparta" la numeración del proyecto
    // (bloquearNumeracion) mientras calcula el número y guarda la RQ, y la suelta
    // al terminar (soltarNumeracion). Mientras tanto, la otra persona espera unos
    // segundos. Es un bloqueo con nombre de MySQL (GET_LOCK): si el programa se
    // corta, MySQL lo suelta solo al cerrarse la conexión.
    //-----------------------------------------------------------------------
    //Aparta la numeración del proyecto. Devuelve false si no se pudo en $segundos
    public function bloquearNumeracion($idProyecto, $segundos = 10)
    {
        $resultado = $this->ejecutar("SELECT GET_LOCK('hana_rq_numero_" . intval($idProyecto) . "', " . intval($segundos) . ") AS ok");
        $fila = $resultado ? $resultado->fetch_assoc() : null;
        return $fila && (int)$fila['ok'] === 1;
    }

    //Suelta la numeración del proyecto
    public function soltarNumeracion($idProyecto)
    {
        $this->ejecutar("SELECT RELEASE_LOCK('hana_rq_numero_" . intval($idProyecto) . "')");
    }

    //El siguiente número libre del proyecto (llamar con la numeración apartada)
    public function siguienteNumero($idProyecto)
    {
        $idProyecto = intval($idProyecto);
        //El número puede tener letras ("230A"): el siguiente sale de los que son solo números
        //El proyecto de una RQ es el de su peaje (rq no guarda ID_PROYECTO: sería el mismo dato dos veces)
        $resultado = $this->ejecutar("SELECT COALESCE(MAX(CAST(r.NUMERO_RQ AS UNSIGNED)), 0) + 1 AS n
                                FROM rq r INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                               WHERE c.ID_PROYECTO = $idProyecto AND r.NUMERO_RQ REGEXP '^[0-9]+$'");
        $fila = $resultado ? $resultado->fetch_assoc() : null;
        return $fila ? (int)$fila['n'] : 1;
    }

    //¿Ese número de RQ ya existe en el proyecto?
    public function existeNumero($idProyecto, $numero)
    {
        $idProyecto = intval($idProyecto);
        //El número no se repite dentro del proyecto: lo revisa esta consulta
        $resultado = $this->ejecutar("SELECT 1 FROM rq r INNER JOIN centros_operacion c ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                               WHERE c.ID_PROYECTO = $idProyecto AND r.NUMERO_RQ = " . $this->textoSql((string)$numero) . " LIMIT 1");
        return $resultado && $resultado->num_rows > 0;
    }

    //-----------------------------------------------------------------------
    // Creación
    //-----------------------------------------------------------------------
    //Tipo: 'N' normal o 'U' urgente. Las RQ no llevan rol destino
    //(les llega a quien tenga el permiso de aprobar) ni generan novedades.
    //Nacen en "Solicitada"
    public function insertar($numero, $idProyecto, $idCentro, $fecha, $idColaborador, $observacion, $tipo)
    {
        $tipo = ($tipo === 'U') ? 'U' : 'N';
        //$idProyecto no se guarda: el proyecto es el del peaje ($idCentro)
        //Desde cuándo está en su estado no se guarda aquí: sale del historial (RqEstado::sqlDesde)
        $sql = "INSERT INTO rq (NUMERO_RQ, ID_CENTRO_OP, FECHA_RQ, ID_COLABORADOR_SOLICITA,
                                ID_RQ_ESTADO, OBSERVACION_SST, FEC_CREACION, TIPO_RQ)
                VALUES (" . $this->textoSql((string)$numero) . ", " . intval($idCentro) . ",
                        " . $this->textoSql($fecha) . ", " . intval($idColaborador) . ",
                        " . RqEstado::SOLICITADA . ", " . $this->textoSql($observacion) . ", NOW(), '$tipo')";
        return $this->insertarId($sql);
    }

    //Agrega un ítem a la RQ (si la cantidad no es válida, queda en 1)
    public function insertarDetalle($idRq, $descripcion, $justificacion, $cantidad, $unidad, $soporte)
    {
        $cantidad = is_numeric($cantidad) && $cantidad > 0 ? (float)$cantidad : 1;
        $sql = "INSERT INTO rq_detalle (ID_RQ, DESCRIPCION, JUSTIFICACION, CANTIDAD, UNIDAD, SOPORTE_ULTIMA_ADQUISICION)
                VALUES (" . intval($idRq) . ", " . $this->textoSql($descripcion) . ", " . $this->textoSql($justificacion) . ",
                        $cantidad, " . $this->textoSql($unidad !== '' ? $unidad : 'Unidad') . ", " . $this->textoSql($soporte) . ")";
        return $this->insertarId($sql);
    }

    //Cada cambio de estado queda en el historial de la RQ (TIPO CAMBIO_ESTADO); una corrección
    //que no cambia el estado queda como CORRECCION (no mueve la fecha del estado)
    public function insertarSeguimiento($idRq, $anterior, $nuevo, $idColaborador, $observacion, $tipo = RqEstado::HIST_CAMBIO)
    {
        $anterior = $anterior ? intval($anterior) : 'NULL';
        $tipo = ($tipo === RqEstado::HIST_CORRECCION) ? RqEstado::HIST_CORRECCION : RqEstado::HIST_CAMBIO;
        $sql = "INSERT INTO historial (MODULO, ID_REGISTRO, TIPO, ESTADO_ANTERIOR, ESTADO_NUEVO, TEXTO, ID_COLABORADOR, FECHA)
                VALUES ('RQ', " . intval($idRq) . ", '$tipo', $anterior, " . intval($nuevo) . ",
                        " . $this->textoSql($observacion) . ", NULLIF(" . intval($idColaborador) . ", 0), NOW())";
        return $this->insertarId($sql);
    }

    //Registra un archivo de la RQ: un soporte (PDF) o una evidencia (foto)
    public function insertarSoporte($idRq, $texto, $nombreOriginal, $tipo, $tamano = 0)
    {
        global $conexion;
        $tipo = ($tipo === 'soporte') ? 'soporte' : 'evidencia';
        //Los archivos de las RQ se guardan en "adjunto", la tabla de archivos de todos los
        //módulos (MODULO = 'RQ'). El archivo va completo en ARCHIVO, como texto base64
        $sql = "INSERT INTO adjunto (MODULO, ID_REGISTRO, NOMBRE, ARCHIVO, TIPO, TAMANO, ID_COLABORADOR, FEC_REGISTRO, ESTADO)
                VALUES ('RQ', " . intval($idRq) . ", " . $this->textoSql(mb_substr((string)$nombreOriginal, 0, 200)) . ", '" . $conexion->real_escape_string((string)$texto) . "',
                        '$tipo', " . intval($tamano) . ", COALESCE((SELECT ID_COLABORADOR_SOLICITA FROM rq WHERE ID_RQ = " . intval($idRq) . "), 0), NOW(), 1)";
        return $this->insertarId($sql);
    }

    //-----------------------------------------------------------------------
    // Consultas
    //-----------------------------------------------------------------------

    //El listado.
    //"Vencida": lleva más días en "Solicitada" de los esperados sin que nadie la apruebe.
    //ESTADO: 0 si está anulada y 1 si no (así la lee la pantalla)
    //$condAcceso: la condición de AccesoHelper (qué RQ puede ver este usuario)
    //Periodo: $anio = 0 es "todos los años"; $mes = 0 es "todo el año"
    public function listar($condAcceso, $idProyecto, $idCentro, $anio, $mes)
    {
        $filtro = '';
        if (intval($idProyecto) > 0) { $filtro .= " AND c.ID_PROYECTO = " . intval($idProyecto); }
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

        $sql = "SELECT r.ID_RQ, r.NUMERO_RQ, r.TIPO_RQ, r.FECHA_RQ,
                       IF(r.ID_RQ_ESTADO = " . RqEstado::ANULADA . ", 0, 1) AS ESTADO,
                       p.NOM_PROYECTO, c.NOM_CENTRO_OP,
                       r.ID_RQ_ESTADO,
                       " . RqEstado::sqlColumnas('r.ID_RQ_ESTADO') . ",
                       col.NOM_COLABORADOR AS SOLICITA,
                       DATEDIFF(NOW(), " . RqEstado::sqlDesde('r.ID_RQ') . ") AS DIAS_EN_ESTADO,
                       DATEDIFF(NOW(), r.FEC_CREACION) AS DIAS_TOTALES,
                       (SELECT COUNT(*) FROM rq_detalle d WHERE d.ID_RQ = r.ID_RQ) AS ITEMS,
                       (SELECT GROUP_CONCAT(d.DESCRIPCION SEPARATOR ' · ')
                          FROM rq_detalle d WHERE d.ID_RQ = r.ID_RQ) AS RESUMEN,
                       " . RqEstado::sqlVencida('r.ID_RQ_ESTADO', RqEstado::sqlDesde('r.ID_RQ')) . " AS VENCIDA
                  FROM rq r
                  INNER JOIN centros_operacion c  ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                  INNER JOIN proyectos p          ON p.ID_PROYECTO = c.ID_PROYECTO
                  LEFT  JOIN colaboradores col    ON col.ID_COLABORADOR = r.ID_COLABORADOR_SOLICITA
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
        //ID_PROYECTO sale del peaje, con el nombre que espera la pantalla
        $sql = "SELECT r.*, c.ID_PROYECTO, IF(r.ID_RQ_ESTADO = " . RqEstado::ANULADA . ", 0, 1) AS ESTADO,
                       p.NOM_PROYECTO, c.NOM_CENTRO_OP,
                       " . RqEstado::sqlColumnas('r.ID_RQ_ESTADO', array('NOM_RQ_ESTADO', 'COLOR', 'ES_FINAL', 'ORDEN', 'DIAS_ESPERADOS')) . ",
                       col.NOM_COLABORADOR AS SOLICITA,
                       DATEDIFF(NOW(), " . RqEstado::sqlDesde('r.ID_RQ') . ") AS DIAS_EN_ESTADO,
                       " . self::sqlReintentos('r.ID_RQ') . " AS REINTENTOS
                  FROM rq r
                  INNER JOIN centros_operacion c  ON c.ID_CENTRO_OP = r.ID_CENTRO_OP
                  INNER JOIN proyectos p          ON p.ID_PROYECTO = c.ID_PROYECTO
                  LEFT  JOIN colaboradores col    ON col.ID_COLABORADOR = r.ID_COLABORADOR_SOLICITA
                 WHERE r.ID_RQ = $idRq AND $condAcceso
                 LIMIT 1";
        $resultado = $this->ejecutar($sql);
        return $resultado ? $resultado->fetch_assoc() : null;
    }

    //Cuántas veces se corrigió y se envió de nuevo una RQ rechazada: se cuentan en el historial
    //los pasos de Rechazada a Solicitada (no se guarda un contador aparte)
    public static function sqlReintentos($columnaId)
    {
        return "(SELECT COUNT(*) FROM historial hr
                  WHERE hr.MODULO = 'RQ' AND hr.TIPO = '" . RqEstado::HIST_CAMBIO . "' AND hr.ID_REGISTRO = $columnaId
                    AND hr.ESTADO_ANTERIOR = " . RqEstado::RECHAZADA . " AND hr.ESTADO_NUEVO = " . RqEstado::SOLICITADA . ")";
    }

    //Los ítems de una RQ
    public function detalle($idRq)
    {
        return $this->ejecutar("SELECT * FROM rq_detalle WHERE ID_RQ = " . intval($idRq) . " ORDER BY ID_RQ_DETALLE");
    }

    //El historial de la RQ, del más reciente al más antiguo
    public function seguimiento($idRq)
    {
        $sql = "SELECT h.FECHA, h.TEXTO AS OBSERVACION,
                       IF(h.ESTADO_ANTERIOR IS NULL, NULL, " . RqEstado::sqlCampo('h.ESTADO_ANTERIOR', 'NOM_RQ_ESTADO') . ") AS ANTERIOR,
                       " . RqEstado::sqlCampo('h.ESTADO_NUEVO', 'NOM_RQ_ESTADO') . " AS NUEVO,
                       col.NOM_COLABORADOR AS QUIEN, ro.NOM_ROL_USUARIO_SISTEMA AS ROL
                  FROM historial h
                  LEFT JOIN colaboradores col ON col.ID_COLABORADOR = h.ID_COLABORADOR
                  LEFT JOIN rol_usuarios_sistemas ro ON ro.ID_ROL_USUARIO_SISTEMA = col.ID_ROL_USUARIO_SISTEMA
                 WHERE h.MODULO = 'RQ' AND h.ID_REGISTRO = " . intval($idRq) . "
                 ORDER BY h.FECHA DESC, h.ID_HISTORIAL DESC";
        return $this->ejecutar($sql);
    }

    //Los archivos de una RQ, sin el contenido (cada uno se abre aparte con AdjuntoControl.php?op=ver)
    public function soportes($idRq)
    {
        //Con los nombres de columna que usa la pantalla (ID_RQ_SOPORTE, NOMBRE_ORIGINAL...)
        return $this->ejecutar("SELECT ID_ADJUNTO AS ID_RQ_SOPORTE, ID_REGISTRO AS ID_RQ, NOMBRE AS NOMBRE_ORIGINAL,
                                       TIPO, FEC_REGISTRO AS FECHA
                                  FROM adjunto WHERE MODULO = 'RQ' AND ESTADO = 1 AND ID_REGISTRO = " . intval($idRq) . " ORDER BY ID_ADJUNTO");
    }

    //-----------------------------------------------------------------------
    // Cambio de estado
    //-----------------------------------------------------------------------

    //Flujo de un solo paso (ver RQEstados.php):
    //  Solicitada (1) -> Aprobada (3) o Rechazada (7)
    //Solo desde "Solicitada" y solo para quien tiene el permiso de aprobar.
    //Aprobada y Rechazada son finales
    public function estadosPermitidos($idEstadoActual, $puedeAprobar)
    {
        if (!$puedeAprobar || intval($idEstadoActual) !== RqEstado::SOLICITADA) { return array(); }
        return array(RqEstado::datos(RqEstado::APROBADA), RqEstado::datos(RqEstado::RECHAZADA));
    }

    //Cambia el estado de una RQ
    public function cambiarEstado($idRq, $nuevo)
    {
        $sql = "UPDATE rq SET ID_RQ_ESTADO = " . intval($nuevo) . " WHERE ID_RQ = " . intval($idRq);
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
        $sql = "UPDATE rq SET FECHA_RQ = " . $this->textoSql($fecha) . ",
                              OBSERVACION_SST = " . $this->textoSql($observacion) . ",
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
        return $this->ejecutar("UPDATE rq SET ID_RQ_ESTADO = " . RqEstado::SOLICITADA . " WHERE ID_RQ = " . intval($idRq));
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
        $sql = "UPDATE rq SET ID_RQ_ESTADO = " . RqEstado::ANULADA . " WHERE ID_RQ = " . intval($idRq);
        return $this->ejecutar($sql);
    }
}
