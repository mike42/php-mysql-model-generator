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
	public $comment;

	public function __construct(SQL_Statement $statement) {
		$this -> pk = $this -> cols = $this -> constraints = $this -> index = $this -> unique = array();

		/* Find list of columns in the statement */
		$lastId = $token = false;
		$token = null;
		$commentCounter = -1;
		$this -> comment == "";
		foreach($statement -> token as $thisToken) {
			if(is_null($token) && $thisToken -> type == SQL_Token::IDENTIFIER && $thisToken -> has_sub) {
				$token = $thisToken; // This is the one which has the column defs
				$commentCounter = 0;
			} else if($commentCounter >= 0) { // Tick through expected tokens for finding a table comment
				if($commentCounter == 0 && $thisToken -> type == SQL_Token::IDENTIFIER && strtoupper($thisToken -> str) == "COMMENT") {
					$commentCounter++;
				} else if($commentCounter == 1 && $thisToken -> type == SQL_Token::OPERATOR && $thisToken -> str == "=") {
					$commentCounter++;
				} else if($commentCounter == 2 && $thisToken -> type = SQL_Token::STRING_LITERAL) {
					$this -> comment = trim(SQL_Token::get_string_literal($thisToken -> str));
					$commentCounter = -1; // Reset
				} else if($commentCounter > 0) {
					$commentCounter = -1; // Something unexpected. Give up.
				}
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

		$this -> addSpec($nextCol); // Catch last col too
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
			echo "Unknown line in table def. Tokens printed below:\n";
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