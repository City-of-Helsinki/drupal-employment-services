#!/bin/bash
while true
do
  echo "Running Linked Events sync: $(date)"
  drush linkedevents:sync
  # Sleep for 5 minutes.
  sleep 300
done
