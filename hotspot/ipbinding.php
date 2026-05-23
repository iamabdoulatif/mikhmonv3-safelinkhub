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
if (!isset($_SESSION["mikhmon"])) {
	header("Location:../admin.php?id=login");
	} else {
		include_once(__DIR__ . '/../include/mikhmon_compat.php');
		include_once(__DIR__ . '/../include/csrf.php');

	$bindingMessage = "";
	$bindingError = "";
	$getprofile = $API->comm("/ip/hotspot/user/profile/print");
	$getservers = $API->comm("/ip/hotspot/print");
	$profileValidity = array();
	if (is_array($getprofile)) {
		foreach ($getprofile as $profileRow) {
			if (!is_array($profileRow) || !isset($profileRow['name'])) {
				continue;
			}
			$profileValidity[$profileRow['name']] = mikhmon_profile_validity_from_on_login(isset($profileRow['on-login']) ? $profileRow['on-login'] : '');
		}
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_ip_binding_duration'])) {
		csrf_guard();
		$bindingMac = strtoupper(trim(isset($_POST['binding_mac']) ? $_POST['binding_mac'] : ''));
		$bindingAddress = trim(isset($_POST['binding_address']) ? $_POST['binding_address'] : '');
		$bindingToAddress = trim(isset($_POST['binding_to_address']) ? $_POST['binding_to_address'] : '');
		$bindingServer = trim(isset($_POST['binding_server']) ? $_POST['binding_server'] : 'all');
		$bindingType = trim(isset($_POST['binding_type']) ? $_POST['binding_type'] : 'bypassed');
		$bindingProfile = trim(isset($_POST['binding_profile']) ? $_POST['binding_profile'] : '');
		$bindingDuration = mikhmon_normalize_routeros_duration(isset($_POST['binding_duration']) ? $_POST['binding_duration'] : '');
		$bindingNote = trim(isset($_POST['binding_note']) ? $_POST['binding_note'] : '');

		if ($bindingMac === '' || !preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $bindingMac)) {
			$bindingError = "Adresse MAC invalide. Format attendu : AA:BB:CC:DD:EE:FF.";
		} elseif ($bindingProfile === '' || !isset($profileValidity[$bindingProfile])) {
			$bindingError = "Sélectionnez un profil Hotspot valide.";
		} elseif ($bindingDuration === '') {
			$bindingError = "Durée invalide. Exemple : 30m, 2h, 1d ou 00:30:00.";
		} elseif (!in_array($bindingType, array('bypassed', 'blocked', 'regular'), true)) {
			$bindingError = "Type IP Binding invalide.";
		} else {
			$comment = mikhmon_build_ip_binding_comment($bindingProfile, $bindingDuration);
			if ($bindingNote !== '') {
				$comment .= '|' . $bindingNote;
			}

			$attrs = array(
				"mac-address" => $bindingMac,
				"type" => $bindingType,
				"server" => $bindingServer !== '' ? $bindingServer : 'all',
				"comment" => $comment,
			);
			if ($bindingAddress !== '') {
				$attrs["address"] = $bindingAddress;
			}
			if ($bindingToAddress !== '') {
				$attrs["to-address"] = $bindingToAddress;
			}

			$bindingResponse = $API->comm("/ip/hotspot/ip-binding/add", $attrs);
			$bindingApiError = mikhmon_routeros_response_error($bindingResponse);

			if ($bindingApiError !== '') {
				$bindingError = "RouterOS a refusé l'IP Binding : " . $bindingApiError;
			} else {
				$schedulerName = mikhmon_ip_binding_scheduler_name($bindingMac);
				$oldSchedulers = $API->comm("/system/scheduler/print", array("?name" => $schedulerName));
				if (is_array($oldSchedulers)) {
					foreach ($oldSchedulers as $oldScheduler) {
						if (is_array($oldScheduler) && isset($oldScheduler['.id'])) {
							$API->comm("/system/scheduler/remove", array(".id" => $oldScheduler['.id']));
						}
					}
				}

				$schedulerResponse = $API->comm("/system/scheduler/add", array(
					"name" => $schedulerName,
					"interval" => $bindingDuration,
					"disabled" => "no",
					"comment" => "mikhmon-ipbinding-expire",
					"on-event" => mikhmon_build_ip_binding_expire_script($bindingMac, $bindingAddress, $schedulerName),
				));
				$schedulerApiError = mikhmon_routeros_response_error($schedulerResponse);
				if ($schedulerApiError !== '') {
					$bindingError = "IP Binding ajouté, mais le scheduler d'expiration a échoué : " . $schedulerApiError;
				} else {
					$bindingMessage = "IP Binding ajouté pour " . htmlspecialchars($bindingMac) . " avec expiration " . htmlspecialchars($bindingDuration) . " selon le profil " . htmlspecialchars($bindingProfile) . ".";
				}
			}
		}
	}

	$getbinding = $API->comm("/ip/hotspot/ip-binding/print");
	$TotalReg = count($getbinding);

	$countbinding = $API->comm("/ip/hotspot/ip-binding/print", array(
		"count-only" => "",
	));
}

?>
<div class="row">
<div id="reloadbinding">
<div class="col-12">
<div class="card">
<div class="card-header">
	<h3><i class=" fa fa-address-book"></i> <?= $_ip_bindings ?> 
<?php
if ($countbinding < 2) {
	echo "$countbinding item";
} elseif ($countbinding > 1) {
	echo "$countbinding items";
};
?>
    </h3>
</div>
<div class="card-body">	   
<?php if ($bindingMessage !== "") { ?>
<div class="alert bg-success text-white" style="padding:10px 12px;margin-bottom:10px;border-radius:4px;">
	<i class="fa fa-check-circle"></i> <?= $bindingMessage ?>
</div>
<?php } ?>
<?php if ($bindingError !== "") { ?>
<div class="alert bg-danger text-white" style="padding:10px 12px;margin-bottom:10px;border-radius:4px;">
	<i class="fa fa-warning"></i> <?= htmlspecialchars($bindingError) ?>
</div>
<?php } ?>
<div class="box-bordered mr-b-10 ipbind-duration-panel">
	<form class="ipbind-duration-form" method="post" action="./?hotspot=ipbinding&session=<?= urlencode($session); ?>">
		<?= csrf_field() ?>
		<input type="hidden" name="add_ip_binding_duration" value="1">
		<div class="ipbind-duration-grid">
			<div class="ipbind-field ipbind-field-mac">
				<label>MAC Address</label>
				<input class="form-control" type="text" name="binding_mac" placeholder="AA:BB:CC:DD:EE:FF" required>
			</div>
			<div class="ipbind-field">
				<label>Address <span class="ipbind-optional">(optionnel)</span></label>
				<input class="form-control" type="text" name="binding_address" placeholder="optionnel">
			</div>
			<div class="ipbind-field">
				<label>To Address</label>
				<input class="form-control" type="text" name="binding_to_address" placeholder="optionnel">
			</div>
			<div class="ipbind-field">
				<label><?= $_profile ?></label>
				<select class="form-control" name="binding_profile" id="bindingProfile" required>
					<option value="">Sélectionner</option>
					<?php foreach ($profileValidity as $profileName => $validity) { ?>
						<option value="<?= htmlspecialchars($profileName) ?>" data-validity="<?= htmlspecialchars($validity) ?>">
							<?= htmlspecialchars($profileName) ?><?= $validity !== '' ? ' - ' . htmlspecialchars($validity) : '' ?>
						</option>
					<?php } ?>
				</select>
			</div>
			<div class="ipbind-field ipbind-field-duration">
				<label>Durée</label>
				<input class="form-control" type="text" name="binding_duration" id="bindingDuration" placeholder="1d" required>
			</div>
			<div class="ipbind-field">
				<label>Type</label>
				<select class="form-control" name="binding_type">
					<option value="bypassed">bypassed</option>
					<option value="regular">regular</option>
					<option value="blocked">blocked</option>
				</select>
			</div>
			<div class="ipbind-field">
				<label>Server</label>
				<select class="form-control" name="binding_server">
					<option value="all">all</option>
					<?php if (is_array($getservers)) { foreach ($getservers as $serverRow) { if (is_array($serverRow) && isset($serverRow['name'])) { ?>
						<option value="<?= htmlspecialchars($serverRow['name']) ?>"><?= htmlspecialchars($serverRow['name']) ?></option>
					<?php } } } ?>
				</select>
			</div>
			<div class="ipbind-field ipbind-field-note">
				<label>Commentaire</label>
				<input class="form-control" type="text" name="binding_note" placeholder="note optionnelle">
			</div>
			<div class="ipbind-field ipbind-actions">
				<button class="btn bg-primary" type="submit"><i class="fa fa-plus"></i> Ajouter</button>
			</div>
		</div>
	</form>
</div>
<div class="w-6 ipbind-search">
    <input id="filterTable" type="text" class="form-control" placeholder="Search..">
  </div>
<div class="overflow box-bordered mr-t-10 ipbind-table-wrap" style="max-height: 75vh">  	   
<table id="dataTable" class="table table-bordered table-hover text-nowrap"> 
 <thead>
  <tr>
    <th></th>
    <th></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> <?= $_name ?></th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> MAC Address</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Address</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> To Address</th>
    <th class="pointer" title="Click to sort"><i class="fa fa-sort"></i> Server</th>
  </tr>
  </thead>
  <tbody> 
<?php
for ($i = 0; $i < $TotalReg; $i++) {
	$binding = $getbinding[$i];
	$id = $binding['.id'];

	$maca = $binding['mac-address'];
	$addr = $binding['address'];
	$toaddr = $binding['to-address'];
	$server = $binding['server'];
	$commt = $binding['comment'];
	$bdisabled = $binding['disabled'];

	echo "<tr>";
	?>
  	<td style='text-align:center;'><i class='fa fa-minus-square text-danger pointer' onclick="if(confirm('Are you sure to delete (<?= $maca; ?>)?')){loadpage('./?remove-ip-binding=<?= $id . '&mac=' . $maca . '&addr=' . $addr; ?>&session=<?= $session; ?>')}else{}" title='Remove <?= $maca; ?>'></i>&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp
  	<?php

		if ($bdisabled == "true") {
			$uriprocess = "'./?enable-ip-binding=" . $id . "&session=" . $session . "'";
			echo "<span class='text-warning btnsmall pointer' title='Enable Binding " . $addr . "' onclick=loadpage(".$uriprocess.")><i class='fa fa-lock'></span></td>";
		} else {
			$uriprocess = "'./?disable-ip-binding=" . $id . "&session=" . $session . "'";
			echo "<span title='Disable Binding " . $addr . "' class='btnsmall pointer' onclick=loadpage(".$uriprocess.")><i class='fa fa-unlock '></span></td>";
		}
		echo "<td style='text-align:center;'>";
		if ($binding['bypassed'] == "true") {
			echo "<b style='color:#0091EA;'>P</b>";
		} else {
		}
		echo "</td>";
		echo "<td>" . $commt . "</td>";
		echo "<td>" . $maca . "</td>";
		echo "<td>" . $addr . "</a></td>";
		echo "<td>" . $toaddr . "</td>";
		echo "<td>" . $server . "</td>";
		echo "</tr>";
	}
	?>
  </tbody>
</table>
</div>
</div>
</div>
</div>
<script>
(function () {
	var profileSelect = document.getElementById('bindingProfile');
	var durationInput = document.getElementById('bindingDuration');
	if (!profileSelect || !durationInput) return;
	profileSelect.onchange = function () {
		var option = profileSelect.options[profileSelect.selectedIndex];
		var validity = option ? option.getAttribute('data-validity') : '';
		if (validity) durationInput.value = validity;
	};
})();
</script>
<div class="modal-window" id="help" aria-hidden="true">
  <div>
  	<header><h1>Help</h1></header>
  	<a style="font-weight:bold;" href="#" title="Close" class="modal-close">X</a>
	<p> 
		    <?php if (mikhmon_currency_uses_integer_amounts($currency, $cekindo)) { ?>
		      <ul>
		        <li>Masuk ke menu Hosts.</li>
		        <li>Klik IP Address yang ingin di binding.</li>
		      </ul>
		    <?php 
				} else { ?>
		      <ul>
		        <li>Go to Hosts menu.</li>
		        <li>Click the IP Address that you want to binding.</li>
		      </ul>
		    <?php 
				} ?>
	</p>
  </div>
</div>
</div>
</div>
