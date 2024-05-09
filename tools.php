<?php

require_once "Michelf/MarkdownInterface.php";
require_once "Michelf/Markdown.php";
require_once "Michelf/MarkdownExtra.php";
use Michelf\MarkdownExtra as MarkdownExtra;

require_once "authenticate.php";
define("JSON_PRETTY", JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// polyfill
if( !function_exists('array_key_last') ) {
    function array_key_last(array $array) {
        if( !empty($array) ) return key(array_slice($array, -1, 1, true));
    }
}

$_fullnames = NULL;
$_usernames = NULL;
function pretty_name_of($user) {
    global $_usernames, $_fullnames;
    if ($_fullnames == NULL) {
        if (file_exists('log/names.json'))
            $_usernames = json_decode(file_get_contents('log/names.json'), true);
        else $_usernames = array();
        if (file_exists('fullnames.json'))
            $_fullnames = json_decode(file_get_contents('fullnames.json'), true);
        else $_fullnames = array();
    }
    if (isset($_usernames[$user])) return $_usernames[$user];
    if (isset($_fullnames[$user])) return $_fullnames[$user];
    return $user;
}

function debug_dump(...$args) {
    global $realisstaff;
    if (!$realisstaff) return;
    $bt = debug_backtrace();
    $caller = array_shift($bt);
    echo "<script>\n";
    echo "console.log(";
    echo json_encode(basename($caller['file'])).",".json_encode($caller['line']);
    foreach($args as $arg) {
        echo "\n,".json_encode($arg);
    }
    echo "\n)</script>";
}

function now_timestamp() {
    return date('c');
}


function beginsWith($haystack, $needle) {
    return 0 === strncasecmp($haystack, $needle, strlen($needle));
}

function file_put_contents_recursive($filename, $contents, $flags=0) {
    if (!is_dir(dirname($filename))) {
        umask(0);
        mkdir(dirname($filename), 0777, true);
    }
    file_put_contents($filename, $contents, $flags);
    chmod($filename, 0666);
}

function session_file($username, $quiz_id) {
    if (preg_match("/^[a-zA-Z0-9]+$/",  $username, $matches)) {
        $filtered_username = $matches[0];
    } else {
        $filtered_username = "UNKNOWN";
    }
    if (preg_match("/^[-_a-zA-Z0-9]+$/",  $quiz_id, $matches)) {
        $filtered_quiz_id = $matches[0];
    } else {
        $filtered_quiz_id = "UNKNOWN";
    }
    return "sessions/$filtered_quiz_id/$filtered_username";
}

function make_or_get_session($username, $quiz_id) {
    $filename = session_file($username, $quiz_id);
    if (file_exists($filename)) {
        return file_get_contents($filename);
    } else {
        $session_id = bin2hex(random_bytes(32));
        file_put_contents_recursive($filename, $session_id);
        if (!file_exists($filename))
            echo('FAILED TO CREATE');
        return $session_id;
    }
}

function check_session($username, $quiz_id, $session_id) {
    $filename = session_file($username, $quiz_id);
    if (file_exists($filename)) {
        return file_get_contents($filename) == $session_id;
    } else {
        return FALSE;
    }
}

function listener_url() {
    global $metadata;
    if (array_key_exists('quiz-listener', $metadata)) {
        return $metadata['quiz-listener'];
    } else {
        return 'quiz_listener.php';
    }
}

function katexify($txt, $display=false) {
    $cache_file = "cache/katex/".($display ? 'display' : 'inline')."/".sha1($txt).'.html';
    if (file_exists($cache_file))
        return file_get_contents($cache_file);
    $dsc = array(
        array('pipe','r'),
        array('pipe','w'),
        array('pipe','w'),
    );
    $proc = proc_open('npx katex --no-throw-on-error' . ($display ? ' --display-mode' : ''), $dsc, $pipes);
    if (is_resource($proc)) {
        fwrite($pipes[0], $txt);
        fclose($pipes[0]);
        $ans = trim(stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($proc);
        file_put_contents_recursive($cache_file, $ans);
        return $ans;
    } else {
        file_put_contents_recursive($cache_file, trim($txt));
        return trim($txt);
    }
}

function katexify_inline($txt) {
    global $metadata;
    if ($metadata['server-side KaTeX'])
        return katexify(html_entity_decode('{\\displaystyle{'.$txt[1].'}}'), false);
    else
        return '<span class="mymath">`{\\displaystyle{'.$txt[1].'}}`</span>';
        // note: backticks prevent \{ turning into {, but also mean ` cannot appear
}
function katexify_inline2($txt) {
    global $metadata;
    if ($metadata['server-side KaTeX'])
        return katexify(html_entity_decode('{\\displaystyle{'.$txt[1].'}}'), false);
    else
        return '<span class="mymath">{\\displaystyle{'.$txt[1].'}}</span>';
}
function katexify_display($txt) {
    global $metadata;
    if ($metadata['server-side KaTeX'])
        return katexify(html_entity_decode($txt[1]), true);
    else
        return '<div class="mymath">'.$txt[1].'</div>';
}

/// performs Markdown -> HTML and KaTeX transformations
/// does $math$ and $$math$$ first, then markdown, then \\(math\\) and \\[math\\]
/// time consuming because of the katex calls, but only if there is math in the page (otherwise quite fast)
function toHTML($md) {
    global $metadata;
    if (!$metadata['disable-katex']) {
        $md = preg_replace_callback('/\$\$(.*?)\$\$/s', 'katexify_display', $md);
        $md = preg_replace_callback('/\$([^\n]*?)\$/s', 'katexify_inline', $md);
    }
    $html = MarkdownExtra::defaultTransform($md);
    if (!$metadata['disable-katex']) {
        if (!$metadata['server-side KaTeX']) {
            $html = preg_replace('/(<span class="mymath">)<code>(.*?)<\/code>(<\/span>)/s', '$1$2$3', $html);
            $html = preg_replace('/(<div class="mymath">)<code>(.*?)<\/code>(<\/div>)/s', '$1$2$3', $html);
        }
        $html = preg_replace_callback('/\&#92;\[(.*?)\&#92;\]/s', 'katexify_display', $html);
        $html = preg_replace_callback('/\&#92;\((.*?)\&#92;\)/s', 'katexify_inline2', $html);
    }
    return $html;
}
function toInlineHTML($md) {
    // fixme: use a markdown library that has an API for inline html
    $tmp = trim(toHTML($md));
    return substr($tmp, 3, strlen($tmp)-7);
}


$_qparse = array();
/**
 * Get all details about one particular quiz (updating the disk cache if needed)
 * 
 * Returns an array containing just the key 'error' if it cannot parse "questions/$qid.md"
 */
function qparse($qid,$abspath=FALSE) {
    global $_qparse, $metadata;
    if (is_array($qid)) return $qid;
    if (isset($_qparse[$qid])) return $_qparse[$qid];
    //error_log("!!! $qid [".($abspath?"yes":"no")."]");
    if ($abspath && file_exists($qid)) {
        $filename = $qid;
        // $qid = basename($qid, ".md");
        $cache = "cache/".str_replace("/","-",$qid).".json";
        //$cache = $filename.".cache";
    } else {
        if (stristr($qid, "/")) return $_qparse[$qid] = array('error'=>"no quiz named $qid");
        $filename = "questions/$qid.md";
        $cache = "cache/$qid.json";
    }
    if (!file_exists($filename)) return $_qparse[$qid] = array('error'=>"no quiz named $qid");
    $updated = filemtime($filename);
    if (file_exists($cache) && filemtime($cache) > $updated)
        return $_qparse[$qid] = json_decode(file_get_contents($cache),true);
    do {
        $updated = filemtime($filename);
        
        $ans = isset($metadata['defaults']) ? $metadata['defaults'] : array();
        $ans += array( // defaults
            "quizid" => $qid,
            "title"=>"$metadata[quizname] $qid",
            "seconds"=>0,
            "open"=>strtotime("2999-12-31 12:00"),
            "due"=>strtotime("2999-12-31 12:00"),
            "directions"=>"",
            "comments"=>true,
            "keyless"=>false,
            "order"=>"shuffle",
            "hide"=>false,
            "unhide"=>array(),
            "draft"=>false,
            "regrades"=>true,
            "imgneeded"=>false,
        );
        
        $fh = fopen($filename, 'rb');
        if (!$fh) { return  $_qparse[$qid] = array('error'=>"cannot read $filename"); }
        
        // read headers
        for(;;) {
            $line = trim(fgets($fh));
            if ($line === '') break;
            $kv = explode(":", $line, 2);
            if (count($kv) != 2) return $_qparse[$qid] = array('error'=>"Malformed header line \"$line\"");
            $k = trim($kv[0]);
            $v = trim($kv[1]);
            if ($k == 'open' || $k == 'due') $v = strtotime($v);
            if ($k == 'hours') { $k = 'seconds'; $v = intval(round(floatval($v)*60*60)); }
            if ($k == 'minutes') { $k = 'seconds'; $v = intval(round(floatval($v)*60)); }
            if ($k == 'title') $v = toInlineHTML($v);
            if ($k == 'unhide') $v = preg_split("/[\s,;]+/", $v, -1, PREG_SPLIT_NO_EMPTY);
            if ($v === "true") $v = true;
            if ($v === "false") $v = false;
            $ans[$k] = $v;
        }
        if (!isset($ans['qorder'])) $ans['qorder'] = $ans['order'];
        
        // parse questions into array of groups, each with questions inside
        $opt = False; $options = False; $q = False; $mq = False;
        $keys = array(); $options = array();
        $all = array();
        
        $line = TRUE;
        while($line !== FALSE) {
            $line = fgets($fh);
            // line is one of {header (3 kinds), MC option, key, text}
            $is_mq = ($line === FALSE) || beginsWith($line, 'Multiquestion');
            $is_key = beginsWith($line, 'key: ') || preg_match('/^key\([0-9.]*\): /', $line);
            $is_option = preg_match('/^\*?[a-zA-Z]\./', $line);
            $is_q = beginsWith($line, 'Question');
            $is_sq = beginsWith($line, 'Subquestion');
            $is_qexp = beginsWith($line, 'ex: ') || trim($line) == 'ex:';
            $is_oexp = beginsWith($line, 'ex. ') || trim($line) == 'ex.';
            $is_rubric = beginsWith($line, 'rubric:');
            $is_header = $is_mq || $is_q || $is_sq;
            $is_text = !$is_header && !$is_key && !$is_option && !$is_oexp && !$is_qexp && !$is_rubric;

            if ($is_key) { 
                // key: text
                // key: /[Rr]eg(ular)?[Ee]x(p?ression)?/
                // key(0.8): partial credit
                // one space after ":" mandatory; extra space ignored
                // to do: add key parsing for "mmc keyed" questions
                if (beginsWith($line, 'key: ')) {
                    $keys[] = array('text'=>trim(substr($line, 4)), 'points'=>1);
                } else {
                    $kv = explode('):', $line, 2);
                    $keys[] = array('text'=>trim($kv[1]), 'points'=>floatval(substr($kv[0],4)));
                }
                continue; 
            }
            if ($is_oexp) {
                if ($opt !== FALSE) {
                    if (isset($opt['explain'])) {
                        $opt['explain'] = $opt['explain'] . "\n" . substr($line,4);
                    } else {
                        $opt['explain'] = substr($line,4);
                    }
                    $opt['explain'] = substr($line,4);
                    continue;
                } else { $is_text = true; }
            }
            if ($is_qexp) {
                if ($q !== FALSE) {
                    if (isset($q['explain'])) {
                        $q['explain'] = $q['explain'] . "\n" . substr($line,4);
                    } else {
                        $q['explain'] = substr($line,4);
                    }
                    continue;
                } else { $is_text = true; }
            }
            if ($is_rubric) {
                if ($q !== FALSE) {
                    $q['rubric'] = array(trim(substr($line,7)));
                    continue;
                } else { $is_text = true; }
            }
            if ($is_text) {
                if (isset($opt['explain'])) $opt['explain'] .= $line;
                else if (isset($q['explain'])) $q['explain'] .= $line;
                else if ($opt !== FALSE) {
                    $opt['text'] .= $line;
                } else if (isset($q['rubric'])) {
                    $q['rubic'][0] .= $line;
                } else if ($q !== FALSE) {
                    $q['text'] .= $line;
                } else if ($mq !== FALSE) {
                    $mq['text'] .= $line;
                } else {
                    $ans['directions'] .= $line;
                }
                continue;
            }
            if ($opt !== FALSE) {
                if (!isset($q['rubric']))
                    $opt['pin'] = stripos($opt['text'], 'of the above') !== FALSE;
                $opt['text'] = toHTML($opt['text']);
                if (isset($opt['explain']))
                    $opt['explain'] = toHTML($opt['explain']);
                if (isset($q['rubric'])) {
                    if (is_string($q['rubric'][0])) {
                        if (strlen(trim($q['rubric'][0])) > 0)
                            $q['rubric'][0] = array(
                                'text'=>toHTML($q['rubric'][0]),
                                'hide'=>false,
                                'points'=>1,
                            );
                        else array_pop($q['rubric']);
                    }
                    $q['rubric'][] = $opt;
                } else $options[] = $opt;
                $opt = FALSE;
            }
            if ($is_option) { // parse multiple-choice option text
                $bit = explode(' ',$line,2);
                $opt = array('text'=>$bit[1]);
                $bit = $bit[0];
                $opt['hide'] = ($bit[0] == 'x' || $bit[1] == 'x');
                if (isset($q['rubric'])) {
                    // a. weight 1 
                    // h. weight 0.5
                    // w.85 weight 0.85
                    // x. X. weight 0
                    // * meaningless
                    if ($bit[0] == 'h') $opt['points'] = 0.5;
                    else if ($bit[0] == 'w') $opt['points'] = floatval('0'.substr($bit,1));
                    else if ($bit[0] == 'x' || $bit[0] == 'X') $opt['points'] = 0;
                    else $opt['points'] = 1;
                } else if ($q['type'] == 'radio') {
                    // *a. this gets full points
                    // h. this gets half points
                    // w.85 this gets 0.85 points
                    // x. *x. X. *X. this gets full points (should the question be dropped instead?)
                    if ($bit[0] == 'h') $opt['points'] = 0.5;
                    else if ($bit[0] == 'w') $opt['points'] = floatval('0'.substr($bit,1));
                    else if ($bit[0] == '*') $opt['points'] = 1.0;
                    else if ($bit[0] == 'x' || $bit[0] == 'X') {$opt['points'] = 1; $opt['radio-drop'] = 1;} // fixme?
                    else $opt['points'] = 0;
                } else { // checkbox
                    // *a. select this, full weight
                    // a. don't select this, full weight
                    // (*)h. (do) select this, half weight
                    // (*)w.85 (do) select this, 0.85 weight
                    // x. *x. X. *X. 0 weight
                    $sign = -1;
                    if ($bit[0] == '*') { $sign = 1; $bit = substr($bit,1); }
                    if ($bit[0] == 'h') $opt['points'] = $sign * 0.5;
                    else if ($bit[0] == 'x' || $bit[0] == 'X') $opt['points'] = 0;
                    else if ($bit[0] == 'Y') { $opt['points'] = 1; $opt['any-answer'] = 1; $opt['original-sign'] = $sign; }
                    else if ($bit[0] == 'w') $opt['points'] = $sign * floatval('0'.substr($bit,1));
                    else $opt['points'] = $sign;
                }
                continue;
            }
            if ($q !== FALSE) { // done with a question; error-check and possibly re-type
                if ($q['type'] == 'image') {
                    if (count($options) || count($keys))
                        return array('error'=>'contradictory data: image questions cannot have answer keys\n'.json_encode($options)."\n".json_encode($keys));
                } else if ($q['type'] == 'checkbox') {
                    if (count($options) == 0)
                        return array('error'=>'contradictory data: multiple-select questions must have options to pick from');
                    if (count($keys) != 0)
                        return array('error'=>'contradictory data: multiple-select questions must not have fill-in-the-blank keys');
                    $q['options'] = $options;
                    $options = array();
                } else if ($q['type'] == 'box') {
                    if (count($options) != 0)
                        return array('error'=>'contradictory data: text box questions cannot have multiple-choice options');
                    $q['key'] = $keys;
                    $keys = array();
                } else if ($q['type'] == 'radio') {
                    if (count($options) == 0) {
                        $q['type'] = 'text'; // replace placeholder type with real type
                        $q['key'] = $keys;
                        $keys = array();
                    } else if (count($keys) == 0) {
                        $q['options'] = $options;
                        $options = array();
                    } else {
                        return array('error'=>'contradictory data: cannot be both select-one and fill-in-the-blank');
                    }
                }
                $q['quizid'] = $qid;
                $q['text'] = toHTML($q['text']);
                if (isset($q['explain']))
                    $q['explain'] = toHTML($q['explain']);
                $mq['q'][] = $q;
                $q = FALSE;
            }
            if (!$is_sq) { // done with question group
                if ($mq !== FALSE) {
                    if (!isset($mq['text']) || strlen($mq['text']) == 0) $mq['text'] = False;
                    else $mq['text'] = toHTML($mq['text']);
                    $all[] = $mq; 
                }
                $mq = array('q'=>array(), 'text'=>'');
            }
            if (!$is_mq) { // new question
                $q = array(
                    'type'=> (stripos($line, 'mmc') ? 'checkbox' : 
                        (stripos($line, 'img') ? 'image' : 
                        (stripos($line, 'box') ? 'box' :'radio'))),
                    'text'=>'',
                    'pin'=> stripos($line, 'pin') !== FALSE,
                );
                if (stripos($line,'grade-in-comments')) {
                    $q['grade-in-comments'] = true;
                }
                if (stripos($line,'context-previous')) {
                    $q['context-previous'] = true;
                }
                if (stripos($line, 'box(')) {
                    $boxpos1 = stripos($line, 'box(')+4;
                    $boxpos2 = stripos($line, ')', $boxpos);
                    $q['boxrows'] = substr($line, $boxpos1, $boxpos2-$boxpos1);
                }
                // TO DO: add mmc keyed option
                $ptspos = stripos($line, ' points)');
                if ($ptspos > 0) {
                    $ptstart = strrpos($line, '(')+1;
                    $q['points'] = floatval(substr($line, $ptstart, $ptspos-$ptstart));
                } else {
                    $q['points'] = 1.0;
                }
            }
        }
        fclose($fh);
        
        if (trim($ans['directions']))
            $ans['directions'] = toHTML($ans['directions']);
        else $ans['directions'] = '';
        
        // post-process: re-weight points and assign slugs
        $qn = 0;
        $previous_slug = FALSE;
        foreach($all as &$mq) {
            foreach($mq['q'] as &$q) {
                $qn += 1;
                $q['slug'] = substr(sha1("questions/$qid.md $qn"), 32);
                if (isset($q['options'])) {
                    $an = 0;
                    foreach($q['options'] as &$opt) {
                        $an += 1;
                        $opt['slug'] = substr(sha1("questions/$qid.md $qn $an"), 32);
                    }
                }
                if ($q['type'] == 'checkbox') {
                    $pos = 0;
                    $neg = 0;
                    foreach($q['options'] as &$opt) {
                        if ($opt['points'] > 0) $pos += $opt['points'];
                        else $neg -= $opt['points'];
                    }
                    if (!$pos && !$neg) $q['points'] = 0;
                    else {
                        $scale = 1/($pos+$neg);
                        foreach($q['options'] as &$opt) $opt['points'] *= $scale;
                        $q['blank'] = $neg*$scale;
                    }
                } else {
                    $q['blank'] = 0;
                }
                if (isset($q['context-previous'])) {
                    $q['show-context-slugs'] = array($previous_slug);
                }
                $previous_slug = $q['slug'];
            }
        }
        
        
        $ans['q'] = $all;
        $ans['slug'] = $qid;
        
        // cache results
        if ($cache) {
            file_put_contents_recursive($cache, json_encode($ans, JSON_PRETTY));
            chmod($cache, 0666);
        }
    } while ($updated != filemtime($filename));

    if (isset($ans['external'])) $ans['due'] = $ans['open'];

    return $_qparse[$qid] = $ans;
}

$_aparse = array();
function aparse($qobj, $sid, $time_cutoff=FALSE) {
    global $_aparse;
    if (is_array($sid)) return $sid;
    if (is_string($qobj)) $qobj = qparse($qobj);
    if (isset($_aparse["$qobj[slug] $sid"])) return $_aparse["$qobj[slug] $sid"];

    if (isset($qobj['external'])) return csv_parse($qobj, $sid);

    $ans = array();
    
    // parse all submissions by students and graders
    if (file_exists("log/$qobj[slug]/$sid.log")) {
        $fh = fopen("log/$qobj[slug]/$sid.log", "rb");
        if ($fh) while(($jsdata = fgets($fh)) !== FALSE) {
            $obj = json_decode($jsdata, TRUE);
            if ($obj === null) continue;
            if (isset($obj['feedback'])) { // grader action
                $slug = $obj['slug'];
                if (isset($obj['grade']) && $obj['grade'] != '') $ans[$slug]['grade'] = $obj['grade'];
                if (isset($obj['rubric'])) $ans[$slug]['rubric'] = $obj['rubric'];
                if (isset($obj['date']) && !isset($ans['start']))
                    $ans['start'] = strtotime($obj['date']);
                if (isset($ans[$obj['slug']]['chat'])) {
                    $entry = array(
                        'from'=>'staff',
                        'text'=>$obj['feedback'],
                        'date'=>$obj['date'],
                    );
                    if (isset($obj['grade'])) {
                        $entry['grade'] = $obj['grade'];
                    }
                    if (isset($ans[$obj['slug']]['regrade'])) { // new
                        $ans[$obj['slug']]['chat'][] = $entry;
                        unset($ans[$obj['slug']]['regrade']);
                    } else { // update to existing
                        $ans[$obj['slug']]['chat'][count($ans[$obj['slug']]['chat'])-1] = $entry;
                    }
                } else {
                    if ($obj['feedback'] != "") {
                        $ans[$slug]['feedback'] = $obj['feedback'];
                    }
                }
            } else if (isset($obj['upload-to'])) { // upload action
                // invalidates previous grades
                $slug = $obj['upload-to'];
                if (isset($ans[$slug])) {
                    if (isset($ans[$slug]['grade'])) unset($ans[$slug]['grade']);
                    if (isset($ans[$slug]['feedback'])) unset($ans[$slug]['feedback']);
                    if (isset($ans[$slug]['rubric'])) unset($ans[$slug]['rubric']);
                    if (isset($ans[$slug]['regrade'])) unset($ans[$slug]['regrade']);
                    if (isset($ans[$slug]['chat'])) unset($ans[$slug]['chat']);
                }
                if (isset($obj['date']) && !isset($ans['start']))
                    $ans['start'] = strtotime($obj['date']);
            } else if (isset($obj['request'])) { // regrade request
                if (!isset($ans[$obj['slug']]['chat'])) {
                    $ans[$obj['slug']]['chat'] = array();
                    if (isset($ans[$obj['slug']]['feedback']) && $ans[$obj['slug']]['feedback']) {
                        $ans[$obj['slug']]['chat'][] = array(
                            'from'=>'staff',
                            'text'=>$ans[$obj['slug']]['feedback'],
                            'date'=>'original grader feedback',
                        );
                        $ans[$obj['slug']]['feedback'] = '';
                    }
                }
                $entry = array(
                    'from'=>'student',
                    'text'=>$obj['request'],
                    'date'=>$obj['date'],
                );
                if (isset($ans[$obj['slug']]['regrade'])) { // update to existing
                    $ans[$obj['slug']]['chat'][count($ans[$obj['slug']]['chat'])-1] = $entry;
                } else { // new
                    $ans[$obj['slug']]['chat'][] = $entry;
                    $ans[$obj['slug']]['regrade'] = true;
                }
            } else { // student action
                if ($time_cutoff !== FALSE) {
                    if (isset($obj['date']) && strtotime($obj['date']) > $time_cutoff) {
                        continue;
                    }
                }
                if (isset($obj['answer'])) { // student answer
                    $show = array('answer'=>$obj['answer']);
                    if (isset($obj['comments']))
                        $show['comments'] = $obj['comments'];
                    else
                        $show['comments'] = "";
                    $ans[$obj['slug']] = $show;
                    if (!isset($ans['start']))
                        $ans['start'] = strtotime($obj['date']);
                }
                if (isset($obj['date']) && !isset($ans['start']))
                    $ans['start'] = strtotime($obj['date']);
            }
        }
        $ans['started'] = isset($ans['start']);
        $ans['got file'] = "log/$qobj[slug]/$sid.log";
    } else {
        $ans['started'] = false;
        $ans['no file'] = "log/$qobj[slug]/$sid.log";
    }
    $ans['slug'] = $sid;
    
    // compute permissions and time remaining
    global $metadata;
    $now = time();
    if (isset($metadata['extension'][$qobj['slug']][$sid])) {
        $olddue = $qobj['due'];
        $qobj['due'] += 60*60*24*$metadata['extension'][$qobj['slug']][$sid];
    }
    if (isset($metadata['early'][$qobj['slug']][$sid])) {
        $oldopen = $qobj['open'];
        $qobj['open'] -= 60*60*24*$metadata['early'][$qobj['slug']][$sid];
    }
    if (isset($metadata['open'][$qobj['slug']][$sid])) {
        $qobj['open'] = strtotime($metadata['open'][$qobj['slug']][$sid]);
    }
    if (isset($metadata['due'][$qobj['slug']][$sid])) {
        $qobj['due'] = strtotime($metadata['due'][$qobj['slug']][$sid]);
    }
    // view any open quiz, even if time's up
    $ans['may_view'] = in_array($sid, $metadata['staff']) 
        || in_array($sid, $qobj['unhide'])
        || ($qobj['open'] <= $now && !$qobj['draft']);
    // view key of any non-keyless past-due quiz
    $ans['may_view_key'] = $qobj['due'] < $now && !$qobj['keyless'];
    $time_left = $qobj['seconds'];
    if (isset($metadata['time_mult'][$sid]))
        $time_left *= $metadata['time_mult'][$sid];
    if ($time_left == 0) { // no time limit? only due date matters
        $time_left = $qobj['due'] - $now;
    } else { // time limit? due date still wins unless keyless
        if (isset($ans['start'])) $time_left += $ans['start'] - $now;
        if (!$qobj['keyless'] && $qobj['due'] < $time_left+$now)
            $time_left = $qobj['due'] - $now;
    }
    $ans['time_left'] = $time_left;
    $ans['may_submit'] = $ans['may_view'] && !$ans['may_view_key'] && $time_left >= 0;
    
    if ($time_left < 0 && $qobj['imgneeded'] && count(glob("log/$qobj[slug]/$ans[slug]-*")) == 0) $ans['started'] = false;

    if (isset($metadata['extension'][$qobj['slug']][$sid])) {
        // fix due date in case others are here too
        $qobj['due'] = $olddue;
    }

    
    $_aparse["$qobj[slug] $sid"] = $ans;
    return $ans;
}

/**
 * given one question and the student object
 * returns 0..1 if took and earned ratio of points
 * returns FALSE if there is no key and no points can be computed
 * adds to $review if less than 100% and there is something for a human to read
 * adds to $hist if present
 */
function gradeQuestion($q, &$sobj, &$review=FALSE, &$hist=FALSE) {
    $slug = $q['slug'];

    if ($q['points'] <= 0) return FALSE;

    // being manually graded should not bypass histogram computation
    $graded = false;
    if (isset($sobj[$slug]) && isset($sobj[$slug]['grade']) && is_numeric($sobj[$slug]['grade'])) {
        $graded = true;
        $earn = $sobj[$slug]['grade'];
    }
    
    $reply = FALSE;
    if (isset($q['rubric'])) {

        if (!isset($sobj[$slug]['rubric'])) {
            if ($review !== FALSE)
                $review["$slug-pending"][] = $sobj['slug'];
            return FALSE;
        }
        if ($hist !== FALSE && !isset($hist[$slug]))
            $hist[$slug] = array('right'=>0,'total'=>0);
        if (!$graded) {
            $earn = 0;
            $of = 0;
            $finished = true;
            foreach($q['rubric'] as $i=>$obj) {
                if ($obj['hide']) continue;
                $of += $obj['points'];
                if (isset($sobj[$slug]['rubric'][$i]))
                    $earn += $obj['points']*$sobj[$slug]['rubric'][$i];
                else $finished = false;
            }
            $earn /= $of;
            if ($review !== FALSE) {
                if (!isset($review["$slug-pending"])) $review["$slug-pending"] = array();
                if ($finished) $review["$slug-graded"][] = $sobj['slug'];
                else $review["$slug-pending"][] = $sobj['slug'];
            }
        } else if ($review !== FALSE)
            $review["$slug-graded"][] = $sobj['slug'];
    } else if ($q['type'] == 'image') return FALSE;
    else if ($q['type'] == 'text' || $q['type'] == 'box') {
        if (!isset($q['key'])) return FALSE;

        if ($hist !== FALSE && !isset($hist[$slug]))
            $hist[$slug] = array('right'=>0,'total'=>0);
        if (!$graded) {
            $earn = 0;
            if (isset($sobj[$slug])) {
                if (array_key_exists('answer', $sobj[$slug])) {
                    $resp = trim($sobj[$slug]['answer'][0]);
                } else {
                    $resp = '';
                }
                foreach($q['key'] as $key) {
                    $k = $key['text'];
                    $match = ($k[0] == '/') ? preg_match($k, $resp) : $k == $resp;
                    if ($match && $key['points'] > $earn) $earn = $key['points'];
                }
                if (file_exists("log/$q[quizid]/key_$slug.json")) {
                    $obj = json_decode(file_get_contents("log/$q[quizid]/key_$slug.json"), true);
                    if (isset($obj[$resp]) && is_numeric($obj[$resp]))
                        $earn = $obj[$resp];
                    if (isset($obj[$resp]) && isset($obj[$resp]['grade']))
                        $earn = $obj[$resp]['grade'];
                    if (isset($obj[$resp]) && isset($obj[$resp]['reply']))
                        $reply = $obj[$resp]['reply'];
                }
                if ($review !== FALSE && $resp) {
                    if (!isset($review["$slug-answers"]))
                        $review["$slug-answers"] = array();
                    if (isset($review["$slug-answers"][$resp]))
                        $review["$slug-answers"][$resp][] = $sobj['slug'];
                    else
                        $review["$slug-answers"][$resp] = array($sobj['slug']);
                }
                if ($review !== FALSE && ((array_key_exists('comments', $sobj[$slug]) && $sobj[$slug]['comments']) || isset($q['grade-in-comments']))) {
                    if (!isset($review[$slug])) {
                        $review[$slug] = array();
                    }
                    $review[$slug][$sobj['slug']] = array(
                        "pregrade" => $earn,
                    );
                }
            }
        }
    } else {
        // assert(isset($q['options']));
        if ($hist !== FALSE && !isset($hist[$slug]))
            $hist[$slug] = array('right'=>0,'total'=>0);
        if (!$graded) $earn = $q['blank'];
        if (isset($sobj[$slug]['answer'])) {
            $resp = $sobj[$slug]['answer'];
//error_log(json_encode($resp));
            foreach($q['options'] as $opt) {
                if (!$graded) {
                    if (array_key_exists('any-answer', $opt) && $opt['any-answer']) {
                        $earn += abs($opt['points']);
                    } else if (in_array($opt['slug'],$resp)) {
                        if ($q['type'] == 'checkbox') $earn += $opt['points'];
                        else $earn = max($earn, $opt['points']);
                    }
                }
                if (in_array($opt['slug'],$resp)) {
                    if ($hist !== FALSE)
                        if (isset($hist[$slug][$opt['slug']]))
                            $hist[$slug][$opt['slug']] += 1;
                        else
                            $hist[$slug][$opt['slug']] = 1;
                } 
            }
            if ($review !== FALSE
            && ($sobj[$slug]['comments'])) {
                if (!isset($review[$slug])) {
                    $review[$slug] = array();
                }
                $review[$slug][$sobj['slug']] = array(
                    "pregrade" => $earn
                );
            }
        } else {
            foreach($q['options'] as $opt) {
                if (!$graded) {
                    if (array_key_exists('any-answer', $opt) && $opt['any-answer']) {
                        $earn += abs($opt['points']);
                    }
                }
            }
        }
    }
    if ($review !== FALSE && isset($sobj[$slug]['regrade'])) {
        if (!isset($review[$slug])) {
            $review[$slug] = array();
        }
        if (!isset($review[$slug][$sobj['slug']])) {
            $review[$slug][$sobj['slug']] = array();
        } 
        $review[$slug][$sobj['slug']]['regrade'] = true;
    }
        
    $sobj[$slug]['score'] = round($earn*$q['points'], 6);
    if ($reply !== FALSE && !isset($sobj[$slug]['feedback'])) {
        $sobj[$slug]['feedback'] = $reply;
    }
    if ($hist !== FALSE) {
        $hist[$slug]['right'] += $sobj[$slug]['score'];
        $hist[$slug]['total'] += 1;
    }
    return $earn;
}

function grade($qobj, &$sobj, &$review=FALSE, &$hist=FALSE) {
    if (is_string($sobj)) $sobj = aparse($qobj, $sobj);
    if (is_string($qobj)) $qobj = qparse($qobj);
    if (!$sobj || !$sobj['started']) return 0;
    if (!isset($qobj['external'])) {
        $earned = 0;
        $outof = 0;
        foreach($qobj['q'] as $qg) {
            foreach($qg['q'] as $q) {
                $earn = gradeQuestion($q, $sobj, $review, $hist);
                if ($earn !== FALSE) {
                    $earned += $earn * $q['points'];
                    $outof += $q['points'];
                }
            }
        }
        $sobj['score'] = $outof ? $earned / $outof : 0;
    }
    return $sobj['score'];
}

function csv_parse($qobj, $sid) {
    global $_aparse;
    if (is_array($sid)) return $sid;
    if (is_string($qobj)) $qobj = qparse($qobj);
    if (isset($_aparse["$qobj[slug] $sid"])) return $_aparse["$qobj[slug] $sid"];
    
    assert(isset($qobj['external']));
    $ans = array();
    $ans['start'] = $qobj['open'];
    $ans['may_view'] = true;
    $ans['may_view_key'] = true;
    $ans['may_submit'] = false;
    $ans['time_left'] = 0;
    $ans['started'] = file_exists($qobj['external']);
    if ($ans['started']) {
        $ans['score'] = 0;
        $fh = fopen($qobj['external'], "r");
        $header = fgetcsv($fh);
        $cid = 0; $scr = 1;
        foreach($header as $i=>$v) {
            if (isset($qobj['compid']) && $v == $qobj['compid']) $cid = $i;
            if (isset($qobj['score']) && $v == $qobj['score']) $scr = $i;
        }
        while(($row = fgetcsv($fh)) !== FALSE) {
            if (count($row) <= $cid) continue;
            if (count($row) <= $scr) continue;
            if ($row[$cid] == $sid || strpos($row[$cid], "$sid@") === 0)
                $ans['score'] = floatval($row[$scr]);
        }
        fclose($fh);
        if (isset($qobj['outof'])) $ans['score'] /= $qobj['outof'];
    }
    $_aparse["$qobj[slug] $sid"] = $ans;
    return $ans;
}



$_histogram = array();
function histogram($qobj, &$review=FALSE) {
    global $metadata, $isstaff;
    $section = ($isstaff && isset($_GET['section'])) ? $_GET['section'] : FALSE;
    if (is_string($qobj)) $qobj = qparse($qobj);
    if (!$section) {
        if (isset($_histogram[$qobj['slug']])) return $_histogram[$qobj['slug']];
        $cache_hist = "cache/$qobj[slug]-hist.json";
        if (FALSE && file_exists($cache_hist)) { // only saves 10 ms in my example run
            $cachetime = filemtime($cache_hist);
            $use_cache = $cachetime > filemtime("questions/$qobj[slug].md");
            if ($use_cache) foreach(glob("log/$qobj[slug]/*.log") as $anspath)
                if (filemtime($anspath) >= $cachetime) {
                    $use_cache = FALSE;
                    break;
                }
            if ($use_cache)
                return $_histogram[$qobj['slug']] = json_decode(file_get_contents($cache_hist), TRUE);
        }
    } else {
        $smap = json_decode(file_get_contents('sections.json'), true);
    }
    
    if (!$review) $review = array();
    $hist = array(
        "total"=>0,
        "right"=>0,
    );
    foreach(glob("log/$qobj[slug]/*.log") as $anspath) {
        $student = basename($anspath, ".log");
        if (in_array($student, $metadata['staff'])) continue;
        if ($section && (!isset($smap[$student]) || $smap[$student] != $section)) continue;
        $sobj = aparse($qobj['slug'], $student);
        if (!$sobj['started']) continue;
        $hist['total'] += 1;
        $hist['right'] += grade($qobj, $sobj, $review, $hist);
    }
    if (!$section) {
        file_put_contents_recursive($cache_hist, json_encode($hist, JSON_PRETTY));
        chmod($cache_hist, 0666);
        file_put_contents_recursive("cache/$qobj[slug]-review.json", json_encode($review, JSON_PRETTY));
        chmod("cache/$qobj[slug]-review.json", 0666);
        $_histogram[$qobj['slug']] = $hist;
    }
    return $hist;
}

function durationString($time) {
    if ($time < 0) return "";
    if ($time == 0) return "";
    $base = new DateTime('1970-01-01');
    $base2= new DateTime('1970-01-01');
    $base2->add(new DateInterval('PT'.$time.'S'));
    if ($base == $base2) return 'unlimited';
    $dur = $base2->diff($base);
    $days = $dur->format('%a');
    if ($days == 1) return $dur->format("1 day");
    if ($days > 0) return $dur->format("$days days");
    return $dur->format('%h:%I');
}

function putlog($name, $line) {
    file_put_contents_recursive("log/$name", $line, FILE_APPEND);
}

function _optcmp($a,$b) { return strnatcasecmp($a['text'], $b['text']); }


$vulgar_fractions = array(
    '⊤' => 1,
    '⅞' => 7/8, 
    '⅚' => 5/6, 
    '⅘' => 4/5, 
    '¾' => 3/4, 
    '⅔' => 2/3, 
    '⅝' => 5/8, 
    '⅗' => 3/5, 
    '½' => 1/2, 
    '⅖' => 2/5, 
    '⅜' => 3/8, 
    '⅓' => 1/3, 
    '¼' => 1/4, 
    '⅕' => 1/5, 
    '⅙' => 1/6, 
    '⅐' => 1/7, 
    '⅛' => 1/8, 
    '⅑' => 1/9, 
    '⅒' => 1/10, 
    ' ' => 0,
);
//sort($vulgar_fractions);
/// rounds down to a unicode vulgar fraction symbol; 0 = ' ' and 1 = '⊤'
function fractionOf($num) {
    global $vulgar_fractions;
    foreach($vulgar_fractions as $c=>$n)
        if ($n <= $num) return $c;
    return ' ';
}

function showQuestion($q, $quizid, $qnum, $user, $comments=false, $seeabove=false, $replied=array(), $disable=false, $hist=false, $ajax=true, $unshuffle=false, $regrades=false, $printmode=false, $showchat=false){
    global $metadata, $realisstaff;
    $postcall = "postAns(".htmlspecialchars(json_encode($quizid)).", $qnum)";
    
    if ($printmode) {
        if ($qnum) {
            echo "<div class='question' id='q$qnum' slug='$q[slug]'>";
            echo "<div class='label'>";
            echo "<strong>$qnum.</strong> ($q[points] pt) ";
            echo "</div>";
        } else {
            echo " ($q[points] pt)";
        }
        echo "<div class='description' id='d$qnum'>";
        echo "\n$q[text]";
        echo "</div>";
    } else {

        echo "<div class='question' id='q$qnum' slug='$q[slug]'>";

        
        echo "<div class='description' id='d$qnum'>";
        echo "<strong>Question $qnum</strong>";
        if (!$q['points']) {
            echo " (dropped)";
        } else if ($hist && isset($replied['score'])) {
            echo " (".round($replied['score'],2)." / $q[points] pt";
            if (isset($hist[$q['slug']]) && $hist[$q['slug']]['total'])
                echo "; mean ".round($hist[$q['slug']]['right']/$hist[$q['slug']]['total'],2).")";
            else echo ")";
        } else if ($hist && !isset($q['rubric']) && $q['type'] != 'image') {
            echo " ($q[points] pt";
            if (isset($hist[$q['slug']]) && $hist[$q['slug']]['total'])
                echo "; mean ".round($hist[$q['slug']]['right']/$hist[$q['slug']]['total'],2).")";
            else echo ")";
        } else {
            if ($q['points'] != 1) echo $q['points'] ? " ($q[points] points)" : " (dropped)";
        }
        if ($seeabove) echo " (see above)";
        echo "\n$q[text]";
        echo "</div>";
    }

    if ($q['type'] == 'radio' || $q['type'] == 'checkbox') {
        if ($printmode) {
            if ($q['type'] == 'radio') echo "<strong>Pick One</strong>";
            else echo "<strong>Select all that apply</strong>";
        }
        echo '<ol class="options">';
        
        // should I shuffle (or sort) options?
        $ordering = $q['pin'] ? 'pin' : qparse($quizid)['order'];
        //echo "<pre>$ordering srand(".crc32("$user $q[slug]").")</pre>";
        if (!$unshuffle && $ordering == 'shuffle') {
            srand(crc32("$user $q[slug]"));
            $i = count($q['options']) - 1;
            while ($i >= 0)
                if ($q['options'][$i]['pin']) $i-=1;
                else {
                    $j = rand(0,$i);
                    $tmp = $q['options'][$i];
                    $q['options'][$i] = $q['options'][$j];
                    $q['options'][$j] = $tmp;
                    $i -= 1;
                }
        } else if ($ordering == 'sort') {
            $i = count($q['options']) - 1;
            while($i >= 0 && $q['options'][$i]['pin']) $i-=1;
            $mixer = array_slice($q['options'], 0, $i+1);
            usort($mixer, '_optcmp');
            foreach($mixer as $i=>$tmp) $q['options'][$i] = $tmp;
        }
        
        $subm = $disable ? "disabled='disabled'" : ($ajax ? "onchange='$postcall'" : '');
        foreach($q['options'] as $opt) {
            if ($opt['hide']) continue;
            echo "<li";
            if ($hist && isset($hist[$q['slug']]) && $q['type'] == 'checkbox' && !$opt['points']) echo ' style="text-decoration:line-through; opacity:0.35"';
            echo "><label>";
            if ($hist) {
                if (isset($hist[$q['slug']])) {
                    echo "<div style='flex-basis: 2.8em; text-align:right; flex-grow:0; flex-shrink:0;'>";
                    if (isset($hist[$q['slug']][$opt['slug']]))
                        echo round(100*$hist[$q['slug']][$opt['slug']] / $hist[$q['slug']]['total'])."%";
                    echo "</div>";
                }
                echo "<div style='flex-basis: 1.5em; text-align:right; flex-grow:0; flex-shrink:0; color:green;'>";
                if (array_key_exists('any-answer', $opt)) {
                    if ($q['type'] == 'checkbox') echo $opt['original-sign'] > 0 ? '⊤ (correct)' : '';
                    echo ' (gave credit for any answer)';
                } else if (array_key_exists('radio-drop', $opt)) {
                    echo ' (gave credit but not best answer)';
                } else {
                    if ($q['type'] == 'checkbox') echo $opt['points'] > 0 ? '⊤ (correct)' : '';
                    else echo $metadata['detailed-partial'] 
                        ? fractionOf($opt['points']) 
                        : ($opt['points'] == 1 ? '⊤ (correct)' : ($opt['points'] > 0 ? '½ (partial credit)' : ''));
                }
                echo "</div>";
            }
            echo "<input type='$q[type]' name='ans$qnum' value='$opt[slug]' $subm";
            if (isset($replied['answer']) && in_array($opt['slug'], $replied['answer'])) echo " checked='checked'";
            echo "/>";
            echo "<div>$opt[text]</div>";
            echo "</label>";
            if ($hist && isset($opt['explain']))
                echo "<div class='explanation'>$opt[explain]</div>";
            
            echo "</li>";
        }
        
        echo '</ol>';
    } else if ($q['type'] == 'box') {
        $subm = $disable ? "disabled='disabled'" : ($ajax ? "onchange='$postcall' onkeydown='pending($qnum)'" : '');
        if (isset($q['boxrows'])) $subm .= " rows='$q[boxrows]'";
        echo "<div class='tinput box'><span>Answer:</span><textarea name='ans$qnum' $subm>";
        if (isset($replied['answer'][0])) echo htmlentities($replied['answer'][0]);
        echo "</textarea></div>";
        if ($hist && isset($q['key'][0]['text'])) echo "Key: <tt>".htmlentities($q['key'][0]['text'])."</tt>";
    } else if ($q['type'] == 'text') {
        $subm = $disable ? "disabled='disabled'" : ($ajax ? "onchange='$postcall' onkeydown='pending($qnum)'" : '');

        echo "<div class='tinput text'><span>Answer:</span><input type='text' name='ans$qnum' $subm";
        if (isset($replied['answer'][0])) echo " value='".htmlentities($replied['answer'][0])."'";
        echo "/></div>";
        if ($hist && isset($q['key'][0]['text'])) echo "Key: <tt>".htmlentities($q['key'][0]['text'])."</tt>";
    } else if ($q['type'] == 'image') {
        if ($ajax && ($realisstaff || !$disable)) {
            echo "<form method='POST' enctype='multipart/form-data' action='$_SERVER[REQUEST_URI]'>Upload an image of your answer: <input type='file' name='$q[slug]' onchange='pending($qnum)'/><input type='submit' value='upload selected file'/></form>";
            echo "Accepted filetypes: JPEG, PNG, GIF, HEIF, HEIC, PDF. Multi-page responses currently accepted only as PDF.";
        }
        if ($ajax && file_exists("log/$quizid/$user-$q[slug]")) {
            echo "<br/>Last image uploaded: <img class='preview' src='imgshow.php?$_SERVER[QUERY_STRING]&slug=$q[slug]'/>";
            
            echo "<form method='POST' action='$_SERVER[REQUEST_URI]'>";
            ?>
            If the above preview is not the right way up, please click the button that shows what a "T" would look like if rotated the same way that the preview image is:
            <input type='submit' name='rot' value='⊤'/>
            <input type='submit' name='rot' value='⊢'/>
            <input type='submit' name='rot' value='⊥'/>
            <input type='submit' name='rot' value='⊣'/>
            <input type='hidden' name='slug' value='<?=htmlentities($q['slug'])?>'/>
            </form><?php
        }
    } else {
        echo "<strong>ERROR: quiz data malformed (unknown type $q[type])</strong>";
    }
    
    if ($hist && isset($q['explain']))
        echo "<div class='explanation'>$q[explain]</div>";

    if ($hist && isset($q['rubric'])) {
        echo '<div class="explanation"><p><strong>Rubric:</strong></p>';
        foreach($q['rubric'] as $i=>$item) {
            if ($item['hide']) continue;
            echo "<div class='rubric-item'><div style='flex-basis: 1.5em; flex-grow:0; flex-shrink:0; color:green;'>";
            if (isset($replied['rubric'][$i])) {
                $p = $replied['rubric'][$i];
                echo $p >= 1 ? '☑' : ($p <= 0 ? '☐' : '½');
            } else echo '•';
            echo "</div><div>";
            echo $item['text'];
            echo "</div></div>";
        }
        echo "<p><small>(☑/½/☐ means full/partial/no credit earned on this item; • means not yet graded)</small></p></div>";
    }
    
    if ($comments && $ajax && (!$disable || isset($replied['comments']) && $replied['comments'])) {
        echo "<div class='tinput comments'><span>Comments/Work:</span><textarea id='comments$qnum' onchange='$postcall' onkeydown='pending($qnum)'";
        if ($disable) echo " disabled='disabled'";
        echo ">";
        if (isset($replied['comments'])) echo htmlentities($replied['comments']);
        echo "</textarea></div>";
    }
    
    if (($hist || $showchat) && isset($replied['feedback'])
    && ($replied['feedback'] || (isset($replied['grade']) && is_numeric($replied['grade'])))) {
        echo "<blockquote style='white-space: pre-wrap;'>";
        echo toHTML("**Feedback**: $replied[feedback]" . (isset($replied['grade']) && is_numeric($replied['grade']) ? " _(grade set to ".round($replied['grade']*100,0)."%)_" : '')); // cache this in grader_listener??
        echo "</blockquote>";
    }
    
    if (($hist || $showchat) && isset($replied['chat'])) {
        echo "<blockquote class='chat'>Regrade conversation:<dl>\n";
        foreach($replied['chat'] as $entry) {
            echo "<dt>$entry[from] <small>($entry[date])</small></dt><dd>";
            echo htmlspecialchars($entry['text']);
            if (isset($entry['grade']) && is_numeric($entry['grade'])) {
                echo ' <em>(grade set to '.(100 * floatval($entry['grade'])).'%)</em>';
            }
            echo "</dd>\n";
        }
        echo "</dl></blockquote>";
    }

    if ($regrades && $hist) {
        echo "<details><summary>Regrade request</summary><form method='POST'><div class='tinput regrade'><textarea name='request'></textarea><input type='submit' value='submit request'/></div><input type='hidden' name='regrade' value='$q[slug]'/></form></details>";
    }



    if (!$printmode || $qnum) echo "</div>";
}




?>
