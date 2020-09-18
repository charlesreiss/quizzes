<?php
require_once "tools.php";

if (php_sapi_name() != "cli") {
    die("CLI only");
}

$qid = $argv[1];

$answers = array();

foreach (glob("log/$qid/*.log") as $j=>$logname) {
    $sid = pathinfo($logname, PATHINFO_FILENAME);
    $qobj = qparse($qid);
    $sobj = aparse($qobj, $sid);
    $answers[$sid] = $sobj;
}
echo json_encode($answers, JSON_PRETTY_PRINT);

?>

