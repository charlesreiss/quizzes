<?php

$metadata = json_decode(file_get_contents('course.json'), TRUE);

if (php_sapi_name() == "cli") { // let run from commandline for testing
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
    $_SERVER['REQUEST_URI'] = '.';
    $user = 'mst3k'; // UVA's reserved "example use only" shibboleth username
    $isstaff = true;
    $realisstaff = $isstaff;
} else if ($_SERVER['SERVER_PORT'] == 8080) {
    $user = 'mst3k'; // UVA's reserved "example use only" shibboleth username
    $isstaff = true;
    $realisstaff = $isstaff;
} else {
    if (array_key_exists('PHP_AUTH_USER', $_SERVER)) {
        $user = $_SERVER['PHP_AUTH_USER'];
    } else {
        $user = NULL;
    }
    $isstaff = in_array($user, $metadata['staff']);
    $realisstaff = $isstaff;
}
$realuser = $user;
if ($isstaff && array_key_exists('asuser', $_GET)) {
    $user = basename($_GET['asuser']); // remove slashes
    $isstaff = in_array($user, $metadata['staff']);
}

?>
