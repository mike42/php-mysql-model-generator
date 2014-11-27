<?php
class Model_Relationship {
	public $parent;
	public $constraint;
	
	public $nullable;
	public $shortName;
	
	public $toOne;

	public function __construct(SQL_Constraint $foreignKey, SQL_Database $database, array $children = array()) {
		$tbl = $database -> table[$foreignKey -> parent_table];
		$this -> constraint = $foreignKey;
		$this -> parent = new Model_Entity($tbl, $database, $children);
		
		$this -> shortName = self::filterName($this -> constraint -> name, $children[count($children) - 1]);
		
		$this -> toOne = true;
		if($foreignKey -> reversed) {
			$this -> nullable = true;
			$this -> toOne = false;
			;
			/* Check if the foreign key comprises a single, unique field */
			if(count($foreignKey -> parent_fields) == 1) {
				$field = $foreignKey -> parent_fields[0];
				$t = $database -> table[$foreignKey -> parent_table];

				/* Count primary key and UNIQUE indexes only */
				$unique_keys = array($t -> pk);
				foreach($t -> unique as $u) {
					$unique_keys[] = $u -> fields;
				}
				
				foreach($unique_keys as $u) {
					if(count($u) == 1 && $u[0] == $field) {
						$this -> toOne = true;
					}
				}
			}
		} else {
			/* Check if any of the fields in the foreign key are nullable */
			$this -> nullable = false;
			foreach($foreignKey -> child_fields as $field_name) {
				$this -> nullable |= $database -> table[$children[count($children) - 1]] -> cols[$field_name] -> nullable;
			}
		}
	}
	
	private static function filterName($name, $child) {
		/* Re-name relationship  */
		$pref = array("fk_", $child . "_");
		$suf = array("_idx");
		
		foreach($pref as $p) {
			if(substr($name, 0, strlen($p)) == $p) {
				$name = substr($name, strlen($p), strlen($name) - strlen($p));
			}	
		}
		
		foreach($suf as $s) {
			if(substr($name, strlen($name) - strlen($s), strlen($s)) == $s) {
				$name = substr($name, 0, strlen($name) - strlen($s));
			}
		}
		return $name;
	}

}
