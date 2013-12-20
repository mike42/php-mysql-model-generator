<?php
require_once(dirname(__FILE__) . "/SQL_Statement.php");
require_once(dirname(__FILE__) . "/SQL_Table.php");

/**
 * Take SQL input, run the tokeniser, and extract the list of statements
 *
 * @author Michael Billington <michael.billington@gmail.com>
 */
class SQL_Input {
	public $sql;
	public $statement = array();

	function __construct($sql) {
		/* Split input into characters to extract tokens */
		$curtoken = new SQL_Token();
		$alltokens = array();
		for($i = 0; $i < strlen($sql); $i++) {
				$c = substr($sql, $i, 1);
				if(!$curtoken -> add($c)) {
					if($curtoken -> type != SQL_Token::WHITESPACE && $curtoken -> type != SQL_Token::LINE_COMMENT) { // Skip over whitespace
						$alltokens[] = $curtoken;
					}
					$curtoken = new SQL_Token();
					$i--;
				}
		}
		if($curtoken -> type != SQL_Token::WHITESPACE && $curtoken -> type != SQL_Token::LINE_COMMENT) { // Skip over whitespace
			// Add final token
			$alltokens[] = $curtoken;
		}

		/* Structure according to bracketing */
		do {
			$lastcount = count($alltokens);
			$parent = -1;
			$lastidx = -1;
			$children = array();
			$depth = 0;
			$repeat = false;

			foreach($alltokens as $idx => $token) {
				if($token -> type == SQL_Token::OPENBRACKET) {
					if($parent != -1) {
						$repeat = true;
					}
					$parent = $lastidx;
					$children = array($idx => $token);
					$depth++;
				} else if($token -> type == SQL_Token::CLOSEBRACKET) {
					$depth--;
					if($depth < 0) {
						throw new Exception("Too many close brackets");
					}
					$children[$idx] = $token;
					$alltokens[$parent] -> has_sub = true;

					foreach($children as $cid => $c) {
						if($c -> type != SQL_Token::OPENBRACKET && $c -> type != SQL_Token::CLOSEBRACKET) {
							$alltokens[$parent] -> sub[] = $c;
						}
						unset($alltokens[$cid]);
						unset($children[$cid]);
					}
					break;
				} else {
					$lastidx = $idx;
					$children[$idx] = $token;
				}
			}
		} while(count($alltokens) != count($children));

		/* Extract list of statements */
		$nextStatement = array();
		foreach($alltokens as $id => $token) {
			$nextStatement[] = $token;
			unset($alltokens[$id]);
			if($token -> type == SQL_Token::SEMICOLON) {
				$this -> statement[] = new SQL_Statement($nextStatement);
				$nextStatement = array();
			}
		}
		if(count($nextStatement) != 0) {
			throw new Exception("Statement was not ended with a semicolon");
		}
	}
}