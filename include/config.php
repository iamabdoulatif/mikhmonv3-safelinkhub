<?php 
if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -10) == "config.php") {
  header("Location:./");
  exit;
}
$data['mikhmon'] = array ('1'=>'mikhmon<|<mikhmon','2'=>'mikhmon>|>aWNlbA==');
$data['ALB-TECH'] = array(1=>'ALB-TECH!172.25.194.29',2=>'ALB-TECH@|@admin',3=>'ALB-TECH#|#hZKfmpJicWVj',4=>'ALB-TECH%ALBAMBAWY',5=>'ALB-TECH^10.10.0.1',6=>'ALB-TECH&fcfa',7=>'ALB-TECH*10',8=>'ALB-TECH(1',9=>'ALB-TECH)',10=>'ALB-TECH=disable',11=>'ALB-TECH@!@enable');
