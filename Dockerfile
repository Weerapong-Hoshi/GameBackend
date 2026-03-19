# ใช้ PHP 8.4 CLI ตามที่คุณระบุไว้
FROM php:8.4-cli

# ติดตั้ง System Dependencies ที่จำเป็นสำหรับ Laravel
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    curl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ติดตั้ง PHP Extensions สำหรับ Laravel (MySQL, PostgreSQL, GD, Zip, ฯลฯ)
RUN docker-php-ext-install pdo_mysql pdo_pgsql mbstring bcmath zip opcache pcntl

# ติดตั้ง Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ตั้งค่า Working Directory
WORKDIR /var/www/html

# คัดลอกเฉพาะไฟล์ Composer เพื่อใช้ประโยชน์จาก Docker Cache
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader

# คัดลอกไฟล์ทั้งหมดในโปรเจกต์เข้าไป
COPY . .

# สร้างโฟลเดอร์ที่ Laravel ต้องใช้ (เนื่องจาก .dockerignore มักจะข้ามโฟลเดอร์เหล่านี้ไป)
# พร้อมทั้งตั้ง Permission ให้ User www-data สามารถเขียนไฟล์ลงไปได้
USER root
RUN mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache

# สั่ง Composer ทำงานส่วนที่เหลือให้เสร็จ
RUN composer dump-autoload --optimize

# สลับไปใช้ User www-data เพื่อความปลอดภัย (Security Best Practice)
USER www-data

# คำสั่งรัน Server เมื่อ Docker เริ่มทำงาน
# หมายเหตุ: เปลี่ยน migrate:fresh เป็น migrate --force เพื่อป้องกันข้อมูลผู้เล่นหาย
CMD sh -c "\
    PORT=\${PORT:-10000} && \
    php artisan config:clear && \
    php artisan cache:clear && \
    php artisan view:clear && \
    php artisan route:clear && \
    php artisan migrate --force && \
    exec php -S 0.0.0.0:\$PORT -t public"