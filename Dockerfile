# ─────────────────────────────────────────────────────────
# VyOS Companion - Dockerfile
# Base: PHP 8.2 + Apache (slim, Debian Bookworm)
# ─────────────────────────────────────────────────────────

FROM php:8.2-apache

# Metadata
LABEL maintainer="VyOS Companion"
LABEL description="VyOS Router Web Manager - BGP, Static Route, Interface Monitor"
LABEL version="1.0.0"

# ─── Install OS dependencies ─────────────────────────
# openssh-client  : ssh binary for connecting to VyOS
# sshpass         : non-interactive password login over ssh
# libssh2-1-dev   : dev headers for the php-ssh2 extension
# libssl-dev      : OpenSSL (ssh2 dependency)
# curl            : container healthcheck
# ca-certificates : SSL / HTTPS verification
RUN apt-get update && apt-get install -y --no-install-recommends \
        openssh-client \
        sshpass \
        curl \
        ca-certificates \
        libssh2-1-dev \
        libssl-dev \
    && rm -rf /var/lib/apt/lists/*

# ─── Install the PHP ssh2 extension via PECL ─────────
RUN pecl install ssh2-1.3.1 \
    && docker-php-ext-enable ssh2

# ─── PHP configuration for production ───────────────
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i 's/expose_php = On/expose_php = Off/' "$PHP_INI_DIR/php.ini" \
    && sed -i 's/;variables_order = "GPCS"/variables_order = "EGPCS"/' "$PHP_INI_DIR/php.ini"

# ─── Enable Apache mod_rewrite ───────────────────────
RUN a2enmod rewrite

# ─── Apache config: allow .htaccess ──────────────────
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# ─── Copy the app into DocumentRoot ──────────────────
WORKDIR /var/www/html
COPY . /var/www/html/

# ─── Create the data folder, set www-data ownership ──
RUN mkdir -p /var/www/html/data \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/www/html/data

# ─── Expose port HTTP Apache ─────────────────────────────
EXPOSE 80

# ─── Healthcheck ─────────────────────────────────────────
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl -fsS http://localhost/ || exit 1
