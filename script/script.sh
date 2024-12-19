#!/bin/bash

# Function to check and install dependencies
install_dependencies() {
    echo "Checking dependencies..."

    # Install curl if not found
    if ! command -v curl &> /dev/null; then
        echo "Installing curl..."
        sudo apt-get update && sudo apt-get install -y curl
    fi

    # Install unzip if not found
    if ! command -v unzip &> /dev/null; then
        echo "Installing unzip..."
        sudo apt-get install -y unzip
    fi

    # Install Xray if not found
    if ! [ -f "./bin/xray" ]; then
        echo "Downloading Xray..."
        XRAY_VERSION="v1.8.1"  # Specify the desired version
        XRAY_DIR="./bin"
        mkdir -p "$XRAY_DIR"

        # Download the Xray zip file and extract it
        XRAY_URL="https://github.com/XTLS/Xray-core/releases/download/$XRAY_VERSION/Xray-linux-64.zip"
        curl -L -o "$XRAY_DIR/xray.zip" "$XRAY_URL"

        unzip -o "$XRAY_DIR/xray.zip" -d "$XRAY_DIR"
        rm -f "$XRAY_DIR/xray.zip"
        echo "Xray installed in $XRAY_DIR."
    fi
}

# Install dependencies
install_dependencies

# Define paths
TEMP_DIR="./temp"
BIN_DIR="./bin"
ZIP_FILE="$TEMP_DIR/download.zip"
RESULTS_CSV="results.csv"
EXTRACT_DIR="$TEMP_DIR/extracted"
XRAY_EXECUTABLE="./bin/xray"
XRAY_CONFIG_FILE="./bin/config.json"
TEMP_CONFIG_FILE="./temp/temp_config.json"

# Prepare temp directory
mkdir -p "$TEMP_DIR"

# Initialize the output CSV with headers
echo "IP,HTTP Check,Xray Check" > "$RESULTS_CSV"

# Download the IP ZIP file
echo "Downloading IP list ZIP file..."
curl -L -o "$ZIP_FILE" https://zip.baipiao.eu.org

# Extract IP ZIP file
echo "Extracting IP ZIP file..."
mkdir -p "$EXTRACT_DIR"
unzip -o "$ZIP_FILE" -d "$EXTRACT_DIR" > /dev/null

# Loop through extracted files ending in -443.txt
for FILE in "$EXTRACT_DIR"/*-443.txt; do
    while IFS= read -r IPADDR; do
        echo "Checking IP: $IPADDR"

        # Debugging: Print the IP address to ensure it's correctly read
        echo "Testing IP: $IPADDR"
        
        # Perform HTTP check with timeout
        HTTP_CHECK=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 "http://$IPADDR:443")
        
        # Debugging: Check HTTP check result
        echo "HTTP check result for IP $IPADDR: $HTTP_CHECK"

        if [ "$HTTP_CHECK" != "400" ]; then
            echo "IP $IPADDR failed HTTP check with response $HTTP_CHECK. Skipping Xray check."
            echo "$IPADDR,$HTTP_CHECK,Skipped" >> "$RESULTS_CSV"
            continue
        fi

        # Base64-encode the IP address
        BASE64IP=$(echo -n "$IPADDR" | base64)

        # Update Xray config with the Base64 IP
        sed "s/PROXYIP/$BASE64IP/g" "$XRAY_CONFIG_FILE" > "$TEMP_CONFIG_FILE"

        # Start Xray as a background process with the updated config
        echo "Starting Xray for IP: $IPADDR"
        "$XRAY_EXECUTABLE" -config "$TEMP_CONFIG_FILE" &
        XRAY_PID=$!

        # Wait for Xray to initialize
        sleep 3

        # Check if Xray started successfully
        if ! ps -p $XRAY_PID > /dev/null; then
            echo "Failed to start Xray for IP: $IPADDR"
            echo "$IPADDR,$HTTP_CHECK,Failed to Start Xray" >> "$RESULTS_CSV"
            continue
        fi

        # Test 204 response with Xray
        XRAY_CHECK=$(curl -s -o /dev/null -w "%{http_code}" --proxy http://127.0.0.1:8080 https://cp.cloudflare.com/generate_204)

        # Log result to CSV
        echo "$IPADDR,$HTTP_CHECK,$XRAY_CHECK" >> "$RESULTS_CSV"
        echo "IP $IPADDR, HTTP Check: $HTTP_CHECK, Xray Check: $XRAY_CHECK"

        # Stop Xray
        echo "Stopping Xray process for IP: $IPADDR"
        kill $XRAY_PID

        # Give time for Xray process to stop
        sleep 1

    done < "$FILE"
done

# Cleanup
rm -rf "$TEMP_DIR"

echo "All checks complete. Results saved in $RESULTS_CSV."
