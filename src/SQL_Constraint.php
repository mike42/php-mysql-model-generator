<?php
/**
 * Class for handling foreign key constraints
 *
 * @author Michael Billington <michael.billington@gmail.com>
 */
class SQL_Constraint {
	public $name;
	public $child_fields;
	public $parent_table;
	public $parent_fields;
	public $child_table; // Used when generating models

	/**
	 * Roughly parse constraints
	 *
	 * @param SQL_Token $tokens
	 * @throws Exception
	 */
	public function __construct($tokens) {
		$this -> child_fields = array();
		$this -> parent_fields = array();

		$stage = 0;
		foreach($tokens as $id => $token) {
			switch($stage) {
				case 0:
					if($token -> type == SQL_Token::KEYWORD && strtoupper($token -> str) == "FOREIGN") {
						$stage++;
					} else if($token -> type == SQL_Token::IDENTIFIER) {
						$this -> name = SQL_Token::get_identifier($token -> str);
					}
					break;

				case 1:
					if($token -> type == SQL_Token::KEYWORD && strtoupper($token -> str) == "KEY" && count($token -> sub) != 0) {
						$this -> addChildFields($token -> sub);
						$stage++;
					} else {
						break 2; // FOREIGN must be followed by KEY
					}
					break;
				case 2:
					if($token -> type == SQL_Token::KEYWORD && strtoupper($token -> str) == "REFERENCES") {
						$stage++;
					}
					break;
				case 3:
					if($token -> type == SQL_Token::IDENTIFIER && count($token -> sub) != 0) {
						$this -> parent_table = SQL_Token::get_identifier($token -> str);
						$this -> addParentFields($token -> sub);
						return;
					}
					break;
			}
		}
		throw new Exception("Unable to parse constraint");
	}

	/**
	 * Populate child fields from bracketed list (`foo`, `bar`)
	 *
	 * @param SQL_Token[] $tokens
	 */
	private function addChildFields($tokens) {
		foreach($tokens as $token) {
			if($token -> type == SQL_Token::IDENTIFIER) {
				$this -> child_fields[] = SQL_Token::get_identifier($token -> str);
			}
		}
	}

	/**
	 * Populate parent fields from bracketed list (`baz`, `quux`)
	 *
	 * @param SQL_Token[] $tokens
	 */
	private function addParentFields($tokens) {
		foreach($tokens as $token) {
			if($token -> type == SQL_Token::IDENTIFIER) {
				$this -> parent_fields[] = SQL_Token::get_identifier($token -> str);
			}
		}
	}
}