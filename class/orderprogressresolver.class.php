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
 *  \file       class/orderprogressresolver.class.php
 *  \ingroup    orderprogress
 *  \brief      Service class computing workflow progress from native data.
 */

/**
 *  OrderProgressResolver
 *
 *  Reads a native Dolibarr document and its linked documents, then returns a
 *  normalized list of steps describing where the document stands in the
 *  native business flow. It performs NO writes and creates NO parallel status.
 */
class OrderProgressResolver
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var string[] Error messages */
	public $errors = array();

	/** @var array<string,object[]> Documents collected by element type */
	public $collected = array();

	/** @var string 'customer' or 'supplier' */
	public $flow = '';

	/** @var int Third-party id of the anchor object (to seed create forms) */
	public $anchorSocid = 0;

	/** State constants */
	const STATE_COMPLETE = 'complete';
	const STATE_CURRENT  = 'current';
	const STATE_PENDING  = 'pending';
	const STATE_SKIPPED  = 'skipped';
	const STATE_BLOCKED  = 'blocked';

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
	 *  Read an object's status in a forward-compatible way. Dolibarr is moving
	 *  from the deprecated ->statut to ->status; we prefer ->status when set.
	 *
	 *  @param  object  $obj  Native object
	 *  @return int|null       Status value or null
	 */
	public static function statusOf($obj)
	{
		if (isset($obj->status) && $obj->status !== null && $obj->status !== '') {
			return (int) $obj->status;
		}
		if (isset($obj->statut) && $obj->statut !== null && $obj->statut !== '') {
			return (int) $obj->statut;
		}
		return null;
	}

	/**
	 *  Resolve progress steps for a given object.
	 *
	 *  @param  string  $objectType  Native element ('commande','propal','facture',
	 *                               'order_supplier','supplier_proposal',
	 *                               'invoice_supplier','reception','shipping')
	 *  @param  object  $object      Loaded native object (must have ->id)
	 *  @return array                Ordered array of normalized step arrays, or
	 *                               empty array on error / unsupported type.
	 */
	public function resolve($objectType, $object)
	{
		global $langs;
		$langs->loadLangs(array('orderprogress@orderprogress'));

		if (!is_object($object) || empty($object->id)) {
			$this->error = 'Invalid object passed to resolver';
			$this->errors[] = $this->error;
			return array();
		}

		$this->collected = array();
		$this->flow = $this->detectFlow($objectType);
		if (empty($this->flow)) {
			return array();
		}

		// Remember the third-party of the anchor object so we can seed native
		// "create" forms (action links) with the right customer/supplier.
		$this->anchorSocid = 0;
		if (isset($object->socid) && $object->socid > 0) {
			$this->anchorSocid = (int) $object->socid;
		} elseif (isset($object->fk_soc) && $object->fk_soc > 0) {
			$this->anchorSocid = (int) $object->fk_soc;
		}

		// Walk the native document chain, collecting every related document.
		$this->collectChain($object, 2, array());

		if ($this->flow === 'customer') {
			$steps = $this->buildCustomerSteps();
		} else {
			$steps = $this->buildSupplierSteps();
		}

		return $this->addActionLinks($steps);
	}

	/**
	 *  Decide whether the object belongs to the customer or supplier flow.
	 *
	 *  @param  string  $objectType  Native element name
	 *  @return string               'customer', 'supplier' or '' if unsupported
	 */
	public function detectFlow($objectType)
	{
		$customer = array('propal', 'commande', 'facture', 'shipping', 'expedition');
		$supplier = array('supplier_proposal', 'order_supplier', 'commande_fournisseur', 'invoice_supplier', 'facture_fourn', 'reception');

		if (in_array($objectType, $customer)) {
			return 'customer';
		}
		if (in_array($objectType, $supplier)) {
			return 'supplier';
		}
		return '';
	}

	/**
	 *  Recursively collect linked documents into $this->collected, indexed by
	 *  normalized element type then by object id. Depth-limited and cycle-safe.
	 *
	 *  @param  object  $object   Object to expand
	 *  @param  int     $depth    Remaining hops to follow
	 *  @param  array   $visited  Already-visited keys (by reference semantics via return)
	 *  @return void
	 */
	private function collectChain($object, $depth, $visited)
	{
		if (!is_object($object) || empty($object->id) || empty($object->element)) {
			return;
		}

		$type = $this->normalizeElement($object->element);
		$key = $type.'_'.$object->id;
		if (isset($visited[$key])) {
			return;
		}
		$visited[$key] = true;

		if (!isset($this->collected[$type])) {
			$this->collected[$type] = array();
		}
		$this->collected[$type][$object->id] = $object;

		if ($depth <= 0) {
			return;
		}

		// fetchObjectLinked() loads both source and target linked objects into
		// $object->linkedObjects[elementtype] = array of fetched objects.
		$object->linkedObjects = array();
		$res = @$object->fetchObjectLinked();
		if ($res < 0 || empty($object->linkedObjects) || !is_array($object->linkedObjects)) {
			return;
		}

		foreach ($object->linkedObjects as $linktype => $linkedlist) {
			if (!is_array($linkedlist)) {
				continue;
			}
			foreach ($linkedlist as $linked) {
				$this->collectChain($linked, $depth - 1, $visited);
				// $visited mutates locally inside recursion; re-mark here so
				// siblings within this loop are not re-expanded redundantly.
				if (is_object($linked) && !empty($linked->id) && !empty($linked->element)) {
					$visited[$this->normalizeElement($linked->element).'_'.$linked->id] = true;
				}
			}
		}
	}

	/**
	 *  Normalize the many Dolibarr element/link names into stable keys.
	 *
	 *  @param  string  $element  Raw ->element or link type
	 *  @return string            Normalized key
	 */
	public function normalizeElement($element)
	{
		$map = array(
			'expedition'           => 'shipping',
			'commande_fournisseur' => 'order_supplier',
			'commandefournisseur'  => 'order_supplier',
			'facture_fourn'        => 'invoice_supplier',
			'facturefourn'         => 'invoice_supplier',
			'supplier_order'       => 'order_supplier',
		);
		return isset($map[$element]) ? $map[$element] : $element;
	}

	/**
	 *  Return the collected objects for a normalized element type.
	 *
	 *  @param  string  $type  Normalized element key
	 *  @return object[]        List of objects (possibly empty)
	 */
	private function docs($type)
	{
		return isset($this->collected[$type]) ? array_values($this->collected[$type]) : array();
	}

	/**
	 *  Build a reference descriptor (ref/url/date) for the first matching doc.
	 *
	 *  @param  object[]  $list      Candidate documents
	 *  @param  callable  $predicate Optional filter; first passing doc is used
	 *  @return array                 array(object|null, ref, date)
	 */
	private function pickDoc($list, $predicate = null)
	{
		foreach ($list as $doc) {
			if ($predicate === null || call_user_func($predicate, $doc)) {
				return $doc;
			}
		}
		return null;
	}

	/**
	 *  Build customer-side steps: proposal -> order -> shipment -> invoice -> paid -> closed.
	 *
	 *  @return array  Ordered normalized steps
	 */
	private function buildCustomerSteps()
	{
		global $langs;

		$propals    = $this->docs('propal');
		$orders     = $this->docs('commande');
		$shipments  = $this->docs('shipping');
		$invoices   = $this->docs('facture');

		$steps = array();

		// If no proposal exists but an order does, the proposal stage was bypassed
		// entirely — mark those steps skipped rather than pending.
		$proposalSkipped = empty($propals) && !empty($orders);

		// 1. Proposal created
		$p = $this->pickDoc($propals);
		$steps[] = $this->makeStep('proposal_created', 'OrderProgressProposalCreated', 'propal',
			$p ? self::STATE_COMPLETE : ($proposalSkipped ? self::STATE_SKIPPED : self::STATE_PENDING), $p);

		// 2. Proposal signed/accepted
		$pSigned = $this->pickDoc($propals, function ($o) {
			$s = OrderProgressResolver::statusOf($o);
			return ($s === 2 /*Propal::STATUS_SIGNED*/ || $s === 4 /*Propal::STATUS_BILLED*/);
		});
		$steps[] = $this->makeStep('proposal_signed', 'OrderProgressProposalSigned', 'propal',
			$pSigned ? self::STATE_COMPLETE : ($proposalSkipped ? self::STATE_SKIPPED : self::STATE_PENDING),
			$pSigned ? $pSigned : $p);

		// 3. Order created
		$o = $this->pickDoc($orders);
		$steps[] = $this->makeStep('order_created', 'OrderProgressOrderCreated', 'commande',
			$o ? self::STATE_COMPLETE : self::STATE_PENDING, $o);

		// 4. Order validated (Commande::STATUS_VALIDATED=1 and beyond, not canceled=-1)
		$oValid = $this->pickDoc($orders, function ($x) {
			$s = OrderProgressResolver::statusOf($x);
			return ($s !== null && $s >= 1);
		});
		$steps[] = $this->makeStep('order_validated', 'OrderProgressOrderValidated', 'commande',
			$oValid ? self::STATE_COMPLETE : self::STATE_PENDING, $oValid ? $oValid : $o);

		// 5. Shipment / delivery completed (only relevant when products require it)
		$needsShipment = $this->orderNeedsShipment($orders);
		$shipDone = $this->pickDoc($shipments, function ($x) {
			$s = OrderProgressResolver::statusOf($x);
			return ($s !== null && $s >= 2 /*Expedition::STATUS_CLOSED*/);
		});
		$shipAny = $this->pickDoc($shipments);
		if (!empty($shipments) || $needsShipment) {
			$state = $shipDone ? self::STATE_COMPLETE : self::STATE_PENDING;
			$steps[] = $this->makeStep('shipment_done', 'OrderProgressShipmentDone', 'shipping',
				$state, $shipDone ? $shipDone : $shipAny);
		} else {
			$steps[] = $this->makeStep('shipment_done', 'OrderProgressShipmentDone', 'shipping',
				self::STATE_SKIPPED, null);
		}

		// 6. Invoice created
		$inv = $this->pickDoc($invoices);
		$steps[] = $this->makeStep('invoice_created', 'OrderProgressInvoiceCreated', 'facture',
			$inv ? self::STATE_COMPLETE : self::STATE_PENDING, $inv);

		// 7. Invoice paid (paid / partially paid / unpaid)
		$invPaid = $this->pickDoc($invoices, function ($x) {
			return (!empty($x->paye));
		});
		$invPartial = $this->pickDoc($invoices, function ($x) {
			return (empty($x->paye) && property_exists($x, 'totalpaid') && $x->totalpaid > 0);
		});
		if ($invPaid) {
			$paidState = self::STATE_COMPLETE;
			$paidDoc = $invPaid;
		} elseif ($invPartial) {
			$paidState = self::STATE_CURRENT;
			$paidDoc = $invPartial;
		} else {
			$paidState = self::STATE_PENDING;
			$paidDoc = $inv;
		}
		$steps[] = $this->makeStep('invoice_paid', 'OrderProgressInvoicePaid', 'facture',
			$paidState, $paidDoc);

		// 8. Order closed
		$oClosed = $this->pickDoc($orders, function ($x) {
			return (OrderProgressResolver::statusOf($x) === 3 /*Commande::STATUS_CLOSED*/);
		});
		$steps[] = $this->makeStep('order_closed', 'OrderProgressOrderClosed', 'commande',
			$oClosed ? self::STATE_COMPLETE : self::STATE_PENDING, $oClosed ? $oClosed : $o);

		return $this->markCurrent($steps);
	}

	/**
	 *  Build supplier-side steps: request -> order -> approve -> send -> reception -> invoice -> paid -> closed.
	 *
	 *  @return array  Ordered normalized steps
	 */
	private function buildSupplierSteps()
	{
		$proposals  = $this->docs('supplier_proposal');
		$orders     = $this->docs('order_supplier');
		$receptions = $this->docs('reception');
		$invoices   = $this->docs('invoice_supplier');

		$steps = array();

		// 1. Supplier proposal / request created (optional)
		$sp = $this->pickDoc($proposals);
		$steps[] = $this->makeStep('supplier_proposal_created', 'OrderProgressSupplierProposalCreated', 'supplier_proposal',
			$sp ? self::STATE_COMPLETE : self::STATE_SKIPPED, $sp);

		// 2. Supplier order created
		$o = $this->pickDoc($orders);
		$steps[] = $this->makeStep('order_created', 'OrderProgressSupplierOrderCreated', 'order_supplier',
			$o ? self::STATE_COMPLETE : self::STATE_PENDING, $o);

		// 3. Supplier order approved (CommandeFournisseur::STATUS_ACCEPTED=2 .. RECEIVED_COMPLETELY=5;
		//    excludes CANCELED=6, CANCELED_AFTER_ORDER=7, REFUSED=9)
		$oApproved = $this->pickDoc($orders, function ($x) {
			$s = OrderProgressResolver::statusOf($x);
			return ($s !== null && $s >= 2 && $s <= 5);
		});
		$steps[] = $this->makeStep('order_approved', 'OrderProgressSupplierOrderApproved', 'order_supplier',
			$oApproved ? self::STATE_COMPLETE : self::STATE_PENDING, $oApproved ? $oApproved : $o);

		// 4. Supplier order sent/placed (STATUS_ORDERSENT=3 .. RECEIVED_COMPLETELY=5)
		$oSent = $this->pickDoc($orders, function ($x) {
			$s = OrderProgressResolver::statusOf($x);
			return ($s !== null && $s >= 3 && $s <= 5);
		});
		$steps[] = $this->makeStep('order_sent', 'OrderProgressSupplierOrderSent', 'order_supplier',
			$oSent ? self::STATE_COMPLETE : self::STATE_PENDING, $oSent ? $oSent : $o);

		// 5. Reception completed (reception closed OR order received completely STATUS_RECEIVED_COMPLETELY=5)
		$recDone = $this->pickDoc($receptions, function ($x) {
			$s = OrderProgressResolver::statusOf($x);
			return ($s !== null && $s >= 2 /*Reception::STATUS_CLOSED*/);
		});
		$orderReceived = $this->pickDoc($orders, function ($x) {
			return (OrderProgressResolver::statusOf($x) === 5 /*STATUS_RECEIVED_COMPLETELY*/);
		});
		$recAny = $this->pickDoc($receptions);
		if (!empty($receptions) || $orderReceived) {
			$state = ($recDone || $orderReceived) ? self::STATE_COMPLETE : self::STATE_PENDING;
			$steps[] = $this->makeStep('reception_done', 'OrderProgressReception', 'reception',
				$state, $recDone ? $recDone : $recAny);
		} else {
			$steps[] = $this->makeStep('reception_done', 'OrderProgressReception', 'reception',
				self::STATE_PENDING, null);
		}

		// 6. Supplier invoice received
		$inv = $this->pickDoc($invoices);
		$steps[] = $this->makeStep('invoice_received', 'OrderProgressSupplierInvoiceReceived', 'invoice_supplier',
			$inv ? self::STATE_COMPLETE : self::STATE_PENDING, $inv);

		// 7. Supplier invoice paid
		$invPaid = $this->pickDoc($invoices, function ($x) {
			return (!empty($x->paye));
		});
		$steps[] = $this->makeStep('invoice_paid', 'OrderProgressSupplierInvoicePaid', 'invoice_supplier',
			$invPaid ? self::STATE_COMPLETE : self::STATE_PENDING, $invPaid ? $invPaid : $inv);

		// 8. Order closed (CommandeFournisseur::STATUS_RECEIVED_COMPLETELY=5 is the natural terminal state)
		$oClosed = $this->pickDoc($orders, function ($x) {
			return (OrderProgressResolver::statusOf($x) === 5);
		});
		$steps[] = $this->makeStep('order_closed', 'OrderProgressOrderClosed', 'order_supplier',
			$oClosed ? self::STATE_COMPLETE : self::STATE_PENDING, $oClosed ? $oClosed : $o);

		return $this->markCurrent($steps);
	}

	/**
	 *  Determine whether any collected customer order has a product line that
	 *  would require a shipment (product type 0). Falls back to false.
	 *
	 *  @param  object[]  $orders  Customer orders
	 *  @return bool                True if shipment is meaningful
	 */
	private function orderNeedsShipment($orders)
	{
		// If shipment-related modules are off, shipment step is not applicable.
		if (function_exists('isModEnabled') && !isModEnabled('expedition') && !isModEnabled('shipping')) {
			return false;
		}

		foreach ($orders as $order) {
			if (empty($order->lines) && method_exists($order, 'fetch_lines')) {
				@$order->fetch_lines();
			}
			if (!empty($order->lines) && is_array($order->lines)) {
				foreach ($order->lines as $line) {
					// product_type 0 = product (shippable), 1 = service
					if (isset($line->product_type) && $line->product_type == 0) {
						return true;
					}
					if (isset($line->fk_product_type) && $line->fk_product_type == 0) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 *  Compute the "current" step: the first pending step following the last
	 *  completed step. Skipped steps never become current.
	 *
	 *  @param  array  $steps  Steps with raw states
	 *  @return array           Steps with one possibly promoted to 'current'
	 */
	private function markCurrent($steps)
	{
		$lastComplete = -1;
		foreach ($steps as $i => $s) {
			if ($s['state'] === self::STATE_COMPLETE) {
				$lastComplete = $i;
			}
		}

		// Promote the first pending step after the last completed one.
		for ($i = $lastComplete + 1; $i < count($steps); $i++) {
			if ($steps[$i]['state'] === self::STATE_PENDING) {
				$steps[$i]['state'] = self::STATE_CURRENT;
				break;
			}
			// Skip over skipped/current steps to find the real next pending one.
			if ($steps[$i]['state'] === self::STATE_CURRENT) {
				break;
			}
		}

		return $steps;
	}

	/**
	 *  Return the id of the first collected document of a type matching an
	 *  optional predicate, or 0 when none is found.
	 *
	 *  @param  string         $type       Normalized element key
	 *  @param  callable|null  $predicate  Optional filter
	 *  @return int                         Document id or 0
	 */
	private function firstId($type, $predicate = null)
	{
		foreach ($this->docs($type) as $d) {
			if ($predicate === null || call_user_func($predicate, $d)) {
				return (int) $d->id;
			}
		}
		return 0;
	}

	/**
	 *  Attach an "action link" to each step so the user can proceed with the
	 *  next native action. These links only deep-link to native Dolibarr pages
	 *  (creation forms seeded by origin, or the document card where the native
	 *  button lives) — no action is performed and no native logic is duplicated.
	 *
	 *  Each step may gain:
	 *   - 'action_url'  : relative URL to the native page
	 *   - 'action_perm' : list of alternative permission specs; the renderer
	 *                     shows the link only if the user satisfies any of them.
	 *
	 *  @param  array  $steps  Steps from a build method
	 *  @return array           Steps with action links attached
	 */
	private function addActionLinks($steps)
	{
		$soc = $this->anchorSocid;
		$socParam = $soc > 0 ? '&socid='.$soc : '';

		if ($this->flow === 'customer') {
			$propalId = $this->firstId('propal');
			$signedPropalId = $this->firstId('propal', function ($o) {
				$s = OrderProgressResolver::statusOf($o);
				return ($s === 2 || $s === 4);
			});
			$orderId = $this->firstId('commande');
			$shipId  = $this->firstId('shipping');
			$invId   = $this->firstId('facture');
			$unpaidInvId = $this->firstId('facture', function ($o) {
				return empty($o->paye);
			});

			$pCreer = array(array('propal', 'creer'));
			$cCreer = array(array('commande', 'creer'));

			$map = array(
				'proposal_created' => array(
					'url' => '/comm/propal/card.php?action=create'.$socParam, 'perm' => $pCreer,
				),
				'proposal_signed' => $propalId ? array(
					'url' => '/comm/propal/card.php?id='.($signedPropalId ?: $propalId), 'perm' => $pCreer,
				) : null,
				'order_created' => array(
					'url' => '/commande/card.php?action=create'.($signedPropalId ? '&origin=propal&originid='.$signedPropalId.'&origin_id='.$signedPropalId : '').$socParam,
					'perm' => $cCreer,
				),
				'order_validated' => $orderId ? array(
					'url' => '/commande/card.php?id='.$orderId, 'perm' => $cCreer,
				) : null,
				'shipment_done' => (!$shipId && $orderId) ? array(
					'url' => '/expedition/card.php?action=create&origin=commande&origin_id='.$orderId, 'perm' => array(array('expedition', 'creer')),
				) : null,
				'invoice_created' => (!$invId && $orderId) ? array(
					'url' => '/compta/facture/card.php?action=create&origin=commande&originid='.$orderId.'&origin_id='.$orderId.$socParam, 'perm' => array(array('facture', 'creer')),
				) : null,
				'invoice_paid' => $invId ? array(
					'url' => '/compta/paiement/card.php?action=create&facid='.($unpaidInvId ?: $invId), 'perm' => array(array('facture', 'paiement'), array('facture', 'creer')),
				) : null,
				// order_closed: no action link — closing is done via the native
				// button on the order card itself; linking back to the same page
				// would be redundant.
				'order_closed' => null,
			);
		} else {
			$spId  = $this->firstId('supplier_proposal');
			$soId  = $this->firstId('order_supplier');
			$recId = $this->firstId('reception');
			$siId  = $this->firstId('invoice_supplier');
			$unpaidSiId = $this->firstId('invoice_supplier', function ($o) {
				return empty($o->paye);
			});

			$soCreer = array(array('fournisseur', 'commande', 'creer'), array('supplier_order', 'creer'));
			$siCreer = array(array('fournisseur', 'facture', 'creer'), array('supplier_invoice', 'creer'));
			$siLire  = array(array('fournisseur', 'facture', 'lire'), array('supplier_invoice', 'lire'));

			$map = array(
				'supplier_proposal_created' => array(
					'url' => '/supplier_proposal/card.php?action=create'.$socParam, 'perm' => array(array('supplier_proposal', 'creer')),
				),
				'order_created' => array(
					'url' => '/fourn/commande/card.php?action=create'.($spId ? '&origin=supplier_proposal&originid='.$spId.'&origin_id='.$spId : '').$socParam, 'perm' => $soCreer,
				),
				'order_approved' => $soId ? array(
					'url' => '/fourn/commande/card.php?id='.$soId, 'perm' => $soCreer,
				) : null,
				'order_sent' => $soId ? array(
					'url' => '/fourn/commande/card.php?id='.$soId, 'perm' => $soCreer,
				) : null,
				'reception_done' => (!$recId && $soId) ? array(
					'url' => '/reception/card.php?action=create&origin=order_supplier&origin_id='.$soId, 'perm' => array(array('reception', 'creer')),
				) : null,
				'invoice_received' => (!$siId && $soId) ? array(
					'url' => '/fourn/facture/card.php?action=create&origin=order_supplier&originid='.$soId.'&origin_id='.$soId.$socParam, 'perm' => $siCreer,
				) : null,
				'invoice_paid' => $siId ? array(
					'url' => '/fourn/facture/card.php?id='.($unpaidSiId ?: $siId), 'perm' => $siLire,
				) : null,
				'order_closed' => null,
			);
		}

		foreach ($steps as &$step) {
			if (isset($map[$step['key']]) && is_array($map[$step['key']])) {
				$step['action_url'] = DOL_URL_ROOT.$map[$step['key']]['url'];
				$step['action_perm'] = $map[$step['key']]['perm'];
			}
		}
		unset($step);

		return $steps;
	}

	/**
	 *  Build one normalized step descriptor.
	 *
	 *  @param  string       $key         Stable step key
	 *  @param  string       $labelKey    Translation key for the label
	 *  @param  string       $objectType  Normalized element type this step maps to
	 *  @param  string       $state       One of the STATE_* constants
	 *  @param  object|null  $doc         Source document, if any
	 *  @return array                      Normalized step
	 */
	private function makeStep($key, $labelKey, $objectType, $state, $doc)
	{
		global $langs;

		$step = array(
			'key'         => $key,
			'label'       => $langs->trans($labelKey),
			'state'       => $state,
			'object_type' => $objectType,
			'object_id'   => 0,
			'ref'         => '',
			'url'         => '',
			'date'        => null,
		);

		if (is_object($doc) && !empty($doc->id)) {
			$step['object_id'] = $doc->id;
			$step['ref'] = !empty($doc->ref) ? $doc->ref : '';
			$step['url'] = $this->buildDocUrl($objectType, $doc->id);
			$step['date'] = $this->extractDate($doc);
			$step['statut'] = self::statusOf($doc);
			$step['status_label'] = $this->safeStatusLabel($doc);
		}

		return $step;
	}

	/**
	 *  Resolve a relative URL to a document card for a normalized element type.
	 *
	 *  @param  string  $objectType  Normalized element key
	 *  @param  int     $id          Object id
	 *  @return string                Relative URL (DOL_URL_ROOT based) or ''
	 */
	private function buildDocUrl($objectType, $id)
	{
		$paths = array(
			'propal'            => '/comm/propal/card.php?id=',
			'commande'          => '/commande/card.php?id=',
			'facture'           => '/compta/facture/card.php?id=',
			'shipping'          => '/expedition/card.php?id=',
			'order_supplier'    => '/fourn/commande/card.php?id=',
			'supplier_proposal' => '/supplier_proposal/card.php?id=',
			'invoice_supplier'  => '/fourn/facture/card.php?id=',
			'reception'         => '/reception/card.php?id=',
		);
		if (!isset($paths[$objectType])) {
			return '';
		}
		return DOL_URL_ROOT.$paths[$objectType].((int) $id);
	}

	/**
	 *  Best-effort extraction of a meaningful date from a document.
	 *
	 *  @param  object  $doc  Document
	 *  @return int|null       Timestamp or null
	 */
	private function extractDate($doc)
	{
		foreach (array('date_validation', 'date', 'date_commande', 'datep', 'date_creation', 'datec') as $f) {
			if (!empty($doc->$f)) {
				return $doc->$f;
			}
		}
		return null;
	}

	/**
	 *  Safely obtain a short human status label without throwing if the object
	 *  does not implement getLibStatut().
	 *
	 *  @param  object  $doc  Document
	 *  @return string         Status label or ''
	 */
	private function safeStatusLabel($doc)
	{
		if (method_exists($doc, 'getLibStatut')) {
			try {
				return dol_string_nohtmltag($doc->getLibStatut(1));
			} catch (Exception $e) {
				return '';
			}
		}
		return '';
	}
}
