#!/bin/bash

UPLOAD_DIR="/tmp/openclaw-bugcatcher-uploads"

echo "Fixing permissions for $UPLOAD_DIR"

sudo mkdir -p "$UPLOAD_DIR"
sudo chmod 755 "$UPLOAD_DIR"

CURRENT_USER=$(whoami)
sudo chown "$CURRENT_USER:$CURRENT_USER" "$UPLOAD_DIR"

echo "Testing permissions..."

TEST_FILE="$UPLOAD_DIR/test_$(date +%s).txt"

if touch "$TEST_FILE" 2>/dev/null; then
    echo "✓ Write test passed"
    rm -f "$TEST_FILE"
else
    echo "✗ Write test failed, trying 777..."
    sudo chmod 777 "$UPLOAD_DIR"

    if touch "$TEST_FILE" 2>/dev/null; then
        echo "✓ Write test passed with 777"
        rm -f "$TEST_FILE"
    fi
fi

echo "Done. Permissions:"
ls -ld "$UPLOAD_DIR"
