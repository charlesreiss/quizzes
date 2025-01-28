<!DOCTYPE html>﻿<?php

require_once "tools.php";
require_once "grader_rubrics.php";

if (!$isstaff) {
    http_response_code(403);
    die('staff use only');
}

$_review = array();
function get_review($quizid) {
    if (isset($_review[$quizid])) return $_review[$quizid];
    if (FALSE && file_exists("cache/$quizid-review.json")) {
        $ans = json_decode(file_get_contents("cache/$quizid-review.json"), true);
    } else {
        $ans = array();
        histogram($quizid, $ans);
    }
    if (file_exists("log/$quizid/regrades.log")) {
        $fh = fopen("log/$quizid/regrades.log", "r");
        while(($line = fgets($fh)) != FALSE) {
            $rgent = json_decode($line, true);
            if (!isset($ans["$rgent[task]-regrade"]))
                $ans["$rgent[task]-regrade"] = array();
            if ($rgent['add']) {
                $ans["$rgent[task]-regrade"][$rgent['student']] = true;
            } else {
                unset($ans["$rgent[task]-regrade"][$rgent['student']]);
            }
        }
        fclose($fh);
        foreach($ans as $key=>$val) if (substr($key, 8) == '-regrade') {
            $slug = substr($key,0,8);
            if (!isset($ans[$slug])) $ans[$slug] = array();
            foreach($val as $user=>$dump) {
                if (!array_key_exists($user, $ans[$slug])) $ans[$slug][$user] = array("regrade" => 1);
            }
        }
    }
    
    $_review[$quizid] = $ans;
    return $ans;
}

/**
 * Given a quiz and one of its questions, return an array.
 * keys are user-submitted text
 * values are arrays with three keys:
 *  "matches":{"quiz key":weight, "quiz key":weight, ...}
 *  "users":["mst3k", ...]
 *  "decided": (null or number or array {"grade":...,"reply":...})
 */
function get_blanks($quizid, $q) {
    $slug = $q['slug'];
    $rev = get_review($quizid);
    if (!isset($rev["$slug-answers"])) return array();
    $ext = array();
    if (file_exists("log/$q[quizid]/key_$slug.json"))
        $ext = json_decode(file_get_contents("log/$q[quizid]/key_$slug.json"), true);

    $ans = array();
    foreach($rev["$slug-answers"] as $txt=>$users) {
        $match = array();
        $correct_in_key = FALSE;
        foreach($q['key'] as $key) {
            $k = $key['text'];
            if(($k[0] == '/') ? preg_match($k, $txt) : $k == $txt) {
                $match[$k] = $key['points'];
                if (round($key['points'], 6) == 1)
                    $correct_in_key = TRUE;
            }
        }
        if (isset($ext[$txt]) && is_numeric($ext[$txt])) {
            $ext[$txt] = array(
                "grade" => $ext[$txt]
            );
        }
        $ans[$txt] = array(
            'users' => $users,
            'matches' => $match,
            'decided' => (isset($ext[$txt]) ? $ext[$txt] : null),
            'correct-in-key' => $correct_in_key,
        );
    }
    return $ans;
}

function null_first($a,$b) {
    if ($a === null) return $b === null ? 0 : -1;
    return $b === null ? 1 : 0;
}

function comments_sort($a,$b) {
    if (isset($a['regrade']) && !isset($b['regrade'])) {
        return -1;
    } else if (!isset($a['regrade']) && isset($b['regrade'])) {
        return 1;
    }
    $a_graded = isset($a['grade']) || isset($a['feedback']);
    $b_graded = isset($b['grade']) || isset($b['feedback']);
    if ($a_graded && !$b_graded) {
        return 1;
    } else if (!$a_graded && $b_graded) {
        return -1;
    }
    if (!isset($a['pregrade']) && isset($b['pregrade'])) {
        return -1;
    } else if (isset($a['pregrade']) && !isset($b['pregrade'])) {
        return 1;
    }
    if (isset($a['pregrade']) && isset($b['pregrade'])) {
        if ($a['pregrade'] == $b['pregrade']) {
            return 0;
        } else if ($a['pregrade'] < $b['pregrade']) {
            return -1;
        } else {
            return 1;
        }
    } else {
        return 0;
    }
}

/**
 * Given a quiz and one of its questions, return an array.
 * keys are users
 * values are either null (if not reviewed) or an array with keys
 *  "feedback":"TA entered text" or "" (if no feedback text)
 *  "grade":number (new grade) or null (no change)
 */
function get_comments($quizid, $slug) {
    
    $rev = get_review($quizid);
    if (!isset($rev[$slug])) return array();
    $ans = array();
    foreach($rev[$slug] as $k => $v) {
        $ans[$k] = $v;
    }
    if (file_exists("log/$quizid/adjustments_$slug.csv")) {
        $fh = fopen("log/$quizid/adjustments_$slug.csv", "r");
        while (($row = fgetcsv($fh)) !== FALSE) {
            $ans[$row[0]] = array(
                "grade" => is_numeric($row[1]) ? floatval($row[1]) : null,
                "feedback" => $row[2],
            );
        }
    }
    foreach($ans as $k=>$v) {
        if (isset($rev["$slug-regrade"][$k])) {
            $ans[$k]["regrade"] = true;
            if (isset($ans[$k]["feedback"])) {
                $ans[$k]["old-feedback"] = $ans[$k]["feedback"];
                $ans[$k]["old-grade"] = $ans[$k]["grade"];
            }
            unset($ans[$k]["feedback"]);
            unset($ans[$k]["grade"]);
        }
    }
    uasort($ans, 'comments_sort');
    return $ans;
}


function show_blanks($quizid, $q, $mq) {
    $slug = $q['slug'];
    if ($mq['text']) echo "<div class='multiquestion'>$mq[text]";
    showQuestion($q, $quizid, '', 'none', false, $mq['text'], array(''), true, true, false, true) ;
    if ($mq['text']) echo '</div>';
    echo '<button onclick="showCorrectInKey()">show</button> or ';
    echo '<button onclick="hideCorrectInKey()">hide</button> answers marked correct in key';
    $anum = 0;
    foreach(get_blanks($quizid, $q) as $opt => $details) {
        $anum += 1;
        echo "<div class='multiquestion";
        if ($details['correct-in-key']) echo " correctinkey hidden ";
        if (isset($details['decided'])) echo " submitted";
        echo "' id='q-$anum'>Reply: <code style='font-size:150%; border: thin solid gray'>";
        echo htmlentities($opt)."</code> – ".count($details['users'])." replies";
        echo " <a href='quiz.php?qid=$quizid&asuser=".$details['users'][0]."&nosubmit=1'>example quiz</a>";
        $score = 0;
        foreach($details['matches'] as $key=>$weight) {
            echo "<br/>matches key($weight): <code style='font-size:150%; border: thin solid gray'>".htmlentities($key)."</code>";
            if ($weight > $score) $score = $weight;
        }
        $reply = "";
        if (isset($details['decided'])) {
            $score = $details['decided']['grade'];
            $reply = $details['decided']['reply'];
        }
        echo "<p>Portion (0 = no credit; 1 = full credit): <input type='text' id='a-$anum' value='$score' onchange='setKey(\"$anum\",".json_encode(str_replace("'","&apos;",$opt)).")' onkeydown='pending($\"$anum\")'/>";
        echo "<p>Reply: <input type='text' id='r-$anum' size=100 value=\"".htmlentities($reply)."\" onchange='setKey(\"$anum\",".json_encode($opt,JSON_HEX_QUOT|JSON_HEX_APOS).")' onkeydown='pending($\"$anum\")'/>";
        if (!isset($details['decided']))
            echo "<input type='button' onclick='setKey(\"$anum\",".json_encode(str_replace("'","&apos;",$opt)).")' id='delme-$anum' value='no reply needed'/>";
        echo "</p>";
        echo "</div>";
    }
}

function show_one_comment($qobj, $q, $mq, $user, $details, $hide_correct_in_key = FALSE) {
    $sobj = aparse($qobj, $user);
    grade($qobj, $sobj); // annotate with score
    
    echo "<div class='multiquestion";
    if (isset($details['grade']) || isset($details['feedback'])) echo " submitted";
    if (isset($details['regrade']) && $details['regrade']) {
        echo " regrade";
    } else if (isset($details['pregrade']) && round($details['pregrade'], 6) == 1) {
        echo " correctinkey";
        if ($hide_correct_in_key) {
            echo " hidden";
        }
    }
    echo "' id='q-$user'>$mq[text]";
    $quizid = $qobj['quizid'];
    showQuestion($q, $quizid, $user, $user, $qobj['comments']
        ,$mq['text']
        ,isset($sobj[$q['slug']]) ? $sobj[$q['slug']]
            : array('answer'=>array(),'comments'=>'')
        ,true
        ,false
        ,true
        ,false
        ,false
        ,false
        ,true
        );
    $score = isset($sobj[$q['slug']]['score']) ? $sobj[$q['slug']]['score'] : 0;
    if ($q['points']) $score /= $q['points'];
    $rawscore = $score;
    $feedback = '';

    if (isset($q['show-context-slugs'])) {
        foreach ($q['show-context-slugs'] as $other_slug) {
            echo '<p>Answer to '.$other_slug.': '.htmlentities($sobj[$other_slug]['answer'][0]).'</p>';
        }
    }

    if (isset($details['regrade']) && $details['regrade']) {
        echo '<p>This is a regrade request.</p>';
        if (isset($details['old-feedback'])) {
            echo '<p>Old feedback: '.htmlentities($details['old-feedback']).'</p>';
            echo '<p>Old grade: '.htmlentities($details['old-grade']).'</p>';
        }
    }
        
    if (isset($details['grade'])) $score = $details['grade'];
    if (isset($details['feedback'])) $feedback = $details['feedback'];
    
    echo "<p>Ratio: <input type='text' id='a-$user' value='$score' onchange='setComment(\"$user\")' rawscore='$rawscore' onkeydown='pending(\"$user\")'/></p>";
    echo "<p><a href='quiz.php?qid=$quizid&asuser=$user&nosubmit=1'>see full quiz</a></p>";
    
    echo "<div class='tinput'><span>Feedback:</span><textarea id='r-$user' onchange='setComment(\"$user\")' onkeydown='pending(\"$user\")'";
    echo ">";
    echo htmlentities($feedback);
    echo "</textarea></div>";

    if (!isset($details['grade']) && !isset($details['feedback']))
        echo "<input type='button' onclick='setComment(\"$user\")' id='delme-$user' value='no reply needed'/>";

    echo '</div>';
}

function show_random_comment($quizid, $q, $mq, $only_ungraded=TRUE) {
    $qobj = qparse($quizid);
    $hist = histogram($qobj);
    $all_comments = get_comments($quizid, $q['slug']);
    $users = array_keys($all_comments);
    shuffle($users);

    $found_one = FALSE;
    $which_user = NULL;

    foreach ($users as $user) {
        $details = $all_comments[$user];
        if (!$only_ungraded || (!isset($details['grade']) && !isset($details['feedback']) && round($details['pregrade'], 6) != 1)) {
            show_one_comment($qobj, $q, $mq, $user, $details);
            $which_user = $user;
            $found_one = TRUE;
            break;
        }
    }
    ?><script>
        document.querySelectorAll('textarea').forEach(x => {
            x.style.height = 'auto';
            x.style.height = x.scrollHeight+'px';
        });
    </script><?php

    if (!$found_one) {
        echo("<p>No more ".($only_ungraded ? "to grade":"")." for this question.</p>");
    } else {
        echo("<p><input type='button' onclick='setComment(\"$which_user\"); location.href=location.href;' value='submit and next random'/>");
    }
}

function show_comments($quizid, $q, $mq) {
    $qobj = qparse($quizid);
    $hist = histogram($qobj);
    
    echo '<button onclick="showCorrectInKey()">show</button> or ';
    echo '<button onclick="hideCorrectInKey()">hide</button> answers marked correct in non-comment grading';
    
    foreach(get_comments($quizid, $q['slug']) as $user=>$details) {
        show_one_comment($qobj, $q, $mq, $user, $details, TRUE);
    }
    ?><script>
        document.querySelectorAll('textarea').forEach(x => {
            x.style.height = 'auto';
            x.style.height = x.scrollHeight+'px';
        });
    </script><?php
}



?>
<html>
    <head>
    <title>Grade <?=$metadata['quizname']?> <?=isset($_GET['qid']) ? $_GET['qid'] : ''?></title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="stylesheet" href="style.css">
    <script type="text/javascript" src="katex/katex.min.js"></script>
    <link rel="stylesheet" href="katex/katex.min.css">
    <script type="text/javascript">
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll('span.mymath').forEach(x => 
                katex.render(x.textContent, x, 
                    {throwOnError:false, displayMode:false})
            )
            document.querySelectorAll('div.mymath').forEach(x => 
                katex.render(x.textContent, x, 
                    {throwOnError:false, displayMode:true})
            )
        });
    </script>



<script type="text/javascript">//<!--
var quizid = <?=json_encode(isset($_GET['qid']) ? $_GET['qid'] : null)?>;
var slug = <?=json_encode(isset($_GET['slug']) ? $_GET['slug'] : null)?>;

function pending(num) {
    if (document.getElementById('q-'+num).className != "multiquestion submitting")
        document.getElementById('q-'+num).className = "multiquestion submitting";
}


function setKey(id, val) {
    document.getElementById('q-'+id).className = 'multiquestion submitting';
    let v = Number(document.getElementById('a-'+id).value);
    if (isNaN(v) || v<0 || v>1) {
        document.getElementById('q-'+id).className = 'multiquestion disconnected';
        return
    }
    let r = document.getElementById('r-'+id).value;
    let datum = {
        'kind':'key',
        'quiz':quizid,
        'slug':slug,
        'key':val,
        'val':{'grade':v,'reply':r},
    }
    console.log(datum);
    ajaxSend(datum, id);

    let tmp = document.getElementById('delme-'+id)
    if (tmp) tmp.remove();
}

function setComment(id) {
    document.getElementById('q-'+id).className = 'multiquestion submitting';
    let v = document.getElementById('a-'+id).value;
    if (v != '') {
        v = Number(v)
        if (isNaN(v) || v<0 || v>1) {
            document.getElementById('q-'+id).className = 'multiquestion disconnected';
            return
        }
    }
    if (v == Number(document.getElementById('a-'+id).getAttribute('rawscore')))
        v = '';
    let r = document.getElementById('r-'+id).value;
    let datum = {
        'kind':'reply',
        'quiz':quizid,
        'slug':slug,
        'user':id,
        'score':v,
        'reply':r,
    }
    console.log(datum);
    ajaxSend(datum, id);

    let tmp = document.getElementById('delme-'+id)
    if (tmp) tmp.remove();
}

function ajaxSend(data, id) {
	var xhr = new XMLHttpRequest();
	if (!("withCredentials" in xhr)) {
		return null;
	}
	xhr.open("POST", "grader_listener.php", true);
	xhr.withCredentials = true;
    xhr.setRequestHeader("Content-type", 'application/json');
	xhr.onerror = function() {
		console.log("auto-check for new data broken");
	}
    xhr.onreadystatechange = function() { 
        if(xhr.readyState == 4) {
            console.log("done", xhr);
            if (xhr.status == 200) {
                document.getElementById('q-'+id).className = "multiquestion submitted";
                console.log("response: " + xhr.responseText);
            }
        }
    }
	xhr.send(JSON.stringify(data));
}

function hideCorrectInKey() {
    Array.from(document.getElementsByClassName('correctinkey')).forEach(x => x.classList.add('hidden'));
}
function showCorrectInKey() {
    Array.from(document.getElementsByClassName('correctinkey')).forEach(x => x.classList.remove('hidden'));
    document.querySelectorAll('textarea').forEach(x => {
        x.style.height = 'auto';
        x.style.height = x.scrollHeight+'px';
    });
}
//--></script>
    </head>
<body><?php 


if (isset($_GET['qid']) && !isset(($qobj = qparse($_GET['qid']))['error'])) {
    $questions = array();
    $mqs = array();
    foreach($qobj['q'] as $mq) foreach($mq['q'] as $q) {
        $q['grading_label'] = $q['slug'] . ' (Q'.$q['qindex'].')';
        $questions[$q['slug']] = $q;
        $mqs[$q['slug']] = $mq;
    }
    if (isset($_GET['slug']) && isset($questions[$_GET['slug']])) {
        if ($_GET['kind'] == 'blank') {
            show_blanks($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']]);
        } else if ($_GET['kind'] == 'blank-full') {
            show_blanks($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']], TRUE);
        } else if ($_GET['kind'] == 'comment') {
            show_comments($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']]);
        } else if ($_GET['kind'] == 'comment-random') {
            show_random_comment($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']]);
        } else if ($_GET['kind'] == 'rubric') {
            show_rubric($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']]);
        } else {
            echo "To do: show \"$_GET[kind]\" view for $qobj[slug] question $_GET[slug]\n";
        }
    } else {
        $rev = get_review($qobj['slug']);
        ?><table><thead>
            <tr><th>Kind</th><th>Hash</th><th>Done</th><th>Text</th></tr>
        </thead><tbody>
        <?php
        /*
        $qnum = 0;
        foreach($questions as $num=>$q) {
            $qnum += 1;
            if (isset($rev["$q[slug]-answers"])) {
                echo "<tr><td>Question $qnum blank</td></tr>";
            }
            if (isset($rev["$q[slug]"])) {
                echo "<tr><td>Question $qnum comments</td></tr>";
            }
        }
        */

echo "<script>console.log(".json_encode(array_keys($rev)).")</script>";
        foreach($rev as $slug=>$val) if (substr($slug,8) == '-pending') {
            $slug = substr($slug,0,8);
            $done = count($rev["$slug-graded"]);
            $left = count($val);
            $of = $done + $left;
            echo "<tr><td>rubric</td><td><a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=rubric'>$questions[$slug][grading_label]</a></td><td";
            if ($left == 0) echo ' class="submitted"';
            echo ">$done of $of";
            echo "</td><td>".$questions[$slug]['text']."</td></tr>\n";
        }
        foreach($rev as $slug=>$val) if (substr($slug,8) == '-answers') {
            $slug = substr($slug,0,8);
            echo "<tr><td>blank</td><td><a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=blank'>".$questions[$slug]['grading_label']."</a></td><td";
            $total = count($val);
            $sheet = get_blanks($qobj['slug'], $questions[$slug]);
            $left = 0;
            $correct_in_key = 0;
            foreach($sheet as $obj) {
                if ($obj['correct-in-key']) $correct_in_key += 1;
                else if (!isset($obj['decided'])) $left += 1;
            }
            $of = $total - $correct_in_key;
            if ($left == 0) echo ' class="submitted"';
            echo ">".($of-$left)." of $of (+$correct_in_key)";
            echo "</td><td>".$questions[$slug]['text']."</td></tr>\n";
        }
        foreach($rev as $slug=>$val) if (substr($slug,8) == '-regrade') {
            $num = count($val);
            if (!$num) continue;
            $slug = substr($slug,0,8);
            echo "<tr><td>regrade</td><td><a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=comment'>".$questions[$slug]['grading_label']."</a></td><td>0 of $num</td><td>".$questions[$slug]['text']."</td></tr>\n";
        }
        foreach($rev as $slug=>$val) if (strlen($slug) == 8) {
            echo "<tr><td>comment</td><td>".$questions[$slug]['grading_label'].": <a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=comment'>all</a>";
            echo " or <a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=comment-random'>random one at a time</a>";
            echo "</td><td";
            $total = count($val);

            $sheet = get_comments($qobj['slug'], $slug);
            $left = 0;
            $correct_in_key = 0;
            foreach($sheet as $uid=>$obj) {
                if (isset($obj['pregrade']) && round($obj['pregrade'], 6) == 1) {
                    $correct_in_key += 1;
                } else if (!isset($obj['feedback'])) {
                    $left += 1;
                }
            }
            $of = $total - $correct_in_key;
            if ($left == 0) echo ' class="submitted"';
            echo ">".($of-$left)." of $of (+$correct_in_key)";
            echo "</td><td>".$questions[$slug]['text']."</td></tr>\n";
        }
        ?></tbody></table><?php
    }
} else {
    foreach(glob('questions/*.md') as $i=>$name) {
        $name = basename($name,".md");
        $qobj = qparse($name);
        if ($qobj['due'] >= time()) continue;
        echo "<br/><a href='grader.php?qid=$name'>$name: $qobj[title]</a>";
    }
}


?></body></html>
