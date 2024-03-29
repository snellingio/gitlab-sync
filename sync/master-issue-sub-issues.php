<?php

require_once __DIR__ . '/../config.inc.php';

// Setup GitLab
$gitlab = new Gitlab\Client(GITLAB_API_URL);
$gitlab->authenticate(GITLAB_API_KEY, Gitlab\Client::AUTH_URL_TOKEN);

// Init project
$project = new Gitlab\Model\Project(GITLAB_PROJECT_ID, $gitlab);

// Get master issue
$master_issue       = new Gitlab\Model\Issue($project, GITLAB_MASTER_ISSUE, $gitlab);
$master_description = $master_issue->show()->getData()[0]['description'] ?? '';

// Make sure master description is there
if (empty($master_description)) {
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
    $iid        = (int) filter_var($issue_text, FILTER_SANITIZE_NUMBER_INT);

    $issues [] = [
        'text' => $line,
        'open' => $open,
        'iid'  => $iid,
    ];
}

// Make sure there are issues before continuing
if (count($issues) < 1) {
    exit;
}

// Init changes
$changes = false;

// Cycle through all the issues
foreach ($issues as $issue) {

    // Get specific issue
    $specific_issue = new Gitlab\Model\Issue($project, $issue['iid'], $gitlab);
    $state          = @$specific_issue->show()->getData()[0]['state'];

    // Discover if issue specific issue is *really* Open or Closed
    $open = true;
    if ($state === 'closed') {
        $open = false;
    }

    // Change master issue description if the issue is *not* in sync
    if ($issue['open'] !== $open) {
        $line               = $open ? str_replace('* [x]', '* [ ]', $issue['text']) : str_replace('* [ ]', '* [x]', $issue['text']);
        $master_description = str_replace($issue['text'], $line, $master_description);
        $changes            = true;
    }
}

// Final sync && update master issue
if ($changes) {
    $master_issue->update(['description' => $master_description]);
}
