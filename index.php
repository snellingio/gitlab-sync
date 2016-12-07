<?php

define('GITLAB_API_KEY', 'x-hEr2ohT6pMPmj3KFDS');
define('GITLAB_PROJECT_ID', 5);
define('GITLAB_MASTER_ISSUE', 1);

require_once __DIR__ . '/vendor/autoload.php';

// Setup GitLab
$client = new Gitlab\Client('https://git.onroi.com/api/v3/');
$client->authenticate(GITLAB_API_KEY, Gitlab\Client::AUTH_URL_TOKEN);

// Init project
$project            = new Gitlab\Model\Project(GITLAB_PROJECT_ID, $client);

// Get master issue
$master_issue       = new Gitlab\Model\Issue($project, GITLAB_MASTER_ISSUE, $client);
$master_description = @$master_issue->show()->getData()[0]['description'] ?? '';

// Make sure master description is there
if (empty($master_description)) {
    echo 'Master issue does not exist.' . PHP_EOL;
    exit;
}

// Separate lines
$lines = explode(PHP_EOL, $master_description);

// Init issues
$issues = [];

// Cycle through all the lines
foreach ($lines as $line) {
    // Grab only bulleted items as issues
    if (strpos($line, '*') === false) {
        continue;
    }

    // Discover if issue is Open or Closed
    $open = true;
    if (stripos($line, '[x]') !== false) {
        $open = false;
    }

    // Discover issue #
    $issue_text = substr($line, -11, 11);
    $id         = (int) filter_var($issue_text, FILTER_SANITIZE_NUMBER_INT);

    $issues [] = [
        'text' => $line,
        'open' => $open,
        'id'   => $id,
    ];
}

// Make sure there are issues before continuing
if (count($issues) < 1) {
    echo 'No issues found.' . PHP_EOL;
    exit;
}

// Init changes
$changes = false;

// Cycle through all the issues
foreach ($issues as $issue) {
    // Get specific issue
    $specific_issue = new Gitlab\Model\Issue($project, $issue['id'], $client);
    $state          = @$specific_issue->show()->getData()[0]['state'];

    // Discover if issue specific issue is *really* Open or Closed
    $open = true;
    if ($state === 'closed') {
        $open = false;
    }

    // Change master issue description if the issue is *not* in sync
    if ($issue['open'] !== $open) {
        echo 'Issue #' . $issue['id'] . ' is currently ' . ($issue['open'] ? 'open' : 'closed') . ' when it should be ' . ($open ? 'open' : 'closed') . PHP_EOL;
        $line               = $open ? str_replace('* [x]', '* [ ]', $issue['text']) : str_replace('* [ ]', '* [x]', $issue['text']);
        $master_description = str_replace($issue['text'], $line, $master_description);
        $changes            = true;
    }
}

// Final sync
if ($changes) {
    echo 'Out of sync.' . PHP_EOL;
    echo 'Updating GitLab.' . PHP_EOL;

    // Update master issue
    $master_issue->update(['description' => $master_description]);

    echo 'Sync complete.' . PHP_EOL . PHP_EOL;
    echo $master_description;
} else {
    echo 'GitLab is up to date.' . PHP_EOL;
}
