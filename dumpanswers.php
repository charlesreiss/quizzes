<?php
require_once "tools.php";

if (php_sapi_name() != "cli") {
    die("CLI only");
}

$qid = $argv[1];
if (count($argv) > 2) {
    $sid = $argv[2];
} else {
    $sid = FALSE;
}

$answers = array();
$qobj = qparse($qid);

if ($sid === FALSE) {
    foreach (glob("log/$qid/*.log") as $j=>$logname) {
        $sid = pathinfo($logname, PATHINFO_FILENAME);
        $sobj = aparse($qobj, $sid);
        $answers[$sid] = $sobj;
    }
} else {
    if (count($argv) > 3) {
        $time_cutoff = strtotime($argv[3]);
    } else {
        $time_cutoff = FALSE;
    }
    $answers = aparse($qobj, $sid, $time_cutoff);
}
echo json_encode($answers, JSON_PRETTY_PRINT);

?>

