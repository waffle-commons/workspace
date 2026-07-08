#
# Waffle Framework - FrankenPHP Development Dockerfile
# This builds a modern, all-in-one server environment using FrankenPHP.
#
ARG FRANKEN_VERSION='1.12.2'
ARG PHP_VERSION='8'
ARG OS='trixie'
ARG USER=workspace
ARG APP_ENV='development'

# --- 1. Builder Stage (Composer Dependencies) ---
FROM composer:latest AS composer_dependencies

FROM dunglas/frankenphp:${FRANKEN_VERSION}-php${PHP_VERSION}-${OS} AS frankenphp_builder

ARG APP_ENV
ARG USER

# Set working directory
WORKDIR /waffle-commons

# Install necessary PHP extensions and system packages.
# Database drivers (RFC-022 sandbox): PostgreSQL (primary), MySQL/MariaDB, SQLite,
# plus the NoSQL clients (MongoDB, Redis). The heavy proprietary drivers — Oracle
# (oci8/pdo_oci, pulls Instant Client) and MSSQL (sqlsrv/pdo_sqlsrv, pulls MS ODBC)
# — are opt-in: append them to the list below if your sandbox needs them.
RUN install-php-extensions \
	apcu \
	gd \
	intl \
	zip \
	opcache \
	pdo_pgsql \
	pdo_mysql \
	pdo_sqlite \
	mongodb \
	redis; \
    apt-get update && apt-get install -y --no-install-recommends bash libyaml-dev zip git curl unzip; \
    pecl install xdebug yaml && \
    docker-php-ext-enable xdebug yaml; \
    echo "apc.enable_cli=1" >> "$PHP_INI_DIR/conf.d/docker-php-ext-apcu.ini"; \
    rm -rf /var/lib/apt/lists/*

# Install Starship silently
RUN curl -sS https://starship.rs/install.sh | sh -s -- -y

# Copy PHP configurations
RUN cp $PHP_INI_DIR/php.ini-${APP_ENV} $PHP_INI_DIR/php.ini;

RUN \
    echo "[xdebug]" > $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini; \
    echo "zend_extension=xdebug.so" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.mode = debug,coverage" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.start_with_request = yes" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.discover_client_host = 1" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.client_port = 9003" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini; \
    echo "xdebug.log = /tmp/xdebug.log" >> $PHP_INI_DIR/conf.d/docker-php-ext-xdebug.ini;

# --- 2. Final Runtime Stage ---
FROM frankenphp_builder AS waffle_dev_base

# Copy Composer binary if needed for production CLI commands
COPY --from=composer_dependencies /usr/bin/composer /usr/bin/composer

# --- 3. Dependency Installation & Verification ---
# NOTE: Dependency installation is handled by the entrypoint script at runtime.
# This allows live-mounting of the monorepo to work correctly with symlinks.
# The entrypoint will run 'composer install' on first container start and refresh
# if composer.lock changes.
# For build-time verification, we copy and validate composer.lock syntax here.

WORKDIR /waffle-commons/workspace

# Copy only composer.lock for validation
COPY ./composer.lock ./

# Validate composer.lock is valid JSON
RUN php -r "json_decode(file_get_contents('composer.lock'), true) or exit(1); echo 'composer.lock is valid JSON';"

# Non-root user configuration (DevSecOps best practice)
RUN \
	useradd ${USER}; \
	chown -R ${USER}:${USER} /data/caddy; chown -R ${USER}:${USER} /config/caddy; \
    mkdir -p /home/${USER}/.cache; chown -R ${USER}:${USER} /home/${USER}/.cache; \
    mkdir -p /home/${USER}/.config; chown -R ${USER}:${USER} /home/${USER}/.config; \
    touch /home/${USER}/.bash_history; chown -R ${USER}:${USER} /home/${USER}/.bash_history; \
    touch /home/${USER}/.bashrc; chown -R ${USER}:${USER} /home/${USER}/.bashrc; \
    chmod 777 /tmp/xdebug.log; \
    chown -R ${USER}:${USER} /waffle-commons;

# Switch to non-root user
USER ${USER}

# Copy Starship configuration file
COPY ./docker/term/starship.toml /home/${USER}/.config/starship.toml

# 5Activate Starship in Bash
RUN echo 'eval "$(starship init bash)"' >> /home/${USER}/.bashrc

# Configure Xdebug for remote debugging.
# These settings are environment variables so they can be easily overridden.
ENV XDEBUG_MODE="develop,debug"
ENV XDEBUG_CONFIG="client_host=host.docker.internal"

# The default command to run after the entrypoint
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

