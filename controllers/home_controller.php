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
class HomeController extends AppController {
	var $name = 'Home';
	var $layout = 'main';
	var $components = array();
	var $uses = array('Package', 'Profile', 'Host');
	var $helpers = array('Html', 'Navigate', 'Javascript');

	function index() {
		$packageCountTotal = $this->Package->find('count');
		$packageCountEnabled = $this->Package->find('count', array('conditions' => array('Package.enabled' => '1'), 'recursive' => -1));
		$packageCountDisabled = $packageCountTotal - $packageCountEnabled;
		$packageRecent = $this->Package->find('all', array('order' => 'Package.modified DESC', 'limit' => 5, 'fields' => array('Package.id', 'Package.name', 'Package.modified'), 'recursive' => -1));

		$profileCountTotal = $this->Profile->find('count');
		$profileCountEnabled = $this->Profile->find('count', array('conditions' => array('Profile.enabled' => '1'), 'recursive' => -1));
		$profileCountDisabled = $profileCountTotal - $profileCountEnabled;
		$profileRecent = $this->Profile->find('all', array('order' => 'Profile.modified DESC', 'limit' => 5, 'fields' => array('Profile.id', 'Profile.id_text', 'Profile.modified'), 'recursive' => -1));

		$hostCountTotal = $this->Host->find('count');
		$hostCountEnabled = $this->Host->find('count', array('conditions' => array('Host.enabled' => '1'), 'recursive' => -1));
		$hostCountDisabled = $hostCountTotal - $hostCountEnabled;
		$hostRecent = $this->Host->find('all', array('order' => 'Host.modified DESC', 'limit' => 5, 'fields' => array('Host.id', 'Host.name', 'Host.modified'), 'recursive' => -1));

		$this->set(compact('packageCountTotal', 'packageCountEnabled', 'packageCountDisabled', 'packageRecent',
				   'profileCountTotal', 'profileCountEnabled', 'profileCountDisabled', 'profileRecent',
				   'hostCountTotal', 'hostCountEnabled', 'hostCountDisabled', 'hostRecent'
		));
	}
}
?>