<?php
require_once(dirname(__FILE__) . "/SQL_Input.php");

/**
 * Import SQL schema and load information about the structure
 * @author Michael Billington
 */
class SQL_Database {
	public $table;
	public $name;

	public function __construct($sql) {
		/* Parse input */
		$input = new SQL_Input($sql);

		/* Load up tables */
		$tables = array();
		foreach($input -> statement as $statement) {
			if($statement -> type == SQL_Statement::CREATE_TABLE) {
				$this -> addTable($statement);
			}
		}
	}

	private function addTable(SQL_Statement $statement) {
		$table = new SQL_Table($statement);
		$this -> table[$table -> name] = $table;
	}
}
