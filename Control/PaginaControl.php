<?php
/*
  HANA - Lo que las pantallas necesitan saber de quien entra
  ---------------------------------------------------------------------------
  Las vistas (carpeta Vista) NO consultan la base de datos ni cargan modelos:
  antes de dibujar el menú o decidir si la pantalla abre, le preguntan a este
  controlador. Él usa la sesión (AccesoHelper.php) y los modelos.

    pagModuloActivo('ARQUEOS')    ¿el administrador dejó encendido el módulo?
    pagParametroSi('CLAVE')       un parámetro de sí/no de Parámetros del sistema
    pagParametroNum('CLAVE', 10)  un parámetro numérico
    pagUsarModulo('HOY')          la pantalla dice a qué módulo pertenece
    pagEsCoordinador()            ¿coordina un proyecto? (o tiene la casilla del módulo)
    pagPuedeModulo('HOY')         ¿puede usar la pantalla de ese módulo?
    pagTienePeajes()              ¿es jefe de un peaje o tiene peajes asignados?
    pagEsJefePeaje()              ¿es jefe de algún peaje?
    pagPromesaEntrar()            ¿entra a Promesa de valor?
    pagPromesaConsolidado()       ¿entra al consolidado de promesa de valor?
    pagHoy()                      el día de hoy en Colombia (AAAA-MM-DD)
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/AccesoHelper.php';

//Atajos que usan las vistas para preguntar por módulos, parámetros y permisos
//sin tener que cargar cada clase por su cuenta
function pagModuloActivo($modulo)                 { return HanaConfig::modulo($modulo); }
function pagParametroSi($clave, $defecto = true)  { return HanaConfig::si($clave, $defecto); }
function pagParametroNum($clave, $defecto)        { return HanaConfig::num($clave, $defecto); }
function pagUsarModulo($modulo)                   { hanaUsarModulo($modulo); }
function pagEsCoordinador()                       { return hanaIdColaborador() > 0 && hanaEsCoordinador(); }
function pagPuedeModulo($modulo)                  { return hanaPuedeModulo($modulo); }
function pagModulosCoordinador()                  { return array_keys(hanaPermisosModulo()); }
function pagTienePeajes()                         { return hanaIdColaborador() > 0 && hanaIdUsuario() > 0 && Organizacion::tienePeajes(hanaIdColaborador(), hanaIdUsuario()); }
function pagEsJefePeaje()                         { return hanaIdColaborador() > 0 && Organizacion::esJefeDePeaje(hanaIdColaborador()); }
function pagPromesaEntrar()                       { return hanaPromesaEntrar(); }
function pagPromesaConsolidado()                  { return hanaPromesaConsolidado(); }
function pagHoy()                                 { return HanaFechas::hoy(); }

//Para RQVista: por qué alguien con sesión no ve el módulo de requisiciones
//(¿falta crear la tabla?, ¿su rol ya tiene el permiso pero la sesión es vieja?)
function pagDiagnosticoRQ()
{
    $rol = Acceso::rolYPermisos(hanaIdUsuario());
    return array(
        'tablasListas' => Acceso::existeTabla('rq'),
        'nomRol'       => $rol ? $rol['rol'] : '',
        'rolTiene'     => $rol && array_intersect(array('15M', '17M', '18M', '41M'), explode(',', (string)$rol['modulos']))
    );
}

//Para Reporte diario (inicio): cómo va el día del coordinador y su promesa de valor
function pagResumenDelDia()
{
    require_once __DIR__ . '/../Modelo/EstadoDiaModelo.php';
    $id = hanaIdColaborador();
    $hoy = date('Y-m-d');
    //La promesa de valor está en el menú de Ausentismo: aquí solo va el estado del día
    return array('estado' => (new EstadoDia())->resumen($id, $hoy));
}

//Para Informe mensual: los proyectos que puede elegir (todos con 20M, 21M o 24M; si no, los que coordina)
function pagProyectosInforme($todos)
{
    require_once __DIR__ . '/../Modelo/InformeMensualModelo.php';
    return (new InformeMensual())->proyectos(hanaIdColaborador(), $todos);
}
