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

    public function stop_subsequent_valdiators() {
        return FALSE; 
    }

}

class Error_Character_Stripper extends Value_Modifier {
    
    private $error_char;

    function __construct($error_char = "*"){
        $this->error_char = $error_char;
    }

    function modify_value($value) {
		if (substr($value, strlen($value) - 1) == $this->error_char){
			return substr($value, 0, strlen($value) - 1);
		} else {
            return $value;
        }
    }
}

class UTF8_Decoder extends Value_Modifier {

    function modify_value($value) {
        return utf8_decode($value);
    }
}

/*
 * Checks if a value is a blank string and returns as php NULL value if it is.
 * As stripping spaces is a default modifier, this functionality is not included here, if
 * you want whitespace to be considered a null value do not remove the default space stripping
 * and use this modifier.
 *
 * This modifier does not throw an error if the value is not an empty string, assuming that
 * there are other non-null values possible to be checked.
 */
class Null_Validator extends Value_Modifier {

    protected $last_val_null;

    function modify_value($value) {
        // as stripping spaces is default behavior, it is not included here 
        if ($value == ""){
            $this->last_val_null = TRUE;
            return NULL;
        } else {
            $this->last_val_null = FALSE;
            return $value;
        }
    } 

    public function stop_subsequent_valdiators() {
        return $this->last_val_null; 
    }

}


class Boolean_Validator extends Value_Modifier {

    function __construct() {
         
    }

    function modify_value($value) {
        switch (strtolower($value)) {
            case "1": 
            case "on":
            case "t":
            case "true":
                return "1";
                break;
            case "0":
            case "off":
            case "f":
            case "false":
                return "0";
                break;
            default:
                throw new Exception("Error with value formatting, expecting [1, t, true, on] for true or [0, f, false, off] for false.");
        }
    }
}

// TODO - implement a value creating code validator
// will allow for situations like the filename handling in mbed, where I want to have a dependent
// table to store the filenames, but I don't want to make users explicitly create a new entry in
// the filenames table to allow them to upload a sheet
// For this be sure to put a validator ahead of it to make sure it follows the formatting convention for filenames

/*
 * Designed to validate a coded value where the codes are stored in a dependent table
 *
 */
class Code_Value_Validator extends Value_Modifier {

    protected $valid_code_values;
    protected $valid_id_values;
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
        $this->valid_id_values = array(); 
        $query = "select " . $this->code_column . "," . $this->id_column . " from " . $this->table;
        $result = $db->query($query);
        if ($result) {
            for ($i = 0; $i < $result->num_rows; $i++) {
                $row = $result->fetch_assoc();
                if ( ! $this->case_sensitive ) $row[$this->code_column] = strtolower($row[$this->code_column]);
                $this->valid_code_values[ $row[$this->code_column] ] = $row[$this->id_column];
                $this->valid_id_values[ $row[$this->id_column] ] = $row[$this->code_column];
            }
        } 
        else {
            echo $query;
            throw new Exception("error reading from database: " . $db->error); 
        }
    }

    function modify_value($value) {
        if ( ! $this->case_sensitive ) $value = strtolower($value);
        if ( isset($this->valid_code_values[$value]) ) {
            return $this->valid_code_values[$value];
        } else {
            throw new Exception("Code '" . $value . "' not found in the '" . $this->table . "' table."); 
        }
    }
}

class ID_Value_Enforcer extends Code_Value_Validator {

    function modify_value($value) {
        if ( ! $this->case_sensitive ) $value = strtolower($value);
        if ( isset($this->valid_id_values[$value]) ) {
            return $value;
        } else {
            throw new Exception("Code not found in the '" . $this->table . "' table."); 
        }
    }

}

class Unique_Code_Enforcer extends Code_Value_Validator {

    function modify_value($value) {
        if ( ! $this->case_sensitive ) $value = strtolower($value);
        if ( isset($this->valid_code_values[$value]) ) {
            throw new Exception("Code already appears in the '" . $this->table . "' table."); 
        } else {
            return $value;
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

    // TODO - expand to handle other formats, seconds and milliseconds

    function modify_value($value) {
        $error_str = "Error with formatting of a time value.";
        $value = strtolower($value);
        $am_pm = NULL;
        if ( strstr($value, "am") ) {
            $am_pm = 'am'; 
            $value = str_replace('am', '', $value);
        }
        if ( strstr($value, 'pm' ) ) {
            if ($am_pm == 'am') throw new Exception($error_str);
            else {
                $am_pm = 'pm'; 
                $value = str_replace('pm', '', $value);
            }
        }
        $time_parts = explode(":", $value);
        // commenting this out for now to alow 12:30:00, but not currently validating or storing seconds
        //assert_true(count($time_parts) == 2, "Error with formatting of a time value.");
        $min = $time_parts[1];
        $hour = $time_parts[0];
        if (  ! is_numeric($min)  || ! is_numeric($hour) || 
                intval($hour) < 0 || intval($hour) > 23  ||
                intval($min) < 0  || intval($min) > 59){
            throw new Exception("Error with time, values are non-numeric or outside of proper range for hours or minutes.");
        }
        else {
            if ($am_pm == 'pm') {
                $hour += 12;
                if ($hour > 24) {
                    throw new Exception($error_str);
                }
            }
            return $hour . ":" . $min;
            // TODO - will need to do some value modification when doing other date formats as input
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
    private $date_parts;
    private $format_strings;

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
            assert_true( $this->year_pos >= 0 && $this->month_pos >= 0 && $this->day_pos >= 0);
        }
        $format = array();
        $format[$this->year_pos] = "%d";
        $format[$this->month_pos] = "%[0-9a-zA-Z]";
        $format[$this->day_pos] = "%d";
        $this->format_strings[] = implode("-", $format);
        $this->format_strings[] = implode("/", $format);
        $date_parts = array();
    }

    function modify_value($value) {
        // extract the date and format it for database insertion
        // we use a class variable to store the actual data to prevent creating a bunch of arrays
        // but we will create a local reference variable to avoid type $this eveywhere

        foreach ($this->format_strings as $format) {
            sscanf($value, $format, $date_parts[0], $date_parts[1], $date_parts[2]);
            if ($date_parts[1] != '') break;
        }

        //check if we already have integers for months, otherwise replace the month names/abbreviations with 
        if ( 0 + $date_parts[$this->month_pos] == 0 ) {
            $date_parts[$this->month_pos] = $this->get_month($date_parts[$this->month_pos]);
            if ( is_null($date_parts[$this->month_pos]) ) {
                throw new Exception("Error with date formatting.");
            }
        }
        // check that the month is valid
        if ( 
            0 + $date_parts[$this->month_pos] == 0 ||
            0 + $date_parts[$this->day_pos] == 0 ||
            0 + $date_parts[$this->year_pos] == 0 ||
            ! checkdate($date_parts[$this->month_pos], $date_parts[$this->day_pos], $date_parts[$this->year_pos])  ){
            throw new Exception("Error with date formatting.");
        }
        else{
            $date = $this->four_digit_year($date_parts[$this->year_pos]) .
                '-' . $date_parts[$this->month_pos] . '-' . $date_parts[$this->day_pos];
        }
        return $date;
    }

    // be sure to update the year_cuttoff value in the future, this defines the
    // point at which a 2 digit date is considered one in the 2000's vs 1900's
    // right now the cutoff is 2060, so a year of 61, would be read as 1961
    function four_digit_year($year){
        $year_cuttoff = 60;
        $year_val = 0 + $year;
        if ( ! is_int($year_val)){
            return $year;   
        }
        if ( strlen($year) == 4){
            return $year;
        }
        else if (strlen($year) == 2){
            if ( $year_val < $year_cuttoff)
                return '20' . $year;
            else
                return '19' . $year;
        }
        else{// not a 2 digit or four digit year
            return $year;
        }
    }

    function get_month($month_name){
        // extra cases are for portuguese month names used in some datasets
        switch(strtolower($month_name)){
            case 'jan':         return 1;
            case 'feb':         return 2;
            case 'mar':         return 3;
            case 'apr':         return 4;
            case 'may':         return 5;
            case 'jun':         return 6;
            case 'jul':         return 7;
            case 'aug':         return 8;
            case 'sep':         return 9;
            case 'oct':         return 10;
            case 'nov':         return 11;
            case 'dec':         return 12;
            default:            return null; 
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
