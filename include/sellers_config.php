<?php
if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -19) == "sellers_config.php") {
  header("Location:./");
  exit;
}
// Comptes vendeurs - gérés par l'administrateur
// Format: $sellers_data['username'] = array('password' => encrypt('motdepasse'), 'name' => 'Nom Affiché', 'session' => 'NomSession');
$sellers_data = array();
$sellers_data['hassatou'] = array('password'=>'v2:5Hvf8GCL6LmkPOz+afHHcmCNsw2wPd+KObDy1wHsjVo=','name'=>'hassatou','session'=>'Safelink','commission'=>10);
$sellers_data['ib25'] = array('password'=>'v2:jlwA9eZGnCiFW4K+IIlrkAqOIFJdKOvrjwnjQwYDM08=','name'=>'Ib25','session'=>'Safelink','commission'=>10);
$sellers_data['ferima'] = array('password'=>'$2y$10$2iOgX/2bEFlNf19JHdMjVua5dwERvIqM1zMEPqCdd1MJL44CYNcGK','name'=>'Ferima','session'=>'Safelink','commission'=>10);
$sellers_data['karim'] = array('password'=>'$2y$10$l0IOFVR4lyZMrUdot4R97OhN1Ufvxz8iNAjqwRBN2XkWh.j1.SkxG','name'=>'Karim','session'=>'Safelink','commission'=>10);
$sellers_data['jul112026194642'] = array('password'=>'v2:1YpX7osN7XukIU6z67ZciCtYwmb11RMFCQ58Sg==','name'=>'Jul112026194642 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun182026185605'] = array('password'=>'v2:kfVPd3xN74L7QOsIWMxg7qOd9csvSP6MryNd2A==','name'=>'Jun182026185605 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun182026105602'] = array('password'=>'v2:iPKFQD90B97EkFOjl/mWTOaRdwikPQ5lRl//SQ==','name'=>'Jun182026105602 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun182026202753'] = array('password'=>'v2:6OzKRSfO/Vq4BeTN4WkQ1ycr7s8dmcDtwLX0DQ==','name'=>'Jun182026202753 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun182026201738'] = array('password'=>'v2:3DkrpTqEFKNOEyuatRqLXB35GDkDGQqdO9s/dA==','name'=>'Jun182026201738 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026200945'] = array('password'=>'v2:GAYdCJpQTTEcE2/WMyMJUZptI66/0JbdwvQXug==','name'=>'Jun132026200945 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026182925'] = array('password'=>'v2:hraCYwiNoClTZ0YU5z2HxoGXGRRIybu3t/H4ZA==','name'=>'Jun132026182925 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jul112026203638'] = array('password'=>'v2:DYrB7jcxAVjOnO6/bhTIJ8wLUPR/PEOR20ayvg==','name'=>'Jul112026203638 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun122026225056'] = array('password'=>'v2:YyUfbjb/mFcxPgoCP79bZIIh9MLAfzpjxDlybg==','name'=>'Jun122026225056 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['Mijai'] = array('password'=>'v2:985K4cMZrRVGrJUGHjiDubIHNXHt3RIUc5+0Vw==','name'=>'Mijai (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun122026195706'] = array('password'=>'v2:WxYFnnS3G7PkYO4gRRmSF1zj0Tbta97AAKOSyg==','name'=>'Jun122026195706 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['Levie'] = array('password'=>'v2:yY0mRIYAdBFNTGsQAgKM5MuoH92gCpwTlTzfPg==','name'=>'Levie (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['Moumine'] = array('password'=>'v2:C8d6OOI7g8l+N5+gZBqnobIXPuKGgPWewppa0g==','name'=>'Moumine (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun122026195011'] = array('password'=>'v2:6aJrCek4BfsH8I2O4Ulzudrgsd5vZlQuCDBPOg==','name'=>'Jun122026195011 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun122026195152'] = array('password'=>'v2:+4mazofu2XcwQmrJ1uGb2QO9bBerC2wwdwJIvw==','name'=>'Jun122026195152 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
