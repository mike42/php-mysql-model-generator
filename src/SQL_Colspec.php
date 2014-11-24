<?php
class SQL_Colspec {
	public $name;
	public $type;
	public $size;
	public $values;
	public $comment;
	public $nullable;

	public function __construct($tokens) {
		$this -> nullable = true;
		
		$this -> name = SQL_Token::get_identifier($tokens[0] -> str);
		$this -> type = strtoupper($tokens[1] -> str);
		$size = array();
		if(count($tokens[1] -> sub) != 0) {
			foreach($tokens[1] -> sub as $subtoken) {
				if($this -> type == "ENUM" && $subtoken -> type == SQL_Token::STRING_LITERAL) {
					$this -> values[] = SQL_Token::get_string_literal($subtoken -> str);
				} else {
					if($subtoken -> type == SQL_Token::NUMBER_LITERAL) {
						$this -> size[] = SQL_Token::get_number_literal($subtoken -> str);
					} else if($subtoken -> type == SQL_Token::STRING_LITERAL) {
						$this -> size[] = SQL_Token::get_string_literal($subtoken -> str);
					}
				}
			}
		}
		
		/* Find comment */
		$this -> comment = "";
		$commentNext = false;
		$not = false;
		foreach($tokens as $token) {		
			if($commentNext) {
				if($token -> type == SQL_Token::STRING_LITERAL) {
					$this -> comment = trim(SQL_Token::get_string_literal($token -> str));
					break;
				} else {
					break; // Not a comment. Give up.
				}
			} else if($token -> type == SQL_Token::IDENTIFIER && strtoupper($token -> str) == "COMMENT") {
				$commentNext = true;
			} else if($not && $token -> type == SQL_Token::KEYWORD && strtoupper($token -> str) == "NULL") {
				$this -> nullable = false;
			}
			
			/* Track the NOT keyword so we can spot NOT NULL */
			if($token -> type == SQL_Token::KEYWORD && strtoupper($token -> str) == "NOT") {
				$not = true;
			} else {
				$not = false;
			}
		}
	}

}