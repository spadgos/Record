<?php
/**
 *	This file defines the Record class
 *	@package FineForm
 */
/**
 *	Record class
 *
 *	This class is designed to hold the data from a single record from the database.
 *	It has functions to load and save data from the database, and is designed to
 *	be subclassed into more specific classes - one for each table.
 *
 *	Individual fields of each records can be get and set by their name:
 * <code>
 * $myRecord->myField;
 * $myRecord->myField = 'abc';
 * </code>
 *	Fields can be iterated using foreach
 * <code>
 * foreach ($myRecord as $key => $value) { }
 * </code>
 *
 * To count the fields, count the Record:
 * <code>
 * count($myRecord);
 * </code>
 *
 * This class and its subclasses are tied very closely to the database and have
 * a few basic constraints around naming and data structure.
 *
 * - Table names match exactly to the name of the class, converted from CamelCase
 *   to underscores. eg: SomeClass -> some_class. See {@link camelCaseToUnderscore()}
 *   This can be overridden by setting the {@link Record::$table $table} property
 *   prior to the main constructor being called, eg by setting a class variable.
 * - Every table has a primary key consisting of a single field named 'id'. This
 *   must be an auto incrementing field.
 *
 *	@package FineForm
 */
abstract class Record implements IteratorAggregate, Countable {

	// #Region : Class variables ##############################

	/**
	 *	Holds the actual values of the fields of this record.
	 *
	 *	It's an array in the format: $this->dbfields[<fieldName>] = <value>
	 *	It should only be accessed through the __get and __set functions by
	 *	requesting <fieldName>.
	 *	eg: to get the value of $this->dbfields['id'] you should call $this->id
	 *	Similar for setting - the __set function applies type casting to make
	 *	sure the data is of the type defined in the database. This will make it
	 *	either a string (truncated if the length is longer than the (var)char limit),
	 *	int, float or Date object.
	 *	@var	Array
	 */
	private $dbfields;

	/**
	 *	An array holding information about the fields of this record.
	 *	The format is:
	 *	<code>
	 *  // $this->fieldInfo[<fieldName>] = array(),  eg:
	 *  $this->fieldInfo['contactId'] = array("type" => "int", "size" => "10");
	 *  $this->fieldInfo['shiftDate'] = array("type" => "datetime"...);
	 *  </code>
	 *	It is populated by the {@link Record::describe()} function.
	 *	@var	Array
	 */
	private $fieldInfo;

	/**
	 *	The name of the database table this record belongs to.
	 *
	 *	@var	string
	 */
	protected $table;

	/**
	 *	Shows whether or not the values of this record have changed since being loaded.
	 *	Only applies to the values in the {@link $dbfields} array.
	 *	@var	bool
	 */
	private $dirty;

	/**
	 *	A cache of variables which may require significant processing or database calls to calculate.
	 *	@see Record::cache()
	 *	@see Record::cached()
	 *	@see Record::uncache()
	 *	@var Array
	 */
	private $cache;

	/**
	 * A cache of calls to SHOW FULL COLUMNS for each table. This saves us looking up the
	 * table definition for every new instance of a Record. In the future, this should probably
	 * be replaced with a disk cache or something.
	 * Note that this is being declared as static - it is shared globally amongst all Record
	 * objects, which is unlike the regular $cache variable.
	 *	@var	Array
	 */
	private static $describeCache;

	/**
	 *	Status code to show that the {@link Record::save() save} function failed.
	 *	This along with the other SAVE_* constants is passed to {@link Record::afterSave()}
	 */
	const SAVE_ERROR = 0;
	/**
	 *	Status code to show that the {@link Record::save() save} function was successful and required an update.
	 *	This along with the other SAVE_* constants is passed to {@link Record::afterSave()}
	 */
	const SAVE_UPDATED = 1;
	/**
	 *	Status code to show that the {@link Record::save() save} function was successful and required an insert.
	 *	This along with the other SAVE_* constants is passed to {@link Record::afterSave()}
	 */
	const SAVE_INSERTED = 2;

	// #End Region

	// #Region : Constructor ##################################
	/**
	 *	Record constructor
	 *	@param		Mixed		$id		Currently should be an int, the id of the record in the table for this record.
	 *									A value of 0 makes an empty/new record.
	 *	@param		bool		$runInitialisation		Should this function's {@link init()} function be called?
	 *													This would be set to false to avoid unnecessary database
	 *													calls when only basic information about this Record is needed.
	 *	@uses	Record::init()
	 *	@uses	Record::load()
	 *	@uses	Record::describe()
	 *
	 *	@author		nickf
	 *	@date		2007-10-01
	 */
	public function __construct($id = 0, $runInitialisation = true) {
		$this->dbfields = array();
		$this->table || ($this->table = camelCaseToUnderscore(get_class($this)));
		$this->describe();
		$this->load($id);
		$this->cache = array();

		if ($runInitialisation) $this->init();
		// $this->externals = $this->linkExternals = array();
	}

	/**
	 *	Perform post-construction initialisation functions.
	 *
	 *	This function is used by subclasses to run a set of functions after the
	 *	object is constructed. For example, loading settings from the database.
	 *	It is called at the end of {@link __construct} if its second parameter
	 *	is not set to false.
	 */
	protected function init() {}
	// #End Region

	// #Region : Object population functions ##################

	/**
	 *	Load the record from the database.
	 *
	 *	@param		mixed		$param		See the first parameter of {@link __construct()}
	 *	@uses		Record::loadFromId()
	 *	@uses		Record::loadFromArray()
	 *	@uses		Record::loadFromRecord()
	 *	@author		nickf
	 *	@date		2007-10-01
	 */
	protected function load($param) {
		if ($param && is_numeric($param)) {	// a number > 0, treat as an ID
			$this->loadFromId($param);
		} else if (is_array($param)) {
			$this->loadFromArray($param);
		} else if (is_object($param) && get_class($param) == get_class($this)) {
			$this->loadFromRecord($param);
		}
		$this->dirty = false;
	}

	/**
	 *	Load this Record's data from the database, using an id to look it up.
	 *
	 *	The passed parameter is an id, query the database and load the record
	 *	from there.
	 *
	 *	@param		int		$id		The id of this object
	 *	@return		void
	 *	@throws		RecordNotFoundException
	 *	@uses		Record::loadFromArray()
	 *	@author		nickf
	 *	@date		2007-11-03
	 */
	private function loadFromId($id) {

		$sql = sprintf("SELECT * FROM `%s` WHERE `id` = %d LIMIT 1", $this->table, $id);

		$result = db_query($sql);

		if ($row = db_assoc($result)) {
			$this->loadFromArray($row);
		} else {
			throw new RecordNotFoundException($this->table, $id);
		}
	}

	/**
	 *	Populate this Record's fields with values from an array.
	 *
	 *	The passed parameter is a row from the database, or a $_POST array of
	 *	field => value pairs.
	 *
	 *	@param		Array	$arr	The array containing the data for this object
	 *	@return		void
	 *	@author		nickf
	 *	@date		2007-11-03
	 */
	private function loadFromArray(Array $arr) {
		foreach ($arr as $field => $val) {
			$this->$field = $val;	// so that it goes through the __set function
		}
	}

	/**
	 *	Load this Record's fields from $_POST.
	 *	Similar to loadFromArray() except that this sets the field to dirty,
	 *	something which is not the case with the other load* functions.
	 *
	 *	@uses	Record::loadFromArray()
	 *	@return	void
	 */
	public function loadFromPost() {
		$this->loadFromArray($_POST);
		$this->dirty = true;
	}

	/**
	 *	Instantiate this object, using the variables from a different Record as a base.
	 *
	 *	This function only copies the database field values, and no other externals or anything.
	 *
	 *	@param		Record	$r					$r should be the same class as $this.
	 *	@return		void
	 *	@throws		IncorrectDataTypeException	if the class is of the wrong type
	 *	@author		nickf
	 *	@date		2008-10-13
	 */
	private function loadFromRecord(Record $r) {
		if (get_class($r) != get_class($this)) {
			throw new IncorrectDataTypeException("", get_class($this));
		}
		foreach (array_keys($this->dbfields) as $field) {
			$this->$field = $r->$field;
		}
	}

	// #End Region

	// #Region : Magic methods ################################

	/**
	 *	Retrieves a field value from the {@link $dbfields} array.
	 *
	 *	This is the main accessor for the database fields. If this Record is
	 *	mapping a table with a field named "foo", then you only need to call
	 *	<code>$myRecord->foo</code>
	 *	to access its value.
	 *
	 *	@throws FieldNotFoundException		If the field is non-existent
	 *	@magic	Magic function which is called when trying to access a class member.
	 */
	public function __get($var) {
		if (array_key_exists($var, $this->dbfields)) {
			return $this->dbfields[$var];
		} else {
			throw new FieldNotFoundException($var);
		}
	}

	/**
	 *	Set the value of a field, taking its type and constraints into account.
	 *
	 *	Formats and sets the value of a field, according to the data type described
	 *	in {@link $fieldInfo}. Enums can be set by passing their string value (the label) or
	 *	an integer which corresponds to the array of possible values. Make sure
	 *	it is an integer, and not "1", for example.
	 *	@throws 	FieldNotFoundException		If the field doesn't exist.
	 *	@throws		ValueNotValidException		If the value passed is incompatible with this field type.
	 *	@return		mixed						The value of the requested variable
	 *	@author		nickf
	 *	@date		2007-10-01
	 *	@magic		Magic function which is called when trying to set a class member.
	 */
	public function __set($var, $val) {
		if ($this->hasField($var)) {
			$dirtied = null;		// null means we're not sure yet.

			$canBeNull = $this->fieldInfo[$var]['null'];

			if (!is_null($val) || !$canBeNull) {
				switch ($this->fieldInfo[$var]['type']) {
					case "varchar" : case "char" :
						$val = (string) $val;
						if (strlen($val) > $this->fieldInfo[$var]['size']) {
							// throw an error?
							$val = substr($val, 0, $this->fieldInfo[$var]['size']);
						}
					break;
					case "bool" :	// tinyint(1)
						if (is_string($val)
						  && !is_numeric($val)
						  && in_array(strtolower($val), array("no", "false", "off"))) {
							$val = false;
						} else {
							$val = (bool) $val;
						}
					break;
					case "int" : case "bit" : case "tinyint" : case "smallint" :
					case "mediumint" : case "integer" : case "bigint" :
						if ($this->fieldInfo[$var]['unsigned']) {
							if ($val < 0) {
								throw new ValueNotValidException($var, $val);
							}
							if (is_int($val + 0)) { // for numbers larger than 2^31
													// this will be false (they become floats)
								$val = (int) $val;
							}
						} else {
							$val = (int) $val;
						}
					break;
					case "real" : case "float" : case "decimal" :
					case "double" : case "numeric" :
						$val = (float) $val;
						if ($val < 0 && $this->fieldInfo[$var]['unsigned']) {
							throw new ValueNotValidException($var, $val);
						}
					break;
					case "date" : case "datetime" :
					case "timestamp" :
						if (!is_int($val)) {
							$val = strtotime($val);
							$val = (int) $val;
						}
					break;
					case "enum" :
						if (is_int($val)) {
							// assume it's an index of the values array.
							if (isset($this->fieldInfo[$var]['values'][$val])) {
								$val = $this->fieldInfo[$var]['values'][$val];
							} else {
								throw new ValueNotValidException($var, $val);
							}
						} else {
							$val = (string) $val;
							if (!in_array($val, $this->fieldInfo[$var]['values'], true)) {
								throw new ValueNotValidException($var, $val);
							}
						}
					break;
					case "text" :
					case 'time' :
					default :
						$val = (string) $val;
					break;
				}
			}
			// the use of isset here might be confusing in that you'd think that
			// if the field !isset() then you've got a dirty object, but we
			// actually want the opposite. The fields should all be initialised
			// when built, and we don't want to mark it dirty until it changes
			// from that.
			if (is_null($dirtied)) {
				$dirtied = array_key_exists($var, $this->dbfields) && $this->dbfields[$var] !== $val;
			}
			$this->dirty = $this->dirty || $dirtied;
			$this->dbfields[$var] = $val;
		} else {
			throw new FieldNotFoundException($var);
		}
	}

	/**
	 *	Will tell you whether one of the database fields exists and is set (not null).
	 *	Almost identical in function to Record::hasField(), however this will
	 *	return false if the field is null.
	 *	@return		bool	True if there is a field with the given name, AND if
	 *						the value of that field is not NULL.
	 */
	public function __isset($field) {
		return isset($this->fieldInfo[$field]);
	}

	// #End Region

	// #Region : Interface methods ############################

	/**
	 *	Implementation for the IteratorAggregate interface.
	 *
	 *	The iterator iterates over the {@link $dbfields} array.
	 *
	 *	@return	ArrayIterator
	 */
	public function getIterator() {
		return new ArrayIterator($this->dbfields);
	}

	/**
	 * Counts the number of fields in this Record.
	 *
	 * Does not need to be called directly. Instead, just use it like this:
	 * <code>
	 * count($myRecord);
	 * </code>
	 *
	 * @return	int		The number of fields in this Record
	 */
	public function count() {
		return count($this->dbfields);
	}
	// #End Region

	// #Region : Bitmask functions ############################

	/**
	 *	Add a value to a bitmask field.
	 *
	 *	Adds the given flag into the bitmask. More than one flag can be added at
	 *	once, by performing bitwise calculations prior to calling this function,
	 *	or simply by adding more parameters to the function, eg:
	 *	<code>
	 *  $o->addFlag("myMask", FLAG_A | FLAG_B | FLAG_D);  // } these two are
	 *  $o->addFlag("myMask", FLAG_A, FLAG_B, FLAG_D);    // } identical
	 *  </code>
	 *
	 *	@param	string		$field		The bitmask field to manipulate
	 *	@param	int			$flag		The flag (or combination of flags) to add.
	 *	@param	int			$flag,...	Unlimited OPTIONAL extra flags
	 *	@return	void
	 *
	 *	@author	nickf
	 *	@date	2008-10-28
	 */
	public function addFlag($field, $flag) {
		$args = func_get_args();
		if (($l = count($args)) > 2) {
			for ($i = 2; $i < $l; ++$i) {
				$flag |= (int) $args[$i];
			}
		}

		$this->$field |= (int) $flag;
	}

	/**
	 *	Remove a value from a bitmask field.
	 *
	 *	Removes the given flag from the bitmask. More than one flag can be removed at
	 *	once, by performing bitwise calculations prior to calling this function,
	 *	or simply by adding more parameters to the function, eg:
	 *	<code>
	 *  $o->removeFlag("myMask", FLAG_A | FLAG_B | FLAG_D);  // } these two are
	 *  $o->removeFlag("myMask", FLAG_A, FLAG_B, FLAG_D);    // } identical
	 *  </code>
	 *	@param	string	$field		The bitmask field to manipulate
	 *	@param	int		$flag		The flag (or combination of flags) to remove.

	 *	@return	void
	 *
	 *	@author	nickf
	 *	@date	2008-10-28
	 */
	public function removeFlag($field, $flag) {
		$args = func_get_args();
		if (($l = count($args)) > 2) {
			for ($i = 2; $i < $l; ++$i) {
				$flag |= (int) $args[$i];
			}
		}
		$this->$field &= ~((int) $flag);
	}

	/**
	 *	Toggle a value in a bitmask field.
	 *
	 *	Toggles the given flags in the bitmask. More than one flag can be toggled at
	 *	once, by performing bitwise calculations prior to calling this function,
	 *	or simply by adding more parameters to the function, eg:
	 *	<code>
	 *  $o->toggleFlag("myMask", FLAG_A | FLAG_B | FLAG_D);  // } these two are
	 *  $o->toggleFlag("myMask", FLAG_A, FLAG_B, FLAG_D);    // } identical
	 *  </code>
	 *
	 *	@param	string		$field		The bitmask field to manipulate
	 *	@param	int			$flag		The flag (or combination of flags) to toggle.
	 *	@param	int			$flag,...	Unlimited OPTIONAL extra flags
	 *	@return	void
	 *
	 *	@author	nickf
	 *	@date	2008-10-30
	 */
	public function toggleFlag($field, $flag) {
		$args = func_get_args();
		if (($l = count($args)) > 2) {
			for ($i = 2; $i < $l; ++$i) {
				$flag |= (int) $args[$i];
			}
		}
		$this->$field ^= (int) $flag;
	}

	/**
	 *	hasFlag()
	 *	Function to handle working with bitmask fields.
	 *	Checks if the given flag exists in the mask. If a combination of flags is
	 *	passed to this function, it will only return true if they ALL exist.
	 *	<code>
	 *  //eg: assume $o->myMask = 13 (1101)
	 *  $o->hasFlag("myMask", 6)     (0110)  false
	 *  $o->hasFlag("myMask", 4, 1)  (0101)  true
	 *  $o->hasFlag("myMask", 15)    (1111)  false
	 *  </code>
	 *
	 *	@param	string	$field			The bitmask field to check
	 *	@param	int		$flag			The flag (or combination of flags) to check.
	 *	@param	int		$flag,...		Unlimited OPTIONAL extra flags
	 *	@return	bool					If the given flags are all set.
	 *
	 *	@author	nickf
	 *	@date	2008-10-28
	 */
	public function hasFlag($field, $flag) {
		$flag = (int) $flag;

		$args = func_get_args();
		if (($l = count($args)) > 2) {
			for ($i = 2; $i < $l; ++$i) {
				$flag |= (int) $args[$i];
			}
		}
		return ($this->$field & $flag) == $flag;
	}

	/**
	 *	hasAnyFlag()
	 *	Function to handle working with bitmask fields.
	 *	Checks if any of the given flags exist in the mask.
	 *	<code>
	 *	// eg: assume $o->myMask = 13   (1101)
	 *	$o->hasAnyFlag('myMask', 4, 2)  (0110)  true
	 *	$o->hasAnyFlag('myMask', 5)     (0101)  true
	 *	$o->hasAnyFlag('myMask', 2)     (0010)  false
	 *	</code>
	 *	@param	string	$field			The bitmask field to check
	 *	@param	int		$flags			The flags to check.
	 *	@param	int		$flag,...		Unlimited OPTIONAL extra flags
	 *	@return			bool			If any of the given flags are set.
	 *
	 *	@author	nickf
	 *	@date	2008-10-29
	 */
	public function hasAnyFlag($field, $flag) {
		$flag = (int) $flag;

		$args = func_get_args();
		if (($l = count($args)) > 2) {
			for ($i = 2; $i < $l; ++$i) {
				$flag |= (int) $args[$i];
			}
		}
		return (bool) ($this->$field & $flag);
	}

	// #End Region

	// #Region : Field and property functions #################
	/**
	 *	Return the numerical value of an enum.
	 *
	 *	The return value is the position (index) in the array of possible values.
	 *
	 *	@param		string		$fieldName	The name of the field which is an enum.
	 *	@return		int						Returns the position in the array (0-indexed)
	 *
	 *	@throws	FieldNotFoundException		If the field does not exist.
	 *	@throws	IncorrectDataTypeException	If the field is not an enum.
	 *	@throws	ValueNotValidException		If the value is not found in the array.
	 *	@author	nickf
	 *	@date	2008-10-07
	 */
	public function getEnumIndex($fieldName) {
		if (!$this->hasField($fieldName)) {
			throw new FieldNotFoundException($fieldName);
		} else if ($this->fieldInfo[$fieldName]['type'] != 'enum') {
			throw new IncorrectDataTypeException($fieldName, 'enum');
		} else {
			$val = $this->$fieldName;
			$ret = array_search($val, $this->fieldInfo[$fieldName]['values']);
			if ($ret === false) {
				throw ValueNotValidException($fieldName, $val);
			} else {
				return $ret;
			}
		}
	}

	/**
	 *	Check if this object's corresponding DB table has a specific field.
	 *
	 *	This differs from {@link __isset()} in that this function does not
	 *	consider the value, or lack thereof, of the field.
	 *
	 *	@param		string		$fieldName		The name of the field you want to check for
	 *	@return						bool		True if the field exists.
	 *	@author		nickf
	 *	@date		2007-10-31
	 *	@see		Record::__isset()
	 */
	public function hasField($fieldName) {
		return array_key_exists($fieldName, $this->fieldInfo);
	}

	// #End Region

	// #Region : Internal caching functions ###################

	/**
	 *	Store or retrieve a value from the cache.
	 *
	 *	The cache is used, obviously, to cache certain variables which may be
	 *	requested multiple times, and are not likely to change during execution,
	 *	and may require an amount of processing to calculate. eg: checking the
	 *	database for certain variables.
	 *
	 *	Cached variables are per-request. That is, they are not stored between
	 *	requests at all (eg: on disk, or in memory).
	 *
	 *	Depending one whether one parameter is passed or two, this function is
	 *	both an accessor and mutator.
	 *
	 *	As a mutator:
	 *	@param		string	$key		The name of the cache variable to store or retrieve.
	 *	@param		mixed	$value		The value to store in the cache. Old values will be overwritten.
	 *
	 *	@return		void|mixed			The value of that cache variable, or void if used as a mutator.
	 *	@see		Record::uncache()
	 *	@see		Record::cached()
	 *
	 *	@throws				CacheNotFoundException	if there is no cache variable of that name.
	 *	@author		nickf
	 *	@date		2008-10-14
	 */
	protected function cache($key) {
		$args = func_get_args();
		if (count($args) == 1) {
			if (!$this->cached($key)) {
				throw new CacheNotFoundException($key, $this->cache);
			} else {
				return $this->cache[$key];
			}
		} else {
			$this->cache[$key] = $args[1];
		}
	}

	/**
	 *	Check whether a variable has been cached. This should be called prior to
	 *	Record::cache(x) to avoid the CacheNotFoundException.
	 *
	 *	@param		string	$key		The name of the variable to check.
	 *	@return				bool		True if it has been cached.
	 *	@author		nickf
	 *	@date		2008-10-14
	 */
	protected function cached($key) {
		return array_key_exists($key, $this->cache);
	}

	/**
	 *	Remove a variable from the cache.
	 *
	 *	@param		string	$key		The name of the variable to remove.
	 *	@return				void
	 *	@author		nickf
	 *	@date		2008-10-14
	 */
	protected function uncache($key) {
		unset($this->cache[$key]);
	}

	// #End Region

	// #Region : Database functions ###########################

	/**
	 *
	 *
	 */
	protected static function classToTable($className) {
		return camelCaseToUnderscore($className);
	}

	/**
	 *	Gets the table information from the database, and initialises
	 *	all of this object's fields to the default.
	 *
	 *	@author		nickf
	 *	@date		2007-10-01
	 */
	protected final function describe() {

		$table = $this->table;
		if (empty(Record::$describeCache[$table])) {
			$out = array();
			$result = db_query("SHOW FULL COLUMNS FROM `%s`", $table);

			while ($row = db_assoc($result)) {
				$field = $row['Field'];

				preg_match("@^([a-z]*)(?:\\((\d+)\\)|)\s*(.*)$@", strtolower($row['Type']), $matches);

				// "int(10) unsigned"				"enum('a','b','c')"
				// 1 - Type "int"					"enum"
				// 2 - size indicator "10"			""
				// 3 - extra info "unsigned"		"('a','b','c')"

				$out[$field] = array(
					"type" => $matches[1],
					"size" => intval($matches[2]),
					"unsigned" => $matches[3] == "unsigned",
					"extra" => $row['Extra'],
					"default" => $row['Default'],
					"label" => $row['Comment'],
					"null" => $row['Null'] == "YES"
				);
				if ($matches[1] == "enum") {	// get the enumerated values.
					$values = explode("','", substr($matches[3], 2, -2));
					$out[$field]['values'] = $values;

					// if the default value isn't in this list (ie: there isn't a default value), make the first option the default.
					if (!in_array($row['Default'], $out[$field]['values'])) {
						$out[$field]['default'] = $out[$field]['values'][0];
					}
				} else if ($matches[1] == "tinyint" && intval($matches[2]) === 1) {
					$out[$field]['type'] = 'bool';
				}
			}
			self::$describeCache[$table] = $out;
		}
		$this->fieldInfo = Record::$describeCache[$table];
		foreach (array_keys($this->fieldInfo) as $field) {
			$this->$field = $this->fieldInfo[$field]['default'];
		}

	}

	/**
	 *	Checks the database to see that this record exists.
	 *
	 *	The result is {@link cache() cached} for future calls.
	 *
	 *	@return		bool	True if this record exists in the database
	 *	@author		nickf
	 *	@date		2007-10-01
	 */
	public function exists() {
		if (!$this->cached('exists')) {
			if ($this->id == 0) {
				$this->cache('exists', false);
			} else {
				$sql = sprintf(
					"SELECT * FROM `%s` WHERE `id` = %d",
					$this->table, $this->id
				);
				$result = db_query($sql);
				$this->cache('exists', db_num_rows($result) > 0);
			}
		}
		return $this->cache('exists');
	}

	/**
	 *	Save this record to the database.
	 *
	 *	This will check whether the table needs updating or an insert. If inserting,
	 *	the Record will have its id field updated to the new value.
	 *
	 *	Before performing any action, this function calls {@link beforeSave()},
	 *	and if that returns false, the save will be aborted immediately, returning false.
	 *
	 *	After the save, this function will call {@link afterSave()} passing it
	 *	one of the constants, {@link SAVE_ERROR}, {@link SAVE_INSERTED}, or
	 *	{@link SAVE_UPDATED}. If afterSave returns a value other than NULL, that
	 *	will be used as this function's return value, otherwise this function
	 *	will return a bool indicating success.
	 *
	 *	@uses		Record::beforeSave()
	 *	@uses		Record::afterSave()
	 *	@return		bool		True if the save was successful.
	 *	@author		nickf
	 *	@date		2007-10-01
	 */
	public function save() {
		if ($this->beforeSave() === false) return false;
		$doInsert = !$this->exists();
		$result = true;
		if ($doInsert || $this->dirty) {
			$temp = array();
			foreach (array_keys($this->dbfields) as $key) {
				$temp["`" . $key . "`"] = $this->getSQLValue($key) . "\n";
			}
			// not using REPLACE since that plays havoc when you've got foreign key constraints.
			if (!$doInsert) {
				$temp2 = array();
				foreach ($temp as $f => $v) {
					$temp2[] = $f . " = " . $v;
				}
				$sql = sprintf(
					  "UPDATE `%s` SET %s WHERE `id` = %d"
					, $this->table
					, implode(", ", $temp2)
					, $this->id
				);
			} else {
				$sql = sprintf(
					  "INSERT INTO `%s` (%s) VALUES (%s)"
					, $this->table
					, implode (", ", array_keys($temp))
					, implode(", ", $temp)
				);
			}
			if ($result = db_query($sql)) {
				$this->cache('exists', true);
				if ($doInsert) $this->id = db_insert_id();
				$this->dirty = false;
			}
		}
		$saveIndicator = !$result ? self::SAVE_ERROR : ($doInsert ? self::SAVE_INSERTED : self::SAVE_UPDATED);
		$afterSave = $this->afterSave($saveIndicator);
		if (!is_null($afterSave)) $result = $afterSave;

		return $result;
	}

	/**
	 *	Delete this Record from the database.
	 *
	 *	Does not perform any cascading deletes.
	 *	@return		bool		Success
	 */
	public function delete() {
		return db_query("DELETE FROM `%s` WHERE `id` = %d LIMIT 1", $this->table, $this->id);
	}

	/**
	 *	Gets a variable from the {@link $dbfields} array and returns a string ready to
	 *	be inserted into an SQL statement. eg:
	 *  <code>
	 *  echo $this->getSQLValue('myIntegerField');  // "4"
	 *  echo $this->getSQLValue('lastName');        // "'O\'Leary'"
	 *  echo $this->getSQLValue('myDateField');     // "'2007-09-30'"
	 *  </code>
	 *
	 *	@throws 	MSException		Field not found
	 *	@param		string			$field				The field name.
	 *	@return						string				The value of the field, escaped and
	 *													ready for injection into an SQL statement.
	 *	@author		nickf
	 *	@date		2007-10-01
	 */
	protected function getSQLValue($field) {
		if (!$this->hasField($field)) {
			throw new FieldNotFoundException($field);
		}

		$type = $this->fieldInfo[$field]['type'];
		$value = $this->dbfields[$field];	// think about whether we should be passing this through __get
		if (is_null($value) || ($this->fieldInfo[$field]['extra'] == "auto_increment" && !$value)) {
			return "NULL";
		} else {
			switch ($type) {
				case "bit" : case "tinyint" : case "smallint" : case "mediumint" :
				case "int" : case "integer" : case "bigint" :
					return (string) intval($value);
				case "float" : case "double" : case "real" : case "decimal" : case "numeric" :
					return (string) floatval($value);
				case "date" :
					return date("'Y-m-d'", $value);
				case "datetime" : case "timestamp" :
					return date("'Y-m-d h:i:s'", $value);
				case "bool" :	// is a TINYINT(1)
					return $value ? '1' : '0';
				case "text" : case "char" : case "varchar" : default :
					$value = str_replace("\r\n", "\n", $value);
					return "'" . db_escape($value) . "'";
			}
		}
	}

	// #End Region


	// #Region : Debugging ####################################

	/**
	 *	Prints information about this record's members to the output buffer.
	 *
	 *	@return 	void
	 *	@author		nickf
	 *	@date		2007-10-01
	 */
	public function dump() {
		echo "Record from table: '" . $this->table . "' {\n";
		foreach ($this->dbfields as $key => $val) {
			echo "  $key : ";
			var_dump($val);
		}
		echo "}\n";
	}

	// #End Region

	// #Region : JSON/Javascript functions ####################

	/**
	 *	Get an array of {@link $dbfields values} to be encoded into JSON.
	 *
	 *	This function is designed to be overwritten in subclasses so they can
	 *	alter the fields returned to suit the situation.
	 *
	 *	@return		Array		An associative array of fieldName => fieldValue pairs.
	 */
	public function getJSONArray() {
		$out = array();
		foreach (array_keys($this->dbfields) as $fieldName) {
			$out[$fieldName] = $this->$fieldName;	// so that it get pushed through the __get function
		}
		return $out;
	}

	/**
	 *	Get the JSON string of this object's fields.
	 *
	 *	@uses	Record::toJSON()
	 *	@return	string
	 */
	public function toJSON() {
		return json_encode($this->getJSONArray());
	}

	// #End Region

	// #Region : Events ######################################

	/**
	 * Called before any other action occurs in the save() method.
	 * If this returns false, the save method is aborted.
	 * @return bool
	 */
	protected function beforeSave() { return true; }

	/**
	 * Called after the save function has completed.
	 * If this function is implemented in subclasses, it can optionally return
	 * a success indicator (bool). This value will be in turn returned by save().
	 * If there is no return value (or NULL is returned), then the regular save()
	 * return value will be passed.
	 *
	 * @param	int		$saveResult		This will be one of the following:
	 * 									{@link SAVE_ERROR}, {@link SAVE_INSERTED} or {@link SAVE_UPDATED}
	 * @return void|bool
	 */
	protected function afterSave($saveResult) {}

	// #End Region

	public static function getIdFromObject($obj) {
		if (isset($obj->id)) {
			return $obj->id;
		} else if (is_array($obj)) {
			$out = array();
			foreach ($obj as $n) {
				$out[] = self::getIdFromObject($n);
			}
			return $out;
		} else {
			return null;
		}
	}
}
