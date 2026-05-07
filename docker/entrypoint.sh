#!/bin/bash

echo "📦 Installing dependencies..."

echo "🔑 Generating app key..."
php artisan key:generate

echo "📁 Setting permissions..."
chmod -R 775 storage bootstrap/cache

echo "⏳ Waiting for MySQL..."

until php -r "
try {
    new PDO('mysql:host=db;port=3306;dbname=cidroy', 'root', 'root');
    echo 'DB ready';
    exit(0);
} catch (Exception \$e) {
    exit(1);
}
"; do
  sleep 2
done

echo "✅ DB is ready"

php artisan config:clear
php artisan cache:clear

echo "🚀 Running migrations..."
php artisan migrate --force -vvv

# Optional:
# php artisan db:seed --force

echo "⏰ Starting scheduler..."
php artisan schedule:work &

echo "🌐 Starting Laravel server..."
php artisan serve --host=0.0.0.0 --port=8000