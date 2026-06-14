<?php
/*
 *  Copyright (C) 2018 Laksamadi Guko.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
session_start();
// hide all error
error_reporting(0);
include_once __DIR__ . '/../include/sellers_config.php';
include_once __DIR__ . '/../include/seller_ticket_helper.php';
include_once __DIR__ . '/../include/mikhmon_compat.php';
if (!isset($_SESSION["mikhmon"])) {
  header("Location:../admin.php?id=login");
} else {


// get MikroTik system clock
  $getclock = $API->comm("/system/clock/print");
  $clock = $getclock[0];
  $timezone = mikhmon_safe_timezone(isset($getclock[0]['time-zone-name']) ? $getclock[0]['time-zone-name'] : ($_SESSION['timezone'] ?? 'UTC'));
  $_SESSION['timezone'] = $timezone;
  date_default_timezone_set($timezone);
  $clockDisplay = mikhmon_router_clock_display($clock, $timezone);
  $clockDayKey = mikhmon_router_clock_day_key($clock, $timezone);
  mikhmon_upgrade_legacy_expiration_profiles($API);
  mikhmon_ensure_expiration_profile_monitors($API);

// get system resource MikroTik
  $getresource = $API->comm("/system/resource/print");
  $resource = $getresource[0];

// get routeboard info
  $getrouterboard = $API->comm("/system/routerboard/print");
  $routerboard = $getrouterboard[0];

  if (!function_exists('mikhmon_dashboard_money')) {
    function mikhmon_dashboard_money($amount, $currency, $cekindo) {
      return mikhmon_format_money_amount($amount, $currency, $cekindo);
    }
  }

  $incomeTodayCount = 0;
  $incomeTodayTotal = 0.0;
  $incomeMonthCount = 0;
  $incomeMonthTotal = 0.0;
  $incomeTodayFormatted = mikhmon_format_money_amount(0, $currency, $cekindo);
  $incomeMonthFormatted = mikhmon_format_money_amount(0, $currency, $cekindo);
  $revenueForecast = null;
  $reportMonthlySales = null;
  if ($livereport != "disable") {
    $currentDayKey = $clockDayKey;
    $currentMonthKey = mikhmon_sale_month_key($clockDayKey);
    $reportMonthlySales = mikhmon_dashboard_sales_for_month($API, $currentMonthKey, $clockDayKey, $iphost, $userhost, $passwdhost);
    $incomeSummary = mikhmon_income_summary_from_scripts($reportMonthlySales, $clockDayKey, $currentMonthKey);
    $incomeTodayCount = $incomeSummary['today_count'];
    $incomeTodayTotal = $incomeSummary['today_total'];
    $incomeMonthCount = $incomeSummary['month_count'];
    $incomeMonthTotal = $incomeSummary['month_total'];

    $incomeTodayFormatted = mikhmon_format_money_amount($incomeTodayTotal, $currency, $cekindo);
    $incomeMonthFormatted = mikhmon_format_money_amount($incomeMonthTotal, $currency, $cekindo);

    $_SESSION[$session.'idhr'] = $currentDayKey;
    $_SESSION[$session.'totalHr'] = $incomeTodayCount;
    $_SESSION[$session.'totalBl'] = $incomeMonthCount;
    $_SESSION[$session.'dincome'] = $incomeTodayFormatted;
    $_SESSION[$session.'mincome'] = $incomeMonthFormatted;

    $revenueForecast = mikhmon_revenue_forecast($API, $clockDayKey, 7, $reportMonthlySales);
  }

  $vendorAnalytics = array();
  $vendorRecentSales = array();
  $vendorTodayTickets = 0;
  $vendorTodayRevenue = 0.0;
  $vendorTodayCommission = 0.0;
  $vendorMonthRevenue = 0.0;
  $vendorMonthCommission = 0.0;
  $vendorActiveToday = 0;
  $vendorPeakRevenue = 0.0;

  $monthlySales = null;
  $monthlySalesMonthKey = null;

  $enableDashboardSalesScan = true;
  $dashboardSellersData = function_exists('mikhmon_filter_display_sellers')
    ? mikhmon_filter_display_sellers($sellers_data)
    : $sellers_data;
  if ($enableDashboardSalesScan && !empty($dashboardSellersData) && is_array($dashboardSellersData)) {
    foreach ($dashboardSellersData as $sellerKey => $sellerData) {
      $vendorAnalytics[$sellerKey] = array(
        'name' => isset($sellerData['name']) ? $sellerData['name'] : $sellerKey,
        'commission_rate' => isset($sellerData['commission']) ? (int)$sellerData['commission'] : 0,
        'today_qty' => 0,
        'today_rev' => 0.0,
        'today_commission' => 0.0,
        'month_qty' => 0,
        'month_rev' => 0.0,
        'month_commission' => 0.0,
        'last_sale' => '',
        'last_ts' => 0,
      );
    }

    $currentMonthTag = mikhmon_sale_month_key($clockDayKey);
    $currentDayTag = $clockDayKey;
    $monthlySales = is_array($reportMonthlySales)
      ? $reportMonthlySales
      : mikhmon_dashboard_sales_for_month($API, $currentMonthTag, $currentDayTag, $iphost, $userhost, $passwdhost);
    $monthlySalesMonthKey = $currentMonthTag;

    if (function_exists('mikhmon_seller_lot_owner_map_from_users') && function_exists('mikhmon_enrich_sales_with_lot_owner')) {
      $dashboardHotspotUsers = mikhmon_comm_with_reconnect($API, '/ip/hotspot/user/print', array(
        '.proplist' => '.id,name,profile,comment,uptime',
      ), $iphost, $userhost, $passwdhost);
      if (is_array($dashboardHotspotUsers)) {
        $dashboardLotOwnerUsers = array_values(array_filter($dashboardHotspotUsers, 'mikhmon_hotspot_user_is_available'));
        $lotOwnerMap = mikhmon_seller_lot_owner_map_from_users($dashboardLotOwnerUsers, $dashboardSellersData);
        $monthlySales = mikhmon_enrich_sales_with_lot_owner($monthlySales, $lotOwnerMap, $dashboardSellersData);
      }
    }

    if (is_array($monthlySales)) {
      foreach ($monthlySales as $saleScript) {
        $sale = (isset($saleScript['date']) && isset($saleScript['comment']))
          ? $saleScript
          : mikhmon_parse_sale_script($saleScript);

        $saleDate = isset($sale['date']) ? mikhmon_normalize_sale_date($sale['date']) : '';
        $saleTime = isset($sale['time']) ? trim($sale['time']) : '';
        $saleUser = isset($sale['user']) ? trim($sale['user']) : '';
        $salePrice = mikhmon_parse_money_amount(isset($sale['price']) ? $sale['price'] : 0);
        $saleProfile = isset($sale['profile']) ? trim($sale['profile']) : '';
        $saleComment = isset($sale['comment']) ? trim($sale['comment']) : '';
        $sellerKey = mikhmon_comment_seller_key($saleComment, $dashboardSellersData);
        if ($sellerKey === '' || !isset($vendorAnalytics[$sellerKey])) {
          continue;
        }
        $sellerCommissionRate = isset($vendorAnalytics[$sellerKey]['commission_rate']) ? (int)$vendorAnalytics[$sellerKey]['commission_rate'] : 0;
        $saleCommission = $salePrice * $sellerCommissionRate / 100;

        $saleStamp = strtotime($saleDate . ' ' . $saleTime);
        if ($saleStamp === false) {
          $saleStamp = 0;
        }

        $vendorAnalytics[$sellerKey]['month_qty']++;
        $vendorAnalytics[$sellerKey]['month_rev'] += $salePrice;
        $vendorAnalytics[$sellerKey]['month_commission'] += $saleCommission;
        $vendorMonthRevenue += $salePrice;
        $vendorMonthCommission += $saleCommission;
        if ($saleStamp >= $vendorAnalytics[$sellerKey]['last_ts']) {
          $vendorAnalytics[$sellerKey]['last_ts'] = $saleStamp;
          $vendorAnalytics[$sellerKey]['last_sale'] = trim($saleDate . ' ' . $saleTime);
        }

        if ($saleDate === $currentDayTag) {
          $vendorAnalytics[$sellerKey]['today_qty']++;
          $vendorAnalytics[$sellerKey]['today_rev'] += $salePrice;
          $vendorAnalytics[$sellerKey]['today_commission'] += $saleCommission;
          $vendorTodayTickets++;
          $vendorTodayRevenue += $salePrice;
          $vendorTodayCommission += $saleCommission;
        }

        $vendorRecentSales[] = array(
          'ts' => $saleStamp,
          'date' => $saleDate,
          'time' => $saleTime,
          'seller' => $vendorAnalytics[$sellerKey]['name'],
          'user' => $saleUser,
          'profile' => $saleProfile,
          'price' => $salePrice,
          'commission' => $saleCommission,
        );
      }
    }

    foreach ($vendorAnalytics as $sellerKey => $analytics) {
      if ($analytics['today_qty'] > 0) {
        $vendorActiveToday++;
      }
      if ($analytics['month_rev'] > $vendorPeakRevenue) {
        $vendorPeakRevenue = $analytics['month_rev'];
      }
    }

    uasort($vendorAnalytics, function ($left, $right) {
      if ($left['month_rev'] == $right['month_rev']) {
        return $right['today_qty'] <=> $left['today_qty'];
      }
      return $right['month_rev'] <=> $left['month_rev'];
    });

    usort($vendorRecentSales, function ($left, $right) {
      return $right['ts'] <=> $left['ts'];
    });
    $vendorRecentSales = array_slice($vendorRecentSales, 0, 12);
  }
/*
// move hotspot log to disk *
  $getlogging = $API->comm("/system/logging/print", array("?prefix" => "->", ));
  $logging = $getlogging[0];
  if ($logging['prefix'] == "->") {
  } else {
    $API->comm("/system/logging/add", array("action" => "disk", "prefix" => "->", "topics" => "hotspot,info,debug", ));
  }

// get hotspot log
  $getlog = $API->comm("/log/print", array("?topics" => "hotspot,info,debug", ));
  $log = array_reverse($getlog);
  $THotspotLog = count($getlog);
*/
// get & counting hotspot users
  $countallusers = mikhmon_count_only_result($API->comm("/ip/hotspot/user/print", array("count-only" => "")));
  $uunit = ($countallusers < 2) ? "item" : "items";

// get & counting hotspot active
  $counthotspotactive = mikhmon_count_only_result($API->comm("/ip/hotspot/active/print", array("count-only" => "")));
  $hunit = ($counthotspotactive < 2) ? "item" : "items";

// get & counting pppoe resources
  $countpppactive = mikhmon_count_only_result($API->comm("/ppp/active/print", array("count-only" => "")));
  $countpppprofiles = mikhmon_count_only_result($API->comm("/ppp/profile/print", array("count-only" => "")));
  $countpppsecrets = mikhmon_count_only_result($API->comm("/ppp/secret/print", array("count-only" => "")));
  $countpppoeservers = mikhmon_count_only_result($API->comm("/interface/pppoe-server/server/print", array("count-only" => "")));
  $pactiveunit = ($countpppactive > 1) ? "items" : "item";
  $pprofileunit = ($countpppprofiles > 1) ? "items" : "item";
  $psecretunit = ($countpppsecrets > 1) ? "items" : "item";
  $pppoeserverunit = ($countpppoeservers > 1) ? "items" : "item";

  if ($livereport == "disable") {
    $logh = "457px";
    $lreport = "style='display:none;'";
  } else {
    $logh = "350px";
    $lreport = "style='display:block;'";
  }

  $reportMonthlySales = is_array($reportMonthlySales) ? $reportMonthlySales : array();
/*
// get selling report
    $thisD = date("d");
    $thisM = strtolower(date("M"));
    $thisY = date("Y");

    if (strlen($thisD) == 1) {
      $thisD = "0" . $thisD;
    } else {
      $thisD = $thisD;
    }

    $idhr = $thisM . "/" . $thisD . "/" . $thisY;
    $idbl = $thisM . $thisY;

    $getSRHr = $API->comm("/system/script/print", array(
      "?source" => "$idhr",
    ));
    $TotalRHr = count($getSRHr);
    $getSRBl = $API->comm("/system/script/print", array(
      "?owner" => "$idbl",
    ));
    $TotalRBl = count($getSRBl);

    for ($i = 0; $i < $TotalRHr; $i++) {

      $tHr += explode("-|-", $getSRHr[$i]['name'])[3];

    }
    for ($i = 0; $i < $TotalRBl; $i++) {

      $tBl += explode("-|-", $getSRBl[$i]['name'])[3];
    }
  }*/
}
?>
    
<div id="reloadHome">

    <div id="r_1" class="row dashboard-top-row">
      <div class="col-4">
        <div class="box bmh-75 box-bordered">
          <div class="box-group">
            <div class="box-group-icon"><i class="fa fa-calendar"></i></div>
              <div class="box-group-area">
                <span ><?= $_system_date_time ?><br>
                    <?php 
                    echo $clockDisplay . "<br>
                    ".$_uptime." : " . formatDTM($resource['uptime']);
                    $_SESSION[$session.'sdate'] = $clockDayKey;
                    ?>
                </span>
              </div>
            </div>
          </div>
        </div>
      <div class="col-4">
        <div class="box bmh-75 box-bordered">
          <div class="box-group">
          <div class="box-group-icon"><i class="fa fa-info-circle"></i></div>
              <div class="box-group-area">
                <span >
                    <?php
                    echo $_board_name." : " . $resource['board-name'] . "<br/>
                    ".$_model." : " . $routerboard['model'] . "<br/>
                    Router OS : " . $resource['version'];
                    ?>
                </span>
              </div>
            </div>
          </div>
        </div>
    <div class="col-4">
      <div class="box bmh-75 box-bordered">
        <div class="box-group">
          <div class="box-group-icon"><i class="fa fa-server"></i></div>
              <div class="box-group-area">
                <span >
                    <?php
                    echo $_cpu_load." : " . $resource['cpu-load'] . "%<br/>
                    ".$_free_memory." : " . formatBytes($resource['free-memory'], 2) . "<br/>
                    ".$_free_hdd." : " . formatBytes($resource['free-hdd-space'], 2)
                    ?>
                </span>
                </div>
              </div>
            </div>
          </div> 
      </div>

        <div class="row dashboard-main-row">
          <div  class="col-8">
            <div id="r_2" class="row dashboard-hotspot-row">
            <div class="card dashboard-hotspot-card">
              <div class="card-header"><h3><i class="fa fa-wifi"></i> Hotspot</h3></div>
                <div class="card-body">
                  <div class="row dashboard-hotspot-grid">
                    <div class="col-3 col-box-6">
                      <div class="box bg-blue bmh-75">
                        <a onclick="cancelPage()" href="./?hotspot=active&session=<?= $session; ?>">
                          <h1><?= $counthotspotactive; ?>
                              <span class="box-stat-unit"><?= $hunit; ?></span>
                            </h1>
                          <div>
                            <i class="fa fa-laptop"></i> <?= $_hotspot_active ?>
                          </div>
                        </a>
                      </div>
                    </div>
                    <div class="col-3 col-box-6">
                    <div class="box bg-green bmh-75">
                      <a onclick="cancelPage()" href="./?hotspot=users&profile=all&session=<?= $session; ?>">
                            <h1><?= $countallusers; ?>
                              <span class="box-stat-unit"><?= $uunit; ?></span>
                            </h1>
                      <div>
                            <i class="fa fa-users"></i> <?= $_hotspot_users ?>
                          </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-3 col-box-6">
                    <div class="box bg-yellow bmh-75">
                      <a onclick="cancelPage()" href="./?hotspot-user=add&session=<?= $session; ?>">
                        <div>
                          <h1><i class="fa fa-user-plus"></i>
                              <span class="box-stat-unit"><?= $_add ?></span>
                          </h1>
                        </div>
                        <div>
                            <i class="fa fa-user-plus"></i> <?= $_hotspot_users ?>
                        </div>
                      </a>
                    </div>
                  </div>
                  <div class="col-3 col-box-6">
                    <div class="box bg-red bmh-75">
                      <a onclick="cancelPage()" href="./?hotspot-user=generate&session=<?= $session; ?>">
                        <div>
                          <h1><i class="fa fa-user-plus"></i>
                              <span class="box-stat-unit"><?= $_generate ?></span>
                          </h1>
                        </div>
                        <div>
                            <i class="fa fa-user-plus"></i> <?= $_hotspot_users ?>
                        </div>
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
            <div id="r_pppoe" class="row dashboard-pppoe-row">
              <div class="col-12">
              <div class="card dashboard-pppoe-card">
                <div class="card-header"><h3><i class="fa fa-exchange"></i> <?= isset($_pppoe) ? $_pppoe : 'PPPoE' ?></h3></div>
                <div class="card-body">
                  <div class="row dashboard-hotspot-grid">
                    <div class="col-3 col-box-6">
                      <div class="box bg-blue bmh-75">
                        <a onclick="cancelPage()" href="./?ppp=active&session=<?= $session; ?>">
                          <h1><?= (int)$countpppactive; ?><span class="box-stat-unit"><?= $pactiveunit; ?></span></h1>
                          <div><i class="fa fa-link"></i> <?= isset($_ppp_active) ? $_ppp_active : 'PPP Active' ?></div>
                        </a>
                      </div>
                    </div>
                    <div class="col-3 col-box-6">
                      <div class="box bg-green bmh-75">
                        <a onclick="cancelPage()" href="./?ppp=profiles&session=<?= $session; ?>">
                          <h1><?= (int)$countpppprofiles; ?><span class="box-stat-unit"><?= $pprofileunit; ?></span></h1>
                          <div><i class="fa fa-list"></i> <?= isset($_ppp_profiles) ? $_ppp_profiles : 'PPP Profiles' ?></div>
                        </a>
                      </div>
                    </div>
                    <div class="col-3 col-box-6">
                      <div class="box bg-yellow bmh-75">
                        <a onclick="cancelPage()" href="./?ppp=secrets&session=<?= $session; ?>">
                          <h1><?= (int)$countpppsecrets; ?><span class="box-stat-unit"><?= $psecretunit; ?></span></h1>
                          <div><i class="fa fa-lock"></i> <?= isset($_ppp_secrets) ? $_ppp_secrets : 'PPP Secrets' ?></div>
                        </a>
                      </div>
                    </div>
                    <div class="col-3 col-box-6">
                      <div class="box bg-red bmh-75">
                        <a onclick="cancelPage()" href="./?ppp=servers&session=<?= $session; ?>">
                          <h1><?= (int)$countpppoeservers; ?><span class="box-stat-unit"><?= $pppoeserverunit; ?></span></h1>
                          <div><i class="fa fa-server"></i> <?= isset($_pppoe_servers) ? $_pppoe_servers : 'PPPoE Servers' ?></div>
                        </a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              </div>
            </div>
            <div class="card">
              <div class="card-header"><h3><i class="fa fa-area-chart"></i> <?= $_traffic ?> </h3></div>

              <div class="card-body">
  
                  <?php $getinterface = $API->comm("/interface/print");
                  $interface = $getinterface[$iface - 1]['name']; 
                  /*$TotalReg = count($getinterface);
                  for ($i = 0; $i < $TotalReg; $i++) {
                    echo $getinterface[$i]['name'].'<br>';
                  }*/
                  ?>
                  
                  <script type="text/javascript"> 
                    var chart;
                    var sessiondata = "<?= $session ?>";
                    var interface = "<?= $interface ?>";
                    var n = 3000;
                    function requestDatta(session,iface) {
                      $.ajax({
                        url: './traffic/traffic.php?session='+session+'&iface='+iface,
                        datatype: "json",
                        success: function(data) {
                          var midata = JSON.parse(data);
                          if( midata.length > 0 ) {
                            var TX=parseInt(midata[0].data);
                            var RX=parseInt(midata[1].data);
                            var x = (new Date()).getTime(); 
                            shift=chart.series[0].data.length > 19;
                            chart.series[0].addPoint([x, TX], true, shift);
                            chart.series[1].addPoint([x, RX], true, shift);
                          }
                        },
                        error: function(XMLHttpRequest, textStatus, errorThrown) { 
                          console.error("Status: " + textStatus + " request: " + XMLHttpRequest); console.error("Error: " + errorThrown); 
                        }       
                      });
                    }	

                    $(document).ready(function() {
                        Highcharts.setOptions({
                          global: {
                            useUTC: false
                          }
                        });

                        Highcharts.addEvent(Highcharts.Series, 'afterInit', function () {
	                        this.symbolUnicode = {
    	                    circle: '●',
                          diamond: '♦',
                          square: '■',
                          triangle: '▲',
                          'triangle-down': '▼'
                          }[this.symbol] || '●';
                        });

                          chart = new Highcharts.Chart({
                          chart: {
                          renderTo: 'trafficMonitor',
                          animation: Highcharts.svg,
                          type: 'areaspline',
                          events: {
                            load: function () {
                              setInterval(function () {
                                requestDatta(sessiondata,interface);
                              }, 8000);
                            }				
                          }
                        },
                        title: {
                          text: '<?= $_interface ?> ' + interface
                        },
                        
                        xAxis: {
                          type: 'datetime',
                          tickPixelInterval: 150,
                          maxZoom: 20 * 1000,
                        },
                        yAxis: {
                            minPadding: 0.2,
                            maxPadding: 0.2,
                            title: {
                              text: null
                            },
                            labels: {
                              formatter: function () {      
                                var bytes = this.value;                          
                                var sizes = ['bps', 'kbps', 'Mbps', 'Gbps', 'Tbps'];
                                if (bytes == 0) return '0 bps';
                                var i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
                                return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + sizes[i];                    
                              },
                            },       
                        },
                        
                        series: [{
                          name: 'Tx',
                          data: [],
                          marker: {
                            symbol: 'circle'
                          }
                        }, {
                          name: 'Rx',
                          data: [],
                          marker: {
                            symbol: 'circle'
                          }
                        }],

                        tooltip: {
                          formatter: function () { 
                            var _0x2f7f=["\x70\x6F\x69\x6E\x74\x73","\x79","\x62\x70\x73","\x6B\x62\x70\x73","\x4D\x62\x70\x73","\x47\x62\x70\x73","\x54\x62\x70\x73","\x3C\x73\x70\x61\x6E\x20\x73\x74\x79\x6C\x65\x3D\x22\x63\x6F\x6C\x6F\x72\x3A","\x63\x6F\x6C\x6F\x72","\x73\x65\x72\x69\x65\x73","\x3B\x20\x66\x6F\x6E\x74\x2D\x73\x69\x7A\x65\x3A\x20\x31\x2E\x35\x65\x6D\x3B\x22\x3E","\x73\x79\x6D\x62\x6F\x6C\x55\x6E\x69\x63\x6F\x64\x65","\x3C\x2F\x73\x70\x61\x6E\x3E\x3C\x62\x3E","\x6E\x61\x6D\x65","\x3A\x3C\x2F\x62\x3E\x20\x30\x20\x62\x70\x73","\x70\x75\x73\x68","\x6C\x6F\x67","\x66\x6C\x6F\x6F\x72","\x3A\x3C\x2F\x62\x3E\x20","\x74\x6F\x46\x69\x78\x65\x64","\x70\x6F\x77","\x20","\x65\x61\x63\x68","\x3C\x62\x3E\x4D\x69\x6B\x68\x6D\x6F\x6E\x20\x54\x72\x61\x66\x66\x69\x63\x20\x4D\x6F\x6E\x69\x74\x6F\x72\x3C\x2F\x62\x3E\x3C\x62\x72\x20\x2F\x3E\x3C\x62\x3E\x54\x69\x6D\x65\x3A\x20\x3C\x2F\x62\x3E","\x25\x48\x3A\x25\x4D\x3A\x25\x53","\x78","\x64\x61\x74\x65\x46\x6F\x72\x6D\x61\x74","\x3C\x62\x72\x20\x2F\x3E","\x20\x3C\x62\x72\x2F\x3E\x20","\x6A\x6F\x69\x6E"];var s=[];$[_0x2f7f[22]](this[_0x2f7f[0]],function(_0x3735x2,_0x3735x3){var _0x3735x4=_0x3735x3[_0x2f7f[1]];var _0x3735x5=[_0x2f7f[2],_0x2f7f[3],_0x2f7f[4],_0x2f7f[5],_0x2f7f[6]];if(_0x3735x4== 0){s[_0x2f7f[15]](_0x2f7f[7]+ this[_0x2f7f[9]][_0x2f7f[8]]+ _0x2f7f[10]+ this[_0x2f7f[9]][_0x2f7f[11]]+ _0x2f7f[12]+ this[_0x2f7f[9]][_0x2f7f[13]]+ _0x2f7f[14])};var _0x3735x2=parseInt(Math[_0x2f7f[17]](Math[_0x2f7f[16]](_0x3735x4)/ Math[_0x2f7f[16]](1024)));s[_0x2f7f[15]](_0x2f7f[7]+ this[_0x2f7f[9]][_0x2f7f[8]]+ _0x2f7f[10]+ this[_0x2f7f[9]][_0x2f7f[11]]+ _0x2f7f[12]+ this[_0x2f7f[9]][_0x2f7f[13]]+ _0x2f7f[18]+ parseFloat((_0x3735x4/ Math[_0x2f7f[20]](1024,_0x3735x2))[_0x2f7f[19]](2))+ _0x2f7f[21]+ _0x3735x5[_0x3735x2])});return _0x2f7f[23]+ Highcharts[_0x2f7f[26]](_0x2f7f[24], new Date(this[_0x2f7f[25]]))+ _0x2f7f[27]+ s[_0x2f7f[29]](_0x2f7f[28])
                          },
                          shared: true                                                      
                        },
                      });
                    });
                  </script>
                  <div id="trafficMonitor"></div>
              </div>
            </div>
            <?php if (!empty($vendorAnalytics)): ?>
            <div class="card">
              <div class="card-header"><h3><i class="fa fa-line-chart"></i> <?= isset($_vendor_analytics) ? $_vendor_analytics : 'Vendor Analytics' ?></h3></div>
              <div class="card-body">
                <div class="row vendor-analytics-row dashboard-analytics-grid">
                  <div class="col-4 col-box-6">
                    <div class="box bg-green bmh-75">
                      <h1><?= $vendorActiveToday; ?><span class="box-stat-unit"> <?= isset($_seller) ? $_seller : 'Vendor' ?></span></h1>
                      <div><?= isset($_today) ? $_today : 'Today' ?></div>
                    </div>
                  </div>
                  <div class="col-4 col-box-6">
                    <div class="box bg-blue bmh-75">
                      <h1><?= $vendorTodayTickets; ?><span class="box-stat-unit"> vcr</span></h1>
                      <div><?= isset($_tickets_sold) ? $_tickets_sold : 'Tickets sold' ?></div>
                    </div>
                  </div>
                  <div class="col-4 col-box-6">
                    <div class="box bg-yellow bmh-75">
                      <h1 class="box-stat-h1"><?= mikhmon_dashboard_money($vendorTodayRevenue, $currency, $cekindo) ?></h1>
                      <div><?= isset($_seller_ca) ? $_seller_ca : 'Revenue' ?> <?= isset($_today) ? $_today : 'Today' ?></div>
                    </div>
                  </div>
                  <div class="col-4 col-box-6">
                    <div class="box bg-red bmh-75">
                      <h1 class="box-stat-h1"><?= mikhmon_dashboard_money($vendorTodayCommission, $currency, $cekindo) ?></h1>
                      <div><?= isset($_commission_today) ? $_commission_today : 'Commission today' ?></div>
                    </div>
                  </div>
                  <div class="col-4 col-box-6">
                    <div class="box bg-aqua bmh-75">
                      <h1 class="box-stat-h1"><?= mikhmon_dashboard_money($vendorMonthRevenue, $currency, $cekindo) ?></h1>
                      <div><?= isset($_seller_ca) ? $_seller_ca : 'Revenue' ?> <?= isset($_this_month) ? $_this_month : 'This month' ?></div>
                    </div>
                  </div>
                  <div class="col-4 col-box-6">
                    <div class="box bg-purple bmh-75">
                      <h1 class="box-stat-h1"><?= mikhmon_dashboard_money($vendorMonthCommission, $currency, $cekindo) ?></h1>
                      <div><?= isset($_commission_month) ? $_commission_month : 'Commission this month' ?></div>
                    </div>
                  </div>
                </div>
                <div class="row dashboard-main-row">
                  <div class="col-8">
                    <div class="table-responsive portal-table-wrap">
                      <table class="table table-bordered table-hover dashboard-table-sm portal-table-min-lg">
                        <thead>
                          <tr>
                            <th><?= isset($_seller) ? $_seller : 'Vendor' ?></th>
                            <th><?= isset($_last_sale) ? $_last_sale : 'Last sale' ?></th>
                            <th class="text-center"><?= isset($_today) ? $_today : 'Today' ?></th>
                            <th class="text-right"><?= isset($_subtotal) ? $_subtotal : 'Subtotal' ?> <?= isset($_today) ? $_today : 'Today' ?></th>
                            <th class="text-right"><?= isset($_commission) ? $_commission : 'Commission' ?> <?= isset($_today) ? $_today : 'Today' ?></th>
                            <th class="text-center"><?= isset($_this_month) ? $_this_month : 'This month' ?></th>
                            <th class="text-right"><?= isset($_subtotal) ? $_subtotal : 'Subtotal' ?> <?= isset($_this_month) ? $_this_month : 'This month' ?></th>
                            <th class="text-right"><?= isset($_commission) ? $_commission : 'Commission' ?> <?= isset($_this_month) ? $_this_month : 'This month' ?></th>
                            <th><?= isset($_performance) ? $_performance : 'Performance' ?></th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($vendorAnalytics as $sellerKey => $analytics): ?>
                          <?php $perfPct = $vendorPeakRevenue > 0 ? round(($analytics['month_rev'] / $vendorPeakRevenue) * 100) : 0; ?>
                          <tr>
                            <td><b><?= htmlspecialchars($analytics['name']) ?></b><br><small><code><?= htmlspecialchars($sellerKey) ?></code></small></td>
                            <td><?= $analytics['last_sale'] !== '' ? htmlspecialchars($analytics['last_sale']) : '—' ?></td>
                            <td class="text-center"><b><?= $analytics['today_qty'] ?></b></td>
                            <td class="text-right"><?= mikhmon_dashboard_money($analytics['today_rev'], $currency, $cekindo) ?></td>
                            <td class="text-right"><?= mikhmon_dashboard_money($analytics['today_commission'], $currency, $cekindo) ?></td>
                            <td class="text-center"><b><?= $analytics['month_qty'] ?></b></td>
                            <td class="text-right"><?= mikhmon_dashboard_money($analytics['month_rev'], $currency, $cekindo) ?></td>
                            <td class="text-right"><?= mikhmon_dashboard_money($analytics['month_commission'], $currency, $cekindo) ?></td>
                            <td class="perf-bar-cell">
                              <div class="perf-bar-wrap">
                                <div class="perf-bar-fill" style="width:<?= $perfPct ?>%;"></div>
                              </div>
                              <small><?= $perfPct ?>%</small>
                            </td>
                          </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <div class="col-4">
                    <div class="box box-bordered recent-sales-box">
                      <h4 class="recent-sales-title"><i class="fa fa-clock-o"></i> <?= isset($_recent_sales) ? $_recent_sales : 'Recent Sales' ?></h4>
                      <?php if (empty($vendorRecentSales)): ?>
                      <p class="recent-sales-empty"><?= isset($_no_vendor_sales_month) ? $_no_vendor_sales_month : 'No vendor sales this month.' ?></p>
                      <?php else: ?>
                      <div class="overflow recent-sales-scroll portal-table-wrap">
                        <table class="table table-sm table-bordered dashboard-table-sm portal-table-min-md">
                          <thead>
                            <tr>
                              <th><?= isset($_time) ? $_time : 'Time' ?></th>
                              <th><?= isset($_seller) ? $_seller : 'Vendor' ?></th>
                              <th><?= isset($_profile) ? $_profile : 'Profile' ?></th>
                              <th class="text-right"><?= isset($_price) ? $_price : 'Price' ?></th>
                              <th class="text-right"><?= isset($_commission) ? $_commission : 'Commission' ?></th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($vendorRecentSales as $sale): ?>
                            <tr>
                              <td><?= htmlspecialchars($sale['date'] . ' ' . $sale['time']) ?></td>
                              <td><b><?= htmlspecialchars($sale['seller']) ?></b><br><small><?= htmlspecialchars($sale['user']) ?></small></td>
                              <td><?= htmlspecialchars($sale['profile']) ?></td>
                              <td class="text-right"><?= mikhmon_dashboard_money($sale['price'], $currency, $cekindo) ?></td>
                              <td class="text-right"><?= mikhmon_dashboard_money($sale['commission'], $currency, $cekindo) ?></td>
                            </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
            </div>  
            <div class="col-4">
            <div id="r_4" class="row">
              <div <?= $lreport; ?> class="box bmh-75 box-bordered">
                <div class="box-group">
                  <div class="box-group-icon"><i class="fa fa-money"></i></div>
                    <div class="box-group-area">
                      <span >
                        <div id="reloadLreport">
                          <?php 
                          echo $_income." <br/>" . "
                          ".$_today." " . $incomeTodayCount . "vcr : " . $incomeTodayFormatted . "<br/>
                          ".$_this_month." " . $incomeMonthCount . "vcr : " . $incomeMonthFormatted;
                          ?>                       
                        </div>
                    </span>
                </div>
              </div>
            </div>
            </div>
            <?php if ($revenueForecast !== null && $revenueForecast['avg_daily'] > 0): ?>
            <div id="r_forecast" class="row">
              <div class="card forecast-card">
                <div class="card-header">
                  <h3><i class="fa fa-line-chart"></i> <?= isset($_revenue_forecast_title) ? $_revenue_forecast_title : 'Prévision de revenu' ?></h3>
                </div>
                <div class="card-body">
                  <?php
                    $fc = $revenueForecast;
                    $projFmt = mikhmon_format_money_amount($fc['projected'], $currency, $cekindo);
                    $avgFmt  = mikhmon_format_money_amount($fc['avg_daily'], $currency, $cekindo);
                    $confidenceIcon = ($fc['confidence'] === 'high') ? 'fa-check-circle forecast-conf-high'
                                    : (($fc['confidence'] === 'medium') ? 'fa-exclamation-circle forecast-conf-medium'
                                    : 'fa-question-circle forecast-conf-low');
                    $confidenceLabel = ($fc['confidence'] === 'high') ? 'Fiable'
                                     : (($fc['confidence'] === 'medium') ? 'Estimation' : 'Approximatif');
                  ?>
                  <div class="forecast-projected-amount"><?= $projFmt ?></div>
                  <div class="forecast-message">
                    <i class="fa fa-magic"></i>
                    <?php
                      $daysWord = ($fc['window_days'] == 7) ? '7 derniers jours' : $fc['window_days'] . ' derniers jours';
                      echo 'Vous gagnerez probablement <strong>' . $projFmt . '</strong> ce mois-ci, en tenant compte des revenus journaliers sur les ' . $daysWord . '.';
                    ?>
                  </div>
                  <div class="forecast-details">
                    <div class="forecast-detail-item">
                      <span class="forecast-detail-label"><i class="fa fa-calendar-o"></i> Moy. journalière</span>
                      <span class="forecast-detail-value"><?= $avgFmt ?></span>
                    </div>
                    <div class="forecast-detail-item">
                      <span class="forecast-detail-label"><i class="fa fa-clock-o"></i> Jours restants</span>
                      <span class="forecast-detail-value"><?= max(0, $fc['days_remaining'] - 1) ?> j</span>
                    </div>
                    <div class="forecast-detail-item">
                      <span class="forecast-detail-label"><i class="fa fa-bar-chart"></i> Jours analysés</span>
                      <span class="forecast-detail-value"><?= $fc['days_sampled'] ?> / <?= $fc['window_days'] ?></span>
                    </div>
                    <div class="forecast-detail-item">
                      <span class="forecast-detail-label"><i class="fa <?= $confidenceIcon ?>"></i> Confiance</span>
                      <span class="forecast-detail-value"><?= $confidenceLabel ?></span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <div id="r_3" class="row">
            <div class="card">
              <div class="card-header">
                <h3><a onclick="cancelPage()" href="./?hotspot=log&session=<?= $session; ?>" title="Open Hotspot Log" ><i class="fa fa-align-justify"></i> <?= $_hotspot_log ?></a></h3></div>
                  <div class="card-body">
                    <div class="mr-t-10 overflow log-scroll-box portal-table-wrap" style="height:<?= $logh; ?>;">
                      <table class="table table-sm table-bordered table-hover dashboard-table-sm portal-table-min-md">
                        <thead>
                          <tr>
                            <th><?= $_time ?></th>
                            <th><?= $_users ?> (IP)</th>
                            <th><?= $_messages ?></th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr>
                            <td colspan="3" class="text-center">
                            <div id="loader" ><i><i class='fa fa-circle-o-notch fa-spin'></i> <?= $_processing ?> </i></div>
                            </td>
                          </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
              </div>
            </div>
</div>
</div>
