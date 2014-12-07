<?php
require_once(dirname(__FILE__) . "/Model_Entity.php");

class Model_Generator {
	private $database;
	private $base; // Base dir
	private $rev_constraints;

	public function __construct(SQL_Database $database) {
		$this -> database = $database;
		$this -> base = dirname(__FILE__) . "/../" .$database -> name;
	}

	public function generate() {
		$this -> make_app_skeleton();
		foreach($this -> database -> table as $table) {
			$entity = new Model_Entity($table, $this -> database);
			$this -> make_graphviz_doc($entity);
			$this -> make_model($entity);
			$this -> make_controller($entity);
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
		mkdir($this -> base . "/doc/");
		mkdir($this -> base . "/doc/diagram");

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

	private function make_graphviz_doc(Model_Entity $entity) {
		$dot = $pdf = $this -> base . "/doc/diagram/" . $entity -> table -> name;
		$dot .= ".dot";
		$pdf .= ".pdf";
		file_put_contents($dot, $entity -> toGraphVizDotFile());
		$cmd = sprintf("dot -Tpdf %s > %s",
				escapeshellarg($dot),
				escapeshellarg($pdf)
		);
		system($cmd);
	}

	private function make_model(Model_Entity $entity) {
		$data = $entity -> process();

		/* Figure out PK */
		$pkfields = array();
		$pkfields_name_only = array();
		foreach($entity -> table -> pk as $fieldname) {
			$pkfields[] = self::wrapField($entity -> query_table_name, $fieldname) . " = :$fieldname";
			$pkfields_name_only[] = self::wrapField($entity -> query_table_name, $fieldname);
		}

		/* Figure out JOIN clause to use on every SELECT */
		$qry = $this -> getQuery($entity, $data);

		/* Generate model */
		$str = "<?php\nclass ".$entity -> table -> name . "_Model {\n";
		foreach($entity -> table -> cols as $col) {
			/* Class variables */
			$str .= $this -> block_comment("@var " . $this -> primitive($col) . " " . $col -> name . ($col -> comment != ""? " " . $col -> comment: ""), 1);
			$str .= "\tprivate $" . $col -> name . ";\n\n";
		}
		$str .= "\tprivate \$model_variables_changed; // Only variables which have been changed\n";
		$str .= "\tprivate \$model_variables_set; // All variables which have been set (initially or with a setter)\n";
		foreach($entity -> table -> cols as $col) {
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
		if(count($entity -> parent) != 0) {
			$str .= "\n\t/* Parent tables */\n";
			foreach($entity -> parent as $parent) {
				$str .= "\tpublic \$". $parent -> dest -> model_storage_name . ";\n";
			}
		}

		/* Child tables */
		if(count($entity -> child) != 0) {
			$str .= "\n\t/* Child tables */\n";
			foreach($entity -> child as $child) {
				$str .= "\tpublic \$". $child -> dest -> model_storage_name . ";\n";
			}
		}

		/* Main query */
		$str .= "\n\t/* Query to greedy-fetch from this table  */\n";
		$str .= "\tconst SELECT_QUERY = \"". $qry . "\";\n";

		/* Sort (PK by default) */
		$str .= "\n\t/* Sort clause to add when listing rows from this table */\n";
		$str .= "\tconst SORT_CLAUSE = \" ORDER BY " . implode(", ", $pkfields_name_only) . "\";\n";

		/* Init and load related tables */
		$str .= "\n" . $this -> block_comment("Initialise and load related tables", 1);
		$str .= "\tpublic static function init() {\n";
		$str .= "\t\tcore::loadClass(\"Database\");\n";

		/* Load classes for related tables */
		$inc = array(); // Because the table can be referenced in different ways
		if(count($entity -> parent) != 0 || count($entity -> child) != 0) {
			$str .= "\n\t\t/* Load related tables */\n";
			foreach($entity -> parent as $parent) {
				if(!isset($inc[$parent -> dest -> table -> name])) {
					$str .= "\t\tcore::loadClass(\"".$parent -> dest -> table -> name . "_Model\");\n";
					$inc[$parent -> dest -> table -> name] = true;
				}
			}
			foreach($entity -> child as $child) {
				if(!isset($inc[$child -> dest -> table -> name])) {
					$str .= "\t\tcore::loadClass(\"".$child -> dest -> table -> name . "_Model\");\n";
					$inc[$child -> dest -> table -> name] = true;
				}
			}
		}
		$str .= "\t}\n";

		/* Constructor */
		$str .= "\n" . $this -> block_comment("Construct new " . $entity -> table -> name . " from an associative array.\n\n@param array \$fields The array to create this object from.", 1);
		$str .= "\tpublic function __construct(array \$fields = array()) {\n";
		if(count($entity -> table -> cols) != 0) {
			$str .= "\t\t/* Initialise everything as blank to avoid tripping up the permissions fitlers */\n";
			foreach($entity -> table -> cols as $col) {
				$str .= "\t\t\$this -> " . $col -> name . " = '';\n";
			}
			foreach($entity -> parent as $parent) {
				$str .= "\t\t\$this -> " . $parent -> dest -> model_storage_name . " = null;\n";
			}
			$str .= "\n";

			$str .= "\t\t/* Set variables based on what information we have */\n";
			foreach($entity -> table -> cols as $col) {
				$str .= "\t\tif(isset(\$fields['" . $col -> name . "'])) {\n" .
						"\t\t\t\$this -> set" . self::titleCase($col -> name) . "(\$fields['" . $col -> name . "']);\n" .
						"\t\t}\n";
			}
			$str .= "\t\t\$this -> model_variables_changed = array();\n";
		}
		if(count($entity -> parent) != 0) {
			$str .= "\n";
			$str .= "\t\t/* Load parent tables if set */\n";
			foreach($entity -> parent as $parent) {
				$str .= "\t\tif(isset(\$fields['" . $parent -> dest -> model_storage_name . "'])) {\n" .
						"\t\t\t\$this -> " . $parent -> dest -> model_storage_name . " = new " . $parent -> dest -> table -> name . "_Model(\$fields['" . $parent -> dest -> model_storage_name . "']);\n" .
						"\t\t}\n";
			}
		}
		if(count($entity -> child) != 0) {
			$str .= "\n";
			$str .= "\t\t/* Don't load child tables */\n";
			foreach($entity -> child as $child) {
				$str .= "\t\t\$this -> " . $child -> dest -> model_storage_name . " = " . ( !$child -> toOne ? "array()" : "null") .  ";\n";
			}
		}
		$str .= "\t}\n";

		/* To array */
		$str .= "\n" . $this -> block_comment("Convert " . $entity -> table -> name . " to shallow associative array\n\n@return array", 1);
		$str .= "\tprivate function toArray() {\n";
		$fieldlist = array();
		foreach($entity -> table -> cols as $col) {
			$fieldlist[] = "\t\t\t'".$col -> name . "' => \$this -> " . $col -> name . "";
		}
		$str .= "\t\t\$values = array(".(count($fieldlist) > 0 ? "\n" . implode(",\n", $fieldlist) : "") .  ");\n";
		$str .= "\t\treturn \$values;\n";
		$str .= "\t}\n";

		/* To restricted array (eg. for user output) */
		$str .= "\n" . $this -> block_comment("Convert " . $entity -> table -> name . " to associative array, including only visible fields,\n" .
				"parent tables, and loaded child tables\n\n" .
				"@param string \$role The user role to use", 1);
		$str .= "\tpublic function to_array_filtered(\$role = \"anon\") {\n" .
				"\t\tif(core::\$permission[\$role]['" . $entity -> table -> name ."']['read'] === false) {\n" .
				"\t\t\treturn false;\n" .
				"\t\t}\n" .
				"\t\t\$values = array();\n" .
				"\t\t\$everything = \$this -> to_array();\n" .
				"\t\tforeach(core::\$permission[\$role]['" . $entity -> table -> name ."']['read'] as \$field) {\n" .
				"\t\t\tif(!isset(\$everything[\$field])) {\n" .
				"\t\t\t\tthrow new Exception(\"Check permissions: '\$field' is not a real field in " . $entity -> table -> name ."\");\n" .
				"\t\t\t}\n" .
				"\t\t\t\$values[\$field] = \$everything[\$field];\n" .
				"\t\t}\n";
		if(count($entity -> parent) != 0 || count($entity -> child) != 0) {
			$str .= "\n\t\t/* Add filtered versions of everything that's been loaded */\n";
			
			foreach($entity -> parent as $parent) {
				$str .= "\t\tif(\$this -> " . $parent -> dest -> model_storage_name . " !== null) {\n" .
						"\t\t\t\$values['". $parent -> dest -> model_storage_name . "'] = \$this -> " . $parent -> dest -> model_storage_name . " -> to_array_filtered(\$role);\n" .
						"\t\t}\n";
			}
			foreach($entity -> child as $child) {
				if($child -> toOne) {
					$str .= "\t\tif(\$this -> " . $child -> dest -> model_storage_name . " !== null) {\n" .
							"\t\t\t\$values['". $child -> dest -> model_storage_name . "'] = \$this -> " . $parent -> dest -> model_storage_name . " -> to_array_filtered(\$role);\n" .
							"\t\t}\n";
				} else {
					$str .= "\t\t\$values['". $child -> dest -> model_storage_name . "'] = array();\n";
					$str .= "\t\tforeach(\$this -> ". $child -> dest -> model_storage_name . " as \$" . strtolower($child -> dest -> model_storage_name) . ") {\n";
					$str .= "\t\t\t\$values['". $child -> dest -> model_storage_name . "'][] = \$" . strtolower($child -> dest -> model_storage_name) . " -> to_array_filtered(\$role);\n";
					$str .= "\t\t}\n";
				}
			}		
		}
		$str .=	"\t\treturn \$values;\n" .
		"\t}\n";

		/* From array('foo', 'bar', 'baz') to array('a.weeble' => 'foo', 'a.warble' => bar, 'b.beeble' => 'baz') */
		$str .= "\n" . $this -> block_comment("Convert retrieved database row from numbered to named keys, arranged in a hierarchy\n\n@param array \$row ror retrieved from database\n@return array row with indices", 1);
		$str .= "\tprivate static function row_to_assoc(array \$row) {\n";
		$cols = array();
		foreach($data['fields'] as $num => $field) {
			if(count($field['var']) == 0) {
				$cols[] = "\t\t\t\"" . $field['col'] . "\" => \$row[$num]";
			}
		}
		$str .= "\t\t\$" . $entity -> query_table_name . " = array(". (count($cols) > 0? "\n". implode(",\n", $cols) : "") . ");\n";
		/* More complex tree-building of other fields */
		$stack = array();
		$stackVarname = array();
		foreach($data['fields'] as $num => $field) {
			if(count($field['var']) == 0) {
				// Already done above
				continue;
			} else {
				while(self::stack_compare($field['var'], $stack) == -1) {
					// Current and previous variable names, to copy sub-objects
					if(count($stackVarname) == 1) {
						$to = $entity -> query_table_name;
					} else {
						$to = $stackVarname[count($stackVarname) - 2];
					}
					$from = $stackVarname[count($stackVarname) - 1];
					$str .= "\t\t" . str_repeat("\t", count($stack)) . "\$${to}['" . $stack[count($stack) - 1] . "'] = \$${from};\n";
					array_pop($stack);
					array_pop($stackVarname);
					$str .= "\t\t" . str_repeat("\t", count($stack)) . "}\n";
				}
				while(self::stack_compare($field['var'], $stack) == 1) {				
					$i = $num;
					// Check that all non-nullable fields are defined (should always be at least one, due to joins)
					$criteria = array();
					$cols = array();
					while(isset($data['fields'][$i]) && $data['fields'][$i]['table'] == $field['table']) {
						if(!$this -> is_nullable($field['table_orig'], $data['fields'][$i]['col'])) {
							$criteria[] = "\$row[$i] !== NULL";
						}
						$cols[] = "\t\t\t\t" . str_repeat("\t", count($stack)) . "\"" . $data['fields'][$i]['col'] . "\" => \$row[$i]";
						$i++;
					}
					$condition = count($criteria) == 0 ? "true" : implode(" && ", $criteria);
					$str .= "\t\t" . str_repeat("\t", count($stack)) . "if($condition) {\n";
					array_push($stack, $field['var'][count($stack)]);
					array_push($stackVarname, $field['table']);
					$varname = $field['table'];
					$str .= "\t\t" . str_repeat("\t", count($stack)) . "\$" . $field['table'] . " = array(". (count($cols) > 0? "\n". implode(",\n", $cols) : "") . ");\n";
				}
			}
		}
		while(self::stack_compare(array(), $stack) == -1) {
			// Repeat stack_compare() == -1 from above.
			if(count($stackVarname) == 1) {
				$to = $entity -> query_table_name;
			} else {
				$to = $stackVarname[count($stackVarname) - 2];
			}
			$from = $stackVarname[count($stackVarname) - 1];
			$str .= "\t\t" . str_repeat("\t", count($stack)) . "\$${to}['" . $stack[count($stack) - 1] . "'] = \$${from};\n";
			array_pop($stack);
			array_pop($stackVarname);
			$str .= "\t\t" . str_repeat("\t", count($stack)) . "}\n";
		}
		$str .= "\t\treturn \$" . $entity -> query_table_name . ";\n";
		$str .= "\t}\n";

		/* Getters and setters */
		foreach($entity -> table -> cols as $col) {
			$str .= "\n" . $this -> block_comment("Get " . $col -> name . "\n\n@return " . $this -> primitive($col), 1);
			$str .= "\tpublic function get" . self::titleCase($col -> name) . "() {\n";
			$str .= "\t\tif(!isset(\$this -> model_variables_set['" . $col -> name . "'])) {\n";
			$str .= "\t\t\tthrow new Exception(\"" . $entity -> table -> name . "." . $col -> name . " has not been initialised.\");\n";
			$str .= "\t\t}\n";
			$str .= "\t\treturn \$this -> " . $col -> name .";\n";
			$str .= "\t}\n";
			$str .= "\n" . $this -> block_comment("Set " . $col -> name . "\n\n@param " . $this -> primitive($col) . " \$" . $col -> name, 1);
			$str .= "\t".(in_array($col -> name, $entity -> table -> pk) ? "private" : "public" ) . " function set" . self::titleCase($col -> name) . "($" . $col -> name . ") {\n";
			$str .= $this -> validate_type($entity -> table , $col);
			$str .= "\t\t\$this -> " . $col -> name ." = $" . $col -> name . ";\n";
			$str .= "\t\t\$this -> model_variables_changed['" . $col -> name ."'] = true;\n";
			$str .= "\t\t\$this -> model_variables_set['" . $col -> name ."'] = true;\n";
			$str .= "\t}\n";
		}

		/* Update */
		$str .= "\n" . $this -> block_comment("Update " . $entity -> table -> name, 1);
		$str .= "\tpublic function update() {\n" .
		 	"\t\tif(count(\$this -> model_variables_changed) == 0) {\n" .
		 	"\t\t\tthrow new Exception(\"Nothing to update\");\n" .
		 	"\t\t}\n\n" .
		 	"\t\t/* Compose list of changed fields */\n" .
		 	"\t\t\$fieldset = array();\n" .
		 	"\t\t\$everything = \$this -> to_array();\n";
		foreach($entity -> table -> pk as $fieldname) {
			$str .= "\t\t\$data['$fieldname'] = \$this -> get".self::titleCase($fieldname)."();\n";
		}
		$str .= "\t\tforeach(\$this -> model_variables_changed as \$col => \$changed) {\n" .
		 	"\t\t\t\$fieldset[] = \"`\$col` = :\$col\";\n" .
		 	"\t\t\t\$data[\$col] = \$everything[\$col];\n" .
		 	"\t\t}\n" .
		 	"\t\t\$fields = implode(\", \", \$fieldset);\n\n" .
		 	"\t\t/* Execute query */\n";
		$str .= "\t\t\$sth = Database::\$dbh -> prepare(\"UPDATE `".$entity -> table -> name . "` SET \$fields WHERE " . implode(" AND ", $pkfields). "\");\n";
		$str .= "\t\t\$sth -> execute(\$data);\n";
		$str .= "\t}\n";
		
		/* Insert */
		$str .= "\n" . $this -> block_comment("Add new " . $entity -> table -> name, 1);
		$str .= "\tpublic function insert() {\n" .
		 	"\t\tif(count(\$this -> model_variables_set) == 0) {\n" .
		 	"\t\t\tthrow new Exception(\"No fields have been set!\");\n" .
		 	"\t\t}\n\n" .
		 	"\t\t/* Compose list of set fields */\n" .
		 	"\t\t\$fieldset = array();\n" .
		 	"\t\t\$data = array();\n" .
		 	"\t\t\$everything = \$this -> to_array();\n" .
		 	"\t\tforeach(\$this -> model_variables_set as \$col => \$changed) {\n" .
		 	"\t\t\t\$fieldset[] = \"`\$col`\";\n" .
		 	"\t\t\t\$fieldset_colon[] = \":\$col\";\n" .
		 	"\t\t\t\$data[\$col] = \$everything[\$col];\n" .
		 	"\t\t}\n";
		$str .= "\t\t\$fields = implode(\", \", \$fieldset);\n" .
				"\t\t\$vals = implode(\", \", \$fieldset_colon);\n\n" .
				"\t\t/* Execute query */\n" .
				"\t\t\$sth = Database::\$dbh -> prepare(\"INSERT INTO `".$entity -> table -> name . "` (\$fields) VALUES (\$vals);\");\n";
		$str .= "\t\t\$sth -> execute(\$data);\n";
		if(count($entity -> table -> pk) == 1) {
			// Auto-increment keys etc
			$str .= "\t\t\$this -> set" . self::titleCase($entity -> table -> pk[0]). "(database::\$dbh->lastInsertId());\n";
		}
		$str .= "\t}\n";

		/* Delete */
		$str .= "\n" . $this -> block_comment("Delete " . $entity -> table -> name, 1);
		$str .= "\tpublic function delete() {\n";
		$str .= "\t\t\$sth = Database::\$dbh -> prepare(\"DELETE FROM `".$entity -> table -> name . "` WHERE " . implode(" AND ", $pkfields). ";\");\n";
		foreach($entity -> table -> pk as $fieldname) {
			$str .= "\t\t\$data['$fieldname'] = \$this -> get" . self::titleCase($fieldname). "();\n";
		}
		$str .= "\t\t\$sth -> execute(\$data);\n";
		$str .= "\t}\n";
		
		/* Populate child tables */
		foreach($entity -> child as $child) {
			$joinFields = $this -> listFields($this -> database -> table[$child -> dest -> table -> name], $child -> constraint -> parent_fields, true);
			$f = $child -> toOne ? "get" : "list";
			if(!$child -> toOne) {
				$str .= "\n" . $this -> block_comment("Load " . $child -> dest -> model_storage_name ."[] array from the " . $child -> dest -> table -> name  . " table.\n\n" .
						"@param int \$start Row to begin from. Default 0 (begin from start)\n" .
						"@param int \$limit Maximum number of rows to retrieve. Default -1 (no limit)", 1);
				$str .= "\tpublic function load".self::titleCase($child -> dest -> model_storage_name). "(\$start = 0, \$limit = -1) {\n";
				$joinFields = array_merge($joinFields, array("\$start", "\$limit"));
			} else {
				$str .= "\n" . $this -> block_comment("Load " . $child -> dest -> model_storage_name ." from the " . $child -> dest -> table -> name  . " table.", 1);
				$str .= "\tpublic function load".self::titleCase($child -> dest -> model_storage_name). "() {\n";
			}
			for($i = 0; $i < count($child -> constraint -> child_fields); $i++) {
				$str .= "\t\t\$".$child -> constraint -> parent_fields[$i] . " = \$this -> get".self::titleCase($child -> constraint -> child_fields[$i])."();\n";
			}
			$str .= "\t\t\$this -> " . $child -> dest -> model_storage_name ." = " . $child -> dest -> table -> name . "_Model::${f}By" . self::titleCase($child -> shortName) ."(". implode(",", $joinFields) . ");\n";
			$str .= "\t}\n";
		}

		/* Get by primary key */
		$info = self::keyDocumentation($entity -> table, $entity -> table -> pk);
		$str .= "\n" . $this -> block_comment("Retrieve " . $entity -> table -> name . " by " . implode(", ", $entity -> table -> pk) . "\n\n" . implode("\n",$info), 1);
		$str .= "\tpublic static function get(";
		$str .= implode(", ", $this -> listFields($entity -> table, $entity -> table -> pk, true));
		$str .= ") {\n";
		$conditions = $arrEntry = array();
		foreach($entity -> table -> pk as $field) {
			$conditions[] = self::wrapField($entity -> table -> name, $field) ." = :$field";
			$arrEntry[] = "'$field' => \$$field";
		}
		$sql = "WHERE " . implode(" AND ", $conditions);
		$str .= "\t\t\$sth = Database::\$dbh -> prepare(self::SELECT_QUERY . \"$sql;\");\n";
		$str .= "\t\t\$sth -> execute(array(" . implode(", ", $arrEntry) . "));\n";
		$str .= "\t\t\$row = \$sth -> fetch(PDO::FETCH_NUM);\n";
		$str .= "\t\tif(\$row === false){\n";
		$str .= "\t\t\treturn false;\n";
		$str .= "\t\t}\n";
		$str .= "\t\t\$assoc = self::row_to_assoc(\$row);\n";
		$str .= "\t\treturn new " . $entity -> table -> name . "_Model(\$assoc);\n";
		$str .= "\t}\n";
		
		/* Finalise and output */
		$str .= "}\n?>";
		$fn = $this -> base . "/lib/model/" . $entity -> table -> name . "_Model.php";
		file_put_contents($fn, $str);
		echo $str . "\n";
		include($fn); // Very crude syntax check
		unset($inc); // Why is this here? TODO
		return;

		/* Get by unique indices */
		foreach($table -> unique as $unique) {
			$str .= "\n" . $this -> block_comment("Retrieve by " . $unique -> name, 1);
			$str .= "\tpublic static function get_by_".$unique -> name."(";
			$str .= implode(", ", $this -> listFields($table, $unique -> fields, true));
			$str .= ") {\n";
			$conditions = $arrEntry = array();
			foreach($unique -> fields as $field) {
				$conditions[] = "`" . $table -> name . "`.`" . $field . "` = :$field";
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
				"\t\tif(\$start >= 0 && \$limit > 0) {\n" .
				"\t\t\t\$ls = \" LIMIT \$start, \$limit\";\n" .
				"\t\t}\n";
		$sql = "SELECT " . implode(", ", $join['fields']) . " FROM `" . $table -> name . "` " . $join['clause'];
		$str .= "\t\t\$sth = database::\$dbh -> prepare(\"$sql\" . self::SORT_CLAUSE . \$ls . \";\");\n";
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
					"\t\tif(\$start >= 0 && \$limit > 0) {\n" .
					"\t\t\t\$ls = \" LIMIT \$start, \$limit\";\n" .
					"\t\t}\n";
			$conditions = $arrEntry = array();
			foreach($index -> fields as $field) {
				$conditions[] = $table -> name . "." . $field . " = :$field";
				$arrEntry[] = "'$field' => \$$field";
			}
			/* Query is again similar to get() above */
			$sql = "SELECT " . implode(", ", $join['fields']) . " FROM `" . $table -> name . "` " . $join['clause'] . " WHERE " . implode(" AND ", $conditions);
			$str .= "\t\t\$sth = database::\$dbh -> prepare(\"$sql\" . self::SORT_CLAUSE . \$ls . \";\");\n";
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
		foreach($table -> cols as $col) {
			if($this -> primitive($col) != "string") {
				continue;
			}

			$str .= "\n" . $this -> block_comment("Simple search within " . $col -> name . " field\n\n" .
					"@param int \$start Row to begin from. Default 0 (begin from start)\n" .
					"@param int \$limit Maximum number of rows to retrieve. Default -1 (no limit)", 1);
			$str .= "\tpublic static function search_by_".$col -> name."(\$search, \$start = 0, \$limit = -1) {\n";
			$str .= "\t\t\$ls = \"\";\n" .
					"\t\t\$start = (int)\$start;\n" .
					"\t\t\$limit = (int)\$limit;\n" .
					"\t\tif(\$start >= 0 && \$limit > 0) {\n" .
					"\t\t\t\$ls = \" LIMIT \$start, \$limit\";\n" .
					"\t\t}\n";
			$sql = "SELECT " . implode(", ", $join['fields']) . " FROM `" . $table -> name . "` " . $join['clause'] . " WHERE " . $col -> name . " LIKE :search";
			$str .= "\t\t\$sth = database::\$dbh -> prepare(\"$sql\" . self::SORT_CLAUSE . \$ls . \";\");\n";
			$str .= "\t\t\$sth -> execute(array('search' => \"%\".\$search.\"%\"));\n";
			$str .= "\t\t\$rows = \$sth -> fetchAll(PDO::FETCH_NUM);\n" .
					"\t\t\$ret = array();\n" .
					"\t\tforeach(\$rows as \$row) {\n" .
					"\t\t\t\$assoc = self::row_to_assoc(\$row);\n" .
					"\t\t\t\$ret[] = new " . $table -> name . "_model(\$assoc);\n" .
					"\t\t}\n" .
					"\t\treturn \$ret;\n";
			$str .= "\t}\n";
		}

	}

	private function make_controller(Model_Entity $entity) {
		return;

		// TODO
		$pkfields = $this -> listFields($table, $table -> pk, true);
		$pkfields_defaults = array();
		foreach($pkfields as $id => $field) {
			$pkfields_defaults[$id] = $field . " = null";
		}
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
		$str .= "\t\t\treturn $" . $table -> name . " -> toFilteredArray(\$role);\n";
		$str .= "\t\t} catch(Exception \$e) {\n";
		$str .= "\t\t\treturn array('error' => 'Failed to add to database', 'code' => '500');\n";
		$str .= "\t\t}\n";
		$str .=	"\t}\n\n";

		// Read
		$str .= "\tpublic static function read(" . implode(",", $pkfields_defaults) . ") {\n";
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
		$str .= "\tpublic static function update(" . implode(",", $pkfields_defaults) . ") {\n";
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
		$str .= "\tpublic static function delete(" . implode(",", $pkfields_defaults) . ") {\n";
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
		$str .= "\n";

		// List all
		$str .= "\tpublic static function list_all(\$page = 1, \$itemspp = 20) {\n";
		$str .= "\t\t/* Check permission */\n";
		$str .= "\t\t\$role = session::getRole();\n";
		$str .= "\t\tif(!isset(core::\$permission[\$role]['" . $table -> name ."']['read']) || count(core::\$permission[\$role]['" . $table -> name ."']['read']) == 0) {\n";
		$str .= "\t\t\treturn array('error' => 'You do not have permission to do that', 'code' => '403');\n";
		$str .= "\t\t}\n";
		$str .= "\t\tif((int)\$page < 1 || (int)\$itemspp < 1) {\n";
		$str .= "\t\t\t\$start = 0;\n";
		$str .= "\t\t\t\$limit = -1;\n";
		$str .= "\t\t} else {\n";
		$str .= "\t\t\t\$start = (\$page - 1) * \$itemspp;\n";
		$str .= "\t\t\t\$limit = \$itemspp;\n";
		$str .= "\t\t}\n";
		$str .= "\n";
		$str .= "\t\t/* Retrieve and filter rows */\n";
		$str .= "\t\ttry {\n";
		$str .= "\t\t\t\$" . $table -> name ."_list = " . $table -> name ."_model::list_all(\$start, \$limit);\n";
		$str .= "\t\t\t\$ret = array();\n";
		$str .= "\t\t\tforeach(\$" . $table -> name ."_list as \$" . $table -> name .") {\n";
		$str .= "\t\t\t\t\$ret[] = \$" . $table -> name ." -> to_array_filtered(\$role);\n";
		$str .= "\t\t\t}\n";
		$str .= "\t\t\treturn \$ret;\n";
		$str .= "\t\t} catch(Exception \$e) {\n";
		$str .= "\t\t\treturn array('error' => 'Failed to list', 'code' => '500');\n";
		$str .= "\t\t}\n";
		$str .= "\t}\n";

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
					if(Model_Entity::field_match($fk -> parent_fields, $this -> database -> table[$fk -> parent_table] -> pk)) {
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
		$str .= "var " . $table -> name . "_model = Backbone.Model.extend({\n";
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

		$str .= "});\n";

		$str .= "var " . $table -> name . "_collection = Backbone.Collection.extend({\n";
		$str .= "\turl : '/dl/api/" . $table -> name . "/list_all/',\n";
		$str .= "\tmodel : " . $table -> name . "_model\n";
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
	 * Generate a whopping big block comment out of a paragraph.
	 * 
	 * @param string $str Comment. Should be pre-wrapped.
	 * @param string $indent Number of tabs to insert before each line.
	 * @return string
	 */
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
	
	/**
	 * Build the start of a query from an entity and data about its available fields and joins
	 * 
	 * @param array $data
	 */
	private function getQuery(Model_Entity $entity, array $data) {
		/* Fetch a list of fields */
		$fields = array();
		foreach($data['fields'] as $field) {
			$fields[] = self::wrapField($field['table'], $field['col']);
		}		

		/* Compile the JOIN statements */
		$joins = array(self::wrapTable($entity -> table -> name, $entity -> query_table_name));
		foreach($data['join'] as $join) {
			$on = array();
			foreach($join['on'] as $o) {
				$on[] = self::wrapField($o[0]['table'], $o[0]['col']) . " = " . self::wrapField($o[1]['table'], $o[1]['col']);
			}
			$joins[] = "LEFT JOIN " . self::wrapTable($join['table'], $join['as']) . " ON " . implode(" AND ", $on);
		}
		
		$query = "SELECT " . implode(", ", $fields) . " FROM " . implode(" ", $joins) . " ";
		return $query;
	}
	
	/**
	 * Utility for getQuery() to use.
	 * 
	 * @param string $table Name of the table in the database
	 * @param string $as Name to retrieve it as
	 * @return string SQL to retrieve the table as this nmae
	 */
	private static function wrapTable($table, $as) {
		if($table == $as) {
			return "`$table`";
		}
		return "`$table` As `$as`";
	}
	
	/**
	 * Utility for getQuery() to use.
	 * 
	 * @param string $table
	 * @param string $field
	 * @return string "`$table`.`$field`"
	 */
	private static function wrapField($table, $field) {
		return "`$table`.`$field`";
	}
	
	/**
	 * Convert first character of a string to uppercase
	 * 
	 * @param string $in
	 * @return string
	 */
	private static function titleCase($in) {
		if(strlen($in) == 0) {
			return $in;
		}
		return strtoupper(substr($in, 0, 1)) . substr($in, 1, strlen($in) - 1);
	}

	/**
	 * Compare two stacks, and return 1, 0 or -1 depending on how they differ. Specifically used to convert numbered columns into objects.
	 * 
	 * @param array $stack1
	 * @param array $stack2
	 * @return number
	 */
	private static function stack_compare(array $stack1, array $stack2) {
		for($i = 0; $i < count($stack2); $i++) {
			if(!isset($stack1[$i]) || $stack1[$i] != $stack2[$i]) {
				return -1;
			}
		}
		if(isset($stack1[$i])) {
			return 1;
		}
		return 0;
	}
	
	/**
	 * @param string $table
	 * @param string $col
	 */
	private function is_nullable($table, $col) {
		return $this -> database -> table[$table] -> cols[$col] -> nullable;
	}
	
	private static function keyDocumentation(SQL_Table $table, array $key) {
		$info = array();
		foreach($key as $f) {
			$info[] = "@param " . self::primitive($table -> cols[$f]) . " \$$f " . $table -> cols[$f] -> comment;
		}
		return $info;
	}
}
