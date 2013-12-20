#!/usr/bin/env php
<?php
require_once(dirname(__FILE__) . "/src/SQL_Database.php");
require_once(dirname(__FILE__) . "/src/Model_Generator.php");
$usage = "Usage: ". $argv[0] . " foo-code < foo.sql\n";
if(count($argv) != 2) {
	die($usage);
}
$import = new SQL_Database(file_get_contents("php://stdin"));
$import -> name = $argv[1];
$mg = new Model_Generator($import);
$mg -> generate();