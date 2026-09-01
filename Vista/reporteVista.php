<?php
session_start();
if(isset($_SESSION['IdUsuarios'])){
$modulosAcceso=explode(",",$_SESSION['Modulos']);    
if(in_array("13M",$modulosAcceso) or isset($_GET["op"])){    
    
include('head.php');
?>


<!-- Contenido aqui va todo el DIV del contenido.. -->
<div class="right_col" role="main">
  <div class="">

    <div class="clearfix"></div>


    <div class="row">
      <div class="col-md-12 col-xs-12">


        <div class="x_panel">
          <div class="x_title">
            <h1>DASHBOARD</h1>
            
            <div class="clearfix"></div>
          </div>
          <div class="x_content">
            
            <div id="listadoregistros">
                <div class="x_panel">
                  <div class="x_title">
                  <h2>Dashboard Reporte</h2>
                 
                    <div class="clearfix"></div>
                  </div>
                  
                    <iframe title="Dashboard_Novedades" width="100%" height="800px" src="
https://app.powerbi.com/view?r=eyJrIjoiY2YzNjJkOGUtNTk4OC00YWJlLWIxYTQtZDA5OTUyZjVmNzBhIiwidCI6IjYwMjkyY2RlLTIxYTQtNDQ1NS04ZjlmLTY1NTQ0YzI4NzMzMSJ9"
frameborder="0" allowFullScreen="true"></iframe>
                    
                    
                </div>
              </div>

            <!-- end form for validations -->
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- /page content -->

<!-- footer content -->
<?php

include('footer.php');

?>

<?php

}else{   
  echo "<script> 
  window.history.go(-1)
  </script>";
    
}
}else{    
  echo "<script> 
  <!--
  window.location.replace('login.php'); 
  //-->
  </script>";
}
?>