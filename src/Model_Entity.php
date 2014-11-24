<?php
class Model_Table {
	public function __construct(SQL_Table $table) {
			
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
// 		foreach($this -> database -> table[$fromTableName] -> constraints as $constraint) {
// 			$constraint -> child_table = $fromTableName;
// 			$queue[] = $constraint;
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