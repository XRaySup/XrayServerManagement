#!/bin/bash

# Function to get all DNS records from Cloudflare
get_all_dns_records() {
    local zone_id=$1
    local api_token=$2

    response=$(curl -s -X GET "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records" \
        -H "Authorization: Bearer $api_token" \
        -H "Content-Type: application/json")

    echo "$response"
}

# Function to update DNS record on Cloudflare
update_dns_record() {
    local zone_id=$1
    local dns_record_id=$2
    local api_token=$3
    local name=$4
    local proxied=$5
    local type=$6
    local content=$7

    response=$(curl -s -X PUT "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records/$dns_record_id" \
        -H "Authorization: Bearer $api_token" \
        -H "Content-Type: application/json" \
        --data-raw "{\"type\":\"$type\",\"name\":\"$name\",\"content\":\"$content\",\"proxied\":$proxied}")

    success=$(echo "$response" | jq -r '.success')
    if [ "$success" == "true" ]; then
        echo "Successfully updated DNS record with ID: $dns_record_id to name=$name, proxied=$proxied"
    else
        echo "Failed to update DNS record with ID: $dns_record_id"
        echo "Response: $response"
    fi
}

# Function to delete a DNS record from Cloudflare
delete_dns_record() {
    local zone_id=$1
    local dns_record_id=$2
    local api_token=$3

    response=$(curl -s -X DELETE "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records/$dns_record_id" \
        -H "Authorization: Bearer $api_token" \
        -H "Content-Type: application/json")

    success=$(echo "$response" | jq -r '.success')
    if [ "$success" == "true" ]; then
        echo "Successfully deleted DNS record with ID: $dns_record_id"
    else
        echo "Failed to delete DNS record with ID: $dns_record_id"
        echo "Response: $response"
    fi
}

# Check if all required parameters were provided
if [ $# -ne 4 ]; then
    echo "Usage: $0 <subdomain_pattern> <zone_id> <api_token> <csv_file>"
    exit 1
fi

subdomain_pattern=$1
zone_id=$2
api_token=$3
csv_file=$4

# Get all DNS records
dns_records=$(get_all_dns_records "$zone_id" "$api_token")

if [ $? -ne 0 ]; then
    echo "Failed to get DNS records. Exiting."
    exit 1
fi

# Write CSV header
echo "Type,Name,Content,Proxied,Action,Response" > "$csv_file"

# Process each DNS record
echo "$dns_records" | jq -c '.result[]' | while IFS= read -r record; do
    type=$(echo "$record" | jq -r '.type')
    name=$(echo "$record" | jq -r '.name')
    content=$(echo "$record" | jq -r '.content')
    id=$(echo "$record" | jq -r '.id')
    proxied=$(echo "$record" | jq -r '.proxied')

    # Check if record name matches the subdomain pattern
    if [[ "$name" == *"$subdomain_pattern"* ]]; then
        echo "Processing record: Name='$name', Type='$type', Content='$content', Proxied=$proxied"

        # Check the response from the IP address
        echo "Checking IP address: $content"
        response=$(curl -i --connect-timeout 3 --max-time 3 "$content:443" 2>/dev/null | head -n 1)
        echo "Received response: $response"

        # Set the expected response
        expected_response="HTTP/1.1 400 Bad Request"

        if [[ "$response" == *"$expected_response"* ]]; then
            if [ "$proxied" == "true" ]; then
                # Turn off the proxy
                update_dns_record "$zone_id" "$id" "$api_token" "$name" "false" "$type" "$content"
                action="Updated"
            else
                action="No Change"
            fi
        else
            # Rename the DNS record to "deleted.<subdomain_pattern>"
            new_name="deleted.${name}"
            update_dns_record "$zone_id" "$id" "$api_token" "$new_name" "false" "$type" "$content"
            action="Renamed"
        fi

        # Append the result to the CSV
        echo "$type,$name,$content,$proxied,$action,\"$response\"" >> "$csv_file"
    fi
done

echo "Results have been written to $csv_file"
