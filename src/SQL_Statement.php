<?php
require_once(dirname(__FILE__) . "/SQL_Token.php");

class SQL_Statement {
	public $token = array();
	public $type;

	/* Statement types found in schemas */
	const UNKNOWN = 'UNKNOWN';
	const ALTER_DATABASE = 'ALTER DATABASE';
	const ALTER_FUNCTION = 'ALTER FUNCTION';
	const ALTER_PROCEDURE = 'ALTER PROCEDURE';
	const ALTER_TABLE = 'ALTER TABLE';
	const ALTER_VIEW = 'ALTER VIEW';
	const CREATE_DATABASE = 'CREATE DATABASE';
	const CREATE_FUNCTION = 'CREATE FUNCTION';
	const CREATE_INDEX = 'CREATE INDEX';
	const CREATE_PROCEDURE = 'CREATE PROCEDURE';
	const CREATE_TABLE = 'CREATE TABLE';
	const CREATE_TRIGGER = 'CREATE TRIGGER';
	const CREATE_VIEW = 'CREATE VIEW';
	const DROP_DATABASE = 'DROP DATABASE';
	const DROP_FUNCTION = 'DROP FUNCTION';
	const DROP_INDEX = 'DROP INDEX';
	const DROP_PROCEDURE = 'DROP PROCEDURE';
	const DROP_TABLE = 'DROP TABLE';
	const DROP_TRIGGER = 'DROP TRIGGER';
	const DROP_VIEW = 'DROP VIEW';
	const RENAME_TABLE = 'RENAME TABLE';
	const TRUNCATE_TABLE = 'TRUNCATE TABLE';
	const SET = 'SET';

	private static $map = array(
			"alter" => array(
					"database" => self::ALTER_DATABASE,
					"function" => self::ALTER_FUNCTION,
					"procedure"=> self::ALTER_PROCEDURE,
					"table"    => self::ALTER_TABLE,
					"view"     => self::CREATE_VIEW
			),
			"create" => array(
					"database" => self::CREATE_DATABASE,
					"function" => self::CREATE_FUNCTION,
					"index" => self::CREATE_INDEX,
					"procedure" => self::CREATE_PROCEDURE,
					"table" => self::CREATE_TABLE,
					"trigger" => self::CREATE_TRIGGER,
					"view" => self::CREATE_VIEW
			),
			"drop" => array(
					"database" => self::DROP_DATABASE,
					"function" => self::DROP_FUNCTION,
					"index" => self::DROP_INDEX,
					"procedure" => self::DROP_PROCEDURE,
					"table" => self::DROP_TABLE,
					"trigger" => self::DROP_TRIGGER,
					"view" => self::DROP_VIEW
			),
			"rename" => array(
					"table" => self::RENAME_TABLE
			),
			"truncate" => array(
					"table" => self::TRUNCATE_TABLE
			),
			"set" => self::SET
	);

	function __construct($tokens) {
		$this -> token = $tokens;

		/* Identify the statement */
		$candidates = array(self::UNKNOWN);
		$prev = self::$map;
		foreach($tokens as $token) {
			if($token -> type != SQL_Token::KEYWORD || !is_array($prev)) {
				break;
			}
			if(!isset($prev[strtolower($token -> str)])) {
				break;
			}
			$prev = $prev[strtolower($token -> str)];
			$candidates[] = $prev;
		}

		/* Correction for half-complete statements, eg "CREATE `foo`" */
		do {
			$type = array_pop($candidates);
		} while(is_array($type));
		$this -> type = $type;
	}
}