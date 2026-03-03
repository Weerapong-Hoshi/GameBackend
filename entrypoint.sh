#!/bin/sh

echo "Clearing cache..."
php artisan config:clear

echo "Caching config..."
php artisan config:cache

echo "Running migrations..."
php artisan migrate --force

echo "Seeding database..."
php artisan db:seed --class=CharactersSeeder --force

echo "Starting PHP built-in server..."
exec php artisan serve --host=0.0.0.0 --port=8000
