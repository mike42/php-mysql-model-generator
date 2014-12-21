<?php
class Model_Index {
	/* Higher-level version of SQL_Index */
	public $name;
	public $fields;
	public $isUnique;
	
	public function __construct($fields, $name, $isUnique) {
		$this -> name = $name;
		$this -> fields = $fields;
		$this -> isUnique = $isUnique;
	}
	
	public static function addIndex(array $indices, Model_Index $new) {
		// TODO guarantee that names are unique (low-priority).
		foreach($indices as $key => $index) {
			if(self::field_match($new -> fields, $index -> fields)) {
				if($new -> isUnique) {
					$indices[$key] -> isUnique = true;
				}
				return $indices; // Already on the list
			}
		}
		$indices[] = $new;
		return $indices;
	}
	
	/**
	 * Look up a field list in a list of indices, and return the one that matches.
	 * 
	 * @param array $indices
	 * @param array $fields
	 * @throws Exception
	 * @return unknown
	 */
	public static function retrieveFieldIndex(array $indices, array $fields) {
		foreach($indices as $key => $index) {
			if(self::field_match($fields, $index -> fields)) {
				return $index;
			}
		}
		throw new Exception("Index not found with fields specified: " . implode(", ", $fields));
	}
	
	/**
	 * Look up relationship in a list of indices, and return the one that matches.
	 * 
	 * @param array $indices
	 * @param Model_Relationship $rel
	 * @throws Exception
	 * @return unknown
	 */
	public static function retrieveParentIndex(array $indices, Model_Relationship $rel) {
		$new = Model_Index::fromModel_Relationship($rel);
		foreach($indices as $key => $index) {
			if(self::field_match($new -> fields, $index -> fields)) {
				return $index;
			}
		}
		throw new Exception("Index not found with fields specified: " . implode(", ", $new -> fields));
	}
	
	/**
	 * Look up relationship in a list of indices, and return the one that matches.
	 *
	 * @param array $indices
	 * @param Model_Relationship $rel
	 * @throws Exception
	 * @return unknown
	 */
	public static function retrieveChildIndex(Model_Relationship $child) {
		$new = Model_Index::fromModel_RelationshipRev($child);
		foreach($child -> dest -> index as $key => $index) {
			if(self::field_match($new -> fields, $index -> fields)) {
				return $index;
			}
		}
		throw new Exception("Index not found with fields specified: " . implode(", ", $new -> fields));
	}
	
	public function getFunctionName() {
		if($this -> name === null) {
			return "get";
		}
		$f = $this -> isUnique ? "get" : "list";
		return "${f}By".Model_Generator::titleCase($this -> name);
	}
	
	public static function fromSQL_Index(SQL_Index $orig) {
		$name = Model_Relationship::filterName($orig -> name, "");
		return new Model_Index($orig -> fields, $name, false);
	}
	
	public static function fromSQL_Unique(SQL_Unique $orig) {
		$name = Model_Relationship::filterName($orig -> name, "");
		return new Model_Index($orig -> fields, $name, true);
	}
	
	public static function fromModel_Relationship(Model_Relationship $orig) {
		return new Model_Index($orig -> constraint -> child_fields, $orig -> dest -> model_storage_name, false);
	}
	
	public static function fromModel_RelationshipRev(Model_Relationship $orig) {
		return new Model_Index($orig -> constraint -> parent_fields, $orig -> dest -> model_storage_name, false);
	}
	
	/**
	 * Check if two lists of fields are equal
	 *
	 * @param array $f1
	 * @param array $f2
	 * @return boolean
	 */
	private static function field_match(array $f1, array $f2) {
		sort($f1);
		sort($f2);
		if(count(!$f1) != count($f2)) {
			return false;
		}
		for($i = 0; $i < count($f1); $i++) {
			if(!isset($f1[$i]) || !isset($f2[$i]) || $f1[$i] != $f2[$i]) {
				return false;
			}
		}
		return true;
	}
}