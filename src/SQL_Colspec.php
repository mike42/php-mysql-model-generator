<?php
class SQL_Colspec {
	public $name;
	public $type;
	public $size;
	public $values;

	public function __construct($tokens) {
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
	}

}