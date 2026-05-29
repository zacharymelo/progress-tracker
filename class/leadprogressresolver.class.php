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
 *  \file       class/leadprogressresolver.class.php
 *  \ingroup    orderprogress
 *  \brief      Resolver for lead/opportunity progress on project cards.
 *
 *  Reads pipeline stages from llx_c_lead_status (user-configurable dictionary),
 *  outbound contact actions from llx_actioncomm, and linked commercial documents
 *  (proposals, orders, invoices) attached to the project via fk_projet.
 *
 *  Step completion rules:
 *   - Contacted  : at least one outbound manual action (AC_TEL, AC_EMAIL, AC_RDV)
 *   - Pipeline stages : opp_status position has reached or passed this stage
 *   - PROPO stage (extra): a linked proposal must also exist and be sent
 *   - Terminal (Won/Lost): Won = pipeline WON + order or invoice exists;
 *                          Lost = pipeline LOST (red/blocked)
 */
class LeadProgressResolver
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message */
	public $error = '';

	/** @var string[] Error messages */
	public $errors = array();

	/** @var string Fixed flow identifier for debug panel compatibility */
	public $flow = 'lead';

	/** @var array Debug collection keyed by type */
	public $collected = array();

	/** @var int Third-party id of the project (to seed action/document create forms) */
	public $anchorSocid = 0;

	/** State constants — must match OrderProgressResolver for renderer compatibility */
	const STATE_COMPLETE = 'complete';
	const STATE_CURRENT  = 'current';
	const STATE_PENDING  = 'pending';
	const STATE_SKIPPED  = 'skipped';
	const STATE_BLOCKED  = 'blocked';

	/**
	 *  Action codes counted as outbound contact.
	 *  AC_EMAIL_IN (incoming e-mail) is intentionally excluded.
	 *  AC_OTH_AUTO (auto-generated system events) is excluded by type.
	 */
	private static $outboundCodes = array('AC_TEL', 'AC_EMAIL', 'AC_RDV');

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
	 *  Resolve lead progress steps for a project object.
	 *
	 *  @param  object  $project  Loaded Project object (must have ->id, ->fk_opp_status,
	 *                             ->opp_status_code, ->opp_percent)
	 *  @return array              Ordered normalized steps, or empty array on error.
	 */
	public function resolve($project)
	{
		global $langs;
		$langs->loadLangs(array('orderprogress@orderprogress'));

		if (!is_object($project) || empty($project->id)) {
			$this->error = 'Invalid project passed to LeadProgressResolver';
			return array();
		}

		$this->anchorSocid = 0;
		if (!empty($project->socid) && $project->socid > 0) {
			$this->anchorSocid = (int) $project->socid;
		} elseif (!empty($project->fk_soc) && $project->fk_soc > 0) {
			$this->anchorSocid = (int) $project->fk_soc;
		}

		// Load pipeline stages from the dictionary (user-configurable).
		$stages = $this->loadStages();
		if (empty($stages)) {
			return array();
		}

		// Collect documents and outreach data.
		$contacted = $this->hasOutboundContact((int) $project->id);
		$propals   = $this->loadLinkedPropals((int) $project->id);
		$orders    = $this->loadLinkedOrders((int) $project->id);
		$invoices  = $this->loadLinkedInvoices((int) $project->id);

		$this->collected = array(
			'stages'   => count($stages),
			'propals'  => count($propals),
			'orders'   => count($orders),
			'invoices' => count($invoices),
			'contacted'=> $contacted ? 1 : 0,
		);

		$currentPos = $this->currentStagePosition($project, $stages);
		$oppCode    = !empty($project->opp_status_code) ? $project->opp_status_code : '';

		$steps = array();

		// ------------------------------------------------------------------
		// Step 1: Contacted (virtual pre-pipeline step)
		// ------------------------------------------------------------------
		$steps[] = $this->makeStep(
			'contacted',
			'LeadProgressContacted',
			'LeadProgressContactedTodo',
			'actioncomm',
			$contacted ? self::STATE_COMPLETE : self::STATE_PENDING,
			null
		);

		// ------------------------------------------------------------------
		// Dynamic pipeline stages (WON and LOST are the terminal step below)
		// ------------------------------------------------------------------
		foreach ($stages as $stage) {
			if (in_array($stage->code, array('WON', 'LOST'))) {
				continue;
			}

			$reached = ($currentPos !== null && $currentPos >= (int) $stage->position);

			// PROPO stage: reaching the pipeline position is not enough —
			// a linked sent proposal must also exist.
			if ($stage->code === 'PROPO' && $reached && empty($propals)) {
				$reached = false;
			}

			// Pick a source document for link/tooltip (PROPO → proposal).
			$doc     = null;
			$docType = 'project';
			if ($stage->code === 'PROPO' && !empty($propals)) {
				$doc     = reset($propals);
				$docType = 'propal';
			}

			// Stage label comes directly from the database (user-defined).
			// We use the same label for both done and todo states because stage
			// names ("Qualification", "Proposal") are already neutral milestones.
			$stageLabel = $stage->label;

			$steps[] = $this->makeStepRaw(
				'stage_'.$stage->code,
				$stageLabel,
				$stageLabel,
				$docType,
				$reached ? self::STATE_COMPLETE : self::STATE_PENDING,
				$doc
			);
		}

		// ------------------------------------------------------------------
		// Terminal step: Won / Lost (one step, two possible states)
		// ------------------------------------------------------------------
		$hasWinDoc  = (!empty($orders) || !empty($invoices));
		$winDoc     = !empty($orders) ? reset($orders) : (!empty($invoices) ? reset($invoices) : null);
		$winDocType = !empty($orders) ? 'commande' : 'facture';

		if ($oppCode === 'LOST') {
			$termState    = self::STATE_BLOCKED;
			$termLabelKey = 'LeadProgressLost';
			$termTodoKey  = 'LeadProgressLost';
			$termDoc      = null;
			$termDocType  = 'project';
		} elseif ($oppCode === 'WON' && $hasWinDoc) {
			$termState    = self::STATE_COMPLETE;
			$termLabelKey = 'LeadProgressWon';
			$termTodoKey  = 'LeadProgressWonNeedDoc';
			$termDoc      = $winDoc;
			$termDocType  = $winDocType;
		} elseif ($oppCode === 'WON' && !$hasWinDoc) {
			// Pipeline says Won but no order/invoice yet — needs document.
			$termState    = self::STATE_CURRENT;
			$termLabelKey = 'LeadProgressWon';
			$termTodoKey  = 'LeadProgressWonNeedDoc';
			$termDoc      = null;
			$termDocType  = 'commande';
		} else {
			$termState    = self::STATE_PENDING;
			$termLabelKey = 'LeadProgressWon';
			$termTodoKey  = 'LeadProgressCloseTodo';
			$termDoc      = null;
			$termDocType  = 'commande';
		}

		$steps[] = $this->makeStep(
			'close',
			$termLabelKey,
			$termTodoKey,
			$termDocType,
			$termState,
			($termState === self::STATE_COMPLETE) ? $termDoc : null
		);

		$steps = $this->markCurrent($steps);

		return $this->addActionLinks($steps, $project);
	}

	// -----------------------------------------------------------------------
	// Data loaders
	// -----------------------------------------------------------------------

	/**
	 *  Load all active pipeline stages from llx_c_lead_status, ordered by position.
	 *
	 *  @return object[]  Stage rows with ->rowid, ->code, ->label, ->position, ->percent
	 */
	private function loadStages()
	{
		$stages = array();
		$sql = "SELECT rowid, code, label, position, percent FROM ".MAIN_DB_PREFIX."c_lead_status"
			." WHERE active = 1 ORDER BY position ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			return array();
		}
		while ($obj = $this->db->fetch_object($res)) {
			$stages[] = $obj;
		}
		$this->db->free($res);
		return $stages;
	}

	/**
	 *  Return the position value of the project's current opportunity stage,
	 *  or null if no stage is set.
	 *
	 *  @param  object    $project  Project object
	 *  @param  object[]  $stages   Loaded stages
	 *  @return int|null             Position value or null
	 */
	private function currentStagePosition($project, $stages)
	{
		if (empty($project->fk_opp_status)) {
			return null;
		}
		foreach ($stages as $stage) {
			if ((int) $stage->rowid === (int) $project->fk_opp_status) {
				return (int) $stage->position;
			}
		}
		// Fallback: try matching by code
		if (!empty($project->opp_status_code)) {
			foreach ($stages as $stage) {
				if ($stage->code === $project->opp_status_code) {
					return (int) $stage->position;
				}
			}
		}
		return null;
	}

	/**
	 *  Return true if at least one outbound manual contact action is logged
	 *  against this project (AC_TEL, AC_EMAIL, AC_RDV).
	 *  Automated system events (type = 'systemauto') are excluded.
	 *
	 *  @param  int  $projectId  Project id
	 *  @return bool
	 */
	private function hasOutboundContact($projectId)
	{
		$codesEscaped = array();
		foreach (self::$outboundCodes as $c) {
			$codesEscaped[] = "'".$this->db->escape($c)."'";
		}
		$sql = "SELECT COUNT(a.rowid) as cnt"
			." FROM ".MAIN_DB_PREFIX."actioncomm a"
			." INNER JOIN ".MAIN_DB_PREFIX."c_actioncomm t ON t.id = a.fk_action"
			." WHERE a.fk_project = ".((int) $projectId)
			." AND t.code IN (".implode(',', $codesEscaped).")"
			." AND t.type != 'systemauto'";
		$res = $this->db->query($sql);
		if (!$res) {
			return false;
		}
		$obj = $this->db->fetch_object($res);
		$this->db->free($res);
		return ($obj && $obj->cnt > 0);
	}

	/**
	 *  Load sent proposals linked to this project (status >= Propal::STATUS_VALIDATED = 1).
	 *
	 *  @param  int  $projectId  Project id
	 *  @return object[]          Minimal proposal rows
	 */
	private function loadLinkedPropals($projectId)
	{
		return $this->queryLinked('propal', 'fk_projet', $projectId, 'fk_statut >= 1');
	}

	/**
	 *  Load customer orders linked to this project.
	 *
	 *  @param  int  $projectId  Project id
	 *  @return object[]
	 */
	private function loadLinkedOrders($projectId)
	{
		return $this->queryLinked('commande', 'fk_projet', $projectId);
	}

	/**
	 *  Load customer invoices linked to this project.
	 *
	 *  @param  int  $projectId  Project id
	 *  @return object[]
	 */
	private function loadLinkedInvoices($projectId)
	{
		return $this->queryLinked('facture', 'fk_projet', $projectId);
	}

	/**
	 *  Generic direct-FK query helper.
	 *
	 *  @param  string       $table      Dolibarr table name (without prefix)
	 *  @param  string       $fkField    FK field in that table
	 *  @param  int          $projectId  Project id
	 *  @param  string|null  $extra      Optional extra WHERE clause
	 *  @return object[]                  Rows with rowid, ref, fk_statut/status, date
	 */
	private function queryLinked($table, $fkField, $projectId, $extra = null)
	{
		$rows = array();
		$sql  = "SELECT rowid, ref, fk_statut as status"
			." FROM ".MAIN_DB_PREFIX.$table
			." WHERE ".$fkField." = ".((int) $projectId)
			." AND entity = ".((int) (isset($GLOBALS['conf']->entity) ? $GLOBALS['conf']->entity : 1));
		if ($extra) {
			$sql .= " AND ".$extra;
		}
		$sql .= " ORDER BY rowid ASC";
		$res  = $this->db->query($sql);
		if (!$res) {
			return array();
		}
		while ($obj = $this->db->fetch_object($res)) {
			$rows[] = $obj;
		}
		$this->db->free($res);
		return $rows;
	}

	// -----------------------------------------------------------------------
	// Action links
	// -----------------------------------------------------------------------

	/**
	 *  Attach action links (deep-links to native Dolibarr pages) to open steps.
	 *
	 *  @param  array   $steps    Steps array
	 *  @param  object  $project  Project object
	 *  @return array              Steps with action_url / action_perm added
	 */
	private function addActionLinks($steps, $project)
	{
		$pid  = (int) $project->id;
		$soc  = $this->anchorSocid;
		$socP = $soc > 0 ? '&socid='.$soc : '';

		// Find first linked document ids for seeding create forms.
		$propalId = 0;
		$orderId  = 0;
		if (!empty($this->collected)) {
			// Re-query minimally for IDs (collected only has counts).
			$row = $this->queryLinked('propal', 'fk_projet', $pid, 'fk_statut >= 1');
			if (!empty($row)) {
				$propalId = (int) reset($row)->rowid;
			}
			$row = $this->queryLinked('commande', 'fk_projet', $pid);
			if (!empty($row)) {
				$orderId = (int) reset($row)->rowid;
			}
		}

		$map = array(
			'contacted' => array(
				'url'  => '/comm/action/card.php?action=create&fk_project='.$pid.$socP,
				'perm' => array(array('agenda', 'myactions', 'create'), array('agenda', 'allactions', 'create')),
			),
			'stage_PROPO' => (!$propalId) ? array(
				'url'  => '/comm/propal/card.php?action=create&fk_projet='.$pid.$socP,
				'perm' => array(array('propal', 'creer')),
			) : null,
			'close' => (!$orderId && $soc > 0) ? array(
				'url'  => '/commande/card.php?action=create&fk_projet='.$pid.$socP,
				'perm' => array(array('commande', 'creer')),
			) : ($orderId ? array(
				'url'  => '/commande/card.php?id='.$orderId,
				'perm' => array(array('commande', 'lire')),
			) : null),
		);

		foreach ($steps as &$step) {
			if (isset($map[$step['key']]) && is_array($map[$step['key']])) {
				$step['action_url']  = DOL_URL_ROOT.$map[$step['key']]['url'];
				$step['action_perm'] = $map[$step['key']]['perm'];
			}
		}
		unset($step);

		return $steps;
	}

	// -----------------------------------------------------------------------
	// markCurrent — same first-pending-from-start algorithm as OrderProgressResolver
	// -----------------------------------------------------------------------

	/**
	 *  Promote the first PENDING step (from the start) to CURRENT.
	 *  An existing CURRENT step (e.g. Won-needs-doc) halts the scan.
	 *
	 *  @param  array  $steps
	 *  @return array
	 */
	private function markCurrent($steps)
	{
		foreach ($steps as $i => $s) {
			if ($s['state'] === self::STATE_CURRENT) {
				break;
			}
			if ($s['state'] === self::STATE_PENDING) {
				$steps[$i]['state'] = self::STATE_CURRENT;
				break;
			}
		}
		return $steps;
	}

	// -----------------------------------------------------------------------
	// Step builders
	// -----------------------------------------------------------------------

	/**
	 *  Build a step using translation keys.
	 *
	 *  @param  string       $key          Stable step key
	 *  @param  string       $labelKeyDone Translation key for done state
	 *  @param  string       $labelKeyTodo Translation key for pending/current state
	 *  @param  string       $objectType   Normalized element type
	 *  @param  string       $state        One of the STATE_* constants
	 *  @param  object|null  $doc          Source document row (rowid + ref + status)
	 *  @return array                       Normalized step
	 */
	private function makeStep($key, $labelKeyDone, $labelKeyTodo, $objectType, $state, $doc)
	{
		global $langs;
		return $this->makeStepRaw(
			$key,
			$langs->trans($labelKeyDone),
			$langs->trans($labelKeyTodo),
			$objectType,
			$state,
			$doc
		);
	}

	/**
	 *  Build a step using raw label strings (used for dynamic database labels).
	 *
	 *  @param  string       $key        Stable step key
	 *  @param  string       $labelDone  Label for done state
	 *  @param  string       $labelTodo  Label for pending/current state
	 *  @param  string       $objectType Normalized element type
	 *  @param  string       $state      One of the STATE_* constants
	 *  @param  object|null  $doc        Source document row
	 *  @return array                     Normalized step
	 */
	private function makeStepRaw($key, $labelDone, $labelTodo, $objectType, $state, $doc)
	{
		$step = array(
			'key'         => $key,
			'label'       => $labelDone,
			'label_todo'  => $labelTodo,
			'state'       => $state,
			'object_type' => $objectType,
			'object_id'   => 0,
			'ref'         => '',
			'url'         => '',
			'date'        => null,
		);

		if (is_object($doc) && !empty($doc->rowid)) {
			$step['object_id'] = (int) $doc->rowid;
			$step['ref']       = !empty($doc->ref) ? $doc->ref : '';
			$step['url']       = $this->buildDocUrl($objectType, (int) $doc->rowid);
		}

		return $step;
	}

	/**
	 *  Resolve a document card URL for a normalized element type.
	 *
	 *  @param  string  $objectType  Normalized element key
	 *  @param  int     $id          Document id
	 *  @return string                Absolute URL or ''
	 */
	private function buildDocUrl($objectType, $id)
	{
		$paths = array(
			'propal'    => '/comm/propal/card.php?id=',
			'commande'  => '/commande/card.php?id=',
			'facture'   => '/compta/facture/card.php?id=',
			'project'   => '/projet/card.php?id=',
		);
		if (!isset($paths[$objectType])) {
			return '';
		}
		return DOL_URL_ROOT.$paths[$objectType].((int) $id);
	}
}
