#!/bin/bash

# Set the web server user (e.g., www-data for Apache/nginx)
WEB_SERVER_USER="www-data"
WEB_SERVER_GROUP="www-data"

# Get the current directory (assumes script is in the Laravel project root)
PROJECT_PATH="$(pwd)"

echo "Starting post-pull setup for Laravel project in $PROJECT_PATH..."

# Pull the latest code from GitHub
echo "Pulling latest code from GitHub..."
git pull origin main || { echo "Git pull failed"; exit 1; }

# Set ownership for the project files
echo "Setting ownership to $WEB_SERVER_USER:$WEB_SERVER_GROUP..."
chown -R "$WEB_SERVER_USER:$WEB_SERVER_GROUP" "$PROJECT_PATH"

# Set permissions for storage and cache directories
echo "Setting permissions for storage and bootstrap/cache directories..."
chmod -R 775 "$PROJECT_PATH/storage"
chmod -R 775 "$PROJECT_PATH/bootstrap/cache"

# Set secure permissions for other files and directories
echo "Setting secure permissions for files and directories..."
find "$PROJECT_PATH" -type f -exec chmod 644 {} \;
find "$PROJECT_PATH" -type d -exec chmod 755 {} \;

# Clear and rebuild Laravel caches
echo "Clearing and caching Laravel configurations..."
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan view:clear
php artisan view:cache

echo "Post-pull setup completed successfully!"
