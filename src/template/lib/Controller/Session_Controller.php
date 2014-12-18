<?php
class Session_Controller {
	public static function init() {
		core::loadClass("Session");
	}
	
	public static function login() {
		if(!isset($_POST['username']) || !isset($_POST['password'])) {
			return array('error' => 'No login details provided', 'code' => '403');
		}
		
		/* Get username & password */
		$username = $_POST['username'];
		$password = $_POST['password'];
		$ok = Session::authenticate($username, $password);
		if($ok) {
			return array('success' => 'true', 'username' => $username, 'role' => Session::getRole());
		}
		return array('error' => 'Login failed', 'code' => '403');
	}
	
	public static function logout() {
		Session::logout();
		return array('success' => 'true'); // Log-out will always succeed
	}
}
