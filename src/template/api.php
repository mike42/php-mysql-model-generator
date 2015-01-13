<?php
require_once(dirname(__FILE__)."/lib/Core.php");
Core::loadClass("Database");

/* Map HTTP reuest types to methods */
$request_method = $_SERVER['REQUEST_METHOD'];
$request_method_defaults = array(
	"GET" => "read",
	"POST" => "create",
	"PUT" => "update",
	"PATCH" => "update",
	"DELETE" => "delete",
);
if(array_key_exists($request_method, $request_method_defaults)) {
	$default_action = $request_method_defaults[$request_method];
} else {
	Core::fizzle("Unsupported request type", "400");
}

/* Get page (or go to default if none is specified) */
$help = "Make requests with api.php?p={controller}/{id}, or api.php?p={Controller}/{action}/{id}";
if(isset($_GET['p']) && $_GET['p'] != '') {
	$arg = explode('/', $_REQUEST['p']);
} else {
	Core::fizzle("Not enough information: $help", "400");
}

/* Map arguments to a controller and method name */
$controller = array_shift($arg);
if(count($arg) > 1) {
	$action = array_shift($arg);
} elseif(count($arg) <= 1) {
	$action = $default_action;
}

/* Sanity check (leading & trailing '/' will trigger these) */
if(trim($controller) == "") {
	Core::fizzle("Controller not specified: $help", "400");
}
if(trim($action) == "") {
	Core::fizzle("Action not specified: $help", "400");
}

/* Figure out class and method name */
try {
	$controllerClassName = $controller.'_Controller';
	core::loadClass($controllerClassName);
	if(!is_callable($controllerClassName . "::" . $action)) {
		Core::fizzle("Controller '$controllerClassName' does not support a '$action' action.", '404');
	}
	$ret = call_user_func_array(array($controllerClassName, $action), $arg);
	if(isset($ret['error'])) {
		/* Something went wrong, we got back an 'error' property. */
		Core::fizzle($ret['error'], isset($ret['code']) ? $ret['code'] : '500');
	}
	echo json_encode($ret);
} catch(Exception $e) {
	/* Something went wrong, an exception was thrown */
	Core::fizzle($e -> getMessage(), '500');
}
