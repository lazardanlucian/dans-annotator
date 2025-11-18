FROM wordpress:latest

# Install git for working with the mounted repository and mark /workspace as safe.
RUN apt-get update \
  && apt-get install -y --no-install-recommends git \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/* \
  && git config --global --add safe.directory /workspace