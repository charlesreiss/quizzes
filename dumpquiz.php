<?php
require_once "tools.php";

if (php_sapi_name() != "cli") {
    die("CLI only");
}

echo json_encode(qparse($argv[1]), JSON_PRETTY_PRINT);
?>

