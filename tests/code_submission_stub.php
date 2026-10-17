<?php
// Local-only fixture; every answer and cookie in this test is synthetic.
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') {
    echo 'ready';
    return;
}
$cookie = $_SERVER['HTTP_COOKIE'] ?? '';
$answer = $_POST['LevelAction_Answer'] ?? $_POST['BonusAction_Answer'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents(getenv('ENXBOT_CODE_TEST_LOG'), json_encode([
        'session_valid' => str_contains($cookie, 'stoken=test-session'),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'level_id' => (int)($_POST['LevelId'] ?? 0),
        'level_number' => (int)($_POST['LevelNumber'] ?? 0),
        'answer' => $answer,
    ])."\n", FILE_APPEND);
}
header('Content-Type: application/json');
if (!str_contains($cookie, 'stoken=test-session') && !str_contains($cookie, 'stoken=finished')) {
    echo json_encode(['Event' => 4, 'Level' => null]);
    return;
}
if (str_contains($cookie, 'stoken=finished')) {
    echo json_encode(['Event' => 17, 'Level' => null]);
    return;
}
if ($answer === 'slow-test-code') usleep(1500000);
if ($answer === 'bad-response') {
    echo '{invalid JSON';
    return;
}
if ($answer === 'session-lost') {
    echo json_encode(['Event' => 4, 'Level' => null]);
    return;
}
if ($answer === 'finish-test-code') {
    echo json_encode(['Event' => 17, 'Level' => null,
        'EngineAction' => ['LevelAction' => ['IsCorrectAnswer' => true]]]);
    return;
}
echo json_encode([
    'Event' => 0, 'GameId' => 424242, 'GameTitle' => 'Local code submission test',
    'Level' => ['LevelId' => 101, 'Number' => 7, 'Sectors' => [], 'Bonuses' => []],
    'EngineAction' => ['LevelAction' => ['IsCorrectAnswer' => $answer !== 'wrong-test-code']],
]);
