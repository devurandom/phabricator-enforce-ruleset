#!/usr/bin/php
<?php

assert_options(ASSERT_BAIL, 1);
define("ROOT", dirname(__FILE__));
define("BASENAME", basename(__FILE__, ".php"));

// LOAD PHABRICATOR

define("PHABRICATOR_ROOT", ROOT . "/../phabricator");
require_once PHABRICATOR_ROOT  . "/scripts/__init_script__.php";

// LOAD UTILITY FUNCTIONS

require_once ROOT . "/" . BASENAME . ".inc.php";

// LOAD CONFIG

require_once ROOT . "/" . BASENAME . ".cfg";

assert(PHAB_ADMIN_USERNAME !== null);
assert(PHAB_USER_POLICY_APPLIED_FIELD !== null);

// START

if (DRY_RUN) {
	debug(">>> DRY RUN <<<\n");
}

// PHABRICATOR

$phab_admin = id(new PhabricatorPeopleQuery())
	->setViewer(PhabricatorUser::getOmnipotentUser())
	->withUsernames(array(PHAB_ADMIN_USERNAME))
	->executeOne();
assert($phab_admin !== null);

$phab = array(
	"admin" => $phab_admin,
	"user_policy_applied_field" => PHAB_USER_POLICY_APPLIED_FIELD,
);

$phab_users = id(new PhabricatorPeopleQuery())
		->setViewer($phab_admin)
		->withIsSystemAgent(false)
		->withIsMailingList(false)
		->execute();

apply_policies_to_users($phab_users, $phab);
