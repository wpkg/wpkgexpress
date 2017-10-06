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
class Installer extends AppModel {

	var $name = 'Installer';
	var $useTable = false;
	var $_schema = array(
		'driver' => array('type' => 'string'),
		'persistent' => array('type' => 'boolean'),
		'database' => array('type' => 'string'),
		'host' => array('type' => 'string'),
		'port' => array('type' => 'integer'),
		'login' => array('type' => 'string'),
		'password' => array('type' => 'string'),
		'user' => array('type' => 'string'),
		'protectxml' => array('type' => 'boolean'),
		'xmlpassword' => array('type' => 'string'),
		'xmluser' => array('type' => 'string')
	);
	var $validate = array(
		array(
			'driver' => array(
				'rule' => 'valid_driver',
				'required' => true,
				'allowEmpty' => false
			),
			'persistent' => array(
				'rule' => 'valid_bool',
				'required' => true,
				'allowEmpty' => false
			),
			'database' => array(
				'rule' => 'notEmpty',
				'required' => true,
				'allowEmpty' => false
			),
			'host' => array(
				'rule' => 'host_ok',
				'required' => true,
				'allowEmpty' => false,
				'message' => 'This field must contain a valid host',
			),
			'port' => array(
				'rule' => 'int_ok',
				'required' => false,
				'allowEmpty' => true,
				'message' => 'This field must contain a valid positive integer'
			),
			'login' => array(
				'rule' => 'notEmpty',
				'required' => true,
				'allowEmpty' => false
			),
			'password' => array(
				'rule' => 'notEmpty',
				'required' => true,
				'allowEmpty' => false
			)
		),
		array(
			'user' => array(
				'rule' => 'notEmpty',
				'required' => true,
				'allowEmpty' => false,
				'message' => 'You must enter a username'
			),
			'password' => array(
				'empty' => array(
					'rule' => 'notEmpty',
					'required' => true,
					'allowEmpty' => false,
					'last' => true,
					'message' => 'You must enter a password'
				),
				'minlength' => array(
					'rule' => array('minLength', 5),
					'required' => true,
					'allowEmpty' => false,
					'last' => true,
					'message' => 'Password length must be geater than 5 characters'
				),
				'maxlength' => array(
					'rule' => array('maxLength', 15),
					'required' => true,
					'allowEmpty' => false,
					'last' => true,
					'message' => 'Password length must be less than 15 characters'
				)
			),
			'protectxml' => array(
				'rule' => 'valid_bool',
				'required' => true,
				'message' => 'Invalid choice'
			),
			'xmluser' => array(
				'rule' => 'notEmpty',
				'required' => true,
				'allowEmpty' => false,
				'message' => 'You must enter a username'
			),
			'xmlpassword' => array(
				'empty' => array(
					'rule' => 'notEmpty',
					'required' => true,
					'allowEmpty' => false,
					'last' => true,
					'message' => 'You must enter a password'
				),
				'minlength' => array(
					'rule' => array('minLength', 5),
					'last' => true,
					'message' => 'Password length must be geater than 5 characters'
				),
				'maxlength' => array(
					'rule' => array('maxLength', 15),
					'last' => true,
					'message' => 'Password length must be less than 15 characters'
				)
			)
		),
		array(
			'user' => array(
				'rule' => 'notEmpty',
				'required' => true,
				'allowEmpty' => false,
				'message' => 'You must enter a username'
			),
			'password' => array(
				'rule' => 'notEmpty',
				'required' => true,
				'allowEmpty' => false,
				'last' => true,
				'message' => 'You must enter a password'
			)
		)
	);

	function step($step) {
		$this->validate = $this->validate[$step-1];
	}

	function valid_driver($data) {
		$data = array_shift($data);
		return (in_array($data, $this->getDBDrivers()));
	}

	function valid_bool($data) {
		$data = array_shift($data);
		return (in_array($data, array(true, false)));
	}

	function int_ok($data) {
		$data = array_shift($data);
		return ($data !== true) && ((string)(int)$data) === ((string)$data) && (int)$data > 0;
	}

	function host_ok($data) {
		$data = array_shift($data);
		// easy way out
		return gethostbynamel($data) !== false;
	}

	function writeSalt() {
		/*if ($fh = fopen(CONFIGS . 'core.php', 'r')) {
			$cfg = '';
			while (!feof($fh))
				$cfg .= fgets($fh, 4096);
			fclose($fh);
			$salt = sha1(uniqid(mt_rand(), true));
			$cfg = preg_replace("/Configure::write\('Security\.salt', '(.*?)'\);/", "Configure::write('Security.salt', '$salt');", $cfg);
			$fh = fopen(CONFIGS . 'core.php', 'w');
			fwrite($fh, $cfg);
			fclose($fh);
			return true;
		}
		return false;*/
		if (($cfg = file_get_contents(CONFIGS . 'core.php')) !== false) {
			preg_match("/Configure::write\('Security\.salt', '(.*?)'\);/", $cfg, $matches);
			// only insert a new salt if there currently is no salt
			if (empty($matches[1])) {
				$salt = sha1(uniqid(mt_rand(), true));
				$cfg = preg_replace("/Configure::write\('Security\.salt', '(.*?)'\);/", "Configure::write('Security.salt', '$salt');", $cfg);
				if (file_put_contents(CONFIGS . 'core.php', $cfg) !== false)
					return true;
			}
		}
		return false;
	}

	function getDBDrivers() {
		$drivers = array();
		$dbo_path = APP . LIBS . "model" . DS . "datasources" . DS . "dbo";
		$files = Configure::listObjects('file', $dbo_path, false);
		foreach ($files as $fname) {
			$name = substr(substr($fname, strpos($fname, "_") + 1), 0, -4);
			$drivers[$name] = $name;
		}		
		return $drivers;
	}

	function checkTables() {
		if ($fh = fopen(CONFIGS . 'sql' . DS . 'wpkgExpress.sql', 'r')) {
			$schemadata = '';
			while (!feof($fh))
				$schemadata .= fgets($fh, 4096);
			$schemadata = trim($schemadata);
			fclose($fh);
			$this->schemadata = $schemadata;

			if (!preg_match_all("/DROP TABLE IF EXISTS `(.+)`;/", $schemadata, $tables))
				return "Could not find any database tables to check";
			else {
				$db = ConnectionManager::getDataSource('default');
				$tables = $tables[1];
				$errors = array();
				foreach ($tables as $table) {
					$db->query("SELECT COUNT(*) FROM $table WHERE 1=1");
					if ($db->error != null) {
						$errors[$table] = $db->error;
						$db->error = null;
					}
				}
				return (!empty($errors) ? $errors : true);
			}
		} else
			return "Could not open database schema file for reading";
	}

	function getCreateTableSQL($tables) {
		$tableschemas = array();
		if (!isset($this->schemadata))
			return false;
		$single = (!is_array($tables));
		if ($single)
			$tables = array($tables);
		foreach ($tables as $table) {
			preg_match("/CREATE TABLE `$table` \((.+?)\);/s", $this->schemadata, $match);
			$tableschemas[$table] = substr($match[0], 0, -1);
		}
		return ($single ? current($tableschemas) : $tableschemas);
	}

}
?>