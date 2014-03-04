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
		$this -> backbone_models($this -> database);
	}

	private function make_app_skeleton() {
		if(file_exists($this -> base)) {
			throw new Exception("Cannot save to " . $this -> base . ", a file/folder exists there.");
		}
		
		$cmd = sprintf("cp -R %s %s", dirname(__FILE__) . "/template", $this -> base);
		system($cmd);
		
		mkdir($this -> base . "/lib/model");
		
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

		/* Init and load related tables */
		$str .= "\n" . $this -> block_comment("Initialise and load related tables", 1);
		$str .= "\tpublic static function init() {\n";
		$str .= "\t\tcore::loadClass(\"database\");\n";
		if(count($table -> constraints) != 0) {
			foreach($table -> constraints as $fk) {
				if($fk -> parent_table != $table -> name) { // Tables which reference themselves do not go well with this!
					$str .= "\t\tcore::loadClass(\"".$fk -> parent_table . "_model\");\n";
				}
			}
		}
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			$str .= "\n\t\t/* Child tables */\n";
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\t\tcore::loadClass(\"".$child . "_model\");\n";
			}
		}
		$str .= "\t}\n";
		
		/* Constructor */
		$str .= "\n" . $this -> block_comment("Construct new " . $table -> name . " from field list\n\n@return array", 1);
		$str .= "\tpublic function __construct(array \$fields = array()) {\n";
		if(count($table -> cols) != 0) {
			$str .= "\t\t/* Initialise everything as blank to avoid tripping up the permissions fitlers */\n";
			foreach($table -> cols as $col) {
				$str .= "\t\t\$this -> " . $col -> name . " = '';\n";
			}
			$str .= "\n";
			
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
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\t\t\$this -> list_".$child . " = array();\n";
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
		$str .= "\tpublic function to_array_filtered(\$role = \"anon\") {\n" .
			"\t\tif(core::\$permission[\$role]['" . $table -> name ."']['read'] === false) {\n" . 
			"\t\t\treturn false;\n" .
			"\t\t}\n" .
			"\t\t\$values = array();\n" .
			"\t\t\$everything = \$this -> to_array();\n" .
			"\t\tforeach(core::\$permission[\$role]['" . $table -> name ."']['read'] as \$field) {\n" .
			"\t\t\tif(!isset(\$everything[\$field])) {\n" .
			"\t\t\t\tthrow new Exception(\"Check permissions: '\$field' is not a real field in " . $table -> name ."\");\n" .
			"\t\t\t}\n" .
			"\t\t\t\$values[\$field] = \$everything[\$field];\n" .
			"\t\t}\n";
		/* List out parent tables */
		if(count($table -> constraints) != 0) {
			foreach($table -> constraints as $fk) {
				if($fk -> parent_table != $table -> name) { // Tables which reference themselves do not go well with this!
					$str .= "\t\t\$values['".$fk -> parent_table . "'] = \$this -> ". $fk -> parent_table. " -> to_array_filtered(\$role);\n";
				}
			}
		}
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			$str .= "\n\t\t/* Add filtered versions of everything that's been loaded */\n";
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\t\t\$values['$child'] = array();\n";
			}
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\t\tforeach(\$this -> list_".$child . " as \$$child) {\n";
				$str .= "\t\t\t\$values['$child'][] = \$$child -> to_array_filtered(\$role);\n";
				$str .= "\t\t}\n";
			}
		}
		$str .=	"\t\treturn \$values;\n" .
			"\t}\n";
		
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
		 	"\t\t\$everything = \$this -> to_array();\n";
		foreach($table -> pk as $fieldname) {
			$str .= "\t\t\$data['$fieldname'] = \$this -> get_$fieldname();\n";
		}
		$str .= "\t\tforeach(\$this -> model_variables_changed as \$col => \$changed) {\n" .
		 	"\t\t\t\$fieldset[] = \"\$col = :\$col\";\n" .
		 	"\t\t\t\$data[\$col] = \$everything[\$col];\n" .
		 	"\t\t}\n" .
		 	"\t\t\$fields = implode(\", \", \$fieldset);\n\n" .
		 	"\t\t/* Execute query */\n";
		$str .= "\t\t\$sth = database::\$dbh -> prepare(\"UPDATE ".$table -> name . " SET \$fields WHERE " . implode(" AND ", $pkfields). "\");\n";
		$str .= "\t\t\$sth -> execute(\$data);\n";
		$str .= "\t}\n";

		/* Insert */
		$str .= "\n" . $this -> block_comment("Add new " . $table -> name, 1);
		$str .= "\tpublic function insert() {\n" .
		 	"\t\tif(count(\$this -> model_variables_set) == 0) {\n" .
		 	"\t\t\tthrow new Exception(\"No fields have been set!\");\n" .
		 	"\t\t}\n\n" .
		 	"\t\t/* Compose list of set fields */\n" .
		 	"\t\t\$fieldset = array();\n" .
		 	"\t\t\$data = array();\n" .
		 	"\t\t\$everything = \$this -> to_array();\n" .
		 	"\t\tforeach(\$this -> model_variables_set as \$col => \$changed) {\n" .
		 	"\t\t\t\$fieldset[] = \$col;\n" .
		 	"\t\t\t\$fieldset_colon[] = \":\$col\";\n" .
		 	"\t\t\t\$data[\$col] = \$everything[\$col];\n" .
		 	"\t\t}\n";
		$str .= "\t\t\$fields = implode(\", \", \$fieldset);\n" .
			"\t\t\$vals = implode(\", \", \$fieldset_colon);\n\n" .
			"\t\t/* Execute query */\n" .
			"\t\t\$sth = database::\$dbh -> prepare(\"INSERT INTO ".$table -> name . " (\$fields) VALUES (\$vals);\");\n";
		$str .= "\t\t\$sth -> execute(\$data);\n";
		if(count($table -> pk) == 1) {
			$str .= "\t\t\$this -> set_" . $table -> pk[0]. "(database::\$dbh->lastInsertId());\n";
		}
		$str .= "\t}\n";

		/* Delete */
		$str .= "\n" . $this -> block_comment("Delete " . $table -> name, 1);
		$str .= "\tpublic function delete() {\n";
		$str .= "\t\t\$sth = database::\$dbh -> prepare(\"DELETE FROM ".$table -> name . " WHERE " . implode(" AND ", $pkfields). "\");\n";
		foreach($table -> pk as $fieldname) {
			$str .= "\t\t\$data['$fieldname'] = \$this -> get_$fieldname();\n";
		}
		$str .= "\t\t\$sth -> execute(\$data);\n";
		$str .= "\t}\n";

		/* Populate child tables */
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\n" . $this -> block_comment("List associated rows from " . $child . " table\n\n" .
							"@param int \$start Row to begin from. Default 0 (begin from start)\n" . 
							"@param int \$limit Maximum number of rows to retrieve. Default -1 (no limit)", 1);
				$str .= "\tpublic function populate_list_".$child . "(\$start = 0, \$limit = -1) {\n";
				for($i = 0; $i < count($fk -> child_fields); $i++) {
					$str .= "\t\t\$".$fk -> child_fields[$i] . " = \$this -> get_".$fk -> parent_fields[$i]."();\n";
				}
				$str .= "\t\t\$this -> list_".$child." = ".$child . "_model::list_by_".$fk -> name ."(". implode(",", $this -> listFields($this -> database -> table[$child], $fk -> child_fields, true)) .", \$start, \$limit);\n";
				$str .= "\t}\n";
			}
		}

		/* Get by primary key */
		$str .= "\n" . $this -> block_comment("Retrieve by primary key", 1);
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
 		$str .= "\t\tif(\$row === false){\n";
		$str .= "\t\t\treturn false;\n";
		$str .= "\t\t}\n";
		$str .= "\t\t\$assoc = self::row_to_assoc(\$row);\n";
		$str .= "\t\treturn new " . $table -> name . "_model(\$assoc);\n";
		$str .= "\t}\n";

		/* Get by unique indices */
		foreach($table -> unique as $unique) {
			$str .= "\n" . $this -> block_comment("Retrieve by " . $unique -> name, 1);
			$str .= "\tpublic static function get_by_".$unique -> name."(";
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
 			$str .= "\t\tif(\$row === false){\n";
 			$str .= "\t\t\treturn false;\n";
 			$str .= "\t\t}\n";
 			$str .= "\t\t\$assoc = self::row_to_assoc(\$row);\n";
 			$str .= "\t\treturn new " . $table -> name . "_model(\$assoc);\n";
			$str .= "\t}\n";
		}

		/* List with no particular criteria */
		$str .= "\n" . $this -> block_comment("List all rows\n\n" .
				"@param int \$start Row to begin from. Default 0 (begin from start)\n" .
				"@param int \$limit Maximum number of rows to retrieve. Default -1 (no limit)", 1);
		$str .= "\tpublic static function list_all(\$start = 0, \$limit = -1) {\n";
		$str .= "\t\t\$ls = \"\";\n" .
				"\t\t\$start = (int)\$start;\n" .
				"\t\t\$limit = (int)\$limit;\n" .
				"\t\tif(\$start > 0 && \$limit > 0) {\n" .
				"\t\t\t\$ls = \" LIMIT \$start, \" . (\$start + \$limit);\n" .
				"\t\t}\n";
		$sql = "SELECT " . implode(", ", $join['fields']) . " FROM " . $table -> name . " " . $join['clause'];
		$str .= "\t\t\$sth = database::\$dbh -> prepare(\"$sql\" . \$ls . \";\");\n";
		$str .= "\t\t\$sth -> execute();\n";
		$str .= "\t\t\$rows = \$sth -> fetchAll(PDO::FETCH_NUM);\n" .
				"\t\t\$ret = array();\n" .
				"\t\tforeach(\$rows as \$row) {\n" .
				"\t\t\t\$assoc = self::row_to_assoc(\$row);\n" .
				"\t\t\t\$ret[] = new " . $table -> name . "_model(\$assoc);\n" .
				"\t\t}\n" .
				"\t\treturn \$ret;\n";
		$str .= "\t}\n";
		
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
		
		/* Search by text fields */
		// TODO

		/* Finalise and output */
		$str .= "}\n?>";
		file_put_contents($this -> base . "/lib/model/" . $table -> name . "_model.php", $str);
	}

	private function make_controller(SQL_Table $table) {
		$pkfields = $this -> listFields($table, $table -> pk, true);
		$field_array = array();
		$nonpk_field_array = array();
		
		foreach($table -> cols as $col) {
			if(!in_array($col -> name, $table -> pk)) {
				$nonpk_field_array[] = "'" . $col -> name . "'";
			}
			$field_array[] = "'" . $col -> name . "'";
		}
		
		$str = "<?php\nclass ".$table -> name . "_controller {\n";
		
		// Init
		$str .= "\tpublic static function init() {\n";
		$str .=	"\t\tcore::loadClass(\"session\");\n";
		$str .=	"\t\tcore::loadClass(\"".$table -> name . "_model\");\n";
		$str .=	"\t}\n\n";

		// Create
		$str .= "\tpublic static function create() {\n";
		$str .= "\t\t/* Check permission */\n";
		$str .= "\t\t\$role = session::getRole();\n";
		$str .= "\t\tif(!isset(core::\$permission[\$role]['" . $table -> name . "']['create']) || core::\$permission[\$role]['" . $table -> name . "']['create'] != true) {\n";
		$str .= "\t\t\treturn array('error' => 'You do not have permission to do that', 'code' => '403');\n";
		$str .= "\t\t}\n\n";
		$str .= "\t\t/* Find fields to insert */\n";
		$str .= "\t\t\$fields = array(".implode(", ", $field_array).");\n";
		$str .= "\t\t\$init = array();\n";
		$str .= "\t\t\$received = json_decode(file_get_contents('php://input'), true, 2);\n";
		$str .= "\t\tforeach(\$fields as \$field) {\n";
		$str .= "\t\t\tif(isset(\$received[\$field])) {\n";
		$str .= "\t\t\t\t\$init[\"" . $table -> name . ".\$field\"] = \$received[\$field];\n";
		$str .=	"\t\t\t}\n";
		$str .= "\t\t}\n";
		$str .= "\t\t\t\$" . $table -> name . " = new " . $table -> name . "_model(\$init);\n\n";
		$str .= $this -> check_foreign_keys($table);
		
		$str .= "\t\t/* Insert new row */\n";
		$str .= "\t\ttry {\n";
		$str .= "\t\t\t\$" . $table -> name . " -> insert();\n";
		$str .= "\t\t\treturn $" . $table -> name . " -> to_array_filtered(\$role);\n";
		$str .= "\t\t} catch(Exception \$e) {\n";
		$str .= "\t\t\treturn array('error' => 'Failed to add to database', 'code' => '500');\n";
		$str .= "\t\t}\n";
		$str .=	"\t}\n\n";

		// Read
		$str .= "\tpublic static function read(" . implode(",", $pkfields) . ") {\n";
		$str .= "\t\t/* Check permission */\n";
		$str .= "\t\t\$role = session::getRole();\n";
		$str .= "\t\tif(!isset(core::\$permission[\$role]['" . $table -> name . "']['read']) || count(core::\$permission[\$role]['" . $table -> name . "']['read']) == 0) {\n";
		$str .= "\t\t\treturn array('error' => 'You do not have permission to do that', 'code' => '403');\n";
		$str .= "\t\t}\n\n";
		$str .= "\t\t/* Load ". $table -> name . " */\n";
		$str .= "\t\t\$". $table -> name . " = " . $table -> name . "_model::get(" . implode(",", $pkfields) . ");\n";
		$str .= "\t\tif(!\$".$table -> name . ") {\n";
		$str .= "\t\t\treturn array('error' => '" . $table -> name . " not found', 'code' => '404');\n";
		$str .= "\t\t}\n";
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\t\t// \$" . $table -> name . " -> populate_list_".$child . "();\n";
			}
		}
		$str .= "\t\treturn $" . $table -> name . " -> to_array_filtered(\$role);\n";
		$str .=	"\t}\n\n";

		// Update
		$str .= "\tpublic static function update(" . implode(",", $pkfields) . ") {\n";
		$str .= "\t\t/* Check permission */\n";
		$str .= "\t\t\$role = session::getRole();\n";
		$str .= "\t\tif(!isset(core::\$permission[\$role]['" . $table -> name . "']['update']) || count(core::\$permission[\$role]['" . $table -> name . "']['update']) == 0) {\n";
		$str .= "\t\t\treturn array('error' => 'You do not have permission to do that', 'code' => '403');\n";
		$str .= "\t\t}\n\n";
		$str .= "\t\t/* Load ". $table -> name . " */\n";
		$str .= "\t\t\$". $table -> name . " = " . $table -> name . "_model::get(" . implode(",", $pkfields) . ");\n";
		$str .= "\t\tif(!\$".$table -> name . ") {\n";
		$str .= "\t\t\treturn array('error' => '" . $table -> name . " not found', 'code' => '404');\n";
		$str .= "\t\t}\n\n";
		$str .= "\t\t/* Find fields to update */\n";
		$str .= "\t\t\$update = false;\n";
		$str .= "\t\t\$received = json_decode(file_get_contents('php://input'), true);\n";
		foreach($table -> cols as $col) {
			if(!in_array($col -> name, $table -> pk)) { /* Primary keys can't be updated with this */
				$str .= "\t\tif(isset(\$received['" . $col -> name . "']) && in_array('" . $col -> name . "', core::\$permission[\$role]['" . $table -> name . "']['update'])) {\n";
				$str .= "\t\t\t\$" . $table -> name . " -> set_" . $col -> name . "(\$received['" . $col -> name . "']);\n";
				$str .=	"\t\t}\n";
			}
		}
		$str .=	"\n";
		$str .= $this -> check_foreign_keys($table);
		
		$str .= "\t\t/* Update the row */\n";
		$str .= "\t\ttry {\n";
		$str .= "\t\t\t\$" . $table -> name . " -> update();\n";
		$str .= "\t\t\treturn $" . $table -> name . " -> to_array_filtered(\$role);\n";
		$str .= "\t\t} catch(Exception \$e) {\n";
		$str .= "\t\t\treturn array('error' => 'Failed to update row', 'code' => '500');\n";
		$str .= "\t\t}\n";
		$str .=	"\t}\n\n";
		
		// Delete
		$str .= "\tpublic static function delete(" . implode(",", $pkfields) . ") {\n";
		$str .= "\t\t/* Check permission */\n";
		$str .= "\t\t\$role = session::getRole();\n";
		$str .= "\t\tif(!isset(core::\$permission[\$role]['" . $table -> name . "']['delete']) || core::\$permission[\$role]['" . $table -> name . "']['delete'] != true) {\n";
		$str .= "\t\t\treturn array('error' => 'You do not have permission to do that', 'code' => '403');\n";
		$str .= "\t\t}\n\n";
				
		$str .= "\t\t/* Load ". $table -> name . " */\n";
		$str .= "\t\t\$". $table -> name . " = " . $table -> name . "_model::get(" . implode(",", $pkfields) . ");\n";
		$str .= "\t\tif(!\$".$table -> name . ") {\n";
		$str .= "\t\t\treturn array('error' => '" . $table -> name . " not found', 'code' => '404');\n";
		$str .= "\t\t}\n\n";
		if(isset($this -> rev_constraints[$table -> name]) && count($this -> rev_constraints[$table -> name]) != 0) {
			$str .= "\t\t/* Check for child rows */\n";
			foreach($this -> rev_constraints[$table -> name] as $child => $fk) {
				$str .= "\t\t\$" . $table -> name . " -> populate_list_".$child . "(0, 1);\n";
				$str .= "\t\tif(count(\$" . $table -> name . " -> list_".$child . ") > 0) {\n";
				$str .= "\t\t\treturn array('error' => 'Cannot delete " . $table -> name . " because of a related " . $child . " entry', 'code' => '400');\n";
				$str .= "\t\t}\n";
			}
		}
		$str .= "\n";
		$str .= "\t\t/* Delete it */\n";
		$str .= "\t\ttry {\n";
		$str .= "\t\t\t\$". $table -> name . " -> delete();\n";
		$str .= "\t\t\treturn array('success' => 'yes');\n";
		$str .= "\t\t} catch(Exception \$e) {\n";
		$str .= "\t\t\treturn array('error' => 'Failed to delete', 'code' => '500');\n";
		$str .= "\t\t}\n";
		$str .=	"\t}\n";
		
		// TODO: get_by.. (unique indexes), list_by_(non-unique), search_by (all text fields)
		
		/* End file */
		$str .= "}\n?>";
		
		file_put_contents($this -> base . "/lib/controller/" . $table -> name . "_controller.php", $str);
	}
	
	/**
	 * Look up foreign keys and throw sensible exceptions
	 * 
	 * @param string $table
	 */
	private function check_foreign_keys($table) {
		$str = "";
		if(count($table -> constraints) != 0) {
			$str .= "\t\t/* Check parent tables */\n";
			foreach($table -> constraints as $fk) {
				if($fk -> parent_table != $table -> name) {
					if($this -> field_match($fk -> parent_fields, $this -> database -> table[$fk -> parent_table] -> pk)) {
						$f = array();
						foreach($fk -> child_fields as $a) {
							$f[] = "\$" . $table -> name . " -> get_$a";
						}
						$str .= "\t\tif(!".$fk -> parent_table . "_model::get(" . implode(", ", $f) . "())) {\n";
						$str .= "\t\t\treturn array('error' => '" . $table -> name . " is invalid because related " . $fk -> parent_table . " does not exist', 'code' => '400');\n";
						$str .= "\t\t}\n";
					}
				}
			}
			$str .= "\n";
		}
		return $str;
	}
	
	private function backbone_models(SQL_Database $database) {
		$str = "";
		foreach($database -> table as $name => $table) {
			if(count($table -> pk) == 1) {
				$str .= $this -> backbone_model($table);
			}
		}
		file_put_contents($this -> base . "/public/js/models.js", $str);
	}
	
	private function backbone_model(SQL_Table $table) {
		$str = "";
		$str .= "/* " . $table -> name . " */\n";
		$str .= $table -> name . "_model = Backbone.Model.extend({\n";
		$str .= "\turlRoot: '/" . $this -> database -> name . "/api/" . $table -> name . "',\n";

		if($table -> pk[0] != 'id') {
			$str .= "\tidAttribute: '" . $table -> pk[0] . "',\n";
		}

		/* Defaults */
		$str .= "\tdefaults: {\n";
		$defaults = array();
		foreach($table -> cols as $col) {
			if($col -> name != $table -> pk[0]) {
				switch($col -> type) {
					case 'INT':
						$a = "0";
						break;
					default:
						$a = "''";
				}
				$defaults[] = "\t\t" . $col -> name . ": $a";
			}
		}
		$str .= implode(",\n", $defaults);
		$str .= "\n\t}\n";
		
		$str .= "});\n\n";
		return $str;
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
		//print_r($child_fields); print_r($table);
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
