<?php
/**
 * Class for helping tokenise the SQL
 *
 * @author Michael Billington <michael.billington@gmail.com>
 */
class SQL_Token {
	public $type = 'UNKNOWN';
	public $str = "";
	public $sub = array();
	public $has_sub = false;

	const UNKNOWN = 'UNKNOWN';
	const KEYWORD = 'KEYWORD';
	const STRING_LITERAL = 'STRING_LITERAL';
	const IDENTIFIER = 'IDENTIFIER';
	const OPERATOR = 'OPERATOR';
	const OPENBRACKET = 'OPENBRACKET';
	const CLOSEBRACKET = 'CLOSEBRACKET';
	const LINE_COMMENT = 'LINE_COMMENT';
	const WHITESPACE = 'WHITESPACE';
	const COMMA = 'COMMA';
	const DOT = 'DOT';
	const SEMICOLON = 'SEMICOLON';
	const NUMBER_LITERAL = 'NUMBER_LITERAL';

	/* See http://dev.mysql.com/doc/mysqld-version-reference/en/mysqld-version-reference-reservedwords-5-5.html */
	private static $keywords = array(
			"ACCESSIBLE", "ADD", "ALL", "ALTER", "ANALYZE", "AND", "AS", "ASC", "ASENSITIVE", "BEFORE", "BETWEEN", "BIGINT",
			"BINARY", "BLOB", "BOTH", "BY", "CALL", "CASCADE", "CASE", "CHANGE", "CHAR", "CHARACTER", "CHECK", "COLLATE",
			"COLUMN", "CONDITION", "CONSTRAINT", "CONTINUE", "CONVERT", "CREATE", "CROSS", "CURRENT_DATE", "CURRENT_TIME",
			"CURRENT_TIMESTAMP", "CURRENT_USER", "CURSOR", "DATABASE", "DATABASES", "DAY_HOUR", "DAY_MICROSECOND",
			"DAY_MINUTE", "DAY_SECOND", "DEC", "DECIMAL", "DECLARE", "DEFAULT", "DELAYED", "DELETE", "DESC", "DESCRIBE",
			"DETERMINISTIC", "DISTINCT", "DISTINCTROW", "DIV", "DOUBLE", "DROP", "DUAL", "EACH", "ELSE", "ELSEIF",
			"ENCLOSED", "ESCAPED", "EXISTS", "EXIT", "EXPLAIN", "FALSE", "FETCH", "FLOAT", "FLOAT4", "FLOAT8", "FOR",
			"FORCE", "FOREIGN", "FROM", "FULLTEXT", "GENERAL", "GRANT", "GROUP", "HAVING", "HIGH_PRIORITY",
			"HOUR_MICROSECOND", "HOUR_MINUTE", "HOUR_SECOND", "IF", "IGNORE", "IGNORE_SERVER_IDS[b]", "IN", "INDEX",
			"INFILE", "INNER", "INOUT", "INSENSITIVE", "INSERT", "INT", "INT1", "INT2", "INT3", "INT4", "INT8", "INTEGER",
			"INTERVAL", "INTO", "IS", "ITERATE", "JOIN", "KEY", "KEYS", "KILL", "LEADING", "LEAVE", "LEFT", "LIKE", "LIMIT",
			"LINEAR", "LINES", "LOAD", "LOCALTIME", "LOCALTIMESTAMP", "LOCK", "LONG", "LONGBLOB", "LONGTEXT", "LOOP",
			"LOW_PRIORITY", "MASTER_HEARTBEAT_PERIOD", "MASTER_SSL_VERIFY_SERVER_CERT", "MATCH", "MAXVALUE", "MEDIUMBLOB",
			"MEDIUMINT", "MEDIUMTEXT", "MIDDLEINT", "MINUTE_MICROSECOND", "MINUTE_SECOND", "MOD", "MODIFIES", "NATURAL", "NOT",
			"NO_WRITE_TO_BINLOG", "NULL", "NUMERIC", "ON", "OPTIMIZE", "OPTION", "OPTIONALLY", "OR", "ORDER", "OUT", "OUTER",
			"OUTFILE", "PRECISION", "PRIMARY", "PROCEDURE", "PURGE", "RANGE", "READ", "READS", "READ_WRITE", "REAL",
			"REFERENCES", "REGEXP", "RELEASE", "RENAME", "REPEAT", "REPLACE", "REQUIRE", "RESIGNAL", "RESTRICT", "RETURN",
			"REVOKE", "RIGHT", "RLIKE", "SCHEMA", "SCHEMAS", "SECOND_MICROSECOND", "SELECT", "SENSITIVE", "SEPARATOR", "SET",
			"SHOW", "SIGNAL", "SLOW", "SMALLINT", "SPATIAL", "SPECIFIC", "SQL", "SQLEXCEPTION", "SQLSTATE", "SQLWARNING",
			"SQL_BIG_RESULT", "SQL_CALC_FOUND_ROWS", "SQL_SMALL_RESULT", "SSL", "STARTING", "STRAIGHT_JOIN", "TABLE",
			"TERMINATED", "THEN", "TINYBLOB", "TINYINT", "TINYTEXT", "TO", "TRAILING", "TRIGGER", "TRUE", "UNDO", "UNION",
			"UNIQUE", "UNLOCK", "UNSIGNED", "UPDATE", "USAGE", "USE", "USING", "UTC_DATE", "UTC_TIME", "UTC_TIMESTAMP",
			"VALUES", "VARBINARY", "VARCHAR", "VARCHARACTER", "VARYING", "WHEN", "WHERE", "WHILE", "WITH", "WRITE", "XOR",
			"YEAR_MONTH", "ZEROFILL"
	);

	private static $whitespace = " \t\r\n";
	private static $alpha = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
	private static $num = "0123456789";
	private static $operators = "+-=*><!";

	/**
	 * New empty token
	 */
	public function __construct() {
		$this -> type = self::UNKNOWN;
		$this -> str = "";
		$this -> sub = array();
		$this -> has_sub = false; // For spotting ( )
	}

	/**
	 * Test if this is a keyword
	 *
	 * @param string $word
	 * @return boolean
	 */
	public static function is_keyword($word) {
		return in_array(strtoupper($word), self::$keywords);
	}

	/**
	 * Test for a character appearing in a string
	 *
	 * @param string $char
	 * @param string $list
	 * @return boolean
	 */
	public static function test($char, $list) {
		return !(strpos($list, $char) === false);
	}

	/**
	 * Test adding a single character
	 *
	 * @param string $char
	 * @return boolean
	 */
	public function add($char) {
		if($this -> str == "") {
			$this -> str .= $char;
			switch($char) {
				case ".":
					$this -> type = self::DOT;
					break;
				case ",":
					$this -> type = self::COMMA;
					break;
				case "(":
					$this -> type = self::OPENBRACKET;
					break;
				case ")":
					$this -> type = self::CLOSEBRACKET;
					break;
				case "'":
					$this -> type = self::STRING_LITERAL;
					break;
				case "`":
					$this -> type = self::IDENTIFIER;
					break;
				case ";":
					$this -> type = self::SEMICOLON;
					break;
				default:
					/* Whitespace */
					if($this -> test($char, self::$whitespace)) {
						$this -> type = self::WHITESPACE;
					}
					if($char != "-" && $this -> test($char, self::$operators)) {
						$this -> type = self::OPERATOR;
					}
					if($this -> test($char, self::$num)) {
						$this -> type = self::NUMBER_LITERAL;
					}
			}
			return true;
		} else {
			/* Whitespace */
			if($this -> type == self::WHITESPACE) {
				if($this -> test($char, self::$whitespace)) {
					$this -> str .= $char;
					return true;
				} else {
					return false;
				}
			}

			/* Quoted identifier */
			if($this -> type == self::IDENTIFIER) {
				if(strlen($this -> str) > 1 && substr($this -> str, -1) == '`') {
					return false;
				}
				if($this -> test($char, self::$alpha . self::$num . "_` ")) {
					$this -> str .= $char;
					return true;
				} else {
					return false;
				}
			}

			/* Comment */
			if($this -> type == self::UNKNOWN && $char == "-" && $this -> str == "-") {
				$this -> type = self::LINE_COMMENT;
			} else if($this -> type == self::UNKNOWN && $char == "-") {
				if($this -> test($char, self::$num)) {
					$this -> type = self::NUMBER_LITERAL;
				} else {
					$this -> type = self::OPERATOR;
				}
			}
			if($this -> type == self::LINE_COMMENT && substr($this -> str, -1) != "\n") {
				$this -> str .= $char;
				return true;
			}

			if($this -> type == self::OPERATOR) {
				if($this -> test($char, self::$operators)) {
					$this -> str .= $char;
					return true;
				}
			}

			/* Unknown */
			if($this -> type == self::UNKNOWN) {
				if($this -> test($char, self::$alpha . self::$num . "_@")) {
					$this -> str .= $char;
					return true;
				} else {
					if(self::is_keyword($this -> str)) {
						$this -> type = self::KEYWORD;
					} else {
						$this -> type = self::IDENTIFIER;
					}
				}
			}

			if($this -> type == self::NUMBER_LITERAL) {
				if($this -> test($char, self::$num . ".e+")) {
					$this -> str .= $char;
					return true;
				} else {
					return false;
				}
			}

			if($this -> type == self::STRING_LITERAL) {
				if(!$char != "'" && self::quotes_valid($this -> str)) {
					return false;
				}
				$this -> str .= $char;
				return true;
			}

			return false;
		}
	}

	/**
	 * Returns true if the quoting of a string is valid
	 *
	 * @param return $str
	 */
	public static function quotes_valid($str) {
		$str = str_replace("\\\\", "", $str); // Ignore backslashes that escape themselves
		$str = str_replace("\\'", "", $str); // Ignore quotes that are escaped
		$str = str_replace("''", "", $str); // Ignore quotes that are doubled
		if($str == "'") {
			return false;
		}
		return($str == "" || (substr($str, 0, 1) == "'" && substr($str, -1) == "'"));
	}

	/**
	 * Take `quotes` off an identifier if it has them
	 *
	 * @param string $str
	 */
	public static function get_identifier($str) {
		if(strlen($str) >= 2 && substr($str, 0, 1) == "`" && substr($str, -1) == "`") {
			return substr($str, 1, strlen($str) - 2);
		}
		return $str;
	}

	/**
	 * Take 'quotes' off a string literal (does not handle anything inside the string properly)
	 *
	 * @param string $str
	 */
	public static function get_string_literal($str) {
		if(strlen($str) >= 2 && substr($str, 0, 1) == "'" && substr($str, -1) == "'") {
			$str = stripslashes(substr($str, 1, strlen($str) - 2));
		}
		return $str;
	}

	public static function get_number_literal($str) {
		return (int)$str;
	}
}