<?php

function checkFilename($filename)
{
    if (!preg_match('/.+\.md$/', $filename)) {
        throw new Exception("Filename must end with .md");
    }

    if (basename($filename) != $filename) {
        throw new Exception("File must be in the root folder");
    }
}

function checkYamlForSpaces($yamlContent)
{
    // Split the content into lines
    $lines = explode("\n", $yamlContent);

    // Regex to match lines with a key followed by one or more spaces and then a newline
    $pattern = '/:\s+$/';
    $match=0;
    foreach ($lines as $index => $line) {
        if (preg_match($pattern, $line)) {
            echo "Line " . ($index + 1) . ": '" . trim($line) . "' contains key followed by spaces and a newline\n";
           $match=1;
        }
    }
    if ($match == 1) {
        throw new Exception("Remove trailing spaces after empty key values\n");
    }
}

function getAmountFromText($filename)
{
    $fileContents = shell_exec("sed 's/\r//' '$filename'");
    $lines = explode("\n", $fileContents);

    // Check if the first line is '---'
    if (trim($lines[0]) !== '---') {
        throw new Exception("Failed to parse proposal, the file must start with '---'");
    }
    // Check if the last line ends on a newline
    if (substr($fileContents, -1) !== "\n") {
        throw new Exception("File must end with a newline character");
    }
    $contents = preg_split('/\r?\n?---\r?\n/m', $fileContents);
    if (sizeof($contents) < 3) {
        throw new Exception("Failed to parse proposal, can't find YAML description surrounded by '---' lines");
    }
    
    checkYamlForSpaces($contents[1]);
    echo "YAML Content:\n";
    echo $contents[1];
    echo "\n";
    return yaml_parse($contents[1]);
}

function checkMandatoryFields($values, $mandatoryFields)
{
    foreach ($mandatoryFields as $field) {
        if (empty($values[$field])) {
            throw new Exception("Mandatory field $field is missing");
        }
    }
}

function checkLayout($layout, $layoutToState)
{
    if (!array_key_exists($layout, $layoutToState)) {
        throw new Exception("Invalid layout field value");
    }
}

function checkMilestones($milestones)
{
    if (!is_array($milestones)) {
        throw new Exception("Milestones should be an array");
    }
    foreach ($milestones as $milestone) {
        echo "Checking milestone:\n";
        print_r($milestone);
        if (!array_key_exists('done', $milestone)) {
            throw new Exception("Each milestone must have a 'done' field");
        }
    }
}

function validateProposal($filename)
{
    $layoutToState = [
        'fr'    => 'FUNDING-REQUIRED',
        'wip'   => 'WORK-IN-PROGRESS',
        'cp'    => 'COMPLETED'
    ];

    $mandatoryFields = [
        'amount',
        'author',
        'date',
        'layout',
        'milestones',
        'title'
        //'payout' not mandatory
    ];

    checkFilename($filename);
    $values = getAmountFromText($filename);
    echo "Parsed values:\n";
    print_r($values);
    checkMandatoryFields($values, $mandatoryFields);
    checkLayout($values['layout'], $layoutToState);
    checkMilestones($values['milestones']);

    // Perform the same tasks on the yaml values as the ccs-backend script 
    $amount = floatval(str_replace(",", ".", $values['amount']));
    $author = htmlspecialchars($values['author'], ENT_QUOTES);
    $date = strtotime($values['date']);
    $state = $layoutToState[$values['layout']];
    $milestones = $values['milestones'];
    $title = htmlspecialchars($values['title'], ENT_QUOTES);
    if ($title != $values['title']) {
        echo "Escaped title: $title";
        throw new Exception("Remove special chars from title");
    }
    // Echoing the processed values for verification
    echo "Processed values:\n";
    echo "Amount: $amount\n";
    echo "Author: $author\n";
    echo "Date: " . date('Y-m-d', $date) . "\n";
    echo "State: $state\n";
    echo "Title: $title\n";
    echo "Milestones: \n";
    print_r($milestones);

    return $values;
}

function checkFilenameInUpstream($filename)
{
    // Fetch the list of Markdown files in the upstream branch
    $upstreamFiles = shell_exec("git ls-tree -r origin/master --name-only | grep '.md'");
    $upstreamFilesArray = explode("\n", trim($upstreamFiles));
    foreach ($upstreamFilesArray as $file) {
        if (strcasecmp($file, $filename) === 0) {
            echo "Error: $filename already exists in the upstream branch origin/master (case-insensitive).\n";
            exit(1);
        }
    }
}

try {
    // Retrieve the filename from the command line argument
    if ($argc !== 2) {
        throw new Exception("Usage: php proposal_validator.php <filename>");
    }

    $newFile = $argv[1];

    // Validate the provided markdown file from the merge request
    checkFilenameInUpstream($newFile);
    $proposalValues = validateProposal($newFile);
    echo "Proposal $newFile is valid. Details:\n";
    print_r($proposalValues);
} catch (Exception $e) {
    echo "Validation error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Proposal validation succeeded.\n";
exit(0);
?>
