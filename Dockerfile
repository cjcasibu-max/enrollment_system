FROM php:8.2-apache

# Install system libraries and required PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo pdo_mysql gd zip fileinfo \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache modules required by .htaccess
RUN a2enmod rewrite headers

# Allow .htaccess overrides in Apache document root
RUN echo '<Directory /var/www/html/>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/enrollment-system.conf \
    && a2enconf enrollment-system

# Configure PHP runtime settings for file uploads and memory limits
RUN echo 'upload_max_filesize = 50M\n\
post_max_size = 55M\n\
memory_limit = 256M\n\
max_execution_time = 300\n\
max_input_time = 300\n\
date.timezone = Asia/Manila\n' > $PHP_INI_DIR/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Copy all application source code
COPY . /var/www/html/

# Copy and configure entrypoint script (stripping any CRLF line endings from Windows git)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh && chmod +x /usr/local/bin/docker-entrypoint.sh

# Ensure storage directories exist and assign permissions
RUN mkdir -p /var/www/html/uploads \
             /var/www/html/private_uploads/lms_materials \
             /var/www/html/private_uploads/lms_assignments \
             /var/www/html/private_uploads/lms_assignment_files \
             /var/www/html/private_uploads/lms_submissions \
             /var/www/html/private_uploads/lms_lessons \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/uploads /var/www/html/private_uploads

# Default port exposed (Render overrides via $PORT in entrypoint)
EXPOSE 80 10000

ENTRYPOINT ["docker-entrypoint.sh"]
