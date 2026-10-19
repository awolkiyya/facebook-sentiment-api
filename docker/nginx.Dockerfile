# syntax=docker/dockerfile:1

FROM nginx:alpine

# ------------------------------------------------------------
# Nginx security and runtime defaults
# ------------------------------------------------------------
RUN set -eux; \
    rm -f /etc/nginx/conf.d/default.conf; \
    nginx -t

# ------------------------------------------------------------
# Laravel public assets
# ------------------------------------------------------------
WORKDIR /var/www

COPY --chown=nginx:nginx public/ /var/www/public/

# ------------------------------------------------------------
# Application-specific Nginx configuration
# ------------------------------------------------------------
COPY nginx/default.conf /etc/nginx/conf.d/default.conf

# ------------------------------------------------------------
# Validate configuration during image build
# ------------------------------------------------------------

EXPOSE 80

STOPSIGNAL SIGQUIT

CMD ["nginx", "-g", "daemon off;"]