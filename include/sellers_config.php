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
$sellers_data['jun192026142433'] = array('password'=>'v2:0tMWyCkm4znPcZEVWAd/aG5Tbl1oEjBCMOT3Wg==','name'=>'Jun192026142433 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun142026154757'] = array('password'=>'v2:kgDHnMW2F0gXL3ZuEmI7sQOISgB0l8bmIs5ATg==','name'=>'Jun142026154757 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun142026120147'] = array('password'=>'v2:KmXx6b8yYhp3SaWkRleGMLNCYOg/L+OWfED+7A==','name'=>'Jun142026120147 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026114125'] = array('password'=>'v2:eViY0lwcWeBloDS6cx3m8A6oXko2QBH//wZVBg==','name'=>'Jun132026114125 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026153330'] = array('password'=>'v2:cFakVqxNzJlGkrjj4W7TqKyrnwQUN3fIywuKXg==','name'=>'Jun132026153330 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026130922'] = array('password'=>'v2:wtDvZhESCGDkxxmyvrAzYKD7S6DbFWkXNgrZyw==','name'=>'Jun132026130922 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun142026143101'] = array('password'=>'v2:JQypnb2vc+P0/yGWK59X8T8hgZIXxaowIhtEOg==','name'=>'Jun142026143101 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun142026103440'] = array('password'=>'v2:2LH7VLB9zeLbIBsumpsqk+i3Bz+336S4/YG3ZQ==','name'=>'Jun142026103440 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026155850'] = array('password'=>'v2:dPBYFZgacEAKdmjSD+5xePJUVpyd6cuTI6pAdg==','name'=>'Jun132026155850 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026102555'] = array('password'=>'v2:3JLoHTLrnJgoeyVVxeUYeU3WXvwXqCJHlQHO5w==','name'=>'Jun132026102555 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun132026141853'] = array('password'=>'v2:8PZliz12KC1iDbAxRLaaYKR1898FBdNm3Dd4Bg==','name'=>'Jun132026141853 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun142026161634'] = array('password'=>'v2:dyB1Zsn+QmD9ybzAjtSlLNDW0NsJis/R8g8x1Q==','name'=>'Jun142026161634 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jun142026161709'] = array('password'=>'v2:mZ8F2lFuP4MwcM7BBT07bhmquHta7WCH0zWggg==','name'=>'Jun142026161709 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['boua'] = array('password'=>'v2:aslIv+2GtFbwjkfu3a4mq5SG51wvv12gjVSamjEUMfU=','name'=>'Boua','session'=>'Safelink','commission'=>10);
$sellers_data['jun132026162743'] = array('password'=>'v2:wzZGMVd2Ly2SWtj8ycHk763L28ULKqExvdmTgA==','name'=>'Jun132026162743 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['jul122026163151'] = array('password'=>'v2:r9vmRcVrp/i0ZmSuZQnLzTDhP0sbjMsbGjx+3Q==','name'=>'Jul122026163151 (historique)','session'=>'Safelink','commission'=>10,'historical'=>true);
$sellers_data['levie'] = array('password'=>'v2:o7SL0YaQFr0wrrVaZ3qTmlPRu7dK4/KgxxJ5UmuirR4=','name'=>'Levie','session'=>'Safelink','commission'=>10);
$sellers_data['mijai'] = array('password'=>'v2:K7fjXZEObm95BPvPzg57yZbcLp1kRLZ2grNOho+aT6w=','name'=>'Mijai','session'=>'Safelink','commission'=>10);
$sellers_data['moumine'] = array('password'=>'$2y$10$igeFQYkTKDnVSZ7nDu9RdOeWL0SXArtQ09Fxf/PW64Y48.VVd8dKK','name'=>'Moumine','session'=>'Safelink','commission'=>10);
$sellers_data['koro'] = array('password'=>'$2y$10$mmU/jfCmyT9Rqnht.c2KReNVWM.B0pqg7p.viPDMAQlmxzUPAo2g2','name'=>'Koro','session'=>'Safelink','commission'=>10);
