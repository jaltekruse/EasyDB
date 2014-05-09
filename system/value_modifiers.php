<?php

// TODO - create a modifier that will repeat values down the dataset during subsequent calls to it

abstract class Value_Modifier {

    // this is set in the constructor of the parent as all 
    protected $parent_value_processor;

    function set_parent_value_processor(&$value_processor) {
        $this->parent_value_processor = $value_processor;
    }

    function __construct() {
         
    }

    abstract function modify_value($value); 
   
    // this is mostly unused, just for generating some starting state that cannot be known
    // at construction time 
    public function init() {}

}

/*
 * Designed to validate a coded value where the codes are stored in a dependent table
 *
 */
class Code_Value_Validator extends Value_Modifier {

    protected $valid_code_values;
    protected $case_sensitive;
    protected $table;
    protected $code_column;
    protected $id_column;

    function __construct($table, $case_sensitive = FALSE) {
        $this->case_sensitive = $case_sensitive;
        $this->table = $table;
    }
    
    public function init() {
        $db = $this->parent_value_processor->get_db();
        $this->code_column = $this->parent_value_processor->get_code_column_for_table($this->table);
        $this->id_column = $this->parent_value_processor->get_id_column_for_table($this->table);
        $this->valid_code_values = array(); 
        $result = $db->query("select " . $this->code_column . "," . $this->id_column . " from " . $this->table);
        if ($result) {
            for ($i = 0; $i < $result->num_rows; $i++) {
                $row = $result->fetch_assoc();
                if ( ! $this->case_sensitive ) $row[$this->code_column] = strtolower($row[$this->code_column]);
                $this->valid_code_values[ $row[$this->code_column] ] = $row[$this->id_column];
            }
        } 
        else {
            throw new Exception("error reading from database: " . $db->error); 
        }

    }

    function modify_value($value) {
        if ( ! $this->case_sensitive ) $value = strtolower($value);
        if ( isset($this->valid_code_values[$value]) ) {
            return $this->valid_code_values[$value];
        } else {
            throw new Exception("Code not found in the '" . $this->table . "' table."); 
        }
    }
}

class Enum_Validator extends Value_Modifier {

    // TODO - implement this
    function modify_value($value) {

    }
}

/*
 * A value repeater is designed to accomodate accepting datasets that feature gaps in
 * the columns, and are expected to be interpreted by cascading down the most recent
 * value in the column. This works well for manually entered data that frequently has
 * redundant column values that appear next to one another in the dataset.
 * 
 * Example: recording dates for observations, if one sheet is used for multiple days,
 * this allows the date to be written once, and all records below it are assumed to be
 * on the same day until one appears with a new value;
 *
 */
class Value_Repeater extends Value_Modifier {

    private $last_value;

    function __construct() {
        $this->last_value = NULL;
    }

    function modify_value($value) {
        if( $value != ''){
            $this->last_value = $value;
            return $this->last_value;
        } else {
            if ( is_null($this->last_value)) {
                throw new Exception("No value provided for repeating column.");
            }
            else {
                return $this->last_value; 
            }
        }
    }
    
}

class Time_Validator_Formatter extends Value_Modifier {

    // TODO - expand to handle other formats, am/pm, seconds and milliseconds

    function modify_value($value) {
        $time_parts = explode(":", $value);
        assert(count($time_parts) == 2, "Error with formatting of a time value.");
        $min = $time_parts[1];
        $hour = $time_parts[0];
        if (  ! is_numeric($min)  || ! is_numeric($hour) || 
                intval($hour) < 0 || intval($hour) > 23  ||
                intval($min) < 0  || intval($min) > 59){
            throw new Exception("Error with time, values are non-numeric or outside of proper range for hours or minutes.");
        }
        else{
            // TODO - will need to do some value modification when doing other date formats as input
            // (I don not believe the database can handle am/pm), that is why this is declared a modifier despite only validating
            // right now
            return $value;
        }
    }
}

// 'Enum' for specifying parts of a date input
abstract class Date_Parts {
    const YEAR = 0;
    const MONTH = 1;
    const DAY = 2;
}

class Date_Validator_Formatter extends Value_Modifier {

    private $year_pos;
    private $month_pos;
    private $day_pos;

    /*
     * Construts a date validator/formatter.
     *
     * Parameters:
     * date_parts_order - array with the ordering of the elements in the dates, use
     *      Date_Parts::[YEAR | MONTH | DAY ], default if nothing provided is day, month, year
     */
    function __construct($date_parts_order = NULL) {
        if ( is_null($date_parts_order) ) {
            $this->day_pos = 0;
            $this->month_pos = 1;
            $this->year_pos = 2;
        }
        else { // user supplied an ordering for the parts of the date
            if (count($date_parts_order) != 3) throw new Exception('Need to include all date parts if any are given.');
            $this->year_pos = array_search(Date_Parts::YEAR, $date_parts_order);
            $this->month_pos = array_search(Date_Parts::MONTH, $date_parts_order);
            $this->day_pos = array_search(Date_Parts::DAY, $date_parts_order);
            assert( $this->year_pos >= 0 && $this->month_pos >= 0 && $this->day_pos >= 0);
        }
    }

    function modify_value($value) {
        // extract the date and format it for database insertion
        $date_parts = explode("/", $value);

        // handles case where data is fprmatted with dashes instead of slashes
        if (count($date_parts) == 1) // nothing was split
            $date_parts = explode('-', $value);
        assert(count($date_parts) == 3, "Error with date formatting.");
        //check if we already have integers for months, otherwise replace the month names/abbreviations with 
        if ( ! intval($date_parts[$this->month_pos]) ) {
            $date_parts[1] = $this->get_month($date_parts[$this->month_pos]);
            if ( is_null($date_parts[$this->month_pos]) ) {
                throw new Exception("Error with month.");
            }
        }
        // check that the month is valid
        if ( count($date_parts) != 3 || 
            ! is_numeric($date_parts[$this->month_pos]) ||
            ! is_numeric($date_parts[$this->day_pos]) ||
            ! is_numeric($date_parts[$this->year_pos]) ||
            ! checkdate($date_parts[$this->month_pos], $date_parts[$this->day_pos], $date_parts[$this->year_pos])  ){
            throw new Exception("Error with date formatting.");
        }
        else{
            $date = $date_parts[$this->year_pos] . '-' . $date_parts[$this->month_pos] . '-' . $date_parts[$this->day_pos];
        }
        return $date;
    }

    function get_month($month_name){
        // extra cases are for portuguese month names used in some datasets
        switch(strtolower($month_name)){
            case 'jan': 		return 1;
            case 'feb':     	return 2;
            case 'mar': 		return 3;
            case 'apr':         return 4;
            case 'may':     	return 5;
            case 'jun':		    return 6;
            case 'jul':		    return 7;
            case 'aug':         return 8;
            case 'sep':     	return 9;
            case 'oct':         return 10;
            case 'nov': 		return 11;
            case 'dec':     	return 12;
            default: 	        return null; 
        }
    }

}

// 'Enum' for specifying whitespace handling policy
abstract class Strip_Whitespace {
    const BEFORE = 0;
    const AFTER = 1;
    const BOTH = 2;
    const NONE = 3;
}


class Strip_Whitespace_Before extends Value_Modifier {

    function modify_value($value) {
        return ltrim($value);
    }
}

class Strip_Whitespace_After extends Value_Modifier {

    function modify_value($value) {
        return rtrim($value);
    }
}


class Strip_Whitespace_Both extends Value_Modifier {

    function modify_value($value) {
        return trim($value);
    }
}
?>
