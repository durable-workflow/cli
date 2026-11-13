ARG PHP_RUNTIME_IMAGE
FROM node@sha256:4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da AS node

FROM ${PHP_RUNTIME_IMAGE}

COPY --from=node /usr/local/bin/node /usr/local/bin/node

RUN /bin/sh /usr/local/share/durable-workflow/php-ci/require-fixed-bookworm-apt.sh \
    && apt-get update \
    && apt-get install -y --no-install-recommends procps \
    && rm -rf /var/lib/apt/lists/*
