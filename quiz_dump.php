<!DOCTYPE html>
<?php 
if (php_sapi_name() != "cli") {die("not available from web");}
require_once "tools.php";
?>
<html>
<head>
    <meta charset="utf-8">
    <title><?=$_GET['title']?></title>
    <style>
        <?=file_get_contents("style.css")?>
    </style>
</head>
<body>
<?php 
function showQuiz($qid, $blank = true, $blank_includes_key = false) {
    global $user;
    $qobj = qparse($qid);
    if (isset($qobj['error'])) { echo $qobj['error']; return; }
    $sobj = aparse($qobj, $user);
    if (!$sobj['may_view']) { echo "You may not view this quiz"; return; }
    showQuizFromAParse($qobj, $sobj, $blank, $blank_includes_key);
}

if (!isset($_GET['qid'])) {
    $qids = array();
    foreach(glob('questions/*.md') as $i=>$name) {
        $name = basename($name,".md");
        $qobj = qparse($name);
        if ($qobj['unindexed']) {
            continue;
        }
        $qids[] = $name;
    }
} else {
    $qids = explode(',', $_GET['qid']);
}

foreach($qids as $i => $qid) {
    showQuiz($qid, true, isset($_GET['showkey']));
}
?>
