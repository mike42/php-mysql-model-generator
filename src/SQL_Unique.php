<?php
class SQL_Unique {
	public $name;
	public $fields;

	public function __construct($tokens) {
		/* Leveraging the similar syntax between INDEX and UNIQUE sections */
		$idx = new SQL_Index($tokens);
		$this -> name = $idx -> name;
		$this -> fields = $idx -> fields;
	}
}