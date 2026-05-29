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
 *  \file       lib/orderprogress.lib.php
 *  \ingroup    orderprogress
 *  \brief      Library functions for the OrderProgress module admin pages.
 */

/**
 *  Prepare the array of tabs for the module admin area.
 *
 *  @return array  Array of tabs (head) for dol_get_fiche_head()
 */
function orderprogressAdminPrepareHead()
{
	global $langs, $conf;

	$langs->loadLangs(array('orderprogress@orderprogress'));

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/orderprogress/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/orderprogress/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'orderprogress@orderprogress');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'orderprogress@orderprogress', 'remove');

	return $head;
}
