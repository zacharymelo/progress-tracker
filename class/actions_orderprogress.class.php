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
 *  \file       class/actions_orderprogress.class.php
 *  \ingroup    orderprogress
 *  \brief      Hook handler that injects the progress tracker into card pages.
 */

dol_include_once('/orderprogress/class/orderprogressresolver.class.php');
dol_include_once('/orderprogress/class/orderprogressrenderer.class.php');

/**
 *  Class ActionsOrderprogress
 *
 *  Loaded by Dolibarr's HookManager for the contexts declared in the module
 *  descriptor. We use printCommonFooter (fired on every card page) and then
 *  relocate the rendered tracker to the top of the card via a tiny JS snippet,
 *  so we never modify core templates.
 */
class ActionsOrderprogress
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error code (or message) */
	public $error = '';

	/** @var string[] Errors */
	public $errors = array();

	/** @var array Hook results */
	public $results = array();

	/** @var string String to return as printed content */
	public $resprints;

	/**
	 *  Constructor
	 *
	 *  @param  DoliDB  $db  Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Map a native object element to its config enable flag and a human flow.
	 *
	 *  @param  string  $element  $object->element value
	 *  @return array|null         array('const'=>..., 'type'=>normalized element) or null
	 */
	private function mapElement($element)
	{
		$map = array(
			'commande'             => array('const' => 'ORDERPROGRESS_ENABLE_ORDER', 'type' => 'commande'),
			'order_supplier'       => array('const' => 'ORDERPROGRESS_ENABLE_SUPPLIER_ORDER', 'type' => 'order_supplier'),
			'commande_fournisseur' => array('const' => 'ORDERPROGRESS_ENABLE_SUPPLIER_ORDER', 'type' => 'order_supplier'),
			'propal'               => array('const' => 'ORDERPROGRESS_ENABLE_PROPAL', 'type' => 'propal'),
			'supplier_proposal'    => array('const' => 'ORDERPROGRESS_ENABLE_SUPPLIER_PROPOSAL', 'type' => 'supplier_proposal'),
			'facture'              => array('const' => 'ORDERPROGRESS_ENABLE_INVOICE', 'type' => 'facture'),
			'invoice_supplier'     => array('const' => 'ORDERPROGRESS_ENABLE_SUPPLIER_INVOICE', 'type' => 'invoice_supplier'),
			'facture_fourn'        => array('const' => 'ORDERPROGRESS_ENABLE_SUPPLIER_INVOICE', 'type' => 'invoice_supplier'),
			'reception'            => array('const' => 'ORDERPROGRESS_ENABLE_RECEPTION', 'type' => 'reception'),
			'shipping'             => array('const' => 'ORDERPROGRESS_ENABLE_SHIPMENT', 'type' => 'shipping'),
			'expedition'           => array('const' => 'ORDERPROGRESS_ENABLE_SHIPMENT', 'type' => 'shipping'),
		);
		return isset($map[$element]) ? $map[$element] : null;
	}

	/**
	 *  Read a module configuration constant with a default fallback.
	 *
	 *  @param  string  $name     Constant name
	 *  @param  string  $default  Default value
	 *  @return string             Value
	 */
	private function conf($name, $default = '')
	{
		global $conf;
		if (isset($conf->global->$name) && $conf->global->$name !== '') {
			return $conf->global->$name;
		}
		return $default;
	}

	/**
	 *  Hook fired during the main card form render. $object is reliably in scope
	 *  here; we inject a hidden div and relocate it to the top of the card with
	 *  a small jQuery snippet — keeping us out of core templates.
	 *
	 *  @param  array         $parameters   Hook parameters
	 *  @param  CommonObject  $object       Current page object (the document)
	 *  @param  string        $action       Current action
	 *  @param  HookManager   $hookmanager  Hook manager
	 *  @return int                          0 on success, <0 on error
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		// Dolibarr may not forward $object to executeHooks, or may pass a non-card
		// object (e.g. Form). Fall back to the page-global $object whenever the
		// passed-in one lacks a valid card element/id.
		$globalObj = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		if ((!is_object($object) || empty($object->element) || empty($object->id))
			&& $globalObj !== null && !empty($globalObj->element) && !empty($globalObj->id)) {
			$object = $globalObj;
		}

		// Only on a real document card with an id.
		if (!is_object($object) || empty($object->element) || empty($object->id)) {
			return 0;
		}

		// Guard against formObjectOptions being called more than once per request
		// for the same anchor object (can happen on expedition/shipment cards with
		// multiple third-party linked records).
		static $rendered = array();
		$renderKey = $object->element.':'.$object->id;
		if (isset($rendered[$renderKey])) {
			return 0;
		}
		$rendered[$renderKey] = true;

		$mapping = $this->mapElement($object->element);
		if ($mapping === null) {
			return 0;
		}

		// Respect per-object-type enable switch.
		if ($this->conf($mapping['const'], '0') != '1') {
			return 0;
		}

		// Native read permission for the current object type.
		if (!$this->userCanViewCurrent($object->element, $user)) {
			return 0;
		}

		$langs->loadLangs(array('orderprogress@orderprogress'));

		// Compute steps from native data.
		$resolver = new OrderProgressResolver($this->db);
		$steps = $resolver->resolve($mapping['type'], $object);
		if (empty($steps)) {
			return 0;
		}

		// Render.
		$renderer = new OrderProgressRenderer();
		$renderer->compact     = ($this->conf('ORDERPROGRESS_DISPLAY_MODE', 'full') === 'compact');
		$renderer->hideSkipped = ($this->conf('ORDERPROGRESS_SKIPPED_BEHAVIOR', 'show') === 'hide');
		$renderer->clickable   = ($this->conf('ORDERPROGRESS_CLICKABLE', '1') == '1');
		$renderer->actionLinks = ($this->conf('ORDERPROGRESS_ACTION_LINKS', '1') == '1');

		$html = $renderer->render($steps, $user);
		if (empty($html)) {
			return 0;
		}

		$out  = $this->stylesheet();
		$out .= $this->colorOverrides();

		// Hidden holder placed in the footer; JS moves it under the banner.
		$out .= '<div id="orderprogress-holder" style="display:none;">'.$html.'</div>';

		if ($this->conf('ORDERPROGRESS_DEBUG', '0') == '1' && !empty($user->admin)) {
			$out .= $this->debugPanel($resolver, $steps);
		}

		$out .= $this->relocationScript();

		$this->resprints = $out;
		return 0;
	}

	/**
	 *  Native read permission for the object currently displayed.
	 *
	 *  @param  string  $element  $object->element
	 *  @param  User    $user     Current user
	 *  @return bool               True if the user may see this card (and tracker)
	 */
	private function userCanViewCurrent($element, $user)
	{
		if (!is_object($user)) {
			return false;
		}
		switch ($element) {
			case 'commande':
				return $user->hasRight('commande', 'lire');
			case 'propal':
				return $user->hasRight('propal', 'lire');
			case 'facture':
				return $user->hasRight('facture', 'lire');
			case 'shipping':
			case 'expedition':
				return $user->hasRight('expedition', 'lire');
			case 'reception':
				return $user->hasRight('reception', 'lire');
			case 'order_supplier':
			case 'commande_fournisseur':
				return $user->hasRight('fournisseur', 'commande', 'lire') || $user->hasRight('supplier_order', 'lire');
			case 'supplier_proposal':
				return $user->hasRight('supplier_proposal', 'lire');
			case 'invoice_supplier':
			case 'facture_fourn':
				return $user->hasRight('fournisseur', 'facture', 'lire') || $user->hasRight('supplier_invoice', 'lire');
			default:
				return false;
		}
	}

	/**
	 *  <link> to the module stylesheet (loaded only on relevant card pages).
	 *
	 *  @return string  HTML
	 */
	private function stylesheet()
	{
		$url = dol_buildpath('/orderprogress/css/orderprogress.css', 1);
		return '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($url).'">'."\n";
	}

	/**
	 *  Inline CSS custom-property overrides for admin-configured colors. Empty
	 *  values fall back to the theme defaults declared in the stylesheet.
	 *
	 *  @return string  HTML <style> block (may be empty)
	 */
	private function colorOverrides()
	{
		$vars = array(
			'--orderprogress-complete' => $this->conf('ORDERPROGRESS_COLOR_COMPLETE', ''),
			'--orderprogress-current'  => $this->conf('ORDERPROGRESS_COLOR_CURRENT', ''),
			'--orderprogress-pending'  => $this->conf('ORDERPROGRESS_COLOR_PENDING', ''),
			'--orderprogress-skipped'  => $this->conf('ORDERPROGRESS_COLOR_SKIPPED', ''),
			'--orderprogress-blocked'  => $this->conf('ORDERPROGRESS_COLOR_BLOCKED', ''),
		);
		$decl = '';
		foreach ($vars as $name => $val) {
			$val = trim($val);
			// Allow only safe color tokens to avoid CSS injection.
			if ($val !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\([0-9,\s\.%]+\)$/', $val)) {
				$decl .= $name.':'.$val.';';
			}
		}
		if ($decl === '') {
			return '';
		}
		return '<style>.orderprogress-tracker{'.$decl.'}</style>'."\n";
	}

	/**
	 *  JS that relocates the hidden tracker to just below the card banner.
	 *
	 *  @return string  HTML <script> block
	 */
	private function relocationScript()
	{
		return "<script>\n"
			."jQuery(function(){\n"
			." var holder = jQuery('#orderprogress-holder');\n"
			." if (!holder.length) { return; }\n"
			." var content = holder.children('.orderprogress-tracker');\n"
			." if (!content.length) { holder.remove(); return; }\n"
			." var anchor = jQuery('div.arearef').first();\n"
			." if (anchor.length) { anchor.before(content); }\n"
			." else { var c = jQuery('div.fichecenter').first();\n"
			."   if (c.length) { c.prepend(content); }\n"
			."   else { jQuery('div.tabBar').first().prepend(content); } }\n"
			." holder.remove();\n"
			."});\n"
			."</script>\n";
	}

	/**
	 *  Admin-only debug panel summarizing what the resolver collected.
	 *
	 *  @param  OrderProgressResolver  $resolver  Resolver after resolve()
	 *  @param  array                  $steps     Computed steps
	 *  @return string                             HTML
	 */
	private function debugPanel($resolver, $steps)
	{
		$out = '<div class="orderprogress-debug"><strong>OrderProgress debug</strong> (flow: '.dol_escape_htmltag($resolver->flow).')<br>';
		$out .= 'Collected: ';
		$bits = array();
		foreach ($resolver->collected as $type => $list) {
			$bits[] = dol_escape_htmltag($type).'='.count($list);
		}
		$out .= ($bits ? implode(', ', $bits) : 'none').'<br>';
		foreach ($steps as $s) {
			$out .= dol_escape_htmltag($s['key'].' => '.$s['state'].(!empty($s['ref']) ? ' ('.$s['ref'].')' : '')).'<br>';
		}
		$out .= '</div>';
		return $out;
	}
}
