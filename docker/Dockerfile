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

# Install necessary PHP extensions and system packages
RUN install-php-extensions \
	gd \
	intl \
	zip \
	opcache; \
    apt-get update && apt-get install -y --no-install-recommends bash libyaml-dev zip git curl unzip; \
    pecl install xdebug yaml && \
    docker-php-ext-enable xdebug yaml; \
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

# Non-root user configuration (DevSecOps best practice)
RUN \
	useradd ${USER}; \
	chown -R ${USER}:${USER} /data/caddy; chown -R ${USER}:${USER} /config/caddy; \
    mkdir -p /home/${USER}/.cache; chown -R ${USER}:${USER} /home/${USER}/.cache; \
    mkdir -p /home/${USER}/.config; chown -R ${USER}:${USER} /home/${USER}/.config; \
    touch /home/${USER}/.bash_history; chown -R ${USER}:${USER} /home/${USER}/.bash_history; \
    touch /home/${USER}/.bashrc; chown -R ${USER}:${USER} /home/${USER}/.bashrc; \
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

