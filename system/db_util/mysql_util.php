<?php

class MySQL_Utilities {

    private static $db;

    // This is a bit of a hack, currently being called in the Record_Processor constructor
    // to provide the db connection for escaping input, if use of this utility gets
    // spread around the system we may need to more explicitly associate an instance
    // of the utility for different compoents. This would prevent issue with clashing connections
    // but currently the system is not really set up for handling several connections in one page
    // load.
    public static function set_db($db) {
        self::$db = $db;
    }

    public static function quoted_val_or_null($val) {
        if (is_null($val))
            return "NULL";
        else
            return "'" . MySQL_Utilities::$db->real_escape_string($val) . "'";
    }

    // this variation of the above method is needed because checking for = NULL
    // is not supported by default in MySQL, for more info refer here
    // http://stackoverflow.com/questions/9608639/mysql-comparison-with-null-value
    public static function quoted_val_or_null_with_comparison_op($val) {
        if (is_null($val))
            return " IS NULL";
        else
            return " = '" . MySQL_Utilities::$db->real_escape_string($val) . "'";
    }

    public static function insert_sql_based_on_assoc_array($values, $table, $external_fields_and_data = NULL) {
        $quoted_vals = array();
        $external_fields = "";
        $external_data = "";
        if ($external_fields_and_data != NULL) {
            $external_fields = ", " . $external_fields_and_data['fields'];
            $external_data = ", " . $external_fields_and_data['data'];
        }
        foreach ($values as $key=>$val) {
            $quoted_vals[$key] = MySQL_Utilities::quoted_val_or_null($val);
        }
        $sql = "insert into " . $table . " (`" . 
            implode("`,`", array_keys($values)) . "`" . $external_fields . ") VALUES ";
        $sql .= "(" . implode(",", $quoted_vals) . $external_data . ")";
        return $sql;
    }

}
