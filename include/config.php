<?php 
if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -10) == "config.php") {
  header("Location:./");
  exit;
}
$data['mikhmon'] = array ('1'=>'mikhmon<|<mikhmon','2'=>'mikhmon>|>aWNlbA==');
$data['Safelink'] = array(1=>'Safelink!172.22.41.240',2=>'Safelink@|@admin',3=>'Safelink#|#eZWfoZ9yaWNl',4=>'Safelink%BAYOTA-WIFI',5=>'Safelink^10.0.0.1',6=>'Safelink&fcfa',7=>'Safelink*10',8=>'Safelink(1',9=>'Safelink)',10=>'Safelink=disable',11=>'Safelink@!@enable');
