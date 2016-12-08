<?php

require_once __DIR__ . '/../config.inc.php';

// Setup GitLab
$gitlab = new Gitlab\Client(GITLAB_API_URL);
$gitlab->authenticate(GITLAB_API_KEY, Gitlab\Client::AUTH_URL_TOKEN);

// Init project
$project = new Gitlab\Model\Project(GITLAB_PROJECT_ID, $gitlab);

// Init milestone
$milestone = new Gitlab\Model\Milestone($project, GITLAB_MILESTONE_ID, $gitlab);

// Get issues from milestone
$milestone_issues = $milestone->issues();

// Init blank issues
$issues = [];

// Loop through issues
foreach ($milestone_issues as $issue) {

    // Skip any issues with label of `master issue`
    if (in_array('Master', $issue->labels, true) || in_array('master issue', $issue->labels, true)) {
        continue;
    }

    // Skip and issues if the state is closed. Done manually as lib is not up to date with current API spec.
    if ($issue->getData()['state'] === 'closed') {
        continue;
    }

    // Add issues to array to keep loops nice and neat instead of nested.
    $issues[] = $issue;
}

// Init blank comments
$comments = [];

// Init parser
$patternParser = new OnROI\PatternParser\PatternParser();

// Loop through good issues
foreach ($issues as $issue) {

    // Grab comments from the issue via API
    $issue_comments = $issue->showComments();

    foreach ($issue_comments as $comment) {
        $user    = $comment->author->username;
        $comment = $comment->body;

        // Remove forward slash if someone is using /estimate
        if (strpos($comment, '/') === 0) {
            $comment = substr($comment, 1);
        }

        // Skip comment if it doesn't have estimate starting it out
        if (strpos(strtolower($comment), 'estimate') !== 0) {
            continue;
        }

        // Clean up comment, should remove everything (within reason) but h & m
        $clean_comment = trim(str_replace(['estimate', ':', '-', 'our', 'r', 'in', 'ute', 's'], '', strtolower($comment)));

        // Parse two different scenarios, hours, and hours and minutes
        $parsed_hours_minutes = $patternParser->process($clean_comment, '{hours}h {minutes}m');
        $parsed_hours         = $patternParser->process($clean_comment, '{hours}h');
        $parsed_minutes       = $patternParser->process($clean_comment, '{minutes}m');

        // We could not parse hours, ruh roh. This should always be parsed at a minimum.
        if (empty($parsed_hours) && empty($parsed_minutes)) {
            continue;
        }

        $hours   = 0;
        $minutes = 0;

        // Set estimate to parsed hours
        if (!empty($parsed_hours)) {
            // Parse & filter hours
            $hours = (float) filter_var($parsed_hours['hours'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        }

        if (!empty($parsed_minutes)) {
            // Parse & filter minutes
            $minutes = (float) filter_var($parsed_minutes['minutes'], FILTER_SANITIZE_NUMBER_INT);
        }

        if (!empty($parsed_hours_minutes)) {
            // Parse & filter hours and minutes
            $hours   = (float) filter_var($parsed_hours_minutes['hours'], FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $minutes = (int) filter_var($parsed_hours_minutes['minutes'], FILTER_SANITIZE_NUMBER_INT);
        }

        // Parse in 15 minute increments
        $minutes_to_hours = ceil($minutes / 15) * .25;

        // Set the time
        $estimate = $hours + $minutes_to_hours;

        // Build comments
        $comments[] = [
            'issue_iid' => $issue->iid,
            'user'      => $user,
            'comment'   => $comment,
            'cleaned'   => $clean_comment,
            'estimate'  => $estimate,
        ];
    }
}

// Initialize total estimates
$estimates = [];

foreach ($comments as $comment) {

    // Initialize array item by issue
    if (!isset($estimates[$comment['issue_iid']])) {
        $estimates[$comment['issue_iid']] = [
            'issue_iid'           => $comment['issue_iid'],
            'estimate'            => 0,
            'number_of_estimates' => 0,
        ];
    }

    // Add up estimates for this issue.
    $estimates[$comment['issue_iid']] = [
        'issue_iid'           => $comment['issue_iid'],
        'estimate'            => $estimates[$comment['issue_iid']]['estimate'] + $comment['estimate'],
        'number_of_estimates' => $estimates[$comment['issue_iid']]['number_of_estimates'] + 1,
    ];
}

$milestone_time = 0;
foreach ($issues as $issue) {
    $description = $issue->description;

    // See if our Time Tracking h2 has been initialized
    $description_position = strpos($description, '## Time Tracking - Estimated: ');

    // If the Time Tracking h2 doesn't exist, create it.
    if ($description_position === false) {
        $description = $description . PHP_EOL . PHP_EOL . '## Time Tracking - Estimated: 0h';
        $issue->update(['description' => $description]);
    }

    // Only
    if (isset($estimates[$issue->iid])) {

        // Do the math to get the final average time of the estimate
        $final_estimate = $estimates[$issue->iid]['estimate'] / $estimates[$issue->iid]['number_of_estimates'];
        $milestone_time += $final_estimate;

        // If the h2 does exist, cut it off at the end
        if ($description_position !== false) {
            $description = substr($description, 0, $description_position);
        }

        // Add the h2 back in the description with the correct estimated time
        $description = $description . '## Time Tracking - Estimated: ' . $final_estimate . 'h';

        // Update the issue
        $issue->update(['description' => $description]);
    }
}

// Only run math if you want to re-calculate the milestone due date
if (RECALCULATE_MILESTONE_DUE_DATE) {
    $milestone_time_developer = ceil($milestone_time / DEVELOPER_COUNT);
    $milestone_days           = $milestone_time_developer / WORK_HOURS_PER_DAY;
    $milestone_weeks          = floor($milestone_days / WORK_DAYS_PER_WEEK);
    $milestone_remainder      = fmod($milestone_days, WORK_DAYS_PER_WEEK);
    if ($milestone_weeks === 0) {
        $milestone_remainder += $milestone_days;
    }
    $milestone_total = ceil(($milestone_weeks * 7) + $milestone_remainder);
    $date            = date('Y-m-d', strtotime(date('Y-m-d') . ' +' . $milestone_total . ' Weekday'));
    $milestone->update(['due_date' => $date]);
}