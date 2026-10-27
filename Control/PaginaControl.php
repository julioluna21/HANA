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
    pagEsCoordinador()            ¿llena el módulo actual? (su casilla de llenar y peajes asignados)
    pagPuede('HOY', 'ver')        ¿puede hacer eso en ese módulo? (casillas de Permisos de usuario)
    pagEntra('HOY')               ¿tiene alguna casilla del módulo y peajes asignados?
    pagTieneCentros()             ¿tiene peajes asignados? (el ADMIN TEC, todos)
    pagPuedeModulo('HOY')         ¿puede usar la pantalla de ese módulo?
    pagTienePeajes()              ¿es jefe de un peaje o tiene peajes asignados? (ya casi no se usa)
    pagEsJefePeaje()              ¿es jefe de algún peaje? (dato de Centros de operación)
    pagPromesaEntrar()            ¿puede abrir la promesa de valor?
    pagAusentismoEntrar()         ¿puede abrir Ausentismo? (la promesa de valor va dentro de Ausentismo)
    pagClaveMinimo()              caracteres mínimos de una contraseña nueva
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
//Casillas + peajes (ver Control/AccesoHelper.php): ¿puede hacer eso en ese módulo?, ¿entra al módulo?, ¿tiene peajes?
function pagPuede($modulo, $accion)               { return hanaPuede($modulo, $accion); }
function pagEntra($modulo)                        { return hanaEntra($modulo) && count(hanaMisCentros()) > 0; }
function pagTieneCentros()                        { return count(hanaMisCentros()) > 0; }
function pagPuedeModulo($modulo)                  { return hanaPuedeModulo($modulo); }
function pagModulosCoordinador()                  { return array_keys(hanaPermisosModulo()); }
function pagTienePeajes()                         { return hanaIdColaborador() > 0 && hanaIdUsuario() > 0 && Organizacion::tienePeajes(hanaIdColaborador(), hanaIdUsuario()); }
function pagEsJefePeaje()                         { return hanaIdColaborador() > 0 && Organizacion::esJefeDePeaje(hanaIdColaborador()); }
function pagPromesaEntrar()                       { return hanaPromesaEntrar(); }

//¿Puede abrir Ausentismo? Con alguna casilla del módulo (registrar, ver, cerrar, corregir
//meses cerrados o reabrir) y peajes asignados. La misma regla de AusentismoVista.php
function pagAusentismoEntrar()
{
    //El ausentismo (matriz de cubrimiento) se retiró del sistema: ya no se entra a esa sección.
    //Lo único que queda de esa sección es la promesa de valor (pagPromesaEntrar()).
    return false;
}
//Las dos partes de la sección Ausentismo, como pestañas arriba de cada pantalla: Ausentismo y
//Promesa de valor. Solo salen si la persona entra a las dos. $actual: 'AUSENTISMO' o 'PROMESA'
function pagSeccionesAusentismo($actual)
{
    if (!pagAusentismoEntrar() || !pagPromesaEntrar()) { return ''; }
    $partes = array('AUSENTISMO' => array('AusentismoVista.php', 'fa-user-times', 'Ausentismo', 'irAusentismo'),
                    'PROMESA'    => array('PromesaValorVista.php', 'fa-clock-o', 'Promesa de valor', 'irPromesa'));
    $html = '<nav class="aus-secciones" aria-label="Ausentismo">';
    foreach ($partes as $clave => $parte) {
        $html .= $clave === $actual
            ? '<span class="aus-seccion activa" aria-current="page"><i class="fa ' . $parte[1] . '"></i> ' . $parte[2] . '</span>'
            : '<a class="aus-seccion" id="' . $parte[3] . '" href="' . $parte[0] . '"><i class="fa ' . $parte[1] . '"></i> ' . $parte[2] . '</a>';
    }
    return $html . '</nav>';
}
function pagClaveMinimo()                         { require_once __DIR__ . '/../Modelo/HanaClave.php'; return HanaClave::largoMinimo(); }
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

//Para Reporte diario (inicio): cómo va el día del coordinador
function pagResumenDelDia()
{
    require_once __DIR__ . '/../Modelo/EstadoDiaModelo.php';
    $id = hanaIdColaborador();
    $hoy = date('Y-m-d');
    return array('estado' => (new EstadoDia())->resumen($id, $hoy));
}

//Para Informe mensual: los proyectos de sus peajes asignados (el ADMIN TEC, todos)
function pagProyectosInforme($todos = false)
{
    //Los proyectos de sus peajes asignados (el ADMIN TEC, todos)
    require_once __DIR__ . '/../Modelo/InformeMensualModelo.php';
    $mios = hanaMisProyectos();
    return array_values(array_filter((new InformeMensual())->proyectos(hanaIdColaborador(), true), function ($proyecto) use ($mios) { return isset($mios[(int)$proyecto['ID_PROYECTO']]); }));
}
