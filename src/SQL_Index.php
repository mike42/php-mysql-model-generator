<?php
class SQL_Index {
	public $name;
	public $fields;

	/**
	 * Construct an index from the SQL tokens of the INDEX spec.
	 *
	 * @param SQK_Token[] $tokens
	 * @throws Exception
	 */
	public function __construct($tokens) {
		foreach($tokens as $token) {
			if($token -> type == SQL_Token::IDENTIFIER && count($token -> sub) != 0) {
				/* Stop on an identifier with brackets */
				$this -> addIndex($token);
				return;
			}
		}
		throw new Exception("Couldn't parse index");
	}

	private function addIndex(SQL_Token $token) {
		$this -> name = SQL_Token::get_identifier($token -> str);
		$this -> fields = array();
		foreach($token -> sub as $sub) {
			if($sub -> type == SQL_Token::IDENTIFIER) {
				$this -> fields[] = SQL_Token::get_identifier($sub -> str);
			}
		}
	}
}