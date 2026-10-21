<?php
/*
  HANA - Novedades y hallazgos (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla novedades_hallazgos y de su conversación (tabla historial).
  Lo usa Control/NovedadesControl.php.
*/
require "../Conexion/ConexionDB.php";
require_once __DIR__ . '/NovedadPrioridad.php'; //ALTO, MEDIO o BAJO y sus días
require_once __DIR__ . '/HanaDB.php';

class Novedades
{
        //Los estados de una novedad (ESTADO_NOVEDAD)
        const ASIGNADA = 1;    //recién creada
        const EN_PROCESO = 2;
        const FINALIZADA = 3;  //quien la atiende dice que terminó; auditoría la revisa
        const CERRADA = 4;     //solo con permiso de auditoría; ya no recibe respuestas

        //El estado actual de una novedad (1 a 4), o 0 si no existe
        public function estadoActual($idNovedad)
        {
                $fila = HanaDB::fila("SELECT ESTADO_NOVEDAD FROM novedades_hallazgos WHERE ID_NOVEDADES_HALLAZGOS = ?", 'i', array((int)$idNovedad));
                return $fila ? (int)$fila['ESTADO_NOVEDAD'] : 0;
        }

        //¿El título existe y está activo?
        public function tituloActivo($idTitulo)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM titulo_novedades_hallazgos WHERE ID_TITULO_NOVEDADES_HALLAZGOS = ? AND ESTADO = 1", 'i', array((int)$idTitulo));
        }

        //¿El centro existe y está activo?
        public function centroActivo($idCentro)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM centros_operacion WHERE ID_CENTRO_OP = ? AND ESTADO = 1", 'i', array((int)$idCentro));
        }

        //¿La persona existe y está activa? (a quién se asigna la novedad)
        public function colaboradorActivo($idColaborador)
        {
                return (bool)HanaDB::fila("SELECT 1 AS ok FROM colaboradores WHERE ID_COLABORADOR = ? AND ESTADO = 1", 'i', array((int)$idColaborador));
        }

        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }

        //Registra una novedad nueva (queda Asignada) y devuelve su número.
        //  $fechaCreacion        ahora
        //  $idQuienReporta       la persona con sesión
        //  $idCentro, $idAsignado, $idTitulo   números ya revisados por el controlador
        //  $descripcion, $validez  textos ya pasados por limpiarCadena (listos para la consulta)
        //  $foto                 la foto como texto base64, o '' sin foto
        //  $prioridad            ALTO, MEDIO o BAJO;  $fechaLimite: hasta cuándo hay para atenderla
        //  $rolDestino           el rol al que se le notifica (puede ir vacío)
        public function insertar($fechaCreacion, $idQuienReporta, $idCentro, $idAsignado, $idTitulo, $descripcion, $foto, $validez, $prioridad, $fechaLimite, $rolDestino = null)
        {
                $prioridad = NovedadPrioridad::valida($prioridad) ? $prioridad : 'BAJO';
                $sql = "INSERT INTO novedades_hallazgos(FEC_CREACION,ID_COLABORADOR_REPORTA,ID_CENTRO_OP,ID_COLABORADOR_ASIGNADO,ID_TITULO,DESCRIPCION,FOTO_REPORTE,VALIDEZ,PRIORIDAD,FECHA_LIMITE,ESTADO_NOVEDAD,ID_ROL_DESTINO)
                            VALUES ('$fechaCreacion'," . intval($idQuienReporta) . "," . intval($idCentro) . "," . intval($idAsignado) . "," . intval($idTitulo) . ",
                                    '$descripcion','$foto','$validez','$prioridad','$fechaLimite',1," . ($rolDestino ? intval($rolDestino) : 'NULL') . ")";
                return ejecutarConsulta_retornarID($sql);
        }
        //Las respuestas de una novedad se guardan en historial (MODULO 'NOVEDAD',
        //TIPO 'RESPUESTA'), igual que la conversación de las RQ y las comunicaciones.
        //ESTADO_NUEVO es el estado en que quedó la novedad; NULL si la respuesta no lo cambió
        public function insertarRespuesta($idNovedad,$colaborador,$fecha,$descripcion,$estado)
        {
                $estado = intval($estado);
                $sql = "INSERT INTO historial(MODULO,ID_REGISTRO,TIPO,ESTADO_NUEVO,TEXTO,ID_COLABORADOR,FECHA)
                            VALUES ('NOVEDAD'," . intval($idNovedad) . ",'RESPUESTA'," . ($estado > 0 ? $estado : 'NULL') . ",'$descripcion'," . intval($colaborador) . ",'$fecha')";
                return ejecutarConsulta($sql);
        }
        //Una novedad con su título, quién la reportó, a quién se asignó y su centro, para abrirla
        public function mostrar($idNovedad)
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,centros_operacion.NOM_CENTRO_OP FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_REPORTA INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNADO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP WHERE novedades_hallazgos.ID_NOVEDADES_HALLAZGOS='$idNovedad'";
                return ejecutarConsultaSimpleFila($sql);
        }

        //Las respuestas en orden, con los mismos nombres de campo que usa la pantalla
        public function mostrarRespuesta($idNovedad)
        {
                $sql = "SELECT c.NOM_COLABORADOR, h.ID_HISTORIAL AS ID_RESPUESTA_NOVEDADES_HALLAZGOS,
                               h.ID_REGISTRO AS ID_NOVEDAD_HALLAZGOS_RESPUESTA_NOVEDADES_HALLAZGOS,
                               h.ID_COLABORADOR AS ID_COLABORADOR_RESPUESTA, h.FECHA AS FEC_RESPUESTA_NOVEDAD_HALLAZGOS,
                               h.TEXTO AS DESCR_RESPUESTA_NOVEDAD_HALLAZGOS, IFNULL(h.ESTADO_NUEVO, 0) AS ESTADO_NOVEDAD
                          FROM historial h
                          INNER JOIN colaboradores c ON c.ID_COLABORADOR = h.ID_COLABORADOR
                         WHERE h.MODULO = 'NOVEDAD' AND h.TIPO = 'RESPUESTA' AND h.ID_REGISTRO = " . intval($idNovedad) . "
                         ORDER BY h.FECHA, h.ID_HISTORIAL";
                return ejecutarConsulta($sql);
        }
        //Listados: todas las columnas menos las fotos, que no se muestran en la tabla
        //y son texto base64 muy pesado
        public function listar()
        {
                $sql = "SELECT novedades_hallazgos.ID_NOVEDADES_HALLAZGOS,novedades_hallazgos.FEC_CREACION,novedades_hallazgos.ID_COLABORADOR_REPORTA,novedades_hallazgos.ID_CENTRO_OP,novedades_hallazgos.ID_COLABORADOR_ASIGNADO,novedades_hallazgos.ID_TITULO,novedades_hallazgos.DESCRIPCION,novedades_hallazgos.VALIDEZ,novedades_hallazgos.FEC_CIERRE,novedades_hallazgos.PRIORIDAD,novedades_hallazgos.ESTADO_NOVEDAD,novedades_hallazgos.FECHA_LIMITE,novedades_hallazgos.ID_ROL_DESTINO,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_REPORTA INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNADO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO";
                return ejecutarConsulta($sql);
        }

        //Las novedades que puede ver (condición de acceso armada en AccesoModelo) con los filtros de la pantalla:
        //  desde, hasta (AAAA-MM-DD), asignadoA (id del colaborador), proyecto (id), estado (número)
        public function listarFiltrado($condAcceso, $filtros)
        {
                $cond = array('(' . $condAcceso . ')');
                if (!empty($filtros['desde']) && !empty($filtros['hasta'])
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['hasta'])) {
                        $cond[] = "(novedades_hallazgos.FEC_CREACION BETWEEN '" . $filtros['desde'] . " 00:00:00' AND '" . $filtros['hasta'] . " 23:59:59')";
                }
                if (!empty($filtros['asignadoA'])) { $cond[] = "novedades_hallazgos.ID_COLABORADOR_ASIGNADO = " . intval($filtros['asignadoA']); }
                if (!empty($filtros['proyecto']))  { $cond[] = "proyectos.ID_PROYECTO = " . intval($filtros['proyecto']); }
                if (!empty($filtros['estado']))    { $cond[] = "novedades_hallazgos.ESTADO_NOVEDAD = " . intval($filtros['estado']); }
                return $this->listarCondicional("WHERE " . implode(" AND ", $cond));
        }

        //Consulta base de novedades; quien la llama le pasa el WHERE ya armado
        private function listarCondicional($condicional)
        {
                $sql = "SELECT novedades_hallazgos.ID_NOVEDADES_HALLAZGOS,novedades_hallazgos.FEC_CREACION,novedades_hallazgos.ID_COLABORADOR_REPORTA,novedades_hallazgos.ID_CENTRO_OP,novedades_hallazgos.ID_COLABORADOR_ASIGNADO,novedades_hallazgos.ID_TITULO,novedades_hallazgos.DESCRIPCION,novedades_hallazgos.VALIDEZ,novedades_hallazgos.FEC_CIERRE,novedades_hallazgos.PRIORIDAD,novedades_hallazgos.ESTADO_NOVEDAD,novedades_hallazgos.FECHA_LIMITE,novedades_hallazgos.ID_ROL_DESTINO,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_REPORTA INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNADO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO $condicional";
                return ejecutarConsulta($sql);
        }

        //Días para atender una novedad de esa prioridad (Parámetros del sistema)
        public function diasParaAtender($prioridad)
        {
                return NovedadPrioridad::dias($prioridad);
        }

        //Cambia el estado de una novedad
        public function editarEstado($idNovedad,$estado)
        {
                $idNovedad = intval($idNovedad); $estado = intval($estado); //solo números en la consulta
                $sql = "UPDATE novedades_hallazgos SET ESTADO_NOVEDAD=$estado WHERE ID_NOVEDADES_HALLAZGOS=$idNovedad";
                return ejecutarConsulta($sql);
        }

        //Guarda la fecha de cierre de una novedad
        public function guardarFechaCierre($idNovedad,$fecha)
        {
                $idNovedad = intval($idNovedad); //solo números en la consulta
                $sql = "UPDATE novedades_hallazgos SET  FEC_CIERRE='$fecha' WHERE ID_NOVEDADES_HALLAZGOS=$idNovedad";
                return ejecutarConsulta($sql);
        }

        //Guarda la foto con la que se cerró la novedad (texto base64)
        public function guardarFotoCierre($idNovedad,$imagen)
        {
                $idNovedad = intval($idNovedad); //solo números en la consulta
                $sql = "UPDATE novedades_hallazgos SET FOTO_CIERRE='$imagen' WHERE ID_NOVEDADES_HALLAZGOS=$idNovedad";
                return ejecutarConsulta($sql);
        }

        //Las fotos de una novedad, como texto base64 (data:image/...;base64,...)
        public function fotos($idNovedad)
        {
                $sql = "SELECT FOTO_REPORTE AS INI, FOTO_CIERRE AS FIN FROM novedades_hallazgos WHERE ID_NOVEDADES_HALLAZGOS=" . intval($idNovedad);
                return ejecutarConsultaSimpleFila($sql);
        }

        //Centros asignados a un usuario
        public function centrosAsignados($idPersona)
        {
                $sql = "SELECT asoc_usuarios_sistemas_x_cop.* FROM asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_COLABORADOR=" . intval($idPersona);
                return ejecutarConsulta($sql);
        }

        //Proyectos activos
        public function proyectos()
        {
                $sql = "SELECT * FROM proyectos WHERE proyectos.ESTADO=1";
                return ejecutarConsulta($sql);
        }

        //Correos de las personas activas con usuario que tienen este rol, sin incluir a
        //quien registró la novedad (no tiene sentido avisarle de lo que él mismo hizo)
        public function correosDeRol($idRol, $excluirColaborador)
        {
                $idRol = intval($idRol);
                $excluir = intval($excluirColaborador);
                $sql = "SELECT DISTINCT c.MAIL_COLABORADOR, c.NOM_COLABORADOR
                          FROM colaboradores c
                          INNER JOIN usuarios_sistema u ON u.ID_COLABORADOR = c.ID_COLABORADOR
                         WHERE u.ID_ROL_USUARIO_SISTEMA = $idRol
                           AND c.ESTADO = 1 AND u.ESTADO = 1
                           AND c.ID_COLABORADOR <> $excluir
                           AND c.MAIL_COLABORADOR IS NOT NULL AND c.MAIL_COLABORADOR <> ''";
                return ejecutarConsulta($sql);
        }

        //Datos para el correo de aviso: a quién se asignó, peaje, prioridad y título
        public function correo($id)
        {
                $id = intval($id); //solo números en la consulta
                $sql = "SELECT colaboradores.NOM_COLABORADOR,colaboradores.ID_COLABORADOR,colaboradores.MAIL_COLABORADOR, centros_operacion.NOM_CENTRO_OP, novedades_hallazgos.PRIORIDAD,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS FROM novedades_hallazgos INNER JOIN colaboradores ON colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNADO INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO WHERE novedades_hallazgos.ID_NOVEDADES_HALLAZGOS=$id";
                return ejecutarConsultaSimpleFila($sql);
        }

}