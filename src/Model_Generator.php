<?php
class Model_Generator {
	private $database;
	private $base; // Base dir

	public function __construct(SQL_Database $database) {
		$this -> database = $database;
		$this -> base = dirname(__FILE__) . "/../" .$database -> name;
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
		$str .= "\tprivate \$model_variables_changed;\n";
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

		/* Constructor */
		$str .= "\n\tpublic function __construct(";
		$str .= implode(", ", $this -> listFields($table, false, true));
		$str .= ") {\n";
		if(count($table -> cols) != 0) {
			foreach($table -> cols as $col) {
				$str .= "\t\t\$this -> set_" . $col -> name . "($" . $col -> name . ");\n";
			}
			$str .= "\n";
		}
		$str .= "\t\t\$this -> model_variables_changed = array();\n";
		$str .= "\t}\n";


		/* Getters and setters */
		foreach($table -> cols as $col) {
			$str .= "\n\tpublic function get_" . $col -> name . "() {\n";
			$str .= "\t\treturn \$this -> " . $col -> name .";\n";
			$str .= "\t}\n";
			$str .= "\n\t".(in_array($col -> name, $table -> pk) ? "private" : "public" ) . " function set_" . $col -> name . "($" . $col -> name . ") {\n";
			$str .= $this -> validate_type($table, $col);
			$str .= "\t\t\$this -> " . $col -> name ." = $" . $col -> name . ";\n";
			$str .= "\t\t\$this -> model_variables_changed['" . $col -> name ."'] = true;\n";
			$str .= "\t}\n";
		}


		/* finalise and output */
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
				$ret[] = ($php?"$":"") . $table -> col[$field] -> name;
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
}