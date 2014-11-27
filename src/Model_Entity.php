<?php
require_once(dirname(__FILE__) . "/Model_Relationship.php");

/**
 * Form a tree from this (parent) table for greedy fetching
 */
class Model_Entity {
	public $child;
	public $parent;
	public $table;

	public function __construct(SQL_Table $table, SQL_Database $database, array $children = array()) {
		/* Basic setup */
		$this -> child = $this -> parent = array();
		$this -> table = $table;

		if(array_search($table -> name, $children) !== false) {
			/* Only recurse if this item has not yet appeared in the tree */
			return;
		}

		/* Add actual parent tables */
		$newChildren = $children;
		$newChildren[] = $table -> name;
		foreach($table -> constraints as $constraint) {
			$this -> parent[] = new Model_Relationship($constraint, $database, $newChildren);
		}

		/* Create reverse lookup for child tables */
		foreach($database -> table as $t) {
			foreach($t -> constraints as $constraint) {
				if($constraint -> parent_table == $table -> name) {
					$match = false;
					foreach($this -> parent as $p) {
						if($p -> constraint -> name == $constraint -> name) {
							$match = true;
						}
					}

					if(!$match) {
						/* Flip the constraint */
						$revConstraint = clone $constraint;
						$revConstraint -> child_table = $t -> name;
						$revConstraint -> reverse();
						
						$this -> child[] = new Model_Relationship($revConstraint, $database, array($t -> name));
					}
				}
			}
		}
	}

	public function toGraphVizDot($id = null, $name = null, $toOne = false, $isChild = false) {
		if($id == null) {
			$col = 1;
			$id = $name = $this -> table -> name;
			$ret = array("\"" . $id . "\" [label=\"" . $this -> table -> name . "\",fillcolor=$col];");
		} else {
			if($isChild) {
				$col = 9;
			} else {
				$col = 2;
			}
			$ret = array("\"" . $id . "\" [label=\"$name : " . $this -> table -> name . (!$toOne ? "[]" : "") . "\",fillcolor=$col];");
		}

		foreach($this -> parent as $next) {
			$new_Id = $id.".".$next -> constraint -> name;
			$ret = array_merge($ret, $next -> parent -> toGraphVizDot($new_Id, $next -> shortName, true));
			$dot = $next -> nullable ? " [arrowhead=teeodot]" : " [arrowhead=tee]";
			$ret[] = "\"" . $id . "\" -> \"" . $new_Id . "\"$dot;";
		}
		
		foreach($this -> child as $next) {
			$new_Id = $id.".child-".$next -> constraint -> name;
			$ret = array_merge($ret, $next -> parent -> toGraphVizDot($new_Id, $next -> parent -> table -> name . "_by_" . $next -> shortName, $next -> toOne, true));
			$dot = $next -> toOne ? " [arrowhead=teeodot,style=dashed]" : " [arrowhead=crow]";
			$ret[] = "\"" . $id . "\" -> \"" . $new_Id . "\"$dot;";
		}
		
		return $ret;
	}

	/**
	 * Find the name of an index matching the field list given
	 *
	 * @param SQL_Table $table
	 * @param array $child_fields
	 * @return boolean
	 */
	private static function find_index(SQL_Table $table, array $child_fields) {
		foreach($table -> index as $index) {
			if(self::field_match($index -> fields, $child_fields)) {
				return $index -> name;
			}
		}
		return false;
	}

	/**
	 * Check if two lists of fields are equal
	 *
	 * @param array $f1
	 * @param array $f2
	 * @return boolean
	 */
	public static function field_match(array $f1, array $f2) {
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


	/// ...


	// 	private function getJoin($fromTableName) {
	// 		return $this -> getRelated(array($fromTableName));




	// 		return;
	// 		/* Breadth-first search for parent tables */
	// 		$allfields = $allfields_notick = array();
	// 		$queue = array();
	// 		$ret = array();
	// 		$visited = array($fromTableName);
	// 		foreach($this -> database -> table[$fromTableName] -> cols as $col) {
	// 			$allfields[] = "`" . $fromTableName . "`.`" . $col -> name . "`";
	// 			$allfields_notick[] = $fromTableName . "." . $col -> name;
	// 		}


	// 		while(count($queue) != 0) {
	// 			$constraint = array_shift($queue);
	// 			if(array_search($constraint -> parent_table, $visited) === false) {
	// 				$visited[] = $constraint -> parent_table;

	// 				$condition = array();
	// 				foreach($constraint -> child_fields as $num => $field) {
	// 					$condition[] = "`" . $constraint -> child_table . "`.`" . $field . "` = `" . $constraint -> parent_table . "`.`" . $constraint -> parent_fields[$num] . "`";
	// 				}
	// 				$ret[] = "JOIN `" . $constraint -> parent_table . "` ON " . implode(" AND ", $condition);
	// 				foreach($this -> database -> table[$constraint -> parent_table] -> cols as $col) {
	// 					$allfields[] = "`".$constraint -> parent_table . "`.`".$col -> name ."`";
	// 					$allfields_notick[] = $constraint -> parent_table . ".".$col -> name;
	// 				}
	// 				foreach($this -> database -> table[$constraint -> parent_table] -> constraints as $sub_constraint) {
	// 					$sub_constraint -> child_table = $constraint -> parent_table;
	// 					$queue[] = $sub_constraint;
	// 				}
	// 			}
	// 		}
	// 		return array('clause' => implode(" ", $ret), 'fields' => $allfields, 'fields-notick' => $allfields_notick);
	// 	}

	// 	private function getRelated(array $parent_tables) {
	// 		$queue = array();
	// 		$fromTableName = $parent_tables[count($parent_tables) - 1];
	// 		foreach($this -> database -> table[$fromTableName] -> constraints as $constraint) {

	// 		}
	// 		return array();
	// 	}
}