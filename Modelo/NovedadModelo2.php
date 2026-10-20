<?php
/*
  HANA - Novedades (versión del sistema original, sin uso)
  ---------------------------------------------------------------------------
  Trabaja con los nombres de tabla y columna de la base original
  (FEC_CREACION_NOVEDADES_HALLAZGOS, respuesta_novedades_hallazgos,
  estados_relevancia...), que ya no existen. La versión en uso es
  Modelo/NovedadModelo.php. Ningún archivo lo carga; se conserva solo como
  referencia.
*/
require "../Conexion/ConexionDB.php";
class Novedades
{
        //Constructor vacío: la clase no guarda datos propios
        public function __construct()
        {
        }
        //Registra una novedad nueva
        public function insertar($fechaCreacion,$idReporta,$idCentro,$asignado,$observador,$titulo,$descripcion,$imagen,$validez,$relevancia,$fechaLimite)
        {
                $sql = "INSERT INTO novedades_hallazgos(FEC_CREACION_NOVEDADES_HALLAZGOS,ID_COLABORADOR_NOVEDADES_HALLAZGOS,ID_CENTRO_OP_NOVEDADES_HALLAZGOS,ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS,ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS,DESCR_NOVEDADES_HALLAZGOS,NOM_FOTO_INI_NOVEDADES_HALLAZGOS,VALIDEZ_NOVEDADES_HALLAZGOS,ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS,FECHA_LIMITE_NOVEDAD,ESTADO_NOVEDAD)
                            VALUES ('$fechaCreacion','$idReporta','$idCentro','$asignado','$observador','$titulo','$descripcion','$imagen','$validez','$relevancia','$fechaLimite',1)";
                return ejecutarConsulta($sql); //ejecutarConsulta está en Conexion/ConexionDB.php
        }
        //Guarda una respuesta a una novedad
        public function insertarRespuesta($idNovedad,$colaborador,$fecha,$descripcion,$estado)
        {
                $sql = "INSERT INTO respuesta_novedades_hallazgos(ID_NOVEDAD_HALLAZGOS_RESPUESTA_NOVEDADES_HALLAZGOS,ID_COLABORADOR_RESPUESTA,FEC_RESPUESTA_NOVEDAD_HALLAZGOS,DESCR_RESPUESTA_NOVEDAD_HALLAZGOS,ESTADO_NOVEDAD) VALUES ('$idNovedad','$colaborador','$fecha','$descripcion','$estado')";
                return ejecutarConsulta($sql);
        }
        //Una novedad con su título, quién la reporta, a quién se asignó, su prioridad y su centro
        public function mostrar($idNovedad)
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS WHERE novedades_hallazgos.ID_NOVEDADES_HALLAZGOS='$idNovedad'";
                return ejecutarConsultaSimpleFila($sql);
        }
    
    
          //Las respuestas de una novedad, de la más antigua a la más reciente
          public function mostrarRespuesta($idNovedad)
        {
                $sql = "SELECT colaboradores.NOM_COLABORADOR, respuesta_novedades_hallazgos.* FROM respuesta_novedades_hallazgos INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=respuesta_novedades_hallazgos.ID_COLABORADOR_RESPUESTA WHERE respuesta_novedades_hallazgos.ID_NOVEDAD_HALLAZGOS_RESPUESTA_NOVEDADES_HALLAZGOS=$idNovedad ORDER BY respuesta_novedades_hallazgos.FEC_RESPUESTA_NOVEDAD_HALLAZGOS ASC;";
                return ejecutarConsulta($sql);
        }
        //Todas las novedades con sus datos
        public function listar()
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO_CENTRO_OP INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS";
                return ejecutarConsulta($sql);
        }
    
        //Las novedades que cumplen una condición (el WHERE lo arma el controlador)
        public function listarCondicional($condicional)
        {
                $sql = "SELECT novedades_hallazgos.*,titulo_novedades_hallazgos.NOM_TITULO_NOVEDADES_HALLAZGOS,colaboradores.NOM_COLABORADOR, T2.NOM_COLABORADOR as asignado,estados_relevancia.NOMBRE_ESTADOS_RELEVANCIA,estados_relevancia.DIAS_ESTADOS_RELEVANCIAS,centros_operacion.NOM_CENTRO_OP,centros_operacion.ID_CENTRO_OP, observador_novedades_hallazgos.NOM_OBSERVADOR_NOVEDADES_HALLAZGOS,proyectos.NOM_PROYECTO FROM novedades_hallazgos INNER JOIN titulo_novedades_hallazgos on titulo_novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_TITULO_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS INNER JOIN colaboradores on colaboradores.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_NOVEDADES_HALLAZGOS INNER JOIN colaboradores T2 on T2.ID_COLABORADOR=novedades_hallazgos.ID_COLABORADOR_ASIGNACION_NOVEDADES_HALLAZGOS INNER JOIN centros_operacion on centros_operacion.ID_CENTRO_OP=novedades_hallazgos.ID_CENTRO_OP_NOVEDADES_HALLAZGOS INNER JOIN proyectos ON proyectos.ID_PROYECTO=centros_operacion.ID_PROYECTO_CENTRO_OP INNER JOIN estados_relevancia on estados_relevancia.ID_ESTADOS_RELEVANCIA=novedades_hallazgos.ID_ESTADO_RELEVANCIA_NOVEDADES_HALLAZGOS INNER JOIN observador_novedades_hallazgos on observador_novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS=novedades_hallazgos.ID_OBSERVADOR_NOVEDADES_HALLAZGOS_NOVEDADES_HALLAZGOS $condicional";
                return ejecutarConsulta($sql);
        }
    
    
   
    
        //Días para atender una novedad según su prioridad
        public function diaslimites($id)
        {
                $sql = "SELECT estados_relevancia.DIAS_ESTADOS_RELEVANCIAS FROM estados_relevancia WHERE estados_relevancia.ID_ESTADOS_RELEVANCIA=$id";
                return ejecutarConsultaSimpleFila($sql);
        }
    
         //Cambia el estado de una novedad
         public function editarEstado($idNovedad,$estado)
        {
                $sql = "UPDATE novedades_hallazgos SET ESTADO_NOVEDAD=$estado WHERE ID_NOVEDADES_HALLAZGOS=$idNovedad";
                return ejecutarConsulta($sql);
        }
    
    
          //Guarda la fecha de cierre
          public function editarfecha($idNovedad,$fecha)
        {
                $sql = "UPDATE novedades_hallazgos SET  FEC_CIERRE_NOVEDAD_HALLAZGOS='$fecha' WHERE ID_NOVEDADES_HALLAZGOS=$idNovedad";
                return ejecutarConsulta($sql);
        }
    
    
           //Guarda la foto del cierre
           public function imagencierre($idNovedad,$imagen)
        {
                $sql = "UPDATE novedades_hallazgos SET NOM_FOTO_FIN_NOVEDADES_HALLAZGOS='$imagen' WHERE ID_NOVEDADES_HALLAZGOS=$idNovedad";
                return ejecutarConsulta($sql);
        }
    
          //Los peajes asignados a un usuario
          public function centrosOP($idUsuario)
        {
                $sql = "SELECT asoc_usuarios_sistemas_x_cop.* FROM asoc_usuarios_sistemas_x_cop WHERE asoc_usuarios_sistemas_x_cop.ID_USUARIO_SISTEMA_ASOC_USUARIOS_SISTEMAS_X_COP=$idUsuario";
                return ejecutarConsulta($sql);
        }
	
	      //Los proyectos activos
	      public function proyectos()
        {
                $sql = "SELECT * FROM proyectos WHERE proyectos.Estado=1";
                return ejecutarConsulta($sql);
        }
        
    
		
}