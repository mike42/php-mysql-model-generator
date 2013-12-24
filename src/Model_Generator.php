<?php
class Model_Generator {
	private $database;
	private $base; // Base dir
	private $rev_constraints;

	public function __construct(SQL_Database $database) {
		$this -> database = $database;
		$this -> base = dirname(__FILE__) . "/../" .$database -> name;
		/* Create reverse lookup for foreign keys */
		foreach($this -> database -> table as $table) {
			foreach($table -> constraints as $constraint) {
				$tmp = $constraint;
				if($idx_name = $this -> find_index($table, $tmp -> child_fields)) {
					$tmp -> name = $idx_name;
					$this -> rev_constraints[$constraint -> parent_table][$table -> name] = $tmp;
				}
			}
		}
	}

	public function generate() {
		@mkdir($this -> base);
		@mkdir($this -> base . "/model");
		@mkdir($this -> base . "/view");
		@mkdir($this -> base . "/controller");
		foreach($this -> database -> table as $table) {
			$this -> make_model($table);
		}
	}

	private function make_model(SQL_Table $table) {
		$str = "<?php\nclass ".$table -> name . "_model {\n";
		foreach($table -> cols as $col) {
			/* Class variables */
			$str .= "\tprivate $" . $col -> name . ";\n";
		}
		$str .= "\tprivate \$model_variables_changed; // Only variables which have been changed\n";
		$str .= "\tprivate \$model_variables_set; // All variables which have been set (initially or with a setter)\n";
		foreach($table -> cols as $col) {
			/* Enum values */
			if($col -> type == "ENUM") {
				$val = array();
				foreach($col -> values as $v) {
					$val[] = "'".$v."'";
				}
				$str .= "\tprivate static $" . $col -> name . "_values = array(".implode(", ", $val) . ");\n";
			}
		}

		/* Parent tables */
		if(count($table -> constraints) != 0) {
			$str .= "\n\t/* Parent tables */\n";
			foreach($table -> constraints as $fk) {
				if($fk -> parent_table != $table -> name) { // Tables which reference themselves do not go well with this
					$str .= "\tpublic \$".$fk -> parent_table . ";\n";
				}
			}
		}

		/* Child tables */
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			$str .= "\n\t/* Child tables */\n";
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\tpublic \$list_".$child . ";\n";
			}
		}

		/* Constructor */
		$str .= "\n\tpublic function __construct(array \$fields = array()) {\n";
		if(count($table -> cols) != 0) {
			foreach($table -> cols as $col) {
				$str .= "\t\tif(isset(\$fields['" . $col -> name . "'])) {\n" .
					"\t\t\t\$this -> set_" . $col -> name . "(\$fields['" . $col -> name . "']);\n" .
					"\t\t}\n";
			}
			$str .= "\n";
		}
		$str .= "\t\t\$this -> model_variables_changed = array();\n";
		foreach($table -> constraints as $fk) {
			if($fk -> parent_table != $table -> name) { // Tables which reference themselves do not go well with this
				$str .= "\t\t\$this -> ".$fk -> parent_table . " = new " . $fk -> parent_table . "_model(\$fields);\n";
			}
		}
		$str .= "\t}\n";


		/* Getters and setters */
		foreach($table -> cols as $col) {
			$str .= "\n\tpublic function get_" . $col -> name . "() {\n";
			$str .= "\t\tif(!isset(\$this -> model_variables_set['" . $col -> name . "'])) {\n";
			$str .= "\t\t\tthrow new Exception(\"" . $table -> name . "." . $col -> name . " has not been initialised.\");\n";
			$str .= "\t\t}\n";
			$str .= "\t\treturn \$this -> " . $col -> name .";\n";
			$str .= "\t}\n";
			$str .= "\n\t".(in_array($col -> name, $table -> pk) ? "private" : "public" ) . " function set_" . $col -> name . "($" . $col -> name . ") {\n";
			$str .= $this -> validate_type($table, $col);
			$str .= "\t\t\$this -> " . $col -> name ." = $" . $col -> name . ";\n";
			$str .= "\t\t\$this -> model_variables_changed['" . $col -> name ."'] = true;\n";
			$str .= "\t\t\$this -> model_variables_set['" . $col -> name ."'] = true;\n";
			$str .= "\t}\n";
		}

		/* Update */
		// TODO
		$str .= "\n\tpublic function update() {\n";
		$str .= "\t\t// TODO: Update code for " . $table -> name . "\n";
		$str .= "\t}\n";

		/* Insert */
		// TODO
		$str .= "\n\tpublic function insert() {\n";
		$str .= "\t\t// TODO: Insert code for " . $table -> name . "\n";
		$str .= "\t}\n";

		/* Delete */
		// TODO
		$str .= "\n\tpublic function delete() {\n";
		$str .= "\t\t// TODO: Delete code for " . $table -> name . "\n";
		$str .= "\t}\n";

		/* Populate child tables */
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\n\tpublic  function populate_list_".$child . "(\$start = 0, \$limit = -1) {\n";
				$str .= "\t\t\$this -> list_".$child." = ".$child . "_model::list_by_".$fk -> name ."(". implode(",", $this -> listFields($this -> database -> table[$child], $fk -> child_fields, true)) .", \$start, \$limit);\n";
				$str .= "\t}\n";
			}
		}

		/* Get by primary key */
		// TODO
		$str .= "\n\tpublic static function get(";
		$str .= implode(", ", $this -> listFields($table, $table -> pk, true));
		$str .= ") {\n";
		$str .= "\t\t// TODO: Code to retrieve " . $table -> name . " by primary key\n";
		$str .= "\t}\n";

		/* Get by unique indices */
		// TODO
		foreach($table -> unique as $unique) {
			$str .= "\n\tpublic static function get_by_".$unique -> name."(";
			$str .= implode(", ", $this -> listFields($table, $unique -> fields, true));
			$str .= ") {\n";
			$str .= "\t\t// TODO: Code to retrieve " . $table -> name . " by ".$unique -> name."\n";
			$str .= "\t}\n";
		}

		/* List by other indices */
		// TODO
		foreach($table -> index as $index) {
			$str .= "\n\tpublic static function list_by_".$index -> name."(";
			$str .= implode(", ", $this -> listFields($table, $index -> fields, true));
			$str .= ", \$start = 0, \$limit = -1) {\n";
			$str .= "\t\t// TODO: Code to list " . $table -> name . " by ".$index -> name."\n";
			$str .= "\t}\n";
		}

		/* Finalise and output */
		$str .= "}\n?>";
		file_put_contents($this -> base . "/model/" . $table -> name . "_model.php", $str);
	}

	private function listFields(SQL_Table $table, $fields = false, $php = false) {
		$ret = array();
		if(!$fields) {
			foreach($table -> cols as $col) {
				$ret[] = ($php?"$":"") . $col -> name;
			}
		} else {
			foreach($fields as $field) {
				$ret[] = ($php?"$":"") . $table -> cols[$field] -> name;
			}
		}
		return $ret;
	}

	private function validate_type(SQL_Table $table, SQL_Colspec $col) {
		if($col -> type == "INT") {
			return "\t\tif(!is_numeric(\$".$col -> name . ")) {\n" .
				"\t\t\tthrow new Exception(\"" . $table -> name . "." . $col -> name . " must be numeric\");\n" .
				"\t\t}\n";
		} else if($col -> type == "VARCHAR") {
			if(isset($col -> size[0])) {
				return "\t\tif(strlen(\$".$col -> name . ") > ".$col -> size[0]. ") {\n" .
					"\t\t\tthrow new Exception(\"" . $table -> name . "." . $col -> name . " cannot be longer than ".$col -> size[0]. " characters\");\n" .
					"\t\t}\n";
			}
		} else if($col -> type == "ENUM") {
			if(count($col -> values) > 0) {
				return "\t\tif(!in_array(\$".$col -> name . ", self::\$".$col -> name."_values) {\n" .
						"\t\t\tthrow new Exception(\"" . $table -> name . "." . $col -> name . " must be one of the defined values.\");\n" .
						"\t\t}\n";
			}
		} else if($col -> type == "CHAR") {
			if(isset($col -> size[0]) > 0) {
				return "\t\tif(strlen(\$".$col -> name . ") != ".$col -> size[0]. ") {\n" .
					"\t\t\tthrow new Exception(\"" . $table -> name . "." . $col -> name . " must consist of ".$col -> size[0]. " characters\");\n" .
					"\t\t}\n";
			}
		} else if($col -> type == "TEXT") {
			return "\t\t// TODO: Add TEXT validation to " . $table -> name . "." . $col -> name . "\n";
		}
		return "\t\t// TODO: Add validation to " . $table -> name . "." . $col -> name . "\n";
	}

	/**
	 * Find the name of an index matching the field list given
	 *
	 * @param SQL_Table $table
	 * @param array $child_fields
	 * @return boolean
	 */
	private function find_index(SQL_Table $table, array $child_fields) {
		foreach($table -> index as $index) {
			if($this -> field_match($index -> fields, $child_fields)) {
				return $index -> name;
			}
		}
		print_r($child_fields); print_r($table);
		return false;
	}

	/**
	 * Check if two lists of fields are equal
	 *
	 * @param array $f1
	 * @param array $f2
	 * @return boolean
	 */
	private function field_match(array $f1, array $f2) {
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