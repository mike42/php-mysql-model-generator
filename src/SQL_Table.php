<?php
require_once(dirname(__FILE__) . "/SQL_Constraint.php");
require_once(dirname(__FILE__) . "/SQL_Index.php");
require_once(dirname(__FILE__) . "/SQL_Unique.php");
require_once(dirname(__FILE__) . "/SQL_Colspec.php");

class SQL_Table {
	public $name;
	public $cols;
	public $constraints;
	public $index;
	public $unique;
	public $pk;

	public function __construct(SQL_Statement $statement) {
		$this -> pk = $this -> cols = $this -> constraints = $this -> index = $this -> unique = array();

		/* Find list of columns in the statement */
		$lastId = $token = false;
		foreach($statement -> token as $token) {
			if($token -> type == SQL_Token::IDENTIFIER && $token -> has_sub) {
				break;
			}
		}

		/* Split up comma-separated columns and process them all */
		$this -> name = SQL_Token::get_identifier($token -> str);
		$nextCol = array();
		foreach($token -> sub as $subtoken) {
			if($subtoken -> type == SQL_Token::COMMA) {
				$this -> addSpec($nextCol);
				$nextCol = array();
			} else {
				$nextCol[] = $subtoken;
			}
		}

		$this -> addSpec($nextCol);
	}

	/**
	 * Identify and add row, constraint, or whatever
	 *
	 * @param string $tokens
	 */
	private function addSpec($tokens) {
		if(count($tokens) == 0) {
			return;
		}

		if($tokens[0] -> type == SQL_Token::IDENTIFIER) {
			$this -> addCol($tokens);
		} else if($tokens[0] -> type == SQL_Token::KEYWORD && strtoupper($tokens[0] -> str) == "INDEX") {
			$this -> addIndex($tokens);
		} else if($tokens[0] -> type == SQL_Token::KEYWORD && strtoupper($tokens[0] -> str) == "UNIQUE") {
			$this -> addUnique($tokens);
		} else if($tokens[0] -> type == SQL_Token::KEYWORD && strtoupper($tokens[0] -> str) == "PRIMARY") {
			$this -> addPrimaryKey($tokens);
		} else if($tokens[0] -> type == SQL_Token::KEYWORD && strtoupper($tokens[0] -> str) == "CONSTRAINT") {
			$this -> addConstraint($tokens);
		} else {
			print_r($tokens[0]);
		}
	}

	private function addPrimaryKey($tokens)  {
		foreach($tokens as $token) {
			if(count($token -> sub) != 0) {
				/* Stop on an identifier with brackets */
				foreach($token -> sub as $sub) {
					if($sub -> type == SQL_Token::IDENTIFIER) {
						$this -> pk[] = SQL_Token::get_identifier($sub -> str);
					}
				}
				return;
			}
		}
		throw new Exception("Couldn't parse primary key");
	}

	private function addIndex($tokens) {
		$index = new SQL_Index($tokens);
		$this -> index[$index -> name] = $index;
	}

	private function addUnique($tokens) {
		$unique = new SQL_Unique($tokens);
		$this -> unique[$unique -> name] = $unique;
	}

	private function addCol($tokens) {
		$col = new SQL_Colspec($tokens);
		$this -> cols[$col -> name] = $col;

	}

	private function addConstraint($tokens) {
		$constraint = new SQL_Constraint($tokens);
		$this -> constraints[] = $constraint;
	}
}