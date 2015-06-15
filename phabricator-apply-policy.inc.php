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

	if (!empty($mail_str) && !empty(DEBUG_MAILTO)) {
		mail(DEBUG_MAILTO, "Phabricator policy enforcer report", $mail_str, "From: " . DEBUG_MAILFROM . "\r\n");
	}
}
register_shutdown_function("send_report");

// PHABRICATOR

function initUserProfile($user, $viewer) {
	$profile = $user->loadUserProfile();
	$changed = false;

	if ($profile->getTitle() === null) {
		$profile->setTitle("");
		debug("  Initialised title: " . $profile->getTitle() . " for user: " . $user->getUsername() . "\n");
		$changed = true;
	}

	if ($profile->getBlurb() === null) {
		$profile->setBlurb("");
		debug("  Initialised blurb: " . $profile->getBlurb() . " for user: " . $user->getUsername() . "\n");
		$changed = true;
	}

	if ($changed) {
		$profile->save();
	}
}

function getField($object, $field_key, $viewer) {
	$role = PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS;
	$fields = PhabricatorCustomField::getObjectFields($object, $role)
		->setViewer($viewer)
		->readFieldsFromStorage($object)
		->getFields();

	return idx($fields, $field_key)
		->getProxy()
		->getFieldValue();
}


function buildSetFieldTransaction($object, $field_key, $field_value, $template, $viewer) {
	$role = PhabricatorCustomField::ROLE_APPLICATIONTRANSACTIONS;
	$fields = PhabricatorCustomField::getObjectFields($object, $role)
		->setViewer($viewer)
		->readFieldsFromStorage($object)
		->getFields();

	$field = idx($fields, $field_key);

	$transaction_type = $field->getApplicationTransactionType();
	$xaction = id(clone $template)
		->setTransactionType($transaction_type);

	if ($transaction_type == PhabricatorTransactions::TYPE_CUSTOMFIELD) {
		// For TYPE_CUSTOMFIELD transactions only, we provide the old value
		// as an input.
		$old_value = $field->getOldValueForApplicationTransactions();
		$xaction->setOldValue($old_value);
	}

	$field->getProxy()->setFieldValue($field_value);

	$new_value = $field->getNewValueForApplicationTransactions();
	$xaction->setNewValue($new_value);

	if ($transaction_type == PhabricatorTransactions::TYPE_CUSTOMFIELD) {
		// For TYPE_CUSTOMFIELD transactions, add the field key in metadata.
		$xaction->setMetadataValue('customfield:key', $field->getFieldKey());
	}

	$metadata = $field->getApplicationTransactionMetadata();
	foreach ($metadata as $key => $value) {
		$xaction->setMetadataValue($key, $value);
	}

	return $xaction;
}


function setUserField($user, $field_key, $field_value, $viewer) {
	initUserProfile($user, $viewer);

	$xactions = array();
	$transaction_template = new PhabricatorUserTransaction();

	$xactions[] = buildSetFieldTransaction($user, $field_key, $field_value, $transaction_template, $viewer);

	$editor = id(new PhabricatorUserProfileEditor())
		->setActor($viewer)
		->setContentSource(PhabricatorContentSource::newConsoleSource())
		->setContinueOnNoEffect(true)
		->setContinueOnMissingFields(true)
		->applyTransactions($user, $xactions);
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

		$editor = id(new PhabricatorProjectTransactionEditor())
			->setActor($viewer)
			->setContentSource(PhabricatorContentSource::newConsoleSource())
			->setContinueOnNoEffect(true)
			->setContinueOnMissingFields(true)
			->applyTransactions($project, $xactions);
	}
}


// APPLY POLICIES

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
			setUserField($user, $phab_user_policy_applied_field, POLICY_VERSION_MAX, $phab_admin);
		}
	}

	apply_policy_v1($phab);
}


// POLICY V1

$spielwiese_members_diff = array('+' => array(), '-' => array());

function apply_policy_v1_to_user($user) {
	global $spielwiese_members_diff;

	debug("Applying policy v1 to user: " . $user->getUsername() . "\n");

	$spielwiese_members_diff['+'][$user->getPHID()] = $user->getPHID();
}

function apply_policy_v1($phab) {
	global $spielwiese_members_diff;

	if (!empty($spielwiese_members_diff['+'])) {
		debug("Finalising policy v1\n");

		$phab_admin = $phab["admin"];
		$projectname = "Spielwiese";
		$project = id(new PhabricatorProjectQuery())
			->setViewer($phab_admin)
			->withNames(array($projectname))
			->executeOne();

		modifyProjectMembers($project, $spielwiese_members_diff, $phab_admin);
	}
}
