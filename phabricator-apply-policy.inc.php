<?php

define("POLICY_VERSION_MAX", 1);

$mail_str = "";

function debug($str) {
	global $mail_str;

	if (DEBUG) {
		print($str);
		$mail_str .= $str;
	}
}

function send_report() {
	global $mail_str;

	if (!empty($mail_str) && !empty(DEBUG_EMAIL)) {
		mail(DEBUG_EMAIL, "Phabricator policy enforcer report", $mail_str);
	}
}
register_shutdown_function("send_report");

// PHABRICATOR

function getField($object, $field_key, $viewer) {
	$fields = PhabricatorCustomField::getObjectFields($object, PhabricatorCustomField::ROLE_DEFAULT)
		->setViewer($viewer)
		->readFieldsFromStorage($object)
		->getFields();

	return idx($fields, $field_key)
		->getProxy()
		->getFieldValue();
}

function setField($object, $field_key, $field_value, $viewer) {
	$fields = PhabricatorCustomField::getObjectFields($object, PhabricatorCustomField::ROLE_DEFAULT)
		->setViewer($viewer)
		->readFieldsFromStorage($object)
		->getFields();

	return idx($fields, $field_key)
		->getProxy()
		->setFieldValue($field_value);
}

function modifyProjectMembers($project, $members_diff, $viewer) {
	$projectname = $project->getName();

	if (DEBUG && !empty($members_diff['+'])) {
		debug("Will add members to project '" . $projectname . "':\n");
		foreach ($members_diff['+'] as $memberphid) {
			$user = id(new PhabricatorPeopleQuery())
				->setViewer($viewer)
				->withPHIDs(array($memberphid))
				->executeOne();
			$username = $user->getUserName();
			debug("  " . $username . " (" . $memberphid . ")\n");
		}
	}

	if (DEBUG && !empty($members_diff['-'])) {
		debug("Will remove members from project '" . $projectname . "':\n");
		foreach ($members_diff['-'] as $memberphid) {
			$user = id(new PhabricatorPeopleQuery())
				->setViewer($viewer)
				->withPHIDs(array($memberphid))
				->executeOne();
			$username = $user->getUserName();
			debug("  " . $username . " (" . $memberphid . ")\n");
		}
	}

	if (!DRY_RUN) {
		$type_member = PhabricatorProjectProjectHasMemberEdgeType::EDGECONST;

		$xactions = array();
		$xactions[] = id(new PhabricatorProjectTransaction())
			->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
			->setMetadataValue('edge:type', $type_member)
			->setNewValue($members_diff);

		$editor = id(new PhabricatorProjectTransactionEditor($project))
			->setActor($viewer)
			->setContentSource(PhabricatorContentSource::newConsoleSource())
			->setContinueOnNoEffect(true)
			->setContinueOnMissingFields(true)
			->applyTransactions($project, $xactions);
	}
}

// UPDATE

function apply_policies_to_users($phab_users, $phab) {
	$phab_admin = $phab["admin"];
	$phab_user_policy_applied_field = $phab["user_policy_applied_field"];

	$groupdn_projectphid_map = array();
	$projectphid_groupdn_map = array();

	foreach ($phab_users as $user) {
		$policy_applied = getField($user, $phab_user_policy_applied_field, $phab_admin);
		switch ($policy_applied) {
			default:
			case 0: // update to v1
				apply_policy_v1_to_user($user, $phab);
			case POLICY_VERSION_MAX: // already up to date
		}

		if (!DRY_RUN) {
			setField($user, $phab_user_policy_applied_field, POLICY_VERSION_MAX, $phab_admin);
		}
	}

	apply_policy_v1($phab);
}

$spielwiese_members_diff = array('+' => array(), '-' => array());

function apply_policy_v1_to_user($user) {
	global $spielwiese_members_diff;
	$spielwiese_members_diff['+'][$user->getPHID()] = $user->getPHID();
}

function apply_policy_v1($phab) {
	global $spielwiese_members_diff;

	$phab_admin = $phab["admin"];
	$projectname = "Spielwiese";
	$project = id(new PhabricatorProjectQuery())
		->setViewer($phab_admin)
		->withNames(array($projectname))
		->executeOne();

	modifyProjectMembers($project, $spielwiese_members_diff, $phab_admin);
}
