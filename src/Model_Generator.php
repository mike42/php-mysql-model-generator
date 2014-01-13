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
		$this -> make_app_skeleton();
		foreach($this -> database -> table as $table) {
			$this -> make_model($table);
			$this -> make_controller($table);
		}
	}

	private function make_app_skeleton() {
		if(file_exists($this -> base)) {
			throw new Exception("Cannot save to " . $this -> base . ", a file/folder exists there.");
		}
		mkdir($this -> base);
		mkdir($this -> base . "/lib");
		mkdir($this -> base . "/lib/model");
		mkdir($this -> base . "/lib/controller");
		mkdir($this -> base . "/lib/util");
		mkdir($this -> base . "/site");
		mkdir($this -> base . "/public");
		copy(dirname(__FILE__) . "/template/config.php", $this -> base . "/site/config.php");
		copy(dirname(__FILE__) . "/template/database.php", $this -> base . "/lib/util/database.php");
		copy(dirname(__FILE__) . "/template/core.php", $this -> base . "/lib/core.php");
		copy(dirname(__FILE__) . "/template/index.php", $this -> base . "/index.php");
		
		/* Generate default permissions */
		$str = "<?php\n";
		$str .= "/* Permissions for database fields */\n";
		$roles = array('user', 'admin');

		foreach($roles as $role) {
			$str .= "\$permission['$role'] = array(\n";
			$foo = array();
			foreach($this -> database -> table as $table) {
				/* Quick list of columns */
				$cols = array();
				foreach($table -> cols as $colspec) {
					$cols[] = "\t\t\t'".$colspec -> name."'";
				}
				$col_list = (count($cols) > 0? "\n". implode(",\n", $cols) : "");
				$a = "\t'" . $table -> name . "' => array(\n";
				$a .= "\t\t'create' => true,\n";
				$a .= "\t\t'read' => array(". $col_list ."),\n";
				$a .= "\t\t'update' => array(". $col_list ."),\n";
				$a .= "\t\t'delete' => true)";
				$foo[] = $a;
			}
			$str .= implode(",\n", $foo) . ");\n";
		}
		file_put_contents($this -> base . "/site/permissions.php", $str);
	}
	
	private function make_model(SQL_Table $table) {
		/* Figure out PK */
		$pkfields = array();
		foreach($table -> pk as $fieldname) {
			$pkfields[] = "$fieldname = :$fieldname";
		}
		
		/* Figure out JOIN clause to use on every SELECT */
		$join = $this -> getJOIN($table -> name);
		
		/* Generate model */		
		$str = "<?php\nclass ".$table -> name . "_model {\n";
		foreach($table -> cols as $col) {
			/* Class variables */
			$str .= $this -> block_comment("@var " . $this -> primitive($col) . " " . $col -> name . ($col -> comment != ""? " " . $col -> comment: ""), 1);
			$str .= "\tprivate $" . $col -> name . ";\n\n";
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
				if($fk -> parent_table != $table -> name) { // Tables which reference themselves do not go well with this!
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
		$str .= "\n" . $this -> block_comment("Construct new " . $table -> name . " from field list\n\n@return array", 1);
		$str .= "\tpublic function __construct(array \$fields = array()) {\n";
		if(count($table -> cols) != 0) {
			foreach($table -> cols as $col) {
				$str .= "\t\tif(isset(\$fields['" . $table -> name . "." . $col -> name . "'])) {\n" .
					"\t\t\t\$this -> set_" . $col -> name . "(\$fields['" . $table -> name . "." . $col -> name . "']);\n" .
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
		
		/* To array */
		$str .= "\n" . $this -> block_comment("Convert " . $table -> name . " to shallow associative array\n\n@return array", 1);
		$str .= "\tprivate function to_array() {\n";
		$fieldlist = array();
		foreach($table -> cols as $col) {
				$fieldlist[] = "\t\t\t'".$col -> name . "' => \$this -> " . $col -> name . "";
		}
		$str .= "\t\t\$values = array(".(count($fieldlist) > 0 ? "\n" . implode(",\n", $fieldlist) : "") .  ");\n";
		$str .= "\t\treturn \$values;\n";
		$str .= "\t}\n";
		
		/* To restricted array (eg. for user output) */
		$str .= "\n" . $this -> block_comment("Convert " . $table -> name . " to associative array, including only visible fields,\n" .
				"parent tables, and loaded child tables\n\n" . 
				"@param string \$role The user role to use", 1);
		$str .= "\tpublic function to_array_filtered(\$role = \"anon\") {\n";
		// TODO filter for permissions
		$str .= "\t\t// TODO: Insert code for " . $table -> name . " permission-check\n";
		$str .= "\t}\n";
		
		/* From array('foo', 'bar', 'baz') to array('a.weeble' => 'foo', 'a.warble' => bar, 'b.beeble' => 'baz') */
		$str .= "\n" . $this -> block_comment("Convert retrieved database row from numbered to named keys, including table name\n\n@param array \$row ror retrieved from database\n@return array row with indices", 1);
		$str .= "\tprivate static function row_to_assoc(array \$row) {\n";
		$cols = array();
		foreach($join['fields'] as $num => $name) {
			$cols[] = "\t\t\t\"$name\" => \$row[$num]";
		}
		$str .= "\t\t\$values = array(". (count($cols) > 0? "\n". implode(",\n", $cols) : "") . ");\n";
		$str .= "\t\treturn \$values;\n";
		$str .= "\t}\n";
		
		/* Getters and setters */
		foreach($table -> cols as $col) {
			$str .= "\n" . $this -> block_comment("Get " . $col -> name . "\n\n@return " . $this -> primitive($col), 1);
			$str .= "\tpublic function get_" . $col -> name . "() {\n";
			$str .= "\t\tif(!isset(\$this -> model_variables_set['" . $col -> name . "'])) {\n";
			$str .= "\t\t\tthrow new Exception(\"" . $table -> name . "." . $col -> name . " has not been initialised.\");\n";
			$str .= "\t\t}\n";
			$str .= "\t\treturn \$this -> " . $col -> name .";\n";
			$str .= "\t}\n";
			$str .= "\n" . $this -> block_comment("Set " . $col -> name . "\n\n@param " . $this -> primitive($col) . " \$" . $col -> name, 1);
			$str .= "\t".(in_array($col -> name, $table -> pk) ? "private" : "public" ) . " function set_" . $col -> name . "($" . $col -> name . ") {\n";
			$str .= $this -> validate_type($table, $col);
			$str .= "\t\t\$this -> " . $col -> name ." = $" . $col -> name . ";\n";
			$str .= "\t\t\$this -> model_variables_changed['" . $col -> name ."'] = true;\n";
			$str .= "\t\t\$this -> model_variables_set['" . $col -> name ."'] = true;\n";
			$str .= "\t}\n";
		}

		/* Update */
		$str .= "\n" . $this -> block_comment("Update " . $table -> name, 1);
		$str .= "\tpublic function update() {\n" .
		 	"\t\tif(count(\$this -> model_variables_changed) == 0) {\n" .
		 	"\t\t\tthrow new Exception(\"Nothing to update\");\n" .
		 	"\t\t}\n\n" .
		 	"\t\t/* Compose list of changed fields */\n" .
		 	"\t\t\$fieldset = array();\n" .
		 	"\t\tforeach(\$this -> model_variables_changed as \$col => \$changed) {\n" .
		 	"\t\t\t\$fieldset[] = \"\$col = :\$col\";\n" .
		 	"\t\t}\n" .
		 	"\t\t\$fields = implode(\", \", \$fieldset);\n\n" .
		 	"\t\t/* Execute query */\n";
		$str .= "\t\t\$sth = database::\$dbh -> prepare(\"UPDATE ".$table -> name . " SET \$fields WHERE " . implode(" AND ", $pkfields). "\");\n";
		$str .= "\t\t\$sth -> execute(\$this -> to_array());\n";
		$str .= "\t}\n";

		/* Insert */
		$str .= "\n" . $this -> block_comment("Add new " . $table -> name, 1);
		$str .= "\tpublic function insert() {\n" .
		 	"\t\tif(count(\$this -> model_variables_changed) == 0) {\n" .
		 	"\t\t\tthrow new Exception(\"No fields have been set!\");\n" .
		 	"\t\t}\n\n" .
		 	"\t\t/* Compose list of set fields */\n" .
		 	"\t\t\$fieldset = array();\n" .
		 	"\t\tforeach(\$this -> model_variables_set as \$col => \$changed) {\n" .
		 	"\t\t\t\$fieldset[] = \$col;\n" .
		 	"\t\t\t\$fieldset_colon[] = \":\$col\";\n" .
		 	"\t\t}\n";
		$str .= "\t\t\$fields = implode(\", \", \$fieldset);\n" .
			"\t\t\$vals = implode(\", \", \$fieldset_colon);\n\n" .
			"\t\t/* Execute query */\n" .
			"\t\t\$sth = database::\$dbh -> prepare(\"INSERT INTO ".$table -> name . " (\$fields) VALUES (\$vals);\");\n";
		$str .= "\t\t\$sth -> execute(\$this -> to_array());\n";
		$str .= "\t}\n";

		/* Delete */
		$str .= "\n" . $this -> block_comment("Delete " . $table -> name, 1);
		$str .= "\tpublic function delete() {\n";
		$str .= "\t\t\$sth = database::\$dbh -> prepare(\"DELETE FROM ".$table -> name . " WHERE " . implode(" AND ", $pkfields). "\");\n";
		$str .= "\t\t\$sth -> execute(\$this -> to_array());\n";
		$str .= "\t}\n";

		/* Populate child tables */
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\n" . $this -> block_comment("List associated rows from " . $child . " table\n\n" .
							"@param int \$start Row to begin from. Default 0 (begin from start)\n" . 
							"@param int \$limit Maximum number of rows to retrieve. Default -1 (no limit)", 1);
				$str .= "\tpublic function populate_list_".$child . "(\$start = 0, \$limit = -1) {\n";
				$str .= "\t\t\$this -> list_".$child." = ".$child . "_model::list_by_".$fk -> name ."(". implode(",", $this -> listFields($this -> database -> table[$child], $fk -> child_fields, true)) .", \$start, \$limit);\n";
				$str .= "\t}\n";
			}
		}

		/* Get by primary key */
		$str .= "\n" . $this -> block_comment("Retrieve by primary key\n\n", 1);
		// TODO: Key info
		$str .= "\tpublic static function get(";
		$str .= implode(", ", $this -> listFields($table, $table -> pk, true));
		$str .= ") {\n";
		$conditions = $arrEntry = array();
		foreach($table -> pk as $field) {
			$conditions[] = $table -> name . "." . $field . " = :$field";
			$arrEntry[] = "'$field' => \$$field";
		}
		$sql = "SELECT " . implode(", ", $join['fields']) . " FROM " . $table -> name . " " . $join['clause'] . " WHERE " . implode(" AND ", $conditions);
		$str .= "\t\t\$sth = database::\$dbh -> prepare(\"$sql;\");\n";
		$str .= "\t\t\$sth -> execute(array(" . implode(", ", $arrEntry) . "));\n";
		$str .= "\t\t\$row = \$sth -> fetch(PDO::FETCH_NUM);\n";
		$str .= "\t\t\$assoc = self::row_to_assoc(\$row);\n";
		$str .= "\t\treturn new " . $table -> name . "_model(\$assoc);\n";
		$str .= "\t}\n";

		/* Get by unique indices */
		foreach($table -> unique as $unique) {
			$str .= "\n\tpublic static function get_by_".$unique -> name."(";
			$str .= implode(", ", $this -> listFields($table, $unique -> fields, true));
			$str .= ") {\n";
 			$conditions = $arrEntry = array();
 			foreach($unique -> fields as $field) {
 				$conditions[] = $table -> name . "." . $field . " = :$field";
 				$arrEntry[] = "'$field' => \$$field";
 			}
 			/* Similar to get() above */
 			$sql = "SELECT " . implode(", ", $join['fields']) . " FROM " . $table -> name . " " . $join['clause'] . " WHERE " . implode(" AND ", $conditions);
 			$str .= "\t\t\$sth = database::\$dbh -> prepare(\"$sql;\");\n";
 			$str .= "\t\t\$sth -> execute(array(" . implode(", ", $arrEntry) . "));\n";
 			$str .= "\t\t\$row = \$sth -> fetch(PDO::FETCH_NUM);\n";
 			$str .= "\t\t\$assoc = self::row_to_assoc(\$row);\n";
 			$str .= "\t\treturn new " . $table -> name . "_model(\$assoc);\n";
			$str .= "\t}\n";
		}

		/* List by other indices */
		foreach($table -> index as $index) {
			$str .= "\n" . $this -> block_comment("List rows by " . $index -> name . " index\n\n" .
					"@param int \$start Row to begin from. Default 0 (begin from start)\n" .
					"@param int \$limit Maximum number of rows to retrieve. Default -1 (no limit)", 1);
			$str .= "\tpublic static function list_by_".$index -> name."(";
			$str .= implode(", ", $this -> listFields($table, $index -> fields, true));
			$str .= ", \$start = 0, \$limit = -1) {\n";			
			$str .= "\t\t\$ls = \"\";\n" .
					"\t\t\$start = (int)\$start;\n" .
					"\t\t\$limit = (int)\$limit;\n" .
					"\t\tif(\$start > 0 && \$limit > 0) {\n" .
					"\t\t\t\$ls = \" LIMIT \$start, \" . (\$start + \$limit);\n" .
					"\t\t}\n";
 			$conditions = $arrEntry = array();
 			foreach($index -> fields as $field) {
 				$conditions[] = $table -> name . "." . $field . " = :$field";
 				$arrEntry[] = "'$field' => \$$field";
 			}
 			/* Query is again similar to get() above */
 			$sql = "SELECT " . implode(", ", $join['fields']) . " FROM " . $table -> name . " " . $join['clause'] . " WHERE " . implode(" AND ", $conditions);
 			$str .= "\t\t\$sth = database::\$dbh -> prepare(\"$sql\" . \$ls . \";\");\n";
 			$str .= "\t\t\$sth -> execute(array(" . implode(", ", $arrEntry) . "));\n";
			$str .= "\t\t\$rows = \$sth -> fetchAll(PDO::FETCH_NUM);\n" .
					"\t\t\$ret = array();\n" .
					"\t\tforeach(\$rows as \$row) {\n" .
					"\t\t\t\$assoc = self::row_to_assoc(\$row);\n" .
					"\t\t\t\$ret[] = new " . $table -> name . "_model(\$assoc);\n" .
					"\t\t}\n" .
					"\t\treturn \$ret;\n";
			$str .= "\t}\n";
		}

		/* Finalise and output */
		$str .= "}\n?>";
		file_put_contents($this -> base . "/lib/model/" . $table -> name . "_model.php", $str);
	}

	private function make_controller(SQL_Table $table) {
		$str = "<?php\nclass ".$table -> name . "_controller {\n";
		// Create
		$str .= "function create() {\n" .
				"}\n\n";
		
		// Read
		$str .= "function read() {\n" .
				"}\n\n";
		
		// Update
		$str .= "function update() {\n" .
				"}\n\n";
		
		// Delete
		$str .= "function delete() {\n" .
			 "}\n\n";
		
		$str .= "}\n?>";
		file_put_contents($this -> base . "/lib/controller/" . $table -> name . "_controller.php", $str);
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

	private function primitive(SQL_Colspec $col) {
		if($col -> type == "INT") {
			return "int";
		}
		return "string";
	}
	
	/**
	 * @param SQL_Table $table
	 * @param SQL_Colspec $col
	 * @return string
	 */
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
				return "\t\tif(!in_array(\$".$col -> name . ", self::\$".$col -> name."_values)) {\n" .
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
	
	private function block_comment($str, $indent) {
		$lines = explode("\n", $str);
		$outp = "";
		for($i = 0; $i < $indent; $i++) {
			$outp .= "\t";
		}
		$outp .= "/**\n";
		foreach($lines as $line) {
			for($i = 0; $i < $indent; $i++) {
				$outp .= "\t";
			}
			$outp .= " * " . $line . "\n";
		}
		for($i = 0; $i < $indent; $i++) {
			$outp .= "\t";
		}
		$outp .= " */\n";
		return $outp;
	}
	
	private function getJoin($fromTableName) {
		/* Breadth-first search for parent tables */
		$allfields = array();
		$queue = array();
		$ret = array();
		$visited = array($fromTableName);		
		foreach($this -> database -> table[$fromTableName] -> cols as $col) {
			$allfields[] = $fromTableName . "." . $col -> name;
		}
		foreach($this -> database -> table[$fromTableName] -> constraints as $constraint) {
			$constraint -> child_table = $fromTableName;
			$queue[] = $constraint;
		}
		
		while(count($queue) != 0) {
			$constraint = array_shift($queue);
			if(array_search($constraint -> parent_table, $visited) === false) {
				$visited[] = $constraint -> parent_table;
				
				$condition = array();
				foreach($constraint -> child_fields as $num => $field) {
					$condition[] = $constraint -> child_table . "." . $field . " = " . $constraint -> parent_table . "." . $constraint -> parent_fields[$num];
				}
				$ret[] = "JOIN " . $constraint -> parent_table . " ON " . implode(" AND ", $condition);
				foreach($this -> database -> table[$constraint -> parent_table] -> cols as $col) {
					$allfields[] = $constraint -> parent_table . "." . $col -> name;
				}
				foreach($this -> database -> table[$constraint -> parent_table] -> constraints as $sub_constraint) {
					$sub_constraint -> child_table = $constraint -> parent_table;
					$queue[] = $sub_constraint;
				}
			}
		}
		return array('clause' => implode(" ", $ret), 'fields' => $allfields);
	}
}