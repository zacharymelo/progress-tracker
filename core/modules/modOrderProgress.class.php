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
 *  \file       core/modules/modOrderProgress.class.php
 *  \ingroup    orderprogress
 *  \brief      Description and activation file for module OrderProgress
 *
 *  Order Progress Tracker adds a visual progress tracker to the top of
 *  customer/supplier order, proposal, invoice, shipment and reception
 *  card pages. It is a read-only visual layer over Dolibarr's native
 *  document chain; it does not create a parallel workflow.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Class to describe and enable module OrderProgress
 */
class modOrderProgress extends DolibarrModules
{
	/**
	 *  Constructor. Define names, constants, directories, boxes, permissions
	 *
	 *  @param  DoliDB  $db  Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Id for module (must be unique). Use a free range to avoid collisions.
		$this->numero = 500120;

		// Key text used to identify module (for permissions, menus, etc.)
		$this->rights_class = 'orderprogress';

		// Family can be 'crm','financial','hr','projects','products','ecm',
		// 'technic','interface','other'. Order Progress is a UI layer.
		$this->family = "interface";
		$this->module_position = '90';

		// Module label (no space allowed), used if translation string
		// 'ModuleXXXName' (where XXX is value of numero) not found.
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleXXXDesc' not found.
		$this->description = "Visual progress tracker over the native Dolibarr document chain (orders, proposals, invoices, shipments, receptions).";
		$this->descriptionlong = "Order Progress Tracker displays circular step indicators at the top of order, proposal, invoice, shipment and reception card pages, derived entirely from native Dolibarr statuses and linked documents. It never duplicates native workflow logic and never modifies core files.";

		// Author
		$this->editor_name = 'Order Progress Tracker contributors';
		$this->editor_url = '';

		// Possible values for version are: 'development', 'experimental', 'dolibarr',
		// 'dolibarr_deprecated' or a version string like 'x.y.z'.
		$this->version = '1.2.1';

		// Key used in llx_const table to save module status enabled/disabled
		// (where MYMODULE is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		$this->picto = 'order';

		// Defined all module parts (triggers, login, substitutions, menus,
		// css, etc.) to install with module.
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 0,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory
			'barcode' => 0,
			// Set this to 1 if module has its own models directory
			'models' => 0,
			// Set this to 1 if module has its own theme directory
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(),
			// Hooks. Declare here all hook contexts the module reacts to. The
			// HookManager will load class/actions_orderprogress.class.php
			// (class ActionsOrderprogress) for these contexts.
			'hooks' => array(
				'ordercard',           // customer order card
				'ordersuppliercard',   // supplier order card
				'propalcard',          // customer proposal/quotation card
				'supplier_proposalcard', // supplier proposal/request card
				'invoicecard',         // customer invoice card
				'invoicesuppliercard', // supplier invoice card
				'receptioncard',       // supplier reception card
				'expeditioncard',      // shipment/delivery card
				'projectcard',         // project / lead card
			),
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		$this->dirs = array();

		// Config pages. Path to the admin configuration page.
		$this->config_page_url = array("setup.php@orderprogress");

		// Dependencies
		$this->hidden = false;
		$this->depends = array();   // works standalone; gracefully degrades
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("orderprogress@orderprogress");
		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(14, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants. Default configuration values set on activation.
		// Each entry: name, type, value, note, visible, entity, deleteonunactive.
		$this->const = array(
			array('ORDERPROGRESS_ENABLE_PROJECT', 'chaine', '1', 'Show lead tracker on project/opportunity card', 0),
			array('ORDERPROGRESS_ENABLE_ORDER', 'chaine', '1', 'Show tracker on customer order card', 0),
			array('ORDERPROGRESS_ENABLE_SUPPLIER_ORDER', 'chaine', '1', 'Show tracker on supplier order card', 0),
			array('ORDERPROGRESS_ENABLE_PROPAL', 'chaine', '1', 'Show tracker on customer proposal card', 0),
			array('ORDERPROGRESS_ENABLE_SUPPLIER_PROPOSAL', 'chaine', '1', 'Show tracker on supplier proposal card', 0),
			array('ORDERPROGRESS_ENABLE_INVOICE', 'chaine', '0', 'Show tracker on customer invoice card', 0),
			array('ORDERPROGRESS_ENABLE_SUPPLIER_INVOICE', 'chaine', '0', 'Show tracker on supplier invoice card', 0),
			array('ORDERPROGRESS_ENABLE_RECEPTION', 'chaine', '0', 'Show tracker on reception card', 0),
			array('ORDERPROGRESS_ENABLE_SHIPMENT', 'chaine', '0', 'Show tracker on shipment card', 0),
			array('ORDERPROGRESS_DISPLAY_MODE', 'chaine', 'full', 'Display mode: full or compact', 0),
			array('ORDERPROGRESS_SKIPPED_BEHAVIOR', 'chaine', 'show', 'Skipped steps: show or hide', 0),
			array('ORDERPROGRESS_CLICKABLE', 'chaine', '1', 'Completed steps link to the related document', 0),
			array('ORDERPROGRESS_ACTION_LINKS', 'chaine', '1', 'Current/pending steps link to the next native action', 0),
			array('ORDERPROGRESS_DEBUG', 'chaine', '0', 'Show debug output to admins only', 0),
			array('ORDERPROGRESS_COLOR_COMPLETE', 'chaine', '', 'Override color for completed steps (empty = theme)', 0),
			array('ORDERPROGRESS_COLOR_CURRENT', 'chaine', '', 'Override color for current step (empty = theme)', 0),
			array('ORDERPROGRESS_COLOR_PENDING', 'chaine', '', 'Override color for pending steps (empty = theme)', 0),
			array('ORDERPROGRESS_COLOR_SKIPPED', 'chaine', '', 'Override color for skipped steps (empty = theme)', 0),
			array('ORDERPROGRESS_COLOR_BLOCKED', 'chaine', '', 'Override color for blocked steps (empty = theme)', 0),
		);

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1); // unique permission id (e.g. 50012001)
		$this->rights[$r][1] = 'See the order progress tracker'; // label
		$this->rights[$r][3] = 1; // 1 = enabled by default for everyone
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';

		// Main menu entries: none. This module only injects UI via hooks.
		$this->menu = array();
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function adds constants, boxes, permissions and menus
	 *  (defined in constructor) into Dolibarr database.
	 *  It also creates data directories.
	 *
	 *  @param  string  $options  Options when enabling module ('', 'noboxes')
	 *  @return int                1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		// This module creates no database tables; it only reads native data.
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *  Function called when module is disabled.
	 *  Remove from database constants, boxes and permissions from
	 *  Dolibarr database. Data directories are not deleted.
	 *
	 *  @param  string  $options  Options when enabling module ('', 'noboxes')
	 *  @return int                1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}
