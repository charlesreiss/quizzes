<?php

require_once "tools.php";
 if (!$isstaff) {
    http_response_code(403);
    die('staff use only');
}

$_review = array();
function get_review($quizid) {
    if (isset($_review[$quizid])) return $_review[$quizid];
    if (file_exists("log/$quizid/review.json")) {
        $ans = json_decode(file_get_contents("log/$quizid/review.json"), true);
    } else {
        $ans = array();
        histogram($quizid, $ans);
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
 *  "key_score": (null or number)
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
        $key_score = NULL;
        foreach($q['key'] as $key) {
            $k = $key['text'];
            if(($k[0] == '/') ? preg_match($k, $txt) : $k == $txt) {
                $match[$k] = $key['points'];
                if ($key_score === NULL || $key_score < $key['points']) {
                    $key_score = $key['points'];
                }
            }
        }
        $ans[$txt] = array(
            'users' => $users,
            'matches' => $match,
            'decided' => (isset($ext[$txt]) ? $ext[$txt] : null),
            'key_score' => $key_score,
        );
    }
    return $ans;
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
    $whom = $rev[$slug];
    $ans = array();
    foreach($whom as $k) $ans[$k] = null;
    if (isset($rev["$slug-correct"])) {
        $whom_perfect = $rev["$slug-correct"];
        foreach($whom_perfect as $k) $ans[$k] = array("grade" => 1);
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
    return $ans;
}

function show_blanks($quizid, $q, $mq) {
    $slug = $q['slug'];
    if ($mq['text']) echo "<div class='multiquestion'>$mq[text]";
    showQuestion($q, $quizid, '', 'none', false, $mq['text'], array(''), true, true, false, true);
    if ($mq['text']) echo '</div>';
    $anum = 0;
    foreach(get_blanks($quizid, $q) as $opt => $details) {
        $anum += 1;
        echo "<div class='multiquestion";
        if (isset($details['decided'])) echo " submitted";
        echo "' id='q-$anum'>Reply: <code style='font-size:150%; border: thin solid gray'>";
        echo htmlentities($opt)."</code> – ".count($details['users'])." replies";
        $score = 0;
        foreach($details['matches'] as $key=>$weight) {
            echo "<br/>matches key($weight): <code style='font-size:150%; border: thin solid gray'>".htmlentities($key)."</code>";
            if ($weight > $score) $score = $weight;
        }
        $reply = "";
        if (isset($details['decided']) && is_array($details['decided'])) {
            $score = $details['decided']['grade'];
            $reply = $details['decided']['reply'];
        }
        else if (isset($details['decided'])) $score = $details['decided'];
        echo "<p>Portion (0 = no credit; 1 = full credit): <input type='text' id='a-$anum' value='$score' onchange='setKey(\"$anum\",".json_encode($opt,JSON_HEX_QUOT|JSON_HEX_APOS).")' onkeydown='pending($\"$anum\")'/>";
        echo "<p>Reply: <input type='text' id='r-$anum' size=100 value='$reply' onchange='setKey(\"$anum\",".json_encode($opt,JSON_HEX_QUOT|JSON_HEX_APOS).")' onkeydown='pending($\"$anum\")'/>";
        if (!isset($details['decided']))
            echo "<input type='button' onclick='setKey(\"$anum\",".json_encode($opt,JSON_HEX_QUOT|JSON_HEX_APOS).")' id='delme-$anum' value='no reply needed'/>";
        echo "</p>";
        echo "</div>";
    }
}

function show_one_comment($quizid, $q, $mq, $qobj, $user, $details) {
    global $hist;
    $sobj = aparse($qobj, $user);
    grade($qobj, $sobj); // annotate with score
    
    echo "<div class='multiquestion";
    if (isset($details)) echo " submitted";
    echo "' id='q-$user'>$mq[text]";
    showQuestion($q, $quizid, $user, $user, $qobj['comments']
        ,$mq['text']
        ,isset($sobj[$q['slug']]) ? $sobj[$q['slug']]
            : array('answer'=>array(),'comments'=>'')
        ,true
        ,$hist
        ,true
        ,false
        );
    $score = isset($sobj[$q['slug']]['score']) ? $sobj[$q['slug']]['score'] : 0;
    if ($q['points']) $score /= $q['points'];
    $rawscore = $score;
    $feedback = '';
    if (isset($details['grade'])) $score = $details['grade'];
    if (isset($details['feedback'])) $feedback = $details['feedback'];
   
    echo "<p><a href='quiz.php?asuser=$user&nosubmit=1&qid=$quizid'>full quiz for user</a></p>";
     
    echo "<p>Ratio: <input type='text' id='a-$user' value='$score' onchange='setComment(\"$user\")' rawscore='$rawscore' onkeydown='pending(\"$user\")'/></p>";
    
    echo "<div class='tinput'><span>Feedback:</span><textarea id='r-$user' onchange='setComment(\"$user\")' onkeydown='pending(\"$user\")'";
    echo ">";
    echo htmlentities($feedback);
    echo "</textarea></div>";

    if (!isset($details))
        echo "<input type='button' onclick='pending(\"$user\"); setComment(\"$user\")' id='delme-$user' value='no reply needed'/>";

    echo '</div>';
}

function show_random_comment($quizid, $q, $mq, $only_ungraded=TRUE) {
    global $hist;
    $qobj = qparse($quizid);
    $hist = histogram($qobj);

    $all_comments = get_comments($quizid, $q['slug']);
    $users = array_keys($all_comments);
    shuffle($users);

    $found_one = false;
    $which_user = NULL;
    
    foreach($users as $user) {
        $details = $all_comments[$user];
        if (!isset($details['feedback']) && !isset($details['grade'])) {
            show_one_comment($quizid, $q, $mq, $qobj, $user, $details);
            $found_one = true;
            $which_user = $user;
            break;
        }
    }

    if (!$found_one) {
        echo("<p>No more to grade for this question.</p>");
    } else {
        echo("<p><input type='button' onclick='auto_reload = true;setComment(\"$which_user\");' value='submit and next'><input type='button' onclick='location.href = location.href;' value='another random'></p>");
    }

    ?><script>
        document.querySelectorAll('textarea').forEach(x => {
            x.style.height = 'auto';
            x.style.height = x.scrollHeight+'px';
        });
    </script><?php
}

function show_comments($quizid, $q, $mq) {
    global $hist;
    $qobj = qparse($quizid);
    $hist = histogram($qobj);
    
    foreach(get_comments($quizid, $q['slug']) as $user=>$details) {
        show_one_comment($quizid, $q, $mq, $qobj, $user, $details);
    }
    ?><script>
        document.querySelectorAll('textarea').forEach(x => {
            x.style.height = 'auto';
            x.style.height = x.scrollHeight+'px';
        });
    </script><?php
}


?><!DOCTYPE html><html>
    <head>
    <title>Grade <?=$metadata['quizname']?> <?=isset($_GET['qid']) ? $_GET['qid'] : ''?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="katex/katex.min.css">



<script type="text/javascript">//<!--
var quizid = <?=json_encode(isset($_GET['qid']) ? $_GET['qid'] : null)?>;
var slug = <?=json_encode(isset($_GET['slug']) ? $_GET['slug'] : null)?>;

var auto_reload = false;

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
        'val':v,
        'reply':r,
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
                if (auto_reload) {
                    location.href = location.href;
                }
            }
        }
    }
	xhr.send(JSON.stringify(data));
}
//--></script>
    </head>
<body><?php 


if (isset($_GET['qid']) && !isset(($qobj = qparse($_GET['qid']))['error'])) {
    $questions = array();
    $mqs = array();
    foreach($qobj['q'] as $mq) foreach($mq['q'] as $q) {
        $questions[$q['slug']] = $q;
        $mqs[$q['slug']] = $mq;
    }
    if (isset($_GET['slug']) && isset($questions[$_GET['slug']])) {
        if ($_GET['kind'] == 'blank') {
            show_blanks($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']]);
        } else if ($_GET['kind'] == 'comment') {
            show_comments($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']]);
        } else if ($_GET['kind'] == 'comment-random') {
            show_random_comment($_GET['qid'], $questions[$_GET['slug']], $mqs[$_GET['slug']]);
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
        foreach($rev as $slug=>$val) if (substr($slug,8) == '-answers') {
            $slug = substr($slug,0,8);
            echo "<tr><td>blank</td><td><a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=blank'>$slug</a></td><td";
            $sheet = get_blanks($qobj['slug'], $questions[$slug]);
            $of = count($sheet);
            $left = 0;
            foreach($sheet as $obj)
                if (!isset($obj['decided']) && $obj['key_score'] != 1) $left += 1;
            if ($left == 0) echo ' class="submitted"';
            echo ">".($of-$left)." of $of";
            echo "</td><td>".$questions[$slug]['text']."</td></tr>\n";
        }
        foreach($rev as $slug=>$val) if (strlen($slug) == 8) {
            echo "<tr><td>comment</td><td><a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=comment'>$slug</a> &mdash; <a href='?qid=$_GET[qid]&amp;slug=$slug&amp;kind=comment-random'>one-at-a-time w/o feedback</a></td><td";

            $sheet = get_comments($qobj['slug'], $slug);
            $of = count($sheet);
            $left = 0;
            foreach($sheet as $uid=>$obj)
                if (!is_array($obj)) $left += 1;

            if ($left == 0) echo ' class="submitted"';
            echo ">".($of-$left)." of $of";
            echo "</td><td>".$questions[$slug]['text']."</td></tr>\n";
            //$done = isset($rev["$slug-done"]) ? count($rev["$slug-done"]) : 0;
            //if ($of <= $done) echo ' class="submitted"';
            //echo '>';
            //echo "${done} of $of";
            //echo "</td><td>".$questions[$slug]['text']."</td></tr>\n";
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
