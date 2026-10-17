<?php
// Exercise the real PHP -> FFI -> HTTP path without contacting a live game.
require dirname(__DIR__).'/functions.php';
$work = sys_get_temp_dir().'/enxbot-code-test-'.bin2hex(random_bytes(6));
mkdir($work, 0700);
$log = $work.'/requests.jsonl';
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException('Could not reserve a local test port');
$address = stream_socket_get_name($socket, false);
fclose($socket);
putenv('ENCX_ENGINE=legacy');
putenv('ENCX_USE_HTTP=true');
putenv('ENXBOT_CODE_TEST_LOG='.$log);
$router = __DIR__.'/code_submission_stub.php';
$process = proc_open([PHP_BINARY, '-S', $address, $router], [
    0 => ['pipe', 'r'], 1 => ['file', $work.'/server.log', 'a'],
    2 => ['file', $work.'/server.log', 'a'],
], $pipes);
if (!is_resource($process)) throw new RuntimeException('Could not start the local test server');
fclose($pipes[0]);
$checks = 0;
function codeCheck($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function codeRequests($log) {
    return is_file($log) ? array_map(static fn($line) => json_decode($line, true), file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
}
try {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (@file_get_contents('http://'.$address.'/health') === 'ready') break;
        usleep(100000);
    }
    codeCheck($attempt < 30, 'Local test server did not become ready');
    $session = encxNormalizeCookies('stoken=test-session;');
    $started = microtime(true);
    $slow = sendCode($session, $address, 424242, 'slow-test-code');
    codeCheck(str_contains($slow['result'], 'Код принят'), 'A valid session with a response slower than one second failed');
    codeCheck(microtime(true) - $started >= 1.5, 'Slow-response fixture did not delay the response');
    $requests = codeRequests($log);
    codeCheck(count($requests) === 1, 'Code submission was duplicated');
    codeCheck($requests[0]['session_valid'] === true, 'Session cookie was not sent with the code');
    codeCheck($requests[0]['user_agent'] === 'encx-cli', 'Login and submission User-Agents differ');
    codeCheck($requests[0]['level_id'] === 101 && $requests[0]['level_number'] === 7, 'The wrong level was submitted');
    $failed = sendCode($session, $address, 424242, 'bad-response');
    codeCheck(str_contains($failed['result'], 'не подтвердил'), 'An uncertain submission produced no useful message');
    codeCheck($failed['levelid'] === 101, 'A transport failure lost the known level');
    codeCheck(count(codeRequests($log)) === 2, 'An uncertain submission was retried automatically');
    $wrong = sendCode($session, $address, 424242, 'wrong-test-code');
    codeCheck(str_contains($wrong['result'], 'Код не принят'), 'A rejected code was reported as accepted');
    $lost = sendCode($session, $address, 424242, 'session-lost');
    codeCheck($lost['levelid'] === 101 && str_contains($lost['result'], '/game auth'), 'An authentication error after submission lost the current level');
    $last = sendCode($session, $address, 424242, 'finish-test-code');
    codeCheck($last['levelid'] === -1 && $last['result'] === 'Код принят', 'The last accepted code lost its acknowledgement');
    $expired = sendCode(encxNormalizeCookies('stoken=expired;'), $address, 424242, 'must-not-send');
    codeCheck(str_contains($expired['result'], '/game auth'), 'An expired session was not explained');
    codeCheck($expired['levelid'] === 0, 'An expired session was treated as a finished game');
    codeCheck(count(codeRequests($log)) === 5, 'A code was posted without an active player session');
    $finished = sendCode(encxNormalizeCookies('stoken=finished;'), $address, 424242, 'must-not-send');
    codeCheck($finished['levelid'] === -1 && $finished['result'] === 'Игра завершена', 'A confirmed finish was not preserved');
    echo "Code submission regression checks passed: $checks\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($work.'/*') as $file) unlink($file);
    rmdir($work);
}
