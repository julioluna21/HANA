<?php
//Pantalla de Requisiciones (RQ)
//Reemplaza el Excel "REGISTRO DE RQ". Desde la Fase 1: dos tipos (RQ normal y
//RQ U urgente) y un solo paso: se solicita y se aprueba o se rechaza

session_start();

if (isset($_SESSION['IdUsuarios'])) {
    $modulosAcceso = explode(",", $_SESSION['Modulos']);

    //Requisiciones: entran quien las pide, quien ve todas (17M) o quien aprueba (18M).
    //Las pide solo el coordinador del proyecto (parámetro RQ_SOLO_COORDINADOR); si se apaga, quien tenga 15M
    require_once __DIR__ . '/../Modelo/HanaConfig.php';
    $coordinaRq = isset($_SESSION['Idcolaborador']) && HanaDB::esCoordinador((int)$_SESSION['Idcolaborador']);
    $puedePedirRq = HanaConfig::si('RQ_SOLO_COORDINADOR', true) ? $coordinaRq : in_array("15M", $modulosAcceso);
    if ($puedePedirRq || in_array("15M", $modulosAcceso) || in_array("17M", $modulosAcceso) || in_array("18M", $modulosAcceso)) {
        include('head.php');
?>
<!-- Estilos del módulo -->
<link href="../public/css/rq.css?v=3" rel="stylesheet">

<!-- page content -->
<div class="right_col" role="main">
  <div class="row">
    <div class="col-md-12 col-xs-12">

      <!-- ====================== LISTADO ====================== -->
      <div class="x_panel" id="panelListado">
        <div class="x_title">
          <h2><i class="fa fa-wrench"></i> Requisiciones (RQ)</h2>
          <button type="button" class="btn btn-success pull-right" id="btnNuevaRQ" data-puede="<?php echo $puedePedirRq ? '1' : '0'; ?>" style="<?php echo $puedePedirRq ? '' : 'display:none;'; ?>margin-top:4px;">
            <i class="fa fa-plus-square"></i> Nueva RQ
          </button>
          <div class="clearfix"></div>
        </div>

        <div class="x_content">
          <!-- Los números del periodo elegido en los filtros -->
          <div class="rq-contadores">
            <div class="rq-contador rq-cnt-pend"><span class="rq-num" id="cntPendientes">0</span><span class="rq-lbl">Pendientes</span></div>
            <div class="rq-contador rq-cnt-urg"><span class="rq-num" id="cntUrgentes">0</span><span class="rq-lbl">Urgentes pendientes</span></div>
            <div class="rq-contador rq-cnt-ok"><span class="rq-num" id="cntAprobadas">0</span><span class="rq-lbl">Aprobadas</span></div>
            <div class="rq-contador rq-cnt-rech"><span class="rq-num" id="cntRechazadas">0</span><span class="rq-lbl">Rechazadas</span></div>
          </div>

          <!-- Qué RQ ver: el mismo control de Novedades -->
          <div class="hana-vista" id="vistaRQ" role="group" aria-label="Qué RQ ver">
            <button type="button" class="hana-vista-btn activo" data-vista="mias"><i class="fa fa-user"></i> Mis RQ</button>
            <button type="button" class="hana-vista-btn" data-vista="todas"><i class="fa fa-users"></i> <span class="hana-vista-todas">Las de mi rol</span></button>
          </div>

          <!-- Filtros: se aplican solos al cambiarlos. El periodo abre en el mes actual -->
          <div class="row rq-filtros">
            <div class="form-group col-md-3 col-sm-6 col-xs-12">
              <label for="fProyecto">Proyecto:</label>
              <select id="fProyecto" class="form-control"><option value="0">Todos</option></select>
            </div>
            <div class="form-group col-md-3 col-sm-6 col-xs-12">
              <label for="fCentro">Peaje:</label>
              <select id="fCentro" class="form-control"><option value="0">Todos</option></select>
            </div>
            <div class="form-group col-md-3 col-sm-6 col-xs-6">
              <label for="fMes">Mes:</label>
              <select id="fMes" class="form-control">
                <option value="0">Todo el año</option>
                <option value="1">Enero</option><option value="2">Febrero</option><option value="3">Marzo</option>
                <option value="4">Abril</option><option value="5">Mayo</option><option value="6">Junio</option>
                <option value="7">Julio</option><option value="8">Agosto</option><option value="9">Septiembre</option>
                <option value="10">Octubre</option><option value="11">Noviembre</option><option value="12">Diciembre</option>
              </select>
            </div>
            <div class="form-group col-md-3 col-sm-6 col-xs-6">
              <label for="fAnio">Año:</label>
              <select id="fAnio" class="form-control"><option value="0">Todos</option></select>
            </div>
          </div>

          <table id="tblRQ" class="table table-striped table-bordered" style="width:100%">
            <thead>
              <tr>
                <th>RQ</th>
                <th>Proyecto</th>
                <th>Peaje</th>
                <th>Fecha</th>
                <th>Solicitud</th>
                <th>Estado</th>
                <th>Días</th>
                <th>Opciones</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <!-- ====================== FORMULARIO ====================== -->
      <div class="x_panel" id="panelFormulario" style="display:none;">
        <div class="x_title">
          <h2><i class="fa fa-plus-square"></i> Nueva requisición</h2>
          <div class="clearfix"></div>
        </div>

        <div class="x_content">
          <form id="formRQ" enctype="multipart/form-data" autocomplete="off">

            <div class="row">
              <div class="form-group col-md-5 col-sm-6 col-xs-12">
                <label for="rqCentro">Peaje, báscula, base u oficina: <span class="rq-req">*</span></label>
                <select id="rqCentro" name="centro" class="form-control" required>
                  <option value="">Selecciona...</option>
                </select>
              </div>
              <div class="form-group col-md-3 col-sm-3 col-xs-6">
                <label for="rqNumero">Número de RQ:</label>
                <input type="text" id="rqNumero" name="numero" class="form-control" maxlength="20" pattern="[A-Za-z0-9][A-Za-z0-9-]*"
                       placeholder="Automático">
                <small class="rq-ayuda">Déjalo vacío y se asigna solo. Puede llevar letras, por ejemplo 230A.</small>
              </div>
              <div class="form-group col-md-4 col-sm-3 col-xs-6">
                <label for="rqFecha">Fecha de la RQ: <span class="rq-req">*</span></label>
                <input type="date" id="rqFecha" name="fecha" class="form-control" required>
              </div>
            </div>

            <!-- Tipo: define el color del aviso que le llega a quien aprueba -->
            <h4 class="rq-subtitulo">Tipo de RQ</h4>
            <div class="rq-tipos" role="radiogroup" aria-label="Tipo de RQ">
              <label class="rq-tipo-op rq-tipo-op-n">
                <input type="radio" name="tipo" value="N" checked>
                <span><strong>RQ normal</strong><small>Se atiende en el tiempo habitual. Llega en amarillo.</small></span>
              </label>
              <label class="rq-tipo-op rq-tipo-op-u">
                <input type="radio" name="tipo" value="U">
                <span><strong>RQ U · Urgente</strong><small>Afecta la operación o la seguridad. Llega en rojo.</small></span>
              </label>
            </div>

            <!-- Los ítems: una RQ puede pedir varias cosas a la vez -->
            <h4 class="rq-subtitulo">Qué se solicita</h4>
            <div id="rqItems"></div>
            <button type="button" class="btn btn-default" id="btnAgregarItem">
              <i class="fa fa-plus"></i> Agregar otro ítem
            </button>

            <!-- Ya no se elige rol ni se marca riesgo: la RQ le llega a quien tiene el
                 permiso de aprobar, y lo urgente se indica con el tipo -->
            <h4 class="rq-subtitulo">Observaciones</h4>
            <div class="form-group">
              <label for="rqSst">Observación:</label>
              <textarea id="rqSst" name="sst" class="form-control" rows="2"
                        placeholder="Opcional. Lo que deba saber quien aprueba la RQ."></textarea>
            </div>

            <h4 class="rq-subtitulo">Evidencias y soportes</h4>
            <p class="rq-ayuda" style="margin-top:-4px;">
              Fotos del daño y documentos de soporte (imágenes o PDF). Máximo 10 archivos.
            </p>
            <input type="file" id="rqArchivosCamara" accept="image/*" capture="environment" class="rq-oculto">
            <input type="file" id="rqArchivos" accept="image/*,application/pdf" multiple class="rq-oculto">
            <div class="rq-botones-archivo">
              <button type="button" class="hana-foto-boton" id="btnRqCamara">
                <i class="fa fa-camera"></i> Tomar foto
              </button>
              <button type="button" class="hana-foto-boton hana-foto-boton-alt" id="btnRqArchivos">
                <i class="fa fa-folder-open-o"></i> Elegir de mis archivos
              </button>
            </div>
            <div id="rqPrevias" class="rq-previas"></div>

            <div class="ln_solid"></div>
            <div class="rq-acciones">
              <button type="button" class="btn btn-default" id="btnCancelarRQ">Cancelar</button>
              <button type="submit" class="btn btn-success" id="btnGuardarRQ">
                <i class="fa fa-save"></i> Registrar RQ
              </button>
            </div>
          </form>
        </div>
      </div>

    </div>
  </div>
</div>
<!-- /page content -->

<!-- ====================== DETALLE DE UNA RQ ====================== -->
<div class="modal fade" id="modalRQ" tabindex="-1" role="dialog" aria-labelledby="modalRQTitulo">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header rq-modal-cab">
        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="modalRQTitulo">RQ</h4>
      </div>
      <div class="modal-body" id="modalRQCuerpo">
        <div class="hana-campana-vacia"><i class="fa fa-spinner fa-spin"></i>Cargando...</div>
      </div>
    </div>
  </div>
</div>

<?php include('footer.php'); ?>
<script type="text/javascript" src="../Ajax/RQAjax.js?v=4"></script>

<?php
    } else {
        //Tiene sesión pero no el permiso del módulo. Antes se mandaba a Inicio sin
        //decir nada, y parecía que la página no existía. Ahora se revisa la causa
        //y se explica qué hacer
        require_once __DIR__ . '/../Conexion/ConexionDB.php';
        $idUsuario = intval($_SESSION['IdUsuarios']);

        //¿Ya se ejecutó el script de la base de datos?
        $r = ejecutarConsulta("SELECT COUNT(*) AS n FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rq'");
        $tablasListas = $r && ($f = $r->fetch_assoc()) && (int)$f['n'] > 0;

        //¿El rol del usuario ya tiene el permiso en la base (aunque la sesión aún no)?
        $r = ejecutarConsulta("SELECT ro.NOM_ROL_USUARIO_SISTEMA AS rol, ro.MODULOS_ROL_USUARIOS_SISTEMAS AS modulos
                                 FROM usuarios_sistema u
                                 INNER JOIN rol_usuarios_sistemas ro
                                         ON ro.ID_ROL_USUARIO_SISTEMA = u.ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA
                                WHERE u.ID_USUARIO_SISTEMA = $idUsuario LIMIT 1");
        $filaRol  = $r ? $r->fetch_assoc() : null;
        $nomRol   = $filaRol ? htmlspecialchars($filaRol['rol']) : '';
        $rolTiene = $filaRol && in_array('15M', explode(',', $filaRol['modulos']));

        if (!$tablasListas) {
            $titulo = 'Falta preparar la base de datos';
            $texto  = 'El módulo de requisiciones todavía no está creado en la base de datos. '
                    . 'Hay que ejecutar el script <code>00_MIGRACION_PRODUCCION.sql</code> '
                    . '(o <code>rq_modulo.sql</code>) en phpMyAdmin, sobre la base del sistema.';
        } elseif ($rolTiene) {
            $titulo = 'Cierra sesión y vuelve a entrar';
            $texto  = 'Tu rol ya tiene el permiso de requisiciones, pero los permisos se cargan al '
                    . 'iniciar sesión. Cierra sesión y vuelve a entrar para que aparezca el módulo.';
        } else {
            $titulo = 'Tu rol no tiene este permiso';
            $texto  = 'Tu rol' . ($nomRol ? ' (<strong>' . $nomRol . '</strong>)' : '') . ' no tiene habilitado el '
                    . 'módulo de requisiciones. Un administrador debe entrar a <strong>Configuración → Roles</strong>, '
                    . 'editar el rol y marcar la casilla <strong>Requisiciones (RQ)</strong>. Después, cierra sesión '
                    . 'y vuelve a entrar.';
        }

        include('head.php');
        echo '<div class="right_col" role="main"><div class="row"><div class="col-md-8 col-md-offset-2 col-xs-12">'
           . '<div class="x_panel" style="margin-top:30px;"><div class="x_content" style="padding:24px;">'
           . '<h3 style="color:#6E1A1E;margin-top:0;"><i class="fa fa-lock"></i> ' . $titulo . '</h3>'
           . '<p style="font-size:15px;line-height:1.6;">' . $texto . '</p>'
           . '<a href="InicioVista.php" class="btn btn-default" style="margin-top:10px;">Volver al inicio</a>'
           . '</div></div></div></div></div>';
        include('footer.php');
    }
} else {
    echo "<script>
  <!--
  window.location.replace('login.php');
  //-->
  </script>";
}
?>