<?php
require_once(dirname(__FILE__) . "/Model_Relationship.php");

/**
 * Form a tree from this (parent) table for greedy fetching
 */
class Model_Entity {
	public $child;
	public $parent;
	public $table;

	public $query_table_name; // For "SELECT Foo As Foo2". Unique in tree
	public $model_storage_name; // Unique among siblings only

	/**
	 * @param SQL_Table $table
	 * @param SQL_Database $database
	 * @param array $children
	 */
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

		/* Ensure uniqueness of parent and child names as siblings */
		$this -> model_storage_name = $this -> table -> name; // Later over-ridden by parent if this isn't the root.
		$nameTaken = array();
		foreach($this -> parent as $rel) {
			/* Find a unique name for this entity for the PHP model */
			$num = 0;
			do {
				$testName = $rel -> shortName . ($num == 0 ? "" : $num);
				$num++;
			} while(isset($nameTaken[$testName]));
			$nameTaken[$testName] = true;
			$rel -> dest -> model_storage_name = $testName;
		}
		foreach($this -> child as $rel) {
			/* More uniqueness */
			$num = 0;
			do {
				if($table -> name == $rel -> shortName) {
					$testName = $rel -> dest -> table -> name . ($num == 0 ? "" : $num);
				} else {
					$testName = $rel -> dest -> table -> name . "_by_" . $rel -> shortName . ($num == 0 ? "" : $num);
				}
				$num++;
			} while(isset($nameTaken[$testName]));
			$nameTaken[$testName] = true;
			$rel -> dest -> model_storage_name = $testName;
		}

		/* Breadth-first search to name all sub-tables uniquely */
		$this -> query_table_name = $this -> table -> name;
		$nameTaken = array($this -> query_table_name);
		$queue = array($this);
		while(count($queue) != 0) {
			$current = array_shift($queue);

			/* Find a unique name for this entity when querying */
			$num = 0;
			do {
				$testName = $current -> table -> name . ($num == 0 ? "" : $num);
				$num++;
			} while(isset($nameTaken[$testName]));
			$nameTaken[$testName] = true;
			$current -> query_table_name = $testName;

			/* Add more */
			foreach($current -> parent as $p) {
				$queue[] = $p -> dest;
			}
		}
	}

	public function toGraphVizDotFile() {
		return "digraph G {\n    overlap=false;rankdir=LR;splines=true;    \n    node[shape=record,colorscheme=set39,style=filled];\n    ".implode("\n    ", $this -> toGraphVizDot()) . "\n}\n";
	}

	/**
	 * Generate GraphViz code for a table
	 *
	 * @param string $id
	 * @param string $name
	 * @param boolean $toOne
	 * @param boolean $isChild
	 * @return multitype:
	 */
	private function toGraphVizDot($id = null, $toOne = true, $isChild = false) {
		if($id  == null) {
			$col = 1;
			$id = $this -> query_table_name;
		} else if ($isChild) {
			$col = 9;
		} else {
			$col = 2;
		}
		$ret = array("\"" . $id . "\" [label=\"" . $this -> model_storage_name . " : " . $this -> table -> name . (!$toOne ? "[]" : "") . "\",fillcolor=$col];");

		foreach($this -> parent as $next) {
			$ret = array_merge($ret, $next -> dest -> toGraphVizDot($next -> dest -> query_table_name, true, false));
			$dot = $next -> nullable ? " [arrowhead=teeodot]" : " [arrowhead=tee]";
			$ret[] = "\"" . $id . "\" -> \"" . $next -> dest -> query_table_name . "\":w$dot;";
		}

		foreach($this -> child as $childId => $next) {
			$nextId = $this -> query_table_name . "-child-" . $next -> dest -> model_storage_name;
			$ret = array_merge($ret, $next -> dest -> toGraphVizDot($nextId, $next -> toOne, true));
			$dot = $next -> toOne ? " [arrowhead=teeodot,style=dashed]" : " [arrowhead=crowodot,style=dashed]";
			$ret[] = "\"" . $id . "\" -> \"" . $nextId . "\":w$dot;";
		}

		return $ret;
	}

	/**
	 * Extract a data structure which can be used to build joins and variables.
	 * 
	 * @return Ambigous <multitype:multitype: NULL Ambigous <multitype:multitype:multitype:NULL, multitype:multitype:multitype:NULL   > , multitype:multitype: Ambigous <multitype:multitype:NULL, multitype:multitype:NULL multitype:string   > >
	 */
	public function process() {
		$ret = array();
		$ret['fields'] = self::extractFields($this);
		$ret['join'] = array();

		foreach($this -> parent as $p) {
			/* Join to the parent table */
			$ret['join'][] = array("table" => $p -> dest -> table -> name, "as" =>  $p -> dest -> query_table_name, "on" => self::extractIndexFields($this, $p));
				
			/* Merge lower-level info in */
			$sub = $p -> dest -> process();
			foreach($sub['fields'] as $id => $f) {
				array_unshift($sub['fields'][$id]['var'], $p -> dest -> model_storage_name);
			}
			
			$ret['fields'] = array_merge($ret['fields'], $sub['fields']);
			$ret['join'] = array_merge($ret['join'], $sub['join']);
		}
		return $ret;
	}

	/**
	 * Extract list of fields for a table, labelled by the actual table
	 * 
	 * @param Model_Entity $e
	 * @return multitype:multitype:NULL  
	 */
	private static function extractFields(Model_Entity $e) {
		$ret = array();
		foreach($e -> table -> cols as $col) {
			$ret[] = array(
					"table" => $e -> query_table_name,
					"table_orig" => $e -> table -> name,
					"col" => $col -> name,
					"var" => array()
				);
		}
		return $ret;
	}

	/**
	 * Extract fields to JOIN on
	 * 
	 * @param Model_Entity $child
	 * @param Model_Relationship $r
	 * @throws Exception
	 * @return multitype:multitype:multitype:NULL  multitype:NULL string   
	 */
	private static function extractIndexFields(Model_Entity $child, Model_Relationship $r) {
		if(count($r -> constraint -> child_fields) != count($r -> constraint -> parent_table)) {
			throw new Exception("Index size mis-match: " . $r -> constraint -> name);
		}

		$ret = array();
		for($i = 0; $i < count($r -> constraint -> child_fields); $i++) {
			$ret[] = array(
				array("table" => $r -> dest -> query_table_name, "col" => $r -> constraint -> parent_fields[$i]),
				array("table" => $child -> query_table_name, "col" => $r -> constraint -> child_fields[$i])
			);
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
		// TODO find all uses of this function and remove them
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