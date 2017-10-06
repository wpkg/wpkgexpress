<?php
/* SVN FILE: $Id: app_controller.php 6311 2008-01-02 06:33:52Z phpnut $ */
/**
 * Short description for file.
 *
 * This file is application-wide controller file. You can put all
 * application-wide controller-related methods here.
 *
 * PHP versions 4 and 5
 *
 * CakePHP(tm) :  Rapid Development Framework <http://www.cakephp.org/>
 * Copyright 2005-2008, Cake Software Foundation, Inc.
 *								1785 E. Sahara Avenue, Suite 490-204
 *								Las Vegas, Nevada 89104
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @filesource
 * @copyright		Copyright 2005-2008, Cake Software Foundation, Inc.
 * @link				http://www.cakefoundation.org/projects/info/cakephp CakePHP(tm) Project
 * @package			cake
 * @subpackage		cake.app
 * @since			CakePHP(tm) v 0.2.9
 * @version			$Revision: 6311 $
 * @modifiedby		$LastChangedBy: phpnut $
 * @lastmodified	$Date: 2008-01-02 01:33:52 -0500 (Wed, 02 Jan 2008) $
 * @license			http://www.opensource.org/licenses/mit-license.php The MIT License
 */
/**
 * Short description for class.
 *
 * Add your application-wide methods in the class below, your controllers
 * will inherit them.
 *
 * @package		cake
 * @subpackage	cake.app
 */
class AppController extends Controller {
	var $components = array('Security', 'Session', 'Configuration', 'RequestHandler');
	var $_cancelAction = false;

	function beforeFilter() {
		if (!$this->RequestHandler->isSSL())
			$this->__forceSSL();
		if ($this->RequestHandler->isAjax() || $this->RequestHandler->isXml())
			Configure::write('debug', 0);
		if ($this->name != 'Installer' && !$this->Session->check('loggedIn')) {
			if ($this->RequestHandler->isXml()) {
				if ($this->Configuration->read('Auth.protectxml') == true) {
					$this->Security->loginOptions = array(
						'type'=>'basic',
						'login'=>'authHTTP',
						'realm'=>'wpkgExpress XML Export'
					);
					$this->Security->blackHoleCallback = null;
					//$this->Security->loginUsers = array();
					$this->Security->requireLogin();
				}
			} else {
				$url = $this->_getRequestedURL();

				if (!empty($this->data) && isset($this->data[Inflector::singularize($this->name)]['user']) && isset($this->data[Inflector::singularize($this->name)]['password'])) {
					$data = array_pop($this->data);
					$logvalid =& ClassRegistry::init('Installer');
					$logvalid->data = array('Installer' => $data);
					$logvalid->step(3);
					if ($logvalid->validates()) {
						if (!$this->auth($data['user'], $data['password']))
							$this->set('criticalerrors', array('Incorrect username or password'));
						else {
							$this->Session->write('loggedIn', true);
							return;
						}
					}
				}
				$this->set('url', $url);
				$this->set('dest', Inflector::singularize($this->name));
				$this->layout = 'login';
				$this->autoRender = false;
				$this->render(ELEMENTS . 'login.ctp');
				$this->_cancelAction = true;
			}
		}
	}

	/* Generate a CakePHP url array containing the originally requested url -- useful for knowing where to redirect after login */
	function _getRequestedURL() {
		$params = $this->params['pass'];
		$namedparams = array();
		foreach ($this->params['named'] as $k => $v)
			$namedparams[] = "$k:$v";
		$params = (!empty($params) ? implode("/", $params) : "") . (!empty($namedparams) ? "/" . implode("/", $namedparams) : "");
		if (empty($params))
			$url = array('url' => array('controller' => strtolower($this->name), 'action' => $this->action));
		else
			$url = array('url' => array('controller' => strtolower($this->name), 'action' => $this->action, $params));
		return $url;
	}
	
	function dispatchMethod($method, $params = array()) {
		if ($this->_cancelAction === true)
			return false;
		return parent::dispatchMethod($method, $params);
	}

	function __forceSSL() {
		$url = 'https://' . (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'] . '@' : '') . $_SERVER['SERVER_NAME'] . ($_SERVER['SERVER_PORT'] != 80 ? ':' . $_SERVER['SERVER_PORT'] : '') . $_SERVER['REQUEST_URI'];
		$this->redirect($url);
	}

	function authHTTP($args) {
		$valid = $this->auth($args['username'], $args['password'], true);
		if (!$valid)
			$this->Security->blackHole($this, 'login');
		return $valid;
	}

	/* Authenticate either web or XML access credentials */
	function auth($user, $pwd, $isXML = false) {
		if ($this->Configuration->read('Auth.' . ($isXML == true ? 'xml' : '') . 'user') == $user) {
			if ($this->Configuration->read('Auth.' . ($isXML == true ? 'xml' : '') . 'password') == $this->__hashPwd($pwd))
				return true;
		}
		return false;
	}

	/* Hashes the password using the previously auto-generated salt */
	function __hashPwd($pwd) {
		$textHash = sha1($pwd);
		$saltHash = Configure::read('Security.salt');
		$saltStart = strlen($pwd);
	    if($saltStart > 0 && $saltStart < strlen($saltHash)) {
			$textHashStart = substr($textHash,0,$saltStart);
			$textHashEnd = substr($textHash,$saltStart,strlen($saltHash));
			$outHash = sha1($textHashEnd.$saltHash.$textHashStart);
	    } elseif($saltStart > (strlen($saltHash)-1))
			$outHash = sha1($textHash.$saltHash);
	    else
			$outHash = sha1($saltHash.$textHash);
	    return ($saltHash.$outHash);
	}

	/* Catches all site errors, including HTTP errors */
	function appError($method, $params) {
		if (is_array($params) && is_array($params[0]) && !empty($params[0]['url']) && strtolower($params[0]['url']) == 'logout' && $this->Session->check('loggedIn')) {
			$this->Session->del('loggedIn');
			$this->Session->setFlash('Logged out successfully.');
			$this->redirect(array('action'=>'index'));
		}
		$this->redirect("/");
	}
	
	/* Taken from: http://book.cakephp.org/view/548/Validating-Uploads */
	function isUploadedFile($val){
		if ((isset($val['error']) && $val['error'] == 0) || (!empty($val['tmp_name']) && $val['tmp_name'] != 'none'))
			return is_uploaded_file($val['tmp_name']);
		else
			return false;
	} 
	
	/* Used to satisfy ajax requests -- allows element rendering only (i.e. no meddling with current action rendering settings) */
	function element($name, $data, $extraHelpers = array()) {
		$v = new View($this);
		$v->layout = 'plain';
		$v->helpers = array('Html') + $extraHelpers;
		return $v->element($name, $data, true);
	}
}
?>