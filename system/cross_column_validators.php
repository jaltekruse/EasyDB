<?php
include_once($_SERVER['DOCUMENT_ROOT'] . "/easy_db/system/db_util/mysql_util.php");

abstract class Cross_Column_Validator {

    protected $field_map;
    protected $curr_data;

    // Constructs a new column validator object.
    //
    // Parameters:
    // field_map - an associative array to map the names used by the validator to the names used by 
    //             the data outputs. 
    //            
    //             {  "validator_field" : "data_output_field","validator_field_2" : "data_output_field_2" }
    function __construct($field_map) {
        $this->field_map = $field_map; 
    }

    public function get_field_map() {
        return $this->field_map;
    }

    function validate($data) {
        $this->curr_data = $data;
        $this->validate_impl();
    }

    function get($field_name) {
        return $this->curr_data[$this->field_map[$field_name]];
    }

    function check_field_set($field_name) {
        if ( ! isset($this->curr_data[$this->field_map[$field_name]])) {
            throw new Exception("Expected a value in field '" . $field_name . "'.");
        }
    }

    abstract public function validate_impl();

}

class Date_Range_Validator extends Cross_Column_Validator {

    function validate_impl() {
        $this->check_field_set('start_date');
        $this->check_field_set('end_date');
        $start = new DateTime($this->get('start_date'));
        $end = new DateTime($this->get('end_date'));
        // This looks like the wrong thing to do here, but the invert method
        // actaully returns 1 only if the interval is negative and 0 otherwise
        // http://php.net/manual/en/class.dateinterval.php#dateinterval.props.invert
        if ($start->diff($end)->invert == 0) {
            return;
        }
        else {
            throw new Exception("End date does not occur after start date.");
        }
    }
}


?>
