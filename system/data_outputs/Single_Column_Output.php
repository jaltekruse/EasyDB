<?php

class Single_Column_Output extends Data_Output {

    function __construct($value_processor, $output_column_name, $ignore_in_duplicate_check ){
        parent::__construct($ignore_in_duplicate_check);
        $this->output_column_name = $output_column_name;
        $this->value_processor = $value_processor;
        // TODO - pass down table name from above
        $value_processor->init("dummy_table_name_fixme", $this);
    }

    function get_last_val() {
        return $this->last_val;
    }

    function get_output_col_name(){
        return $this->output_column_name;
    }

    function convert_to_output_format($value) {
        try {
            $this->last_val = $this->value_processor->process_value($value);

            // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
            $this->finished_handling_an_input();
        } catch (Exception $ex) {
            // TODO - make this report an error to the user and store it in the upload history
            $this->finished_handling_an_input();
            throw $ex;
            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
        }
    }
}
?>
