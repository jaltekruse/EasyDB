<?php

include_once($_SERVER['DOCUMENT_ROOT'] . "/easy_db/system/value_validators.php");
include_once($_SERVER['DOCUMENT_ROOT'] . "/easy_db/system/value_modifiers.php");

/*
 * Value processors validate and modify a series of values from a single column (or group of columns with the
 * same validation rules) in a given data-sheet.
 *
 */
class Value_Processor {

    private $modifiers;
    private $validators;
    private $table;
    private $column;
    private $db;
    private $user_config;
    private $parent_data_output;

    // These methods allow access to these fields for all of the validators and modifiers, which have a
    // reference back to this object
    
    // USE THIS TO ALLOW FOR ADDING FEATURES LIKE TABLE PREFIXES FOR SHARING A DATABASE WITH ANOTHER USER
    public function get_table() {
        return $this->table;
    }

    public function get_column() {
        return $this->column;
    }

    public function get_db() {
        return $this->db;
    }

    public function get_user_config() {
        return $this->user_config;
    }

    public function get_code_column_for_table( $table) {
       return $this->user_config->get_code_column_for_table($table);
    }

    public function get_id_column_for_table( $table) {
       return $this->user_config->get_id_column_for_table($table);
    }

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
     * column: name of column the data is to be stored in
     * strip_whitespace: Strip_Whitespace.[ BEFORE | AFTER | BOTH ]
     * modifiers: modifers to run on this column, subclasses of Value_Modifier
     * validators: validators to run on this column, subclasses of Value_Validator
     *
     * TODO - allow the users to order the moifiers and validators, need to add identifiation for each
     *      names of default validators provided, custom modifers/validators can be passed in a map
     *
     */
    function __construct(&$db, $user_config, $parameters) {
        if ( ! isset($parameters['strip_whitespace'] )) $parameters['strip_whitespace'] = Strip_Whitespace::BOTH;
        assert(isset($parameters['column'])); // "Column must be specified for a Value_Processor."
        $this->column = $parameters['column'];
        $this->db = $db;
        $this->user_config = $user_config;
        
        $this->modifiers = array();
        switch($parameters['strip_whitespace']) {
            case Strip_Whitespace::BEFORE: $this->modifiers[] = new Strip_Whitespace_Before(); break;
            case Strip_Whitespace::AFTER: $this->modifiers[] = new Strip_Whitespace_After(); break;
            case Strip_Whitespace::BOTH: $this->modifiers[] = new Strip_Whitespace_Both(); break;
            case Strip_Whitespace::NONE: break; 
            default: throw new Exception("Invalid whitespace handling provided.");
        }
        // TODO - default is to include this, may want to flip this
        if ( ! isset($parameters['exclude_UTF8_decoder'] ) || $parameters['exclude_UTF8_decoder'] == FALSE) {
            $this->modifiers[] = new UTF8_Decoder();
        }
       
        // for now value processors will default to having a value modifier of * used, unless
        if ( ! isset($parameters['error_char'])){
            $this->modifiers[] = new Error_Character_Stripper("*");    
        } else {
            $this->modifiers[] = new Error_Character_Stripper($parameters['error_char']);    
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

    function init($table, $parent_data_output) {
        $this->parent_data_output = $parent_data_output;
        $this->table = $table;
        foreach ($this->modifiers as $modifier) {
            $modifier->init();
        }
        foreach ($this->validators as $validator) {
            $validator->init();
        }
    }

    public function modify_current_value($value) {
        $this->parent_data_output->modify_current_value($value);
    }

    /*
     * Takes a single value to be processed for modification and runs the configured list of modifiers
     * and validators, returns an object to allow descriptive error reporting.
     *
     */
    function process_value($value){
        $new_value = $value;
        // TODO - add loops for validators, currently unused
        /*
        foreach ($this->modifiers as $modifier) {
            echo get_class($modifier) . ',';
        }
        echo '<br>';
        */
        //echo 'process_value: ' . $value . '<br>';
        foreach ($this->modifiers as $modifier) {
            $new_value = $modifier->modify_value($new_value);
            //echo 'new val in val processor: ' . $new_value . '<br>';
            //echo get_class($modifier) . ',';
            //echo "after modifier: stop? = " . $modifier->stop_subsequent_validiators() . '<br>';
            if ($modifier->stop_subsequent_validiators()) {
                break;
            }
        }
        return $new_value;
    }
}
?>
