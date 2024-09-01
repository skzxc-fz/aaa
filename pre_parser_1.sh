#!/bin/bash

set -e

# Function to strip YAML front matter and pre-process for diffs
strip_yaml_front_matter_and_preprocess() {
  awk '
  BEGIN { in_yaml=0 }
  /^---$/ {
    if (in_yaml == 0) {
      in_yaml=1
    } else {
      in_yaml=0
    }
    next
  }
  !in_yaml { print }' | awk 'NF' | tr -s ' '
}


# Function to check for YAML front matter
has_yaml_front_matter() {
  awk '
  BEGIN { yaml_start=0; yaml_end=0 } 
  /^---$/ { 
    if (!yaml_start) { 
      yaml_start=1 
    } else { 
      yaml_end=1 
    } 
  } 
  END { exit !(yaml_start && yaml_end) }' "$1"
}

echo "Fetching merge request description from GitLab API..."
MR_DESCRIPTION=$(curl -s --fail "$CI_API_V4_URL/projects/$CI_PROJECT_ID/merge_requests/$CI_MERGE_REQUEST_IID" | jq -r '.description')
if [ $? -ne 0 ]; then
  echo "Failed to fetch the merge request description."
  exit 1
fi

echo "Stripping YAML front matter from the merge request description..."
MR_DESCRIPTION_CONTENT=$(echo "$MR_DESCRIPTION" | strip_yaml_front_matter_and_preprocess)

if [ -z "$MR_DESCRIPTION_CONTENT" ]; then
  echo "Error: The merge request description is empty after stripping YAML front matter."
  exit 1
fi

echo "Fetching the master branch from the remote repository..."
git fetch origin master || {
  echo "Failed to fetch the master branch from the remote repository."
  exit 1
}

echo "Fetching new files in the merge request..."
NEW_FILES=$(git diff --diff-filter=A --name-only "origin/master...$CI_MERGE_REQUEST_TARGET_BRANCH")
if [ $? -ne 0 ]; then
  echo "Failed to fetch new files in the merge request."
  exit 1
fi

echo "Fetching new and modified files in the merge request..."
CHANGED_FILES=$(git diff --diff-filter=AM --name-only "origin/master...$CI_MERGE_REQUEST_TARGET_BRANCH")
if [ $? -ne 0 ]; then
  echo "Failed to fetch changed files in the merge request."
  exit 1
fi

echo "List of changed files in the merge request:"
echo "$CHANGED_FILES"

# Ensure there is exactly one file changed or added
FILE_COUNT=$(echo "$CHANGED_FILES" | grep -c -v '^[[:space:]]*$')
if [ "$FILE_COUNT" -ne 1 ]; then
  echo "Error: The merge request must contain exactly one new or modified file."
  exit 1
fi

NEW_FILE=$(echo "$NEW_FILES" | head -n 1)
echo "New file in the merge request:"
echo "$NEW_FILE"

if [[ "$NEW_FILE" != *.md ]]; then
  echo "$NEW_FILE file is not a Markdown (.md) file."
  exit 1
fi

echo "Checking if the Markdown file $NEW_FILE contains YAML front matter..."
if ! has_yaml_front_matter "$NEW_FILE"; then
  echo "Error: The Markdown file $NEW_FILE does not contain YAML front matter."
  exit 1
fi

FILE_CONTENT=$(cat "$NEW_FILE" | strip_yaml_front_matter_and_preprocess)
echo "Content of the Markdown file $NEW_FILE (after stripping YAML front matter):"
echo "$FILE_CONTENT"

# Write the stripped contents to temporary files for git diff
TMP_DIR=$(mktemp -d)
MR_DESCRIPTION_FILE="$TMP_DIR/mr_description.md"
FILE_CONTENT_FILE="$TMP_DIR/file_content.md"

echo "$MR_DESCRIPTION_CONTENT" > "$MR_DESCRIPTION_FILE"
echo "$FILE_CONTENT" > "$FILE_CONTENT_FILE"

# Use git diff to compare the files
set +e
DIFF_OUTPUT=$(git diff --no-index --color=always --word-diff=plain --color-moved --ignore-space-change  "$MR_DESCRIPTION_FILE" "$FILE_CONTENT_FILE")
set -e

# Check if there are differences
if [ -n "$DIFF_OUTPUT" ]; then
  echo "Error: The merge request description does not match the content of the Markdown file $NEW_FILE."
  git diff --no-index --color=always --word-diff=plain --color-moved --ignore-space-change  "$MR_DESCRIPTION_FILE" "$FILE_CONTENT_FILE"  
  exit 1
else
  echo "The merge request description matches the content of the Markdown file."
fi

echo "Validating the Markdown file $NEW_FILE..."
php pre_parser_2.php "$NEW_FILE"
