<?php

include_once("value_validators.php");
include_once("value_modifiers.php");

/*
 * Value processors validate and modify a series of values from a single column (or group of columns with the
 * same validation rules) in a given data-sheet.
 *
 */
class Value_Processor {

    private $modifiers;
    private $validators;

    /*
     * Constructs an object to represnt the modifications and validators for a singe column.
     * 
     * First parameter is a reference to the database instance to use with this processor, likely will just need
     * a read only connection, but this depends on what the modifiers do to interact with the database. The current
     * model involves other parts of the library actually inserting records, so explore the current model before
     * starting to use insert statements in your modifiers.
     *
     * Validators do not have a return value, so they can only throw exceptions when there is problem with the value.
     * Modifiers can both validate and return a different value that is formatted for database insertion.
     *
     * Takes an associative array for configuration parameter values. Fields used to set the array are:
     * ------------------------------------------------------------------------------------------------
     * table: name of table
     * column: name of column
     * strip_whitespace: Strip_Whitespace.[ BEFORE | AFTER | BOTH ]
     * modifiers: modifers to run on this column, subclasses of Value_Modifier
     * validators: validators to run on this column, subclasses of Value_Validator
     *
     * TODO - allow the users to order the moifiers and validators, need to add identifiation for each
     *      names of default validators provided, custom modifers/validators can be passed in a map
     *
     */
    function __construct(&$db, $parameters) {
        if ( ! isset($parameters['strip_whitespace'] )) $parameters['strip_whitespace'] = Strip_Whitespace::BOTH;
        assert( isset($parameters['table']) && isset($parameters['column']), "Table and column must be specified for a Value_Processor)");
        
        $this->modifiers = array();
        switch($parameters['strip_whitespace']) {
            case Strip_Whitespace::BEFORE: $this->modifiers[] = new Strip_Whitespace_Before(); break;
            case Strip_Whitespace::AFTER: $this->modifiers[] = new Strip_Whitespace_After(); break;
            case Strip_Whitespace::BOTH: $this->modifiers[] = new Strip_Whitespace_Both(); break;
            case Strip_Whitespace::NONE: break; 
            default: throw new Exception("Invalid whitespace handling provided.");
        }
        if ( isset($parameters['modifiers'])){
            $this->modifiers = array_merge($this->modifiers, $parameters['modifiers']);
        }
        $this->validators = array();
        
        foreach ($this->modifiers as $modifier) {
            $modifier->set_parent_value_processor($this);
        }

        // detect the type of the column and foreign keys, automatically add validators for them

    }

    /*
     * Takes a single value to be processed for modification and runs the configured list of modifiers
     * and validators, returns an object to allow descriptive error reporting.
     *
     */
    function process_value($value){
        $new_value = $value;
        foreach ($this->modifiers as $modifier) {
           $new_value = $modifier->modify_value($new_value);
        }
        return $new_value;
    }
}
?>
