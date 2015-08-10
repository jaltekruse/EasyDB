<?php
class Column_Combiner_Output extends Data_Output {

    private $value_processors;
    private $value_processor_count;
    private $last_vals;


    function get_last_val() {
        return implode(" ", $this->last_vals);
    }


    function add_values_to_assoc_array(&$val_list) {
        if ( $this->disabled ) return;
        $val_list[$this->output_column_name] = $this->get_last_val();
    }

    function add_values_to_array(&$val_list) {
        if ( $this->disabled ) return;
        $val_list[] = $this->get_last_val();
    }

    function __construct($value_processors, $output_column_name, $ignore_in_duplicate_check){
        parent::__construct($ignore_in_duplicate_check);
        $this->output_column_name = $output_column_name;
        $this->value_processors = $value_processors;
        foreach($this->value_processors as $value_processor) {
            // TODO - pass down table name from above
            $value_processor->init("dummy_table_name_fixme", $this);
        }
        $this->value_processor_count = count($this->value_processors);
    }

    function number_of_inputs() {
        return $this->value_processor_count;
    }

    function convert_to_output_format($value) {
        try {
            $this->last_vals[$this->get_current_index()] = $this->value_processors[$this->get_current_index()]->process_value($value);

            // TODO - finally blocks are only in PHP 5.5+, this is a hackt get around it
            $this->finished_handling_an_input();
        } catch (Exception $ex) {
            // TODO - make this report an error to the user and store it in the upload history

            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        }
    }
}
?>
