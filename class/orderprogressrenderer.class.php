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
 *  \file       class/orderprogressrenderer.class.php
 *  \ingroup    orderprogress
 *  \brief      UI renderer for the order progress tracker. Output only.
 */

/**
 *  OrderProgressRenderer
 *
 *  Turns a normalized list of steps (from OrderProgressResolver) into HTML.
 *  It only produces output: it reads no native objects and performs no writes.
 */
class OrderProgressRenderer
{
	/** @var bool Compact display mode */
	public $compact = false;

	/** @var bool Hide skipped steps entirely instead of showing them muted */
	public $hideSkipped = false;

	/** @var bool Render completed/linked steps as clickable links */
	public $clickable = true;

	/** @var bool Render current/pending steps as links to the next native action */
	public $actionLinks = true;

	/**
	 *  Render the tracker.
	 *
	 *  @param  array  $steps  Normalized steps from the resolver
	 *  @param  User   $user   Current user (for native permission checks on links)
	 *  @return string          HTML for the tracker (empty string if nothing to show)
	 */
	public function render($steps, $user)
	{
		global $langs;
		$langs->loadLangs(array('orderprogress@orderprogress'));

		if (empty($steps) || !is_array($steps)) {
			return '';
		}

		// Optionally drop skipped steps.
		$visible = array();
		foreach ($steps as $s) {
			if ($this->hideSkipped && $s['state'] === OrderProgressResolver::STATE_SKIPPED) {
				continue;
			}
			$visible[] = $s;
		}
		if (empty($visible)) {
			return '';
		}

		$classMode = $this->compact ? ' orderprogress-compact' : '';
		$out  = '<div class="orderprogress-tracker'.$classMode.'" role="list" aria-label="'.dol_escape_htmltag($langs->trans('OrderProgressTitle')).'">';

		$prevState = null;
		foreach ($visible as $idx => $step) {
			$out .= $this->renderStep($step, $user, ($idx > 0), $prevState);
			$prevState = $step['state'];
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 *  Render a single step (connector + circle + label), with tooltip and link.
	 *
	 *  @param  array  $step           Normalized step
	 *  @param  User   $user           Current user
	 *  @param  bool   $withConnector  Draw a connector line before this step
	 *  @return string                  HTML
	 */
	private function renderStep($step, $user, $withConnector, $prevState = null)
	{
		global $langs;

		$state = isset($step['state']) ? $step['state'] : OrderProgressResolver::STATE_PENDING;
		$stateClass = 'orderprogress-'.preg_replace('/[^a-z]/', '', $state);

		// Tooltip: ref, status and date when we have a source document.
		$tooltip = $this->buildTooltip($step);

		// The connector segment is "traveled" (green) only when BOTH the previous
		// step and the current step are done — avoids a false green re-appearing
		// after a pending gap (e.g. complete → pending → complete).
		$donePrev = in_array($prevState, array(OrderProgressResolver::STATE_COMPLETE, OrderProgressResolver::STATE_CURRENT));
		$doneSelf = in_array($state,     array(OrderProgressResolver::STATE_COMPLETE, OrderProgressResolver::STATE_CURRENT));
		$connectorClass = ($donePrev && $doneSelf) ? ' orderprogress-connector-complete' : '';

		$out = '<div class="orderprogress-step '.$stateClass.'" role="listitem"'
			.($tooltip ? ' title="'.dol_escape_htmltag($tooltip).'"' : '').'>';

		if ($withConnector) {
			$out .= '<span class="orderprogress-connector'.$connectorClass.'" aria-hidden="true"></span>';
		}

		$circleInner = $this->circleGlyph($state);

		// Decide the link target for this step:
		//  - completed steps link to the existing document (view),
		//  - current/pending steps link to the next native action (proceed).
		$href = '';
		$linkClass = 'orderprogress-link';

		$canView = $this->clickable
			&& !empty($step['url'])
			&& !empty($step['object_id'])
			&& $this->userCanView($step['object_type'], $user);

		$isOpen = in_array($state, array(OrderProgressResolver::STATE_CURRENT, OrderProgressResolver::STATE_PENDING));
		$canAct = $this->actionLinks
			&& $isOpen
			&& !empty($step['action_url'])
			&& $this->userCanAct(isset($step['action_perm']) ? $step['action_perm'] : array(), $user);

		if ($canAct) {
			$href = $step['action_url'];
			$linkClass .= ' orderprogress-action';
		} elseif ($canView) {
			$href = $step['url'];
		}

		$canLink = ($href !== '');
		if ($canLink) {
			$out .= '<a class="'.$linkClass.'" href="'.dol_escape_htmltag($href).'">';
		}

		// Use the "to-do" label for open steps (pending/current) so the wording
		// describes the action still needed, not the outcome that hasn't happened yet.
		$isOpen = in_array($state, array(OrderProgressResolver::STATE_CURRENT, OrderProgressResolver::STATE_PENDING));
		$label  = ($isOpen && !empty($step['label_todo'])) ? $step['label_todo'] : $step['label'];

		$out .= '<span class="orderprogress-circle" aria-hidden="true">'.$circleInner.'</span>';
		$out .= '<span class="orderprogress-label">'.dol_escape_htmltag($label);
		if (!$this->compact && !empty($step['ref'])) {
			$out .= '<span class="orderprogress-ref">'.dol_escape_htmltag($step['ref']).'</span>';
		}
		$out .= '</span>';

		if ($canLink) {
			$out .= '</a>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 *  Glyph shown inside the circle depending on state.
	 *
	 *  @param  string  $state  State constant
	 *  @return string           HTML glyph
	 */
	private function circleGlyph($state)
	{
		switch ($state) {
			case OrderProgressResolver::STATE_COMPLETE:
				return '<span class="orderprogress-glyph">&#10003;</span>'; // check mark
			case OrderProgressResolver::STATE_BLOCKED:
				return '<span class="orderprogress-glyph">&#33;</span>';    // exclamation
			case OrderProgressResolver::STATE_SKIPPED:
				return '<span class="orderprogress-glyph">&#8211;</span>';  // dash
			default:
				return '<span class="orderprogress-glyph"></span>';
		}
	}

	/**
	 *  Build the tooltip text for a step.
	 *
	 *  @param  array  $step  Normalized step
	 *  @return string         Tooltip text (may be empty)
	 */
	private function buildTooltip($step)
	{
		global $langs;

		$parts = array();
		if (!empty($step['ref'])) {
			$parts[] = $step['ref'];
		}
		if (!empty($step['status_label'])) {
			$parts[] = $step['status_label'];
		}
		if (!empty($step['date'])) {
			$parts[] = dol_print_date($step['date'], 'day');
		}
		if (empty($parts) && $step['state'] === OrderProgressResolver::STATE_SKIPPED) {
			return $langs->trans('OrderProgressNotApplicable');
		}
		return implode(' — ', $parts);
	}

	/**
	 *  Check whether the user satisfies any of the alternative permission specs
	 *  required to perform a step's next native action.
	 *
	 *  @param  array  $permList  List of perm specs, each an array of hasRight args
	 *  @param  User   $user      Current user
	 *  @return bool               True if the user may proceed with the action
	 */
	private function userCanAct($permList, $user)
	{
		if (!is_object($user) || empty($permList) || !is_array($permList)) {
			return false;
		}
		foreach ($permList as $perm) {
			if (!is_array($perm) || empty($perm)) {
				continue;
			}
			if (call_user_func_array(array($user, 'hasRight'), $perm)) {
				return true;
			}
		}
		return false;
	}

	/**
	 *  Native permission check before exposing a link to a document.
	 *
	 *  @param  string  $objectType  Normalized element key
	 *  @param  User    $user        Current user
	 *  @return bool                  True if the user may view that element
	 */
	private function userCanView($objectType, $user)
	{
		if (!is_object($user)) {
			return false;
		}

		switch ($objectType) {
			case 'propal':
				return $user->hasRight('propal', 'lire');
			case 'commande':
				return $user->hasRight('commande', 'lire');
			case 'facture':
				return $user->hasRight('facture', 'lire');
			case 'shipping':
				return $user->hasRight('expedition', 'lire');
			case 'reception':
				return $user->hasRight('reception', 'lire');
			case 'order_supplier':
				return $user->hasRight('fournisseur', 'commande', 'lire') || $user->hasRight('supplier_order', 'lire');
			case 'supplier_proposal':
				return $user->hasRight('supplier_proposal', 'lire');
			case 'invoice_supplier':
				return $user->hasRight('fournisseur', 'facture', 'lire') || $user->hasRight('supplier_invoice', 'lire');
			default:
				return false;
		}
	}
}
