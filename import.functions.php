<?php
/*
 * wpkgExpress : A web-based frontend to wpkg
 * Copyright 2009 Brian White
 *
 * This file is part of wpkgExpress.
 *
 * wpkgExpress is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * wpkgExpress is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with wpkgExpress.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
?>
<?php
include_once('wpkg_constants.php');

class WPKGImporter {

	// Caches that store id_text => pk values, for quick lookups and inserting new relationships between various models
	var $pkgIdCache = array();
	var $profIdCache = array();
	var $hostIdCache = array();
	
	// Single instance variable
	private static $instance;
	
	private function __construct() {}
	
	public static function getInstance() {
		if (!self::$instance)
			self::$instance = new WPKGImporter();
		return self::$instance;
	}

	function _array_change_key($orig, $new, &$array) {
		foreach ($array as $k => $v)
			$return[$k === $orig ? $new : $k] = $v;
		return $return;
	}

	function _array_change_key_r($orig, $new, &$array) {
		foreach ($array as $k => $v)
			$return[$k === $orig ? $new : $k] = (is_array($v) ? $this->_array_change_key_r($orig, $new, $v) : $v);
		return $return;
	}
	
	function _arrayify(&$array) {
		if (isset($array[0]) || empty($array)) return;
		$temp = array();
		
		foreach ($array as $k => $v)
			$temp[$k] = $v;
		
		foreach ($temp as $k => $v)
			unset($array[$k]);
			
		$array[0] = $temp;
	}

	function _saveVariables(&$variables, $ref_id, $ref_type) {
		$varInst =& ClassRegistry::init('Variable');
		
		$this->_arrayify($variables);
		for ($i=0; $i<count($variables); $i++) {
			$variables[$i]['ref_id'] = $ref_id;
			$variables[$i]['ref_type'] = $ref_type;
		}
		
		$varInst->create();
		$varInst->saveAll($variables, array('validate' => false));
	}
	
	/*******************************************************************************************************
	 * Begin Package-specific import methods                                                               *
	 *******************************************************************************************************/
	
	function importPackages($XMLfilename) {
		App::import('Xml');

		$parsed_xml =& new XML($XMLfilename);
		$parsed_xml = Set::reverse($parsed_xml);

		if (empty($parsed_xml) || !isset($parsed_xml['Packages']) || empty($parsed_xml['Packages']) ||
			!isset($parsed_xml['Packages']['Package']) || empty($parsed_xml['Packages']['Package']))
			return null;

		$parsed_xml['Packages']['Package'] = $this->_array_change_key_r("id", "id_text", $parsed_xml['Packages']['Package']);
		$parsed_xml['Packages']['Package'] = $this->_array_change_key_r("Check", "PackageCheck", $parsed_xml['Packages']['Package']);
		$parsed_xml['Packages']['Package'] = $this->_array_change_key_r("Exit", "ExitCode", $parsed_xml['Packages']['Package']);

		$messages = array();

		$this->_arrayify($parsed_xml['Packages']['Package']);
		for ($i=0; $i<count($parsed_xml['Packages']['Package']); $i++) {
			$package =& $parsed_xml['Packages']['Package'][$i];
			$this->_modifyPackage($package);

			$depends[$package['id_text']] = $this->_cutPackageDepends($package);

			if (array_key_exists('PackageCheck', $package)) {
				$checks = $package['PackageCheck'];
				unset($package['PackageCheck']);
			}
			if (array_key_exists('PackageAction', $package)) {
				$actions = $package['PackageAction'];
				unset($package['PackageAction']);
			}

			$pkgAttributes = $parsed_xml['Packages']['Package'][$i];
			$newPackage = array('Package' => $pkgAttributes);
			if (isset($checks)) {
				$newPackage['PackageCheck'] = $checks;
				unset($checks);
			}
			if (isset($actions)) {
				$newPackage['PackageAction'] = $actions;
				unset($actions);
			}

			$parsed_xml['Packages']['Package'][$i] = $newPackage;

			// Package validation
			$messages = array_merge_recursive($messages, $this->_validatePackage($package));

			// Package Action(s) validation
			$position = 1;
			if (array_key_exists('PackageAction', $package)) {
				$this->_arrayify($package['PackageAction']);
				foreach ($package['PackageAction'] as $action) {
					$messages = array_merge_recursive($messages, $this->_validateAction($action, $package['Package']['id_text'], $position));
					$position++;
				}
			}

			// Package Check(s) validation
			$position = 1;
			if (array_key_exists('PackageCheck', $package)) {
				$this->_arrayify($package['PackageCheck']);
				foreach ($package['PackageCheck'] as $check)
					$messages = array_merge_recursive($messages, $this->_validateCheck($check, $package['Package']['id_text'], $position));
			}
		}
		
		// (All) Package dependencies validation
		$messages = array_merge_recursive($messages, $this->_validatePackageDepends($depends));

		$ok = (empty($messages) || !isset($messages['Errors']) ? true : false);

		// Save to database if all is OK
		if ($ok) {
			$packages = $parsed_xml['Packages']['Package'];
			$this->_arrayify($packages);
			for ($i = 0; $i < count($packages); $i++)
				$this->_savePackage($packages[$i]);
			$this->_savePackageDepends($depends);
		}

		return ($ok ? true : $messages);
	}
	
	function _createActionType(&$package, $type) {
		if (isset($package[$type])) {
			$this->_arrayify($package[$type]);
			for ($i=0; $i<count($package[$type]); $i++)
				$package['PackageAction'][] = $this->_createActionType_worker($package[$type][$i], $type);
			unset($package[$type]);
		}
	}

	function _createActionType_worker(&$action, $type) {
		static $exitEnum = array(
			'false' => EXITCODE_REBOOT_FALSE,
			'true' => EXITCODE_REBOOT_TRUE,
			'delayed' => EXITCODE_REBOOT_DELAYED,
			'postponed' => EXITCODE_REBOOT_POSTPONED
		);
		$newaction = array('type' => constant('ACTION_TYPE_' . strtoupper($type)), 'command' => $action['cmd']);
		if (isset($action['timeout']))
			$newaction['timeout'] = $action['timeout'];
		if (isset($action['workdir']))
			$newaction['workdir'] = $action['workdir'];
		if (isset($action['ExitCode'])) {
			$this->_arrayify($action['ExitCode']);
			for ($j=0; $j<count($action['ExitCode']); $j++)
				$action['ExitCode'][$j]['reboot'] = Set::enum(strtolower($action['ExitCode'][$j]['reboot']), $exitEnum);
			$newaction['ExitCode'] = $action['ExitCode'];
		}
		return $newaction;
	}

	function _createAllActions(&$package) {
		$this->_createActionType($package, 'Install');
		$this->_createActionType($package, 'Upgrade');
		$this->_createActionType($package, 'Downgrade');
		$this->_createActionType($package, 'Remove');
	}

	function _cutPackageDepends(&$package) {
		$depends = array();

		if (isset($package['Depends'])) {
			$this->_arrayify($package['Depends']);
			for ($i=0; $i<count($package['Depends']); $i++)
				$depends[] = $package['Depends'][$i]['package-id'];
			unset($package['Depends']);
		}

		return $depends;
	}

	function _modifyChecks_r(&$array) {
		static $condEnum = array(
			'logical' => array(
				'not' => CHECK_CONDITION_LOGICAL_NOT,
				'and' => CHECK_CONDITION_LOGICAL_AND,
				'or' => CHECK_CONDITION_LOGICAL_OR,
				'atleast' => CHECK_CONDITION_LOGICAL_AT_LEAST,
				'atmost' => CHECK_CONDITION_LOGICAL_AT_MOST
			),
			'registry' => array(
				'exist' => CHECK_CONDITION_REGISTRY_EXISTS,
				'equals' => CHECK_CONDITION_REGISTRY_EQUALS,
			),
			'file' => array(
				'exists' => CHECK_CONDITION_FILE_EXISTS,
				'sizeequals' => CHECK_CONDITION_FILE_SIZE_EQUALS,
				'versionsmallerthan' => CHECK_CONDITION_FILE_VERSION_SMALLER_THAN,
				'versionlessorequal' => CHECK_CONDITION_FILE_VERSION_LESS_THAN_OR_EQUAL_TO,
				'versionequalto' => CHECK_CONDITION_FILE_VERSION_EQUAL_TO,
				'versiongreaterthan' => CHECK_CONDITION_FILE_VERSION_GREATER_THAN,
				'versiongreaterorequal' => CHECK_CONDITION_FILE_VERSION_GREATER_THAN_OR_EQUAL_TO
			),
			'execute' => array(
				'exitcodesmallerthan' => CHECK_CONDITION_EXECUTE_EXIT_CODE_SMALLER_THAN,
				'exitcodelessorequal' => CHECK_CONDITION_EXECUTE_EXIT_CODE_LESS_THAN_OR_EQUAL_TO,
				'exitcodeequalto' => CHECK_CONDITION_EXECUTE_EXIT_CODE_EQUAL_TO,
				'exitcodegreaterthan' => CHECK_CONDITION_EXECUTE_EXIT_CODE_GREATER_THAN,
				'exitcodegreaterorequal' => CHECK_CONDITION_EXECUTE_EXIT_CODE_GREATER_THAN_OR_EQUAL_TO
			),
			'uninstall' => array(
				'exists' => CHECK_CONDITION_UNINSTALL_EXISTS
			)
		);

		$array['condition'] = Set::enum(strtolower($array['condition']), $condEnum[strtolower($array['type'])]);
		$array['type'] = constant('CHECK_TYPE_' . strtoupper($array['type']));
		if (isset($array['Childcheck']) && ($array['type'] == CHECK_TYPE_LOGICAL)) {
			$this->_arrayify($array['Childcheck']);
			for ($i=0; $i<count($array['Childcheck']); $i++)
				$this->_modifyChecks_r($array['Childcheck'][$i]);
		}
	}

	function _modifyPackage(&$package) {
		$package['reboot'] = constant('PACKAGE_REBOOT_' . strtoupper($package['reboot']));

		if (isset($package['execute']))
			$package['execute'] = constant('PACKAGE_EXECUTE_' . strtoupper($package['execute']));

		if (isset($package['notify']))
			$package['notify'] = constant('PACKAGE_NOTIFY_' . strtoupper($package['notify']));

		if (isset($package['PackageCheck'])) {
			$package['PackageCheck'] = $this->_array_change_key_r("PackageCheck", "Childcheck", $package['PackageCheck']);
			$this->_arrayify($package['PackageCheck']);
			for ($j=0; $j<count($package['PackageCheck']); $j++)
				$this->_modifyChecks_r($package['PackageCheck'][$j]);
		}

		// TODO: handle download tags, for now just strip them if they exist
		if (isset($package['Download']))
			unset($package['Download']);
		$this->_createAllActions($package);
	}

	function _saveAction(&$action, $pkgId) {
		$pkgActInst =& ClassRegistry::init('PackageAction');

		$action['package_id'] = $pkgId;
		$pkgActInst->create();
		$pkgActInst->save($action, array('validate' => false));

		if (isset($action['ExitCode'])) {
			$exitCodeInst = ClassRegistry::init('ExitCode');

			$pkgActId = $pkgActInst->id;
			$exitCodes = $action['ExitCode'];
			$this->_arrayify($exitCodes);
			for ($i=0; $i<count($exitCodes); $i++)
				$exitCodes[$i]['package_action_id'] = $pkgActId;
			$exitCodeInst->saveAll($exitCodes, array('validate' => false));
		}
	}

	function _saveCheck(&$check, $pkgId, $parentCheckId) {
		$pkgChkInst =& ClassRegistry::init('PackageCheck');

		$check['package_id'] = $pkgId;
		if ($parentCheckId != null)
			$check['parent_id'] = $parentCheckId;

		$pkgChkInst->create();
		$pkgChkInst->save(array('PackageCheck' => $check), array('validate' => false));
		$pkgChkId = $pkgChkInst->id;

		if ($check['type'] == CHECK_TYPE_LOGICAL && isset($check['Childcheck'])) {
			$this->_arrayify($check['Childcheck']);
			foreach ($check['Childcheck'] as $child)
				$this->_saveCheck($child, $pkgId, $pkgChkId);
		}
	}

	function _savePackageDepends(&$pkgDepends) {
		$depends = array();
		$pkgDepInst =& ClassRegistry::init('PackagesPackage');

		if (!empty($pkgDepends)) {
			foreach ($pkgDepends as $pkgIdText => $dependlist) {
				foreach ($dependlist as $k => $dependency)
					$depends[] = array('package_id' => $this->pkgIdCache[$pkgIdText], 'dependency_id' => $this->pkgIdCache[$dependency]);
			}

			if (!empty($depends)) {
				$pkgDepInst->create();
				$pkgDepInst->saveAll($depends, array('validate' => false));
			}
		}
	}

	function _savePackage($pkgArray) {
		$pkgInst =& ClassRegistry::init('Package');

		if (isset($pkgArray['PackageCheck'])) {
			$checks = $pkgArray['PackageCheck'];
			unset($pkgArray['PackageCheck']);
		}
		if (isset($pkgArray['PackageAction'])) {
			$actions = $pkgArray['PackageAction'];
			unset($pkgArray['PackageAction']);
		}
		if (isset($pkgArray['Variable'])) {
			$vars = $pkgArray['Variable'];
			unset($pkgArray['Variable']);
		}

		$pkgInst->create();
		$pkgInst->save($pkgArray, array('validate' => false));
		$pkgId = $pkgInst->id;

		// Cache the new package ID
		$this->pkgIdCache[$pkgArray['Package']['id_text']] = $pkgId;

		if (isset($checks)) {
			$this->_arrayify($checks);
			for ($i = 0; $i < count($checks); $i++)
				$this->_saveCheck($checks[$i], $pkgId, null);
			unset($checks);
		}
		if (isset($actions)) {
			$this->_arrayify($actions);
			for ($i = 0; $i < count($actions); $i++)
				$this->_saveAction($actions[$i], $pkgId);
			unset($actions);
		}
		if (isset($vars)) {
			$this->_saveVariables($vars, $pkgId, VARIABLE_TYPE_PACKAGE);
			unset($vars);
		}
	}

	function _savePackageVariable(&$variable, $pkgId) {
		$variable['ref_id'] = $pkgId;
		$variable['ref_type'] = VARIABLE_TYPE_PACKAGE;
	}

	function _validateAction(&$action, $pkgIdText, $position) {
		$messages = array();
		$pkgActInst =& ClassRegistry::init('PackageAction');

		$pkgActInst->set(array('PackageAction' => $action));
		$result = $pkgActInst->validates();
		if (!$result)
			$messages['Errors'][$pkgIdText]['Actions'][$position] = array_values($pkgActInst->invalidFields());

		if (array_key_exists('ExitCode', $action)) {
			$exitCodePosition = 1;
			$this->_arrayify($action['ExitCode']);
			foreach ($action['ExitCode'] as $exitCode) {
				$msgs = $this->_validateExitCode($exitCode, $pkgIdText, $exitCodePosition);
				if (!empty($msgs))
					$messages['Errors'][$pkgIdText]['Actions'][$position]['Exit Codes'][$exitCodePosition] = $msgs;
				$exitCodePosition++;
			}
		}

		return $messages;
	}

	function _validateCheck(&$check, &$pkgIdText, &$position) {
		$messages = array();
		$pkgChkInst =& ClassRegistry::init('PackageCheck');

		$pkgChkInst->set(array('PackageCheck' => $check));
		$result = $pkgChkInst->validates();
		if (!$result)
			$messages['Errors'][$pkgIdText]['Checks'][$position] = array_values($pkgChkInst->invalidFields());
		if ($check['type'] == CHECK_TYPE_LOGICAL && isset($check['Childcheck'])) {
			$this->_arrayify($check['Childcheck']);
			foreach ($check['Childcheck'] as $child) {
				$position++;
				$messages = array_merge_recursive($messages, $this->_validateCheck($child, $pkgIdText, $position));
			}
		}
		return $messages;
	}

	function _validateExitCode(&$exitCode, $pkgIdText, $position) {
		$messages = array();
		$exitCodeInst =& ClassRegistry::init('ExitCode');

		$exitCodeInst->set(array('ExitCode' => $exitCode));
		$result = $exitCodeInst->validates();
		if (!$result)
			$messages[$position] = array_values($exitCodeInst->invalidFields());
			//$messages['Errors'][$pkgIdText]['ExitCode'][$position] = array_values($exitCodeInst->invalidFields());

		return $messages;
	}

	function _validatePackage(&$package) {
		$messages = array();
		$pkgInst =& ClassRegistry::init('Package');

		$pkgInst->set($package);
		$result = $pkgInst->validates();
		if (!$result)
			$messages['Errors'][$package['Package']['id_text']] = array_values($pkgInst->invalidFields());

		return $messages;
	}
	
	function _validatePackageDepends(&$pkgDepends) {
		$messages = array();
		$pkgInst =& ClassRegistry::init('Package');

		foreach ($pkgDepends as $pkgIdText => $dependlist) {
			foreach ($dependlist as $k => $dependency) {
				// check that we don't depend on ourself
				if ($dependency == $pkgIdText) {
					$messages['Warnings'][$pkgIdText][] = "Detected package dependency on self. Skipping.";
					unset($pkgDepends[$pkgIdText][$k]);
				} else if (empty($dependency)) {
					$messages['Warnings'][$pkgIdText][] = "Found package dependency with empty/invalid name. Skipping.";
					unset($pkgDepends[$pkgIdText][$k]);
				} else {
					// Check dependencies against locally defined packages
					if (!array_key_exists($dependency, $pkgDepends)) {
						// Check pre-existing packages in the database
						if (!($pkg = $pkgInst->find('first', array('fields' => array('Package.id'), 'conditions' => array('Package.id_text' => $dependency), 'recursive' => -1))))
							$messages['Errors'][$pkgIdText][] = "Could not find existing dependency package: $dependency";
						else {
							// Cache the package ID of this dependency from the DB
							$this->pkgIdCache[$dependency] = $pkg['Package']['id'];
						}
					}
				}
			}
		}

		return $messages;
	}
	
	/*******************************************************************************************************
	 * Begin Profile-specific import methods                                                               *
	 *******************************************************************************************************/
	
	function importProfiles($XMLfilename) {
		App::import('Xml');

		$parsed_xml =& new XML($XMLfilename);
		$parsed_xml = Set::reverse($parsed_xml);
		
		if (empty($parsed_xml) || !isset($parsed_xml['Profiles']) || empty($parsed_xml['Profiles'])
			|| !isset($parsed_xml['Profiles']['Profile']) || empty($parsed_xml['Profiles']['Profile']))
			return null;
			
		$messages = array();
		
		$parsed_xml['Profiles']['Profile'] = $this->_array_change_key_r("id", "id_text", $parsed_xml['Profiles']['Profile']);
		$this->_arrayify($parsed_xml['Profiles']['Profile']);
		for ($i=0; $i<count($parsed_xml['Profiles']['Profile']); $i++) {
			$profile =& $parsed_xml['Profiles']['Profile'][$i];
			
			$depends[$profile['id_text']] = $this->_cutProfileDepends($profile);
			$packages = $this->_cutProfilePackages($profile);
			
			// Validate Profile
			$messages = array_merge_recursive($messages, $this->_validateProfile($profile));
			
			// Validate Profile Packages
			$messages = array_merge_recursive($messages, $this->_validateProfilePackages($packages, $profile['id_text']));
			
			if (!empty($packages)) {
				$profile['Package'] = $packages;
				unset($packages);
			}
		}
		
		// (All) Profile dependencies validation
		$messages = array_merge_recursive($messages, $this->_validateProfileDepends($depends));
		
		$ok = (empty($messages) || !isset($messages['Errors']) ? true : false);

		// Save to database if all is OK
		if ($ok) {
			$profiles = $parsed_xml['Profiles']['Profile'];
			for ($i = 0; $i < count($profiles); $i++)
				$this->_saveProfile($profiles[$i]);
			$this->_saveProfileDepends($depends);
		}
		
		return ($ok ? true : $messages);
	}

	function _cutProfileDepends(&$profile) {
		$depends = array();

		if (isset($profile['Depends'])) {
			$this->_arrayify($profile['Depends']);
			for ($i=0; $i<count($profile['Depends']); $i++)
				$depends[] = $profile['Depends'][$i]['profile-id'];
			unset($profile['Depends']);
		}

		return $depends;
	}
	
	function _cutProfilePackages(&$profile) {
		$packages = array();

		if (isset($profile['Package'])) {
			$this->_arrayify($profile['Package']);
			for ($i=0; $i<count($profile['Package']); $i++)
				$packages[] = $profile['Package'][$i]['package-id'];
			unset($profile['Package']);
		}

		return $packages;
	}
	
	function _saveProfile(&$profArray) {
		$profInst =& ClassRegistry::init('Profile');

		if (isset($profArray['Package'])) {
			$packages = $profArray['Package'];
			unset($profArray['Package']);
		}
		if (isset($profArray['Variable'])) {
			$vars = $profArray['Variable'];
			unset($profArray['Variable']);
		}

		$profInst->create();
		$profInst->save($profArray, array('validate' => false));
		$profId = $profInst->id;

		// Cache the new profile ID
		$this->profIdCache[$profArray['id_text']] = $profId;

		if (isset($packages)) {
			$this->_saveProfilePackages($packages, $profArray['id_text']);
			unset($packages);
		}
		if (isset($vars)) {
			$this->_saveVariables($vars, $profId, VARIABLE_TYPE_PROFILE);
			unset($vars);
		}
	}
	
	function _saveProfileDepends(&$profDepends) {
		$depends = array();
		$profDepInst =& ClassRegistry::init('ProfilesProfile');

		if (!empty($profDepends)) {
			foreach ($profDepends as $profIdText => $dependlist) {
				foreach ($dependlist as $dependency)
					$depends[] = array('profile_id' => $this->profIdCache[$profIdText], 'dependency_id' => $this->profIdCache[$dependency]);
			}

			if (!empty($depends)) {
				$profDepInst->create();
				$profDepInst->saveAll($depends, array('validate' => false));
			}
		}
	}
	
	function _saveProfilePackages(&$profPackages, $profIdText) {
		$profPkgs = array();
		$profPkgInst =& ClassRegistry::init('PackagesProfile');

		if (!empty($profPackages)) {
			foreach ($profPackages as $pkgIdText)
				$profPkgs[] = array('profile_id' => $this->profIdCache[$profIdText], 'package_id' => $this->pkgIdCache[$pkgIdText]);

			if (!empty($profPkgs)) {
				$profPkgInst->create();
				$profPkgInst->saveAll($profPkgs, array('validate' => false));
			}
		}
	}
	
	function _validateProfile(&$profile) {
		$messages = array();
		$profInst =& ClassRegistry::init('Profile');
		
		$profInst->set($profile);
		$result = $profInst->validates();
		if (!$result)
			$messages['Errors'][$profile['id_text']] = array_values($profInst->invalidFields());
			
		return $messages;
	}
	
	function _validateProfileDepends(&$profDepends) {
		$messages = array();
		$profInst =& ClassRegistry::init('Profile');

		foreach ($profDepends as $profIdText => $dependlist) {
			foreach ($dependlist as $k => $dependency) {
				// check that we don't depend on ourself
				if ($dependency == $profIdText) {
					$messages['Warnings'][$profIdText][] = "Detected profile dependency on self. Skipping.";
					unset($profDepends[$profIdText][$k]);
				} else if (empty($dependency)) {
					$messages['Warnings'][$profIdText][] = "Found profile dependency with empty/invalid name. Skipping.";
					unset($profDepends[$profIdText][$k]);
				} else {
					// Check dependencies against locally defined profiles
					if (!array_key_exists($dependency, $profDepends)) {
						// Check pre-existing profiles in the database
						if (!($prof = $profInst->find('first', array('fields' => array('Profile.id'), 'conditions' => array('Profile.id_text' => $dependency), 'recursive' => -1))))
							$messages['Errors'][$profIdText][] = "Could not find existing dependency profile: $dependency";
						else {
							// Cache the profile ID of this dependency from the DB
							$this->profIdCache[$dependency] = $prof['Profile']['id'];
						}
					}
				}
			}
		}

		return $messages;
	}
	
	function _validateProfilePackages(&$profPackages, $profIdText) {
		$messages = array();
		$pkgInst =& ClassRegistry::init('Package');

		foreach ($profPackages as $pkgIdText) {
			if (empty($pkgIdText))
				$messages['Warnings'][$profIdText][] = "Found profile package with empty/invalid name. Skipping.";
			else {
				// Check profile packages against package id cache
				if (!array_key_exists($pkgIdText, $this->pkgIdCache)) {
					// Check pre-existing packages in the database
					if (!($pkg = $pkgInst->find('first', array('fields' => array('Package.id'), 'conditions' => array('Package.id_text' => $pkgIdText), 'recursive' => -1))))
						$messages['Errors'][$profIdText][] = "Could not find existing profile package: $pkgIdText";
					else {
						// Cache the package ID of this profile package from the DB
						$this->pkgIdCache[$pkgIdText] = $pkg['Package']['id'];
					}
				}
			}
		}

		return $messages;
	}
	
	/*******************************************************************************************************
	 * Begin Host-specific import methods                                                                  *
	 *******************************************************************************************************/

	function importHosts($XMLfilename) {
		App::import('Xml');
		$messages = array();

		$parsed_xml =& new XML($XMLfilename);
		$parsed_xml = Set::reverse($parsed_xml);
		
		if (empty($parsed_xml) || !isset($parsed_xml['Wpkg']) || empty($parsed_xml['Wpkg'])
			|| !isset($parsed_xml['Wpkg']['Host']) || empty($parsed_xml['Wpkg']['Host'])) {
			$messages['Errors']['General'] = "No Hosts to import.";
			return $messages;
		}
		
		$this->_arrayify($parsed_xml['Wpkg']['Host']);
		for ($i=0; $i<count($parsed_xml['Wpkg']['Host']); $i++) {
			$host =& $parsed_xml['Wpkg']['Host'][$i];
			
			$profiles = $this->_cutHostProfiles($host);
			
			// Validate Host
			$messages = array_merge_recursive($messages, $this->_validateHost($host));
			
			// Validate Host Profiles
			$messages = array_merge_recursive($messages, $this->_validateHostProfiles($profiles, $host['name']));
			
			if (!empty($profiles)) {
				$host['Profile'] = $profiles;
				unset($profiles);
			}
		}
		
		$ok = (empty($messages) || !isset($messages['Errors']) ? true : false);

		// Save to database if all is OK
		if ($ok) {
			$hosts = $parsed_xml['Wpkg']['Host'];
			for ($i = 0; $i < count($hosts); $i++)
				$this->_saveHost($hosts[$i]);
		}
		
		return ($ok ? true : $messages);
	}
	
	function _cutHostProfiles(&$host) {
		$packages = array();

		if (isset($host['Profile'])) {
			$this->_arrayify($host['Profile']);
			for ($i=0; $i<count($host['Profile']); $i++)
				$packages[] = $host['Profile'][$i]['id'];
			unset($host['Profile']);
		}

		return $packages;
	}
	
	function _saveHost(&$hostArray) {
		$hostInst =& ClassRegistry::init('Host');

		if (isset($hostArray['Profile'])) {
			$profiles = $hostArray['Profile'];
			unset($hostArray['Profile']);
		}
		if (isset($hostArray['Variable'])) {
			$vars = $hostArray['Variable'];
			unset($hostArray['Variable']);
		}

		$hostInst->create();
		$hostInst->save($hostArray, array('validate' => false));
		$hostId = $hostInst->id;

		// Cache the new host ID
		$this->hostIdCache[$hostArray['name']] = $hostId;

		if (isset($profiles)) {
			$this->_saveHostProfiles($profiles, $hostArray['name']);
			unset($profiles);
		}
		if (isset($vars)) {
			$this->_saveVariables($vars, $hostId, VARIABLE_TYPE_HOST);
			unset($vars);
		}
	}
	
	function _saveHostProfiles(&$hostProfiles, $hostName) {
		$hostProfs = array();
		$hostProfInst =& ClassRegistry::init('HostsProfile');

		if (!empty($hostProfiles)) {
			foreach ($hostProfiles as $profIdText)
				$hostProfs[] = array('host_id' => $this->hostIdCache[$hostName], 'profile_id' => $this->profIdCache[$profIdText]);

			if (!empty($hostProfs)) {
				$hostProfInst->create();
				$hostProfInst->saveAll($hostProfs, array('validate' => false));
			}
		}
	}
	
	function _validateHost(&$host) {
		$messages = array();
		$hostInst =& ClassRegistry::init('Host');
		$profInst =& ClassRegistry::init('Profile');
		
		// Validate host's main profile
		if (isset($host['profile-id'])) {
			$mainprofile = $host['profile-id'];
			unset($host['profile-id']);
			
			// Check host main profile against profile id cache
			if (!array_key_exists($mainprofile, $this->profIdCache)) {
				// Check pre-existing profiles in the database
				if (!($prof = $profInst->find('first', array('fields' => array('Profile.id'), 'conditions' => array('Profile.id_text' => $mainprofile), 'recursive' => -1))))
					$messages['Errors'][$host['name']][] = "Could not find existing host (main) profile: $mainprofile";
				else {
					// Cache the profile ID of this host's main profile from the DB
					$host['mainprofile_id'] = $this->profIdCache[$mainprofile] = $prof['Profile']['id'];
				}
			} else
				$host['mainprofile_id'] = $this->profIdCache[$mainprofile];
		}
		
		$hostInst->set($host);
		$result = $hostInst->validates();
		if (!$result) {
			if (!isset($messages['Errors'][$host['name']]))
				$messages['Errors'][$host['name']] = array();
			$messages['Errors'][$host['name']] += array_values($hostInst->invalidFields());
		}
			
		return $messages;
	}
	
	function _validateHostProfiles(&$hostProfiles, $hostName) {
		$messages = array();
		$profInst =& ClassRegistry::init('Profile');

		foreach ($hostProfiles as $profIdText) {
			if (empty($profIdText))
				$messages['Warnings'][$hostName][] = "Found host profile with empty/invalid name. Skipping.";
			else {
				// Check host profiles against profile id cache
				if (!array_key_exists($profIdText, $this->profIdCache)) {
					// Check pre-existing profiles in the database
					if (!($prof = $profInst->find('first', array('fields' => array('Profile.id'), 'conditions' => array('Profile.id_text' => $profIdText), 'recursive' => -1))))
						$messages['Errors'][$hostName][] = "Could not find existing host profile: $profIdText";
					else {
						// Cache the profile ID of this host profile from the DB
						$this->profIdCache[$profIdText] = $prof['Profile']['id'];
					}
				}
			}
		}

		return $messages;
	}
	
	/*******************************************************************************************************
	 * Begin XML validation methods                                                                        *
	 *******************************************************************************************************/
	
	function libxml_format_error($error) {
		$ret = array();
		$type = 'Other Messages';
		switch ($error->level) {
			case LIBXML_ERR_WARNING:
				$type = 'Warnings';
				break;
			case LIBXML_ERR_ERROR:
				$type = 'Errors';
				break;
			case LIBXML_ERR_FATAL:
				$type = 'Fatal Errors';
				break;
		}
		$ret['code'] = $error->code;
		$ret['message'] = $error->message;
		if ($error->file)
			$ret['file'] = $error->file;
		$ret['line'] = $error->line;

		return array($type => array($ret));
	}

	function libxml_formatted_errors() {
		$ret = array();
		$errors = libxml_get_errors();
		foreach ($errors as $error)
			$ret = array_merge_recursive($ret, $this->libxml_format_error($error));
		libxml_clear_errors();
		
		return $ret;
	} 

	function validateXML($xmlFileName, $xsdFileName) {
		if (version_compare(PHP_VERSION, '5.0.0', '>=')) {
			// PHP5 or newer
			libxml_use_internal_errors(true);
			$xml = new DOMDocument();
			$xml->load($xmlFileName);
			if (!$xml->schemaValidate($xsdFileName))
				$ret = $this->libxml_formatted_errors();
			else
				$ret = true;
		} /*else {
			// PHP4 -- yuck!
			// TODO? Probably not...
		}*/
		
		return $ret;
	}
}
?>