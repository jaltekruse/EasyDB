<?php

class MySQL_Utilities {

    public static function quoted_val_or_null($val) {
        if (is_null($val))
            return "NULL";
        else
            return "'" . $val . "'";
    }

    // this variation of the above method is needed because checking for = NULL
    // is not supported by default in MySQL, for more info refer here
    // http://stackoverflow.com/questions/9608639/mysql-comparison-with-null-value
    public static function quoted_val_or_null_with_comparison_op($val) {
        if (is_null($val))
            return " IS NULL";
        else
            return " = '" . $val . "'";

    }
}
