<?php
chdir(__DIR__);
if (isset($_GET['view_only'])) { 
    http_response_code(403);
    echo 'View-only access';
    exit;
}

require_once "tools.php";

$cors_origin = $metadata['cors-origin'];


if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    if (isset($cors_origin)) {
        header('Access-Control-Allow-Origin: '.$cors_origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Content-Length');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 60');
        header('Vary: Origin');
    }
} else {
    if (isset($cors_origin)) {
        header('Access-Control-Allow-Origin: '.$cors_origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Content-Length');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 60');
        header('Vary: Origin');
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data || $data['user'] != $user) {
        if ($isstaff) {
            $user = $data['user'];
        } else if (!$user && $data['session_id'] && check_session($data['user'], $data['quiz'], $data['session_id'])) {
            $user = $data['user'];
        } else {
            http_response_code(403);
            echo 'user '.$user.' sent as '.$data['user'].' (could not authenticate '.$data['session_id'].')';
            exit;
        }
    } 

    $qid = $data['quiz'];
    unset( $data['quiz'] );
    if (strpos($qid,'/') !== FALSE || strpos($qid, "..") !== FALSE) {
        http_response_code(403);
        echo 'invalid quiz: '.json_encode($qid);
        exit;
    }

    $qobj = qparse($qid);
    if (isset($qobj['error'])) {
        http_response_code(403);
        echo 'invalid quiz: '.json_encode($qid)."\n".$qobj['error'];
        exit;
    }

    $sobj = aparse($qobj, $user);

    if (!$sobj['may_submit']) {
        http_response_code(403);
        echo "quiz $qid is not accepting submissions";
        exit;
    }

    $path = "$qid/$user.log";
    $data['date'] = date('Y-m-d H:i:s');
    putLog($path, json_encode($data)."\n");
}
?>
﻿
