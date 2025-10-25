FROM php:8.5.0RC2-cli-alpine

# INSTALL AND UPDATE COMPOSER
COPY --from=composer /usr/bin/composer /usr/bin/composer
RUN composer self-update

WORKDIR /usr/src/app

# Copy only necessary files first for better caching
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-dev --no-scripts

# Now copy the rest of the application
COPY . .

# Run any post-install scripts if needed
RUN composer run-script post-install-cmd