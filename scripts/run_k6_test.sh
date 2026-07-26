#!/bin/bash
# ============================================================================
# Helper script to execute k6 load testing for CBT-MF
# Usage: ./scripts/run_k6_test.sh [VUs] [Target_URL] [Test_ID]
# Example: ./scripts/run_k6_test.sh 50 http://localhost:8080 1
# ============================================================================

set -e

VUS=${1:-50}
TARGET_URL=${2:-"http://localhost:8080"}
TEST_ID=${3:-"1"}

echo "🚀 Starting CBT-MF k6 Simulation with $VUS virtual students..."
echo "📍 Target URL: $TARGET_URL"
echo "📝 Test ID:    $TEST_ID"
echo ""

# Check if k6 is installed locally or available via Docker
if command -v k6 &> /dev/null; then
    BASE_URL="$TARGET_URL" TEST_ID="$TEST_ID" k6 run --vus "$VUS" --duration 2m scripts/k6_exam_simulation.js
elif command -v docker &> /dev/null; then
    echo "🐳 k6 CLI not found locally. Running k6 inside Docker container..."
    docker run --rm -i \
        --net=host \
        -e BASE_URL="$TARGET_URL" \
        -e TEST_ID="$TEST_ID" \
        -v "$(pwd)/scripts:/scripts" \
        grafana/k6 run --vus "$VUS" --duration 2m /scripts/k6_exam_simulation.js
else
    echo "❌ Neither local k6 nor Docker found. Please install k6 or Docker."
    exit 1
fi
