<?php
$root = dirname(__DIR__);

$generate = file_get_contents($root . '/hotspot/generateuser.php');
$print = file_get_contents($root . '/voucher/print.php');

$checks = array(
  'generator must load RouterOS account assignment helpers' => strpos($generate, "include/hotspot_account_assignment.php") !== false,
  'generator must read RouterOS system users before building seller options' => strpos($generate, "\$API->comm('/user/print')") !== false,
  'generator must restore RouterOS seller records into sellers_config' => strpos($generate, "mikhmon_replace_assignment_line_in_file(\$appPrefix . 'include/sellers_config.php'") !== false,
  'generated batches must redirect to the generated lot comment' => strpos($generate, "hotspot=users&comment=") !== false && strpos($generate, 'rawurlencode($commt)') !== false,
  'single ticket generation must also show the generated lot comment' => strpos($generate, 'hotspot-user=" . $u[1]') === false,
  'voucher print must load seller matching helper' => strpos($print, "include/seller_ticket_helper.php") !== false,
  'voucher print must load RouterOS account assignment helper' => strpos($print, "include/hotspot_account_assignment.php") !== false,
  'voucher print must resolve MIKHMON_ACCOUNT comments' => strpos($print, 'mikhmon_hotspot_assignment_from_comment($comment)') !== false,
  'voucher print must prefer configured seller display names' => strpos($print, 'mikhmon_comment_seller_key($comment, $sellersData)') !== false,
  'voucher print must hide historical suffixes from seller names' => strpos($print, 'mikhmon_seller_display_label') !== false,
  'voucher print must normalize historical lot comments before rendering' => strpos($print, 'mikhmon_normalize_seller_lot_comment($regtable[\'comment\'], $sellers_data)') !== false,
);

foreach ($checks as $label => $ok) {
  if (!$ok) {
    fwrite(STDERR, $label . PHP_EOL);
    exit(1);
  }
}

echo "routeros_account_voucher_assignment_test passed\n";
