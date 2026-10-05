<?php
session_start(); //inicia la session, permite guardar variables de sesion
require_once __DIR__ . '/Guardia.php'; //sesión y permisos (antes no se revisaban)
hanaGuardia(array('3M'), array('select')); //Colaboradores: 3M
require_once "../Modelo/colaboradoresModelo.php"; //Utilizará este archivo
require_once __DIR__ . '/../Modelo/HanaConfig.php';

/*
  Usuario automático: al registrar un colaborador se le crea su usuario para entrar a HANA.
    usuario:    su número de documento (sin puntos ni espacios)
    contraseña: una temporal al azar, que se muestra en pantalla UNA vez (no se envía por correo)
    rol:        USUARIO BASE (o el rol más básico que haya); se cambia en Configuración → Usuarios
  Si ya existe un usuario con ese documento, no se crea otro.
*/
function hanaUsuarioAutomatico($documento)
{
    $usuario = preg_replace('/[^0-9A-Za-z]/', '', html_entity_decode((string)$documento, ENT_QUOTES, 'UTF-8'));
    if ($usuario === '') { return "\n\nNo se creó el usuario: el documento está vacío."; }
    $col = HanaDB::fila("SELECT ID_COLABORADOR FROM colaboradores WHERE DOC_COLABORADO = ? ORDER BY ID_COLABORADOR DESC LIMIT 1", 's', array($documento));
    if (!$col) { return ''; }
    if (HanaDB::fila("SELECT 1 AS ok FROM usuarios_sistema WHERE NOM_USUARIO_SISTEMA = ?", 's', array($usuario))) {
        return "\n\nYa existía un usuario «" . $usuario . "»: no se creó otro.";
    }
    //El rol más básico: USUARIO BASE si existe; si no, el activo con menos permisos que no administre usuarios
    $rol = HanaDB::fila("SELECT ID_ROL_USUARIO_SISTEMA AS ID, NOM_ROL_USUARIO_SISTEMA AS NOMBRE FROM rol_usuarios_sistemas
                          WHERE ESTADO = 1 AND FIND_IN_SET('1M', MODULOS_ROL_USUARIOS_SISTEMAS) = 0
                          ORDER BY NOM_ROL_USUARIO_SISTEMA = 'USUARIO BASE' DESC, LENGTH(MODULOS_ROL_USUARIOS_SISTEMAS) LIMIT 1");
    if (!$rol) { return "\n\nNo se creó el usuario: no hay un rol básico activo. Créalo en Configuración → Usuarios."; }
    $clave = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 10); //sin letras que se confunden (l, i, o, 0, 1)
    $ok = HanaDB::q("INSERT INTO usuarios_sistema (NOM_USUARIO_SISTEMA, CLAVE_USUARIO_SISTEMA, ID_COLABORADOR_USUARIOS_SISTEMA, ID_ROL_USUARIO_SISTEMA_USUARIOS_SISTEMA, ESTADO)
                     VALUES (?, ?, ?, ?, 1)", 'ssii', array($usuario, hash('SHA256', $clave), (int)$col['ID_COLABORADOR'], (int)$rol['ID']));
    if (!$ok) { return "\n\nNo se pudo crear el usuario. Créalo en Configuración → Usuarios."; }
    return "\n\nSe le creó su usuario para entrar a HANA:\nUsuario: " . $usuario . "\nContraseña temporal: " . $clave . "\nRol: " . $rol['NOMBRE']
         . "\n\nAnótala y pásasela a la persona: no se envía por correo y no se vuelve a mostrar."
         . "\nEl rol y los peajes se ajustan en Configuración → Usuarios.";
}
$colaborador = new colaborador(); //crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idColaborador = isset($_POST["idColaborador"]) ? limpiarCadena($_POST["idColaborador"]) : "";
$documento = isset($_POST["documento"]) ? limpiarCadena(($_POST["documento"])) : "";
$nombre = isset($_POST["nombre"]) ? limpiarCadena($_POST["nombre"]) : "";
$email = isset($_POST["email"]) ? limpiarCadena($_POST["email"]):"" ;
$cargo = isset($_POST["selectCargo"]) ? limpiarCadena($_POST["selectCargo"]) : "";
//opciones
switch ($_GET["op"]) {
        case 'guardar': //primer caso
                try {
                        //El cargo es obligatorio en la base (int NOT NULL). Si llega
                        //vacio, se avisa en vez de dejar que el UPDATE falle callado.
                        if (empty($cargo)) {
                                http_response_code(400);
                                echo "Debes seleccionar un cargo.";
                                exit();
                        }
                        if (empty($idColaborador)) {
                                $rspta = $colaborador->insertar($documento, $nombre, $email, $cargo);
                                if (!$rspta) { http_response_code(400); echo 'No se pudo registrar el colaborador.'; break; }
                                //Se le crea su usuario de una vez (se puede apagar en Parámetros). Sin correo:
                                //los datos se muestran aquí para que quien lo registra se los pase
                                $msg = 'Colaborador registrado con éxito.';
                                if (HanaConfig::si('USUARIO_AUTOMATICO')) { $msg .= hanaUsuarioAutomatico($documento); }
                                http_response_code(200);
                                echo $msg;
                        } else {
                                $rspta = $colaborador->editar($idColaborador, $documento, $nombre, $email, $cargo);
                                echo $rspta ? http_response_code(200) : http_response_code(400);
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'mostrar':
                try {
                        $rspta = $colaborador->mostrar($idColaborador);
                        $rspta ? http_response_code(200) : http_response_code(400);
                        echo json_encode($rspta);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'anular':
                try {
                        $rspta = $colaborador->anular($idColaborador);
                        echo $rspta ? http_response_code(200) : http_response_code(400);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'select':
                try {
                        $rspta = $colaborador->select();
                        echo "<option value=''>Seleccione ...</option>";
                        while ($reg = $rspta->fetch_object()) //mientras exista objeto en la respuesta
                        {
                                echo "<option value='$reg->ID_COLABORADOR'>$reg->NOM_COLABORADOR</option>";
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                break;
        case 'listar': //activado por el ajax en el scrip articulos
                $rspta = $colaborador->listar(); //Carga la rspta con lista de articulos
                //Vamos a declarar un array
                $data = array();
                while ($reg = $rspta->fetch_object()) //mientras exista objeto en la respuesta
                {
                        
                        $data[] = array( //arreglo con los datos de las columnas
                                "0" => $reg->ID_COLABORADOR,
                                "1" => $reg->DOC_COLABORADO,
                                "2" => $reg->NOM_COLABORADOR,
                                "3" => $reg->MAIL_COLABORADOR,
                                "4" => $reg->cargo,
                                "5" => ($reg->ESTADO == 1)?'Activo':'Inactivo',
                                "6" => '<SPAN title="Editar">
                                <button class="btn btn-success" onclick="mostrar('. $reg->ID_COLABORADOR .')">
                                <i class="fa fa-eye"></i></button></SPAN>
                   <SPAN title="anular">
        <button type="button" class="btn btn-success" onclick="anular('. $reg->ID_COLABORADOR .')">
        <i class="fa fa-trash-o"></i></button></SPAN>'
                        );
                }
                $results = array( //variable con el resultado del arreglo
                        "sEcho" => 1, //Información para el datatables
                        "iTotalRecords" => count($data), //enviamos el total registros al datatable
                        "iTotalDisplayRecords" => count($data), //enviamos el total registros a visualizar
                        "aaData" => $data
                );
                echo json_encode($results); //muestra el resultado
                break;
}
