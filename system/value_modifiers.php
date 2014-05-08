<?php

// TODO - create a modifier that will repeat values down the dataset during subsequent calls to it

abstract class Value_Modifier {
    
    function __construct() {

    }

   abstract function modify_value($value); 

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
        //check if we already have integers for months, otherwise replace the month names/abbreviations with 
        if ( ! intval($date_parts[$this->month_pos]) ) {
            $date_parts[1] = $this->get_month($date_parts[$this->month_pos]);
            if ( is_null($date_parts[$this->month_pos]) ) {
                // TODO - return an error for an incorrect month
                throw new Exception("Error with month.");
            }
        }
        // check that the month is valid
        if ( count($date_parts) != 3 || ! checkdate($date_parts[$this->month_pos], $date_parts[$this->day_pos], $date_parts[$this->year_pos])  ){
            throw new Exception("Error with date formatting.");
        }
        else{
            $date = $date_parts[$this->year_pos] . '-' . $date_parts[$this->month_pos] . '-' . $date_parts[$this->day_pos] . ' ';
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
