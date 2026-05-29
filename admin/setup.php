<?php
/* Copyright (C) 2026 Order Progress Tracker contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       admin/setup.php
 *  \ingroup    orderprogress
 *  \brief      Admin configuration page for the OrderProgress module.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (web root)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php using relative path
$tmp = realpath(__FILE__);
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && !$res) {
	if (file_exists(substr($tmp, 0, $i)."/main.inc.php")) {
		$res = @include substr($tmp, 0, $i)."/main.inc.php";
		break;
	}
	$i--;
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/orderprogress/lib/orderprogress.lib.php');

// Translations
$langs->loadLangs(array("admin", "orderprogress@orderprogress"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action     = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// List of all configuration keys handled by this page, with their input type.
$boolKeys = array(
	'ORDERPROGRESS_ENABLE_ORDER',
	'ORDERPROGRESS_ENABLE_SUPPLIER_ORDER',
	'ORDERPROGRESS_ENABLE_PROPAL',
	'ORDERPROGRESS_ENABLE_SUPPLIER_PROPOSAL',
	'ORDERPROGRESS_ENABLE_INVOICE',
	'ORDERPROGRESS_ENABLE_SUPPLIER_INVOICE',
	'ORDERPROGRESS_ENABLE_RECEPTION',
	'ORDERPROGRESS_ENABLE_SHIPMENT',
	'ORDERPROGRESS_CLICKABLE',
	'ORDERPROGRESS_ACTION_LINKS',
	'ORDERPROGRESS_DEBUG',
);
$colorKeys = array(
	'ORDERPROGRESS_COLOR_COMPLETE',
	'ORDERPROGRESS_COLOR_CURRENT',
	'ORDERPROGRESS_COLOR_PENDING',
	'ORDERPROGRESS_COLOR_SKIPPED',
	'ORDERPROGRESS_COLOR_BLOCKED',
);


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;

	foreach ($boolKeys as $key) {
		$val = GETPOST($key, 'alpha') ? '1' : '0';
		if (dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	// Display mode (full|compact)
	$mode = GETPOST('ORDERPROGRESS_DISPLAY_MODE', 'alpha');
	if (!in_array($mode, array('full', 'compact'))) {
		$mode = 'full';
	}
	if (dolibarr_set_const($db, 'ORDERPROGRESS_DISPLAY_MODE', $mode, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	// Skipped behavior (show|hide)
	$skip = GETPOST('ORDERPROGRESS_SKIPPED_BEHAVIOR', 'alpha');
	if (!in_array($skip, array('show', 'hide'))) {
		$skip = 'show';
	}
	if (dolibarr_set_const($db, 'ORDERPROGRESS_SKIPPED_BEHAVIOR', $skip, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	// Colors (validate against a safe token to avoid CSS injection)
	foreach ($colorKeys as $key) {
		$val = trim(GETPOST($key, 'alpha'));
		if ($val !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\([0-9,\s\.%]+\)$/', $val)) {
			$val = ''; // reject invalid value
		}
		if (dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("OrderProgressSetup");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-orderprogress page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = orderprogressAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("Module500120Name"), -1, 'order');

print '<span class="opacitymedium">'.$langs->trans("OrderProgressSetupPage").'</span><br><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

// --- Object types ---
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("OrderProgressEnabledObjects").'</td><td class="center" width="120">'.$langs->trans("Status").'</td></tr>';

$objectRows = array(
	'ORDERPROGRESS_ENABLE_ORDER'             => 'OrderProgressEnableOrder',
	'ORDERPROGRESS_ENABLE_SUPPLIER_ORDER'    => 'OrderProgressEnableSupplierOrder',
	'ORDERPROGRESS_ENABLE_PROPAL'            => 'OrderProgressEnablePropal',
	'ORDERPROGRESS_ENABLE_SUPPLIER_PROPOSAL' => 'OrderProgressEnableSupplierProposal',
	'ORDERPROGRESS_ENABLE_INVOICE'           => 'OrderProgressEnableInvoice',
	'ORDERPROGRESS_ENABLE_SUPPLIER_INVOICE'  => 'OrderProgressEnableSupplierInvoice',
	'ORDERPROGRESS_ENABLE_RECEPTION'         => 'OrderProgressEnableReception',
	'ORDERPROGRESS_ENABLE_SHIPMENT'          => 'OrderProgressEnableShipment',
);
foreach ($objectRows as $key => $label) {
	print '<tr class="oddeven"><td>'.$langs->trans($label).'</td>';
	print '<td class="center">';
	print '<input type="checkbox" name="'.$key.'" value="1"'.(getDolGlobalString($key) ? ' checked' : '').'>';
	print '</td></tr>';
}
print '</table><br>';

// --- Display options ---
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("OrderProgressDisplayOptions").'</td><td width="220"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("OrderProgressDisplayMode").'</td><td>';
$modeval = getDolGlobalString('ORDERPROGRESS_DISPLAY_MODE', 'full');
print $form->selectarray('ORDERPROGRESS_DISPLAY_MODE', array(
	'full' => $langs->trans('OrderProgressDisplayModeFull'),
	'compact' => $langs->trans('OrderProgressDisplayModeCompact'),
), $modeval);
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("OrderProgressSkippedBehavior").'</td><td>';
$skipval = getDolGlobalString('ORDERPROGRESS_SKIPPED_BEHAVIOR', 'show');
print $form->selectarray('ORDERPROGRESS_SKIPPED_BEHAVIOR', array(
	'show' => $langs->trans('OrderProgressSkippedShow'),
	'hide' => $langs->trans('OrderProgressSkippedHide'),
), $skipval);
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("OrderProgressClickable").'</td><td>';
print '<input type="checkbox" name="ORDERPROGRESS_CLICKABLE" value="1"'.(getDolGlobalString('ORDERPROGRESS_CLICKABLE') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("OrderProgressActionLinks").'<br><span class="opacitymedium">'.$langs->trans("OrderProgressActionLinksHelp").'</span></td><td>';
print '<input type="checkbox" name="ORDERPROGRESS_ACTION_LINKS" value="1"'.(getDolGlobalString('ORDERPROGRESS_ACTION_LINKS') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("OrderProgressDebug").'</td><td>';
print '<input type="checkbox" name="ORDERPROGRESS_DEBUG" value="1"'.(getDolGlobalString('ORDERPROGRESS_DEBUG') ? ' checked' : '').'>';
print '</td></tr>';
print '</table><br>';

// --- Colors ---
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("OrderProgressColors").'</td><td width="220"></td></tr>';
$colorRows = array(
	'ORDERPROGRESS_COLOR_COMPLETE' => 'OrderProgressColorComplete',
	'ORDERPROGRESS_COLOR_CURRENT'  => 'OrderProgressColorCurrent',
	'ORDERPROGRESS_COLOR_PENDING'  => 'OrderProgressColorPending',
	'ORDERPROGRESS_COLOR_SKIPPED'  => 'OrderProgressColorSkipped',
	'ORDERPROGRESS_COLOR_BLOCKED'  => 'OrderProgressColorBlocked',
);
foreach ($colorRows as $key => $label) {
	print '<tr class="oddeven"><td>'.$langs->trans($label).'</td><td>';
	print '<input type="text" name="'.$key.'" value="'.dol_escape_htmltag(getDolGlobalString($key)).'" placeholder="#2e7d32" size="14">';
	print '</td></tr>';
}
print '<tr><td colspan="2"><span class="opacitymedium">'.$langs->trans("OrderProgressColorHelp").'</span></td></tr>';
print '</table>';

print $form->buttonsSaveCancel("Save", '');

print '</form>';

print dol_get_fiche_end();

// End of page
llxFooter();
$db->close();
