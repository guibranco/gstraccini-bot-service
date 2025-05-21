#!/bin/bash
set -e
set -o pipefail  # Ensures that pipeline failures are caught

# ========================================================
# Script: wait-for-healthy.sh
# Purpose: This script waits for Docker containers in a
#          Docker Compose environment to become healthy.
#          It checks the health status of services defined
#          in the Docker Compose file that has a healthcheck
#          defined, and will wait for them to reach a "healthy"
#          state before continuing. The script is useful in CI/CD
#          pipelines where it's necessary to wait for
#          services like databases and APIs to be fully ready
#          before running tests or other dependent tasks.
#
# Main Functionality:
#   1. Checks for all Docker services with health checks
#   2. Waits for each service to reach the "healthy" state
#   3. Exits if any service becomes "unhealthy" or times out
#   4. Uses a default waiting timeout (300 seconds) and a default
#      sleep interval between checks (5 seconds), which can be
#      overridden by passing arguments.
#   5. Displays detailed health check logs for containers that fail
#
# Arguments:
#   - MAX_WAIT_SECONDS: Optional. Maximum wait time for health status
#                        to be achieved (default is 300 seconds).
#   - SLEEP_INTERVAL: Optional. Time between each health check (default is 5 seconds).
#
# Usage:
#   wait-for-healthy.sh [MAX_WAIT_SECONDS] [SLEEP_INTERVAL]
# Example:
#   wait-for-healthy.sh 180 3
#
# ========================================================

# Define color codes for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

DEFAULT_MAX_WAIT_SECONDS=300    # Default timeout: 5 minutes
DEFAULT_SLEEP_INTERVAL=5        # Default sleep interval: 5 seconds

validate_positive_integer() {
    local value="$1"
    local param_name="$2"
    if ! [[ "$value" =~ ^[1-9][0-9]*$ ]] && [ -n "$value" ]; then
        echo -e "${RED}Error: $param_name must be a positive integer${NC}"
        exit 1
    fi
}

validate_positive_integer "$1" "MAX_WAIT_SECONDS"
validate_positive_integer "$2" "SLEEP_INTERVAL"

MAX_WAIT_SECONDS="${1:-$DEFAULT_MAX_WAIT_SECONDS}"
SLEEP_INTERVAL="${2:-$DEFAULT_SLEEP_INTERVAL}"

echo -e "${BLUE}🕰️ Waiting timeout: $MAX_WAIT_SECONDS seconds${NC}"
echo -e "${BLUE}⏳ Sleep interval: $SLEEP_INTERVAL seconds${NC}"

START_TIME=$(date +%s)

echo -e "${BLUE}🔍 Finding services with healthchecks...${NC}"
SERVICES=$(docker ps --filter "health=starting" --filter "health=unhealthy" --filter "health=healthy" --format '{{.Names}}')

if [ -z "$SERVICES" ]; then
  echo -e "${YELLOW}⚠️ No services with healthchecks found. Skipping wait.${NC}"
  exit 0
fi

echo -e "${BLUE}Found services with healthchecks:${NC}"
for svc in $SERVICES; do
  echo -e "  - ${YELLOW}$svc${NC}"
done

# Function to display health check logs for debugging
show_health_logs() {
  local container=$1
  echo -e "\n${YELLOW}🔍 Health check logs for $container:${NC}"
  
  # Check if jq is installed
  if command -v jq >/dev/null 2>&1; then
    docker inspect --format='{{json .State.Health}}' "$container" 2>/dev/null | jq '.Log[] | {Status: .ExitCode, Output: .Output}'
  else
    echo -e "${YELLOW}Last 5 health check results (install jq for better formatting):${NC}"
    docker inspect --format='{{range $i, $h := .State.Health.Log}}{{if lt $i 5}}{{$h.ExitCode}} - {{$h.Output}}{{end}}{{end}}' "$container" 2>/dev/null
  fi
  
  echo -e "\n${YELLOW}🛠️ Health check command:${NC}"
  docker inspect --format='{{.Config.Healthcheck.Test}}' "$container" 2>/dev/null
}

wait_for_health() {
  local container="$1"

  echo -e "${BLUE}⏳ Waiting for '${YELLOW}$container${BLUE}' to become healthy...${NC}"
  
  local is_timed_out=false
  
  while true; do
    local ELAPSED
    ELAPSED=$(($(date +%s) - START_TIME))
  
    if [ "$ELAPSED" -ge "$MAX_WAIT_SECONDS" ]; then
      echo -e "${RED}⏰ Global timeout reached after $ELAPSED seconds!${NC}"
      is_timed_out=true
      break
    fi
    
    STATUS=$(docker inspect --format='{{.State.Health.Status}}' "$container" 2>/dev/null || echo "not-found")
    
    if [ "$STATUS" = "healthy" ]; then
      echo -e "${GREEN}✅ $container is healthy!${NC}"
      return 0
    elif [ "$STATUS" = "unhealthy" ]; then
      echo -e "${RED}❌ $container is unhealthy.${NC}"
      break
    elif [ "$STATUS" = "not-found" ]; then
      echo -e "${YELLOW}⚠️ $container not found. Retrying...${NC}"
    else
      echo -e "${BLUE}⌛ $container status: ${YELLOW}$STATUS${BLUE}. Waiting...${NC}"
    fi
    
    sleep "$SLEEP_INTERVAL"
  done
  
  echo -e "${RED}❌ Container $container failed to become healthy${NC}"
  show_health_logs "$container"
  
  if [ "$is_timed_out" = true ]; then
    exit 2
  else
    exit 1
  fi
}

# Track the PIDs of background processes
pids=()

echo -e "${BLUE}Starting health checks for all services...${NC}"
for svc in $SERVICES; do
  wait_for_health "$svc" &
  pids+=($!)
done

# Wait for all background processes to complete
exit_code=0
for pid in "${pids[@]}"; do
  if ! wait "$pid"; then
    code=$?
    # propagate highest exit code: 2 (timeout) > 1 (unhealthy)
    if [ "$code" -gt "$exit_code" ]; then
      exit_code=$code
    fi
  fi
done

if [ $exit_code -eq 0 ]; then
  echo -e "\n${GREEN}🎉 All services are healthy!${NC}"
else
  echo -e "\n${RED}❌ Some services failed to become healthy.${NC}"
fi

exit $exit_code
