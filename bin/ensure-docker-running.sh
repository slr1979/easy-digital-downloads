#!/bin/bash

# Single source of truth for the Docker-daemon-running check, shared by
# run-e2e-matrix.sh and run-phpunit-matrix.sh. Sourcing has no side effects —
# it defines the one function below and runs nothing on its own.

# Ensure the Docker daemon is running. On macOS, auto-launch Docker Desktop;
# on other OSes, print how to start it and wait a bounded time (no infinite spin).
ensure_docker_running() {
    docker info >/dev/null 2>&1 && return 0

    if [ "$(uname -s)" = "Darwin" ]; then
        echo "Docker is not running — launching Docker Desktop..."
        open -a Docker 2>/dev/null || true
    else
        echo "Docker is not running. Please start the Docker daemon (e.g. 'sudo systemctl start docker')."
    fi

    local waited=0
    until docker info >/dev/null 2>&1; do
        sleep 2
        waited=$((waited + 2))
        if [ $waited -ge 60 ]; then
            echo "Error: Docker did not become available within 60 seconds." >&2
            exit 2
        fi
    done
}
