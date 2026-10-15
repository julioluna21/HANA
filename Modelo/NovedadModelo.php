<?php
/*
  HANA - Novedades y hallazgos (modelo)
  ---------------------------------------------------------------------------
  Consultas de la tabla novedades_hallazgos y de su conversación (tabla historial).
  Lo usa Control/NovedadesControl.php.
*/
require "../Conexion/ConexionDB.php";
class Novedades
{
        //Implementamos el super constructor 
        public function __construct()
        {
        }
        //Implementamos un método para insertar registros 	
        //$rolDestino: el rol al que se le notifica la novedad (puede ir vacío en
        //las que crea el sistema, como las de listas de chequeo)
        public function insertar($fechacreacion,$colaboraador,$centroopertivo,$asignado,$observador,$titulo,$descripcion,$imagen,$validez,$relevancia,$fechalimite,$rolDestino = null)
        {
                $sql = "INSERT INTO novedades_hallazgos(FEC_CREACION_NOVEDADES_HALLAZGOS,ID_COLABORADOR_NOVEDADES_HALLAZGOS,ID_CENTRO_OP_NOVEDADES_HALLAZGOS,ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS,ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,DESCR_NOVEDADES_HALLAZGOS,NOM_FOTO_INI_NOVEDADES_HALLAZGOS,VALIDEZ_NOVEDADES_HALLAZGOS,ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS,FECHA_LIMITE_NOVEDAD,ESTADO_NOVEDAD,ID_ROL_DESTINO)
                            VALUES ('$fechacreacion','$colaboraador','$centroopertivo','$asignado','$observador','$titulo','$descripcion','$imagen','$validez','$relevancia','$fechalimite',1," . ($rolDestino ? intval($rolDestino) : 'NULL') . ")";
                return ejecutarConsulta_retornarID($sql); //envia la sentencia a la funcion ejecutarConsulta que está en conexion.php
        }
        //Las respuestas de una novedad se guardan en historial (MODULO 'NOVEDAD',
        //TIPO 'RESPUESTA'), igual que la conversación de las RQ y las comunicaciones.
        //ESTADO_NUEVO es el estado en que quedó la novedad; NULL si la respuesta no lo cambió
        public function insertarRespuesta($IDnovedad,$colaborador,$fecha,$descripcion,$estado)
        {
                $estado = intval($estado);
                $sql = "INSERT INTO historial(MODULO,ID_REGISTRO,TIPO,ESTADO_NUEVO,TEXTO,ID_COLABORADOR,FECHA)
                            VALUES ('NOVEDAD'," . intval($IDnovedad) . ",'RESPUESTA'," . ($estado > 0 ? $estado : 'NULL') . ",'$descripcion'," . intval($colaborador) . ",'$fecha')";
                return ejecutarConsulta($sql);
        }
        //Implementar un método para mostrar los datos de un registro a modificar
        public function mostrar($IDnovedad)
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS WHERE novedades_hallazgos.ID_NOVEDADES_HALLAZGOS='$IDnovedad'";
                return ejecutarConsultaSimpleFila($sql);
        }
    
    
          //Las respuestas en orden, con los mismos nombres de campo que usa la pantalla
          public function mostrarRespuesta($IDnovedad)
        {
                $sql = "SELECT c.NOM_COLABORADOR, h.ID_HISTORIAL AS ID_RESPUESTA_NOVEDADES_HALLAZGOS,
                               h.ID_REGISTRO AS ID_NOVEDAD_HALLAZGOS_RESPUESTA_NOVEDADES_HALLAZGOS,
                               h.ID_COLABORADOR AS ID_COLABORADOR_RESPUESTA, h.FECHA AS FEC_RESPUESTA_NOVEDAD_HALLAZGOS,
                               h.TEXTO AS DESCR_RESPUESTA_NOVEDAD_HALLAZGOS, IFNULL(h.ESTADO_NUEVO, 0) AS ESTADO_NOVEDAD
                          FROM historial h
                          INNER JOIN colaboradores c ON c.ID_COLABORADOR = h.ID_COLABORADOR
                         WHERE h.MODULO = 'NOVEDAD' AND h.TIPO = 'RESPUESTA' AND h.ID_REGISTRO = " . intval($IDnovedad) . "
                         ORDER BY h.FECHA, h.ID_HISTORIAL";
                return ejecutarConsulta($sql);
        }
        //Listados: todas las columnas menos las fotos, que no se muestran en la tabla
        //y son texto base64 muy pesado
        public function listar()
        {
                $sql = "SELECT novedades_hallazgos.ID_NOVEDADES_HALLAZGOS,novedades_hallazgos.FEC_CREACION_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,novedades_hallazgos.DESCR_NOVEDADES_HALLAZGOS,novedades_hallazgos.VALIDEZ_NOVEDADES_HALLAZGOS,novedades_hallazgos.FEC_CIERRE_NOVEDAD_HALLAZGOS,novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS,novedades_hallazgos.ESTADO_NOVEDAD,novedades_hallazgos.FECHA_LIMITE_NOVEDAD,novedades_hallazgos.ID_ROL_DESTINO,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO_CENTRO_OP INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS";
                return ejecutarConsulta($sql);
        }
    
        //Las novedades que puede ver (condición de acceso armada en AccesoModelo) con los filtros de la pantalla:
        //  desde, hasta (AAAA-MM-DD), asignadoA (id del colaborador), proyecto (id), estado (número)
        public function listarFiltrado($condAcceso, $filtros)
        {
                $cond = array('(' . $condAcceso . ')');
                if (!empty($filtros['desde']) && !empty($filtros['hasta'])
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['hasta'])) {
                        $cond[] = "(novedades_hallazgos.FEC_CREACION_NOVEDADES_HALLAZGOS BETWEEN '" . $filtros['desde'] . " 00:00:00' AND '" . $filtros['hasta'] . " 23:59:59')";
                }
                if (!empty($filtros['asignadoA'])) { $cond[] = "novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS = " . intval($filtros['asignadoA']); }
                if (!empty($filtros['proyecto']))  { $cond[] = "proyectos.ID_PROYECTO = " . intval($filtros['proyecto']); }
                if (!empty($filtros['estado']))    { $cond[] = "novedades_hallazgos.ESTADO_NOVEDAD = " . intval($filtros['estado']); }
                return $this->listarCondicional("WHERE " . implode(" AND ", $cond));
        }

        //Consulta base de novedades; quien la llama le pasa el WHERE ya armado
        private function listarCondicional($condicional)
        {
                $sql = "SELECT novedades_hallazgos.ID_NOVEDADES_HALLAZGOS,novedades_hallazgos.FEC_CREACION_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,novedades_hallazgos.DESCR_NOVEDADES_HALLAZGOS,novedades_hallazgos.VALIDEZ_NOVEDADES_HALLAZGOS,novedades_hallazgos.FEC_CIERRE_NOVEDAD_HALLAZGOS,novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS,novedades_hallazgos.ESTADO_NOVEDAD,novedades_hallazgos.FECHA_LIMITE_NOVEDAD,novedades_hallazgos.ID_ROL_DESTINO,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO_CENTRO_OP INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS $condicional";
                return ejecutarConsulta($sql);
        }
    
    
   
    
        //Implementar un método para listar los registros
        public function diaslimites($id)
        {
                $id = intval($id); //solo números en la consulta
                $sql = "SELECT estados_relevancia.DIAS_ESTADOS_RELEVANCIAS FROM estados_relevancia WHERE estados_relevancia.ID_ESTADOS_RELEVANCIA=$id";
                return ejecutarConsultaSimpleFila($sql);
        }
    
         //Cambia el estado de una novedad
         public function editarEstado($IDnovedad,$estado)
        {
                $IDnovedad = intval($IDnovedad); $estado = intval($estado); //solo números en la consulta
                $sql = "UPDATE novedades_hallazgos SET ESTADO_NOVEDAD=$estado WHERE ID_NOVEDADES_HALLAZGOS=$IDnovedad";
                return ejecutarConsulta($sql);
        }
    
    
          //Guarda la fecha de cierre de una novedad
          public function editarfecha($IDnovedad,$fecha)
        {
                $IDnovedad = intval($IDnovedad); //solo números en la consulta
                $sql = "UPDATE novedades_hallazgos SET  FEC_CIERRE_NOVEDAD_HALLAZGOS='$fecha' WHERE ID_NOVEDADES_HALLAZGOS=$IDnovedad";
                return ejecutarConsulta($sql);
        }
    
    
           //Guarda la foto con la que se cerró la novedad (texto base64)
           public function imagencierre($IDnovedad,$imagen)
        {
                $IDnovedad = intval($IDnovedad); //solo números en la consulta
                $sql = "UPDATE novedades_hallazgos SET NOM_FOTO_FIN_NOVEDADES_HALLAZGOS='$imagen' WHERE ID_NOVEDADES_HALLAZGOS=$IDnovedad";
                return ejecutarConsulta($sql);
        }

        //Las fotos de una novedad, como texto base64 (data:image/...;base64,...)
        public function fotos($IDnovedad)
        {
                $sql = "SELECT NOM_FOTO_INI_NOVEDADES_HALLAZGOS AS INI, NOM_FOTO_FIN_NOVEDADES_HALLAZGOS AS FIN FROM novedades_hallazgos WHERE ID_NOVEDADES_HALLAZGOS=" . intval($IDnovedad);
                return ejecutarConsultaSimpleFila($sql);
        }
    
          //Centros asignados a un usuario
          public function centrosOP($IDusaurio)
        {
                $sql = "SELECT asoc_usuarios_sistemas_x_cop.* FROM asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP=$IDusaurio";
                return ejecutarConsulta($sql);
        }
	
	      //Proyectos activos
	      public function proyectos()
        {
                $sql = "SELECT * FROM proyectos WHERE proyectos.Estado=1";
                return ejecutarConsulta($sql);
        }
	
        //Correos de las personas activas que tienen este rol, sin incluir a quien
        //registró la novedad (no tiene sentido avisarle de lo que él mismo hizo)
        public function correosDeRol($idRol, $excluirColaborador)
        {
                $idRol = intval($idRol);
                $excluir = intval($excluirColaborador);
                $sql = "SELECT DISTINCT c.MAIL_COLABORADOR, c.NOM_COLABORADOR
                          FROM usuarios_sistema u
                          INNER JOIN colaboradores c ON c.ID_COLABORADOR = u.ID_COLABORADOR_USUARIOS_SISTEMA
                         WHERE u.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA = $idRol
                           AND u.ESTADO = 1
                           AND c.ID_COLABORADOR <> $excluir
                           AND c.MAIL_COLABORADOR IS NOT NULL AND c.MAIL_COLABORADOR <> ''";
                return ejecutarConsulta($sql);
        }

	       //Datos para el correo de aviso: a quién se asignó, peaje, prioridad y título
	       public function correo($id)
        {
                $id = intval($id); //solo números en la consulta
                $sql = "SELECT colaboradores.NOM_COLABORADOR,colaboradores.ID_COLABORADOR,colaboradores.MAIL_COLABORADOR, centros_operacion.NOM_CENTRO_OP, estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS FROM novedades_hallazgos INNER JOIN colaboradores ON colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN estados_relevancia ON estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS WHERE novedades_hallazgos.ID_NOVEDADES_HALLAZGOS=$id";
                return ejecutarConsultaSimpleFila($sql);
        }
        
    
		
}