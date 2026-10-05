<?php
session_start();//inicia la session, permite guardar variables de sesion
require_once "../Modelo/ProyectoModelo.php";//Utilizará este archivo
$Proyecto=new Proyecto();//crea un nuevo articulo
//carga las variables con los valores recibidos y limpia los que no se usaran
$idProyecto=isset($_POST["idProyecto"])? limpiarCadena($_POST["idProyecto"]):"";
$nombreproyecto=isset($_POST["nombre"])? limpiarCadena($_POST["nombre"]):"";
$coordinador=isset($_POST["coordinador"])? intval($_POST["coordinador"]):0;

/*
  Quién puede usar cada operación (Fase 2).
  Antes ninguna revisaba la sesión. Ahora:
    - Sin sesión, nada.
    - Crear, editar, anular, ver y listar proyectos: permiso 6M.
      Las listas de colaboradores y la de proyectos (select) las usan otras
      pantallas: basta con haber iniciado sesión.
*/
$op = isset($_GET["op"]) ? $_GET["op"] : "";
if (!isset($_SESSION['IdUsuarios'])) { http_response_code(401); echo "Tu sesión terminó. Vuelve a iniciar sesión."; exit(); }
$modulosProy = explode(',', isset($_SESSION['Modulos']) ? $_SESSION['Modulos'] : '');
if (in_array($op, array('guardar', 'mostrar', 'anular', 'listar'), true) && !in_array('6M', $modulosProy)) {
    http_response_code(403); echo "No permitido: tu rol no administra proyectos."; exit();
}
if ($coordinador > 0 && !$Proyecto->colaboradorValido($coordinador)) {
    http_response_code(400); echo "El coordinador elegido no existe o está inactivo."; exit();
}

//opciones
switch ($op)
    {
            case 'guardar'://primer caso
                try {
                        if (empty($idProyecto)) {
                          
                            $rspta=$Proyecto->insertar($nombreproyecto, $coordinador);
                            echo $rspta ? http_response_code(200): http_response_code(400); 
                            
                        }else{
                            
                            $rspta=$Proyecto->editar($idProyecto, $nombreproyecto, $coordinador);
                        echo $rspta ? http_response_code(200) : http_response_code(400);
                        }
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
                            
            break;
            case 'mostrar':
                try {
                        $rspta=$Proyecto->mostrar($idProyecto);
                        $rspta ? http_response_code(200):http_response_code(400);
                        echo json_encode($rspta);
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
            break;
            //Colaboradores activos para el selector de coordinador (y de jefe de centro)
            case 'colaboradores':
                $lista = array();
                $rspta = $Proyecto->colaboradores();
                while ($rspta && ($f = $rspta->fetch_assoc())) {
                        $lista[] = array('id' => (int)$f['id'],
                                         'nombre' => html_entity_decode($f['nombre'], ENT_QUOTES, 'UTF-8'),
                                         'cargo' => html_entity_decode((string)$f['cargo'], ENT_QUOTES, 'UTF-8'));
                }
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($lista, JSON_UNESCAPED_UNICODE);
            break;

            case 'anular':
                       $rspta=$Proyecto->anular($idProyecto);
                        echo $rspta ? http_response_code(200):http_response_code(400);        
                            
            break;        
        
            case 'select':
                $search_term = isset($_GET['search'])? $_GET['search']:0;
                try {
                        $rspta=$Proyecto->select($search_term);
                       $usersData = array();  
                        if($rspta->num_rows > 0){  
                            while($row = $rspta->fetch_assoc()){  
                                $data['id'] = $row['ID_PROYECTO'];  
                                $data['text'] = $row['NOM_PROYECTO'];  
                                array_push($usersData, $data);  
                            }  
                        } 
                         
                        // Return results as json encoded array  
                        echo json_encode($usersData); 
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
            
            break;         
        
            case 'listar'://activado por el ajax en el scrip articulos
        
                try {
                        $rspta=$Proyecto->listar();//Carga la rspta con lista de articulos
                        //Vamos a declarar un array
                        $data= Array();
            
                        while ($reg=$rspta->fetch_object())//mientras exista objeto en la respuesta
                        {
                            
                          $bot='<SPAN title="Editar">
                          <button class="btn btn-success" onclick="mostrar('.$reg->ID_PROYECTO.')">
                          <i class="fa fa-eye"></i></button></SPAN>
             <SPAN title="anular">
 <button type="button" class="btn btn-success" onclick="anular('.$reg->ID_PROYECTO.')">
 <i class="fa fa-trash-o"></i></button></SPAN>';       
                    
                            $estado="Activo";
                            if($reg->Estado==0){
                            $estado="Inactivo";
                           /* $bot='<SPAN title="Editar">
                                                <button class="btn btn-secondary" onclick="mostrar('.$reg->ID_PROYECTO.')">
                                                        <i class="fas fa-eye" style="color:white;"></i></button></SPAN> <SPAN title="Activar">
                         <button type="button" class="btn btn-primary" onclick="activar('.$reg->ID_PROYECTO.')">
                         <i class="fas fa-check" style="color:white;"></i></button></SPAN>';*/
                                
                            }
                                   
                            
                                $data[]=array(//arreglo con los datos de las columnas
                                        "0"=>$reg->ID_PROYECTO,
                                        "1"=>$reg->NOM_PROYECTO,
                                        "2"=>$reg->COORDINADOR ? $reg->COORDINADOR : '<span style="color:#8A5B0B;">Sin asignar</span>',
                                        "3"=>$estado,
                                        "4"=>$bot,
                                );
                        }
                     
                        $results = array(//variable con el resultado del arreglo
                                "sEcho"=>1, //Información para el datatables
                                "iTotalRecords"=>count($data), //enviamos el total registros al datatable
                                "iTotalDisplayRecords"=>count($data), //enviamos el total registros a visualizar
                                "aaData"=>$data);
                        echo json_encode($results);//muestra el resultado
                } catch (\Throwable $th) {
                        http_response_code(404);
                        echo json_encode($th);
                }
        
            break;
          
    }