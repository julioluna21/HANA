<?php
/*
  HANA - Controlador de vacantes (Reporte diario)
  Permiso 11M, y solo en los centros asignados al usuario.
*/
session_start();
require_once __DIR__ . "/../Modelo/VacanteModelo.php";
require_once __DIR__ . '/../Modelo/HanaTexto.php'; //las respuestas JSON llevan los textos como se escribieron (sin &amp;)
require_once __DIR__ . "/../Modelo/HanaFechas.php";
require_once __DIR__ . "/AccesoHelper.php";

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Bogota');

//Responde con un código de error y un mensaje en JSON, y corta la ejecución
function vacError($codigo, $mensaje)
{
    http_response_code($codigo);
    echo hanaJsonTexto(array('error' => $mensaje), JSON_UNESCAPED_UNICODE);
    exit;
}

//Si el administrador apagó este módulo (Parámetros del sistema), no se registra nada
require_once __DIR__ . '/../Modelo/HanaConfig.php';
if (!HanaConfig::modulo('VACANTES')) { vacError(403, 'Este módulo está desactivado. Lo activa el administrador en Parámetros del sistema.'); }
hanaUsarModulo('VACANTES');
if (!isset($_SESSION['IdUsuarios'], $_SESSION['Idcolaborador'])) { vacError(401, 'Tu sesión terminó. Vuelve a iniciar sesión.'); }
//Casillas de Vacantes (Permisos de usuario), en los peajes asignados: llenar (31M), ver (48M) y
//cambiar la fecha comprometida por Gestión Humana (49M). El ADMIN TEC (24M) todo
if (!hanaEntra('VACANTES')) { vacError(403, 'Tu rol no tiene acceso a Vacantes. Se da en Configuración → Permisos de usuario.'); }
if (!count(hanaMisCentros())) { vacError(403, 'No tienes peajes asignados. Pide que te los asignen en Configuración → Colaboradores.'); }

$idColaborador = (int)$_SESSION['Idcolaborador'];
$puedeLlenar     = hanaPuede('VACANTES', 'llenar');
$puedeCompromiso = hanaPuede('VACANTES', 'compromiso');
$modeloVacante   = new Vacante();
$hoy = date('Y-m-d');
$ultimoEditable = HanaFechas::ventana()[1]; //las fechas no pueden pasar de hoy
$centros = array();
foreach (hanaMisCentros() as $centro) { $centros[(int)$centro['ID_CENTRO_OP']] = $centro; } //sus peajes

switch (isset($_GET['op']) ? $_GET['op'] : '') {

    //Lo que necesita la pantalla al abrir: peajes, estados, días de ANS y motivos
    case 'config':
        echo hanaJsonTexto(array('hoy' => $hoy, 'ultimoEditable' => $ultimoEditable, 'centros' => array_values($centros), 'estados' => Vacante::$ESTADOS,
                               'estadosGh' => Vacante::$ESTADOS_GH, 'diasAns' => Vacante::diasAns(), 'editaCompromiso' => $puedeCompromiso && $puedeLlenar, 'puedeLlenar' => $puedeLlenar,
                               'motivos' => $modeloVacante->motivos(array_keys($centros)), 'cargos' => $modeloVacante->cargos(array_keys($centros)),
                               'nombresCubren' => $modeloVacante->nombresCubren(array_keys($centros))), JSON_UNESCAPED_UNICODE);
        break;

    //Descarga en Excel lo que se está viendo, con los mismos filtros (proyecto, centro, mes y año):
    //no responde JSON sino el archivo .xlsx. Con la casilla 63M (o el administrador, 24M)
    case 'excel':
        require_once __DIR__ . '/../Modelo/HanaExcel.php';
        if (!hanaPuede('EXCEL', 'descargar')) { HanaExcel::error(403, 'Tu rol no tiene el permiso para descargar en Excel (Permisos de usuario, casilla 63M).'); }
        $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
        $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
        $desde = $hasta = '';
        if ($mes !== 0) {
            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { HanaExcel::error(400, 'El periodo no es válido.'); }
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        }
        $ids = array_keys($centros); $alcance = 'Todos los proyectos';
        $idProyecto = isset($_GET['proyecto']) ? (int)$_GET['proyecto'] : 0;
        if ($idProyecto > 0) {
            $ids = array_keys(array_filter($centros, function ($c) use ($idProyecto) { return (int)$c['ID_PROYECTO'] === $idProyecto; }));
            if (!count($ids)) { HanaExcel::error(403, 'No tienes acceso a ese proyecto.'); }
            foreach ($centros as $c) { if ((int)$c['ID_PROYECTO'] === $idProyecto) { $alcance = html_entity_decode((string)$c['NOM_PROYECTO'], ENT_QUOTES, 'UTF-8'); break; } }
        }
        $idCentro = isset($_GET['centro']) ? (int)$_GET['centro'] : 0;
        if ($idCentro > 0) {
            if (!isset($centros[$idCentro])) { HanaExcel::error(403, 'No tienes acceso a ese centro.'); }
            $ids = array($idCentro); $alcance = html_entity_decode((string)$centros[$idCentro]['NOM_CENTRO_OP'], ENT_QUOTES, 'UTF-8');
        }
        $lista = $modeloVacante->listar($ids, $desde, $hasta, $mes === 0, $hoy);
        $meses = array('', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre');
        $quien = isset($_SESSION['Nombre']) ? html_entity_decode($_SESSION['Nombre'], ENT_QUOTES, 'UTF-8') : '';
        HanaExcel::descargar('Vacantes_' . ($mes === 0 ? 'abiertas_' . $hoy : sprintf('%04d-%02d', $anio, $mes)), array(Vacante::hojaExcel($lista,
            $mes === 0 ? 'Vacantes abiertas al ' . date('d/m/Y') : 'Vacantes abiertas en ' . $meses[$mes] . ' de ' . $anio,
            $alcance . ' · ' . count($lista) . (count($lista) === 1 ? ' vacante' : ' vacantes') . ' · Descargado de HANA el ' . date('d/m/Y H:i') . ($quien !== '' ? ' por ' . $quien : ''))));
        break;

    //Sin mes: todas las abiertas. Con mes: las que se abrieron ese mes
    case 'listar':
        $abiertas = !isset($_GET['mes']) || (int)$_GET['mes'] === 0;
        $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
        $mes  = isset($_GET['mes'])  ? (int)$_GET['mes']  : 0;
        $desde = $hasta = '';
        if (!$abiertas) {
            if ($anio < 2000 || $anio > 2100 || $mes < 1 || $mes > 12) { vacError(400, 'El periodo no es válido.'); }
            $desde = sprintf('%04d-%02d-01', $anio, $mes);
            $hasta = date('Y-m-d', strtotime("$desde +1 month"));
        }
        $ids = array_keys($centros);
        //Por proyecto: solo sus centros (de los que la persona tiene asignados)
        if (isset($_GET['proyecto']) && (int)$_GET['proyecto'] > 0) {
            $idProyecto = (int)$_GET['proyecto'];
            $ids = array_keys(array_filter($centros, function ($centro) use ($idProyecto) { return (int)$centro['ID_PROYECTO'] === $idProyecto; }));
        }
        if (isset($_GET['centro']) && (int)$_GET['centro'] > 0) {
            $ids = isset($centros[(int)$_GET['centro']]) ? array((int)$_GET['centro']) : array();
        }
        echo hanaJsonTexto($modeloVacante->listar($ids, $desde, $hasta, $abiertas, $hoy), JSON_UNESCAPED_UNICODE);
        break;

    //Crea una vacante nueva o guarda los cambios de una existente
    case 'guardar':
        if (!$puedeLlenar) { vacError(403, 'Tu rol solo consulta las vacantes.'); }
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            $antes = $modeloVacante->mostrar($id);
            if (!$antes || !isset($centros[(int)$antes['ID_CENTRO_OP']])) { vacError(404, 'No se encontró la vacante, o no es de tus centros.'); }
        }
        $datos = array();
        $datos['centro'] = isset($_POST['centro']) ? (int)$_POST['centro'] : 0;
        if (!isset($centros[$datos['centro']])) { vacError(400, 'Elige uno de tus peajes asignados.'); }
        $datos['cargo'] = HanaVal::texto(isset($_POST['cargo']) ? $_POST['cargo'] : '', 120);
        if ($datos['cargo'] === null) { vacError(400, 'Escribe el cargo de la vacante.'); }
        //Sin espacios de más y, si es un cargo que ya existe escrito con otras mayúsculas o tildes, como ya está
        $datos['cargo'] = trim(preg_replace('/\s+/u', ' ', $datos['cargo']));
        foreach ($modeloVacante->cargos(array_keys($centros)) as $existente) {
            if (Vacante::llaveCargo($existente) === Vacante::llaveCargo($datos['cargo'])) { $datos['cargo'] = $existente; break; }
        }
        $datos['motivo'] = HanaVal::texto(isset($_POST['motivo']) ? $_POST['motivo'] : '', 150);
        if ($datos['motivo'] === null) { vacError(400, 'Escribe el motivo de la vacante.'); }
        $datos['fecha'] = HanaVal::fecha(isset($_POST['fecha']) ? $_POST['fecha'] : '');
        if ($datos['fecha'] === '' || $datos['fecha'] > $ultimoEditable) { vacError(400, 'La fecha de la vacante no es válida o es posterior a hoy.'); }

        //El acuerdo de servicio: 5 días hábiles desde la vacante, sin festivos.
        //Al editar se conserva el que tenía, salvo que cambie la fecha de la vacante
        $datos['ans'] = ($id > 0 && $antes['FECHA_VACANTE'] === $datos['fecha']) ? $antes['FECHA_ANS']
                                                                         : HanaFechas::sumarHabiles($datos['fecha'], Vacante::diasAns());

        $datos['estado'] = isset($_POST['estado']) ? $_POST['estado'] : 'ABIERTA';
        if (!isset(Vacante::$ESTADOS[$datos['estado']])) { vacError(400, 'Estado no válido.'); }
        //La fecha de cierre se pone sola: el día en que la vacante pasa a cubierta o cancelada.
        //Si ya estaba cerrada, conserva la que tenía; si vuelve a abierta, se borra
        if ($datos['estado'] === 'ABIERTA') { $datos['cierre'] = null; }
        elseif ($id > 0 && $antes['ESTADO_VACANTE'] !== 'ABIERTA' && $antes['FECHA_CIERRE']) { $datos['cierre'] = $antes['FECHA_CIERRE']; }
        else { $datos['cierre'] = max($hoy, $datos['fecha']); }

        $datos['rq']     = HanaVal::texto(isset($_POST['rq']) ? $_POST['rq'] : '', 20);
        $datos['nombre'] = HanaVal::texto(isset($_POST['nombre']) ? $_POST['nombre'] : '', 120);
        //Quién la cubre: mientras está abierta, quien la está cubriendo (encargo o apoyo), si hay alguien;
        //al cubrirla, quién quedó en el cargo (obligatorio)
        if ($datos['estado'] === 'CUBIERTA' && $datos['nombre'] === null) { vacError(400, 'Escribe quién cubrió la vacante (quién quedó en el cargo).'); }
        $datos['gh'] = isset($_POST['gh']) && $_POST['gh'] !== '' ? $_POST['gh'] : null;
        if ($datos['gh'] !== null && !isset(Vacante::$ESTADOS_GH[$datos['gh']])) { vacError(400, 'Estado de Gestión Humana no válido.'); }
        //La fecha comprometida por Gestión Humana es automática: la del acuerdo de servicio.
        //Solo el administrador (24M) la cambia. Si él ya la había cambiado, se conserva
        $automatica = $datos['ans'];
        $datos['compromiso'] = $automatica;
        if ($puedeCompromiso && isset($_POST['compromiso']) && trim($_POST['compromiso']) !== '') {
            $datos['compromiso'] = HanaVal::fecha($_POST['compromiso']);
            if ($datos['compromiso'] === '') { vacError(400, 'La fecha del compromiso de Gestión Humana no es válida.'); }
        } elseif (!$puedeCompromiso && $id > 0 && $antes['FECHA_COMPROMISO_GH'] && $antes['FECHA_COMPROMISO_GH'] !== $antes['FECHA_ANS']) {
            $datos['compromiso'] = $antes['FECHA_COMPROMISO_GH'];
        }
        //Si se cambia a mano la fecha comprometida de una vacante abierta, hay que decir por qué: el cambio
        //queda en historial y así se sabe cuántas veces se ha corrido (no cuenta si solo cambió la fecha
        //de la vacante y con ella el acuerdo de servicio)
        $cambioCompromiso = null;
        if ($id > 0 && $antes['ESTADO_VACANTE'] === 'ABIERTA' && $puedeCompromiso && isset($_POST['compromiso']) && trim($_POST['compromiso']) !== '') {
            $vigente = $antes['FECHA_COMPROMISO_GH'] ? $antes['FECHA_COMPROMISO_GH'] : $antes['FECHA_ANS'];
            if ($datos['compromiso'] !== $vigente) {
                $motivoCambio = HanaVal::texto(isset($_POST['motivoCompromiso']) ? $_POST['motivoCompromiso'] : '', 300);
                if ($motivoCambio === null) { vacError(400, 'Escribe por qué cambia la fecha comprometida por Gestión Humana (antes era el ' . date('d/m/Y', strtotime($vigente)) . ').'); }
                $cambioCompromiso = array($vigente, $datos['compromiso'], $motivoCambio);
            }
        }
        $datos['obs'] = HanaVal::texto(isset($_POST['obs']) ? $_POST['obs'] : '', 300);
        //Quién deja el puesto (renunció, se trasladó...). Opcional: un cargo nuevo no lo tiene.
        //Se guarda en historial (ver VacanteModelo::guardarSale), sin cambiar la tabla vacante
        $sale = HanaVal::texto(isset($_POST['sale']) ? $_POST['sale'] : '', 120);
        //Lo que respondió Gestión Humana: también va en historial, con su fecha y quién lo anotó
        $respuestaGh = trim(preg_replace('/[ \t]+/u', ' ', isset($_POST['respuestaGh']) ? (string)$_POST['respuestaGh'] : ''));
        if (mb_strlen($respuestaGh, 'UTF-8') > Vacante::LARGO_RESPUESTA_GH) { vacError(400, 'La respuesta de Gestión Humana pasa de ' . Vacante::LARGO_RESPUESTA_GH . ' letras.'); }

        $ahora = date('Y-m-d H:i:s');
        HanaDB::iniciar(); //la vacante y quién deja el puesto quedan juntos, o nada
        $nuevo = $modeloVacante->guardar($id, $datos, $idColaborador, $ahora);
        if (!$nuevo || !$modeloVacante->guardarSale($nuevo, $sale, $idColaborador, $ahora)
                    || !$modeloVacante->guardarRespuestaGh($nuevo, $respuestaGh, $idColaborador, $ahora)
                    || ($cambioCompromiso !== null && !$modeloVacante->guardarCambioCompromiso($nuevo, $cambioCompromiso[0], $cambioCompromiso[1], $cambioCompromiso[2], $idColaborador, $ahora))) {
            HanaDB::deshacer();
            vacError(500, 'No se pudo guardar la vacante. Intenta de nuevo.');
        }
        HanaDB::confirmar();
        echo hanaJsonTexto(array('ok' => true, 'id' => $nuevo, 'mensaje' => $id ? 'Vacante actualizada.'
                         : 'Vacante registrada. Fecha límite del acuerdo de servicio: ' . date('d/m/Y', strtotime($datos['ans'])) . '.'),
                         JSON_UNESCAPED_UNICODE);
        break;

    //Lo que ha ido respondiendo Gestión Humana sobre una vacante
    case 'respuestasGh':
        $vacante = $modeloVacante->mostrar(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if (!$vacante || !isset($centros[(int)$vacante['ID_CENTRO_OP']])) { vacError(404, 'No se encontró la vacante, o no es de tus centros.'); }
        echo hanaJsonTexto($modeloVacante->respuestasGh((int)$vacante['ID_VACANTE']), JSON_UNESCAPED_UNICODE);
        break;

    //Las veces que se ha corrido la fecha comprometida de una vacante
    case 'cambiosCompromiso':
        $vacante = $modeloVacante->mostrar(isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if (!$vacante || !isset($centros[(int)$vacante['ID_CENTRO_OP']])) { vacError(404, 'No se encontró la vacante, o no es de tus centros.'); }
        $cambios = Vacante::cambiosCompromiso(array((int)$vacante['ID_VACANTE']));
        echo hanaJsonTexto(isset($cambios[(int)$vacante['ID_VACANTE']]) ? $cambios[(int)$vacante['ID_VACANTE']] : array('veces' => 0, 'anteriores' => array(), 'motivo' => ''), JSON_UNESCAPED_UNICODE);
        break;

    default:
        vacError(400, 'Operación no reconocida.');
}
