<?php

class Column_Splitter_Output extends Data_Output
{

    private $output_column_names;
    private $value_processors;
    private $value_processor_count;
    private $last_vals;
    private $delimiter;

    public function get_last_val()
    {
        throw new Exception("Operation unsupported for column splitter.");
    }

    function add_values_to_array(&$val_list)
    {
        if ($this->disabled) return;
        foreach ($this->last_vals as $value) {
            $val_list[] = $value;
        }
    }

    function add_values_to_assoc_array(&$val_list)
    {
        if ($this->disabled) return;
        $i = 0;
        foreach ($this->last_vals as $value) {
            $val_list[$this->output_column_names[$i]] = $value;
            $i++;
        }
    }

    function __construct($value_processors, $output_column_names, $delimiter, $ignore_in_duplicate_check)
    {
        parent::__construct($ignore_in_duplicate_check);
        $this->output_column_names = $output_column_names;
        $this->value_processors = $value_processors;
        $this->delimiter = $delimiter;
        foreach ($this->value_processors as $value_processor) {
            // TODO - pass down table name from above
            $value_processor->init("dummy_table_name_fixme", $this);
        }
        $this->value_processor_count = count($this->value_processors);
    }

    function number_of_inputs()
    {
        return $this->value_processor_count;
    }

    function split_value($value)
    {
        return explode($this->delimiter, $value);
    }

    public function set_last_val($val)
    {
        $this->last_vals = $val;
    }

    function get_from_assoc_last_vals($index)
    {
        return $this->last_vals[$this->value_processors[$index]->get_column()];
    }

    function get_from_numeric_array_last_vals($index)
    {
        return $this->last_vals[$index];
    }

    // TODO !! - these do not appear to be handling NULL appropriately!
    // need to use IS NULL syntax, NULL does not work with '='
    function duplicate_check_sql_repeated($intermediate_table = "")
    {
        return $this->gen_dup_check($intermediate_table, "get_from_assoc_last_vals");
    }

    function duplicate_check_sql($intermediate_table = "")
    {
        return $this->gen_dup_check($intermediate_table, "get_from_numeric_array_last_vals");
    }

    private function gen_dup_check($intermediate_table, $last_val_getter)
    {
        $sql_wheres = array();
        for ($i = 0; $i < $this->value_processor_count; $i++) {
            if ($intermediate_table != "")
                $sql = " " . $intermediate_table . ".";
            else
                $sql = "";
            $sql .= $this->value_processors[$i]->get_column();
            $sql .= MySQL_Utilities::quoted_val_or_null_with_comparison_op($this->$last_val_getter($i));
            $sql_wheres[] = $sql;
        }
        return implode(" AND ", $sql_wheres);

    }

    function convert_to_output_format($value)
    {
        try {
            $curr_vals = $this->split_value($value);
            // TODO - make sure this is what should be done in this case, currently if the number of validators does not
            // match the number of split values then no processing is done on them, may want to cycle through the smaller list
            // of processor instead if they do not match up (this makes sense particularly in the case where there is just one processor,
            // but this can also be handled by wrapping this data output with a Repeated_Data_Output. Also think about using a subset of
            // the processors if the list is too big (this may cause confusion)
            if (count($curr_vals) == $this->value_processor_count) {
                foreach ($curr_vals as $split_val) {
                    $this->last_vals[$this->get_current_index()] = $this->value_processors[$this->get_current_index()]->process_value($split_val);

                    // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
                    $this->finished_handling_an_input();
                }
            } else {
                foreach ($curr_vals as $split_val) {
                    $this->last_vals[$this->get_current_index()] = $split_val;

                    // TODO - finally blocks are only in PHP 5.5+, this is a hack to get around it
                    $this->finished_handling_an_input();
                }
            }
        } catch (Exception $ex) {
            // TODO - make this report an error to the user and store it in the upload history
            throw $ex;
            // TODO - this appearing above and in this catch block is not a mistake, finally blocks not
            // in until php5
            $this->finished_handling_an_input();
        }
    }

}
?>
