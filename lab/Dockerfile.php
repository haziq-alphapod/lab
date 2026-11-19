# LAB "php": Ubuntu 22.04 + Ondrej PPA php8.3-fpm (same source as prod). The PPA only keeps the latest build,
# so this lab cannot be made vulnerable; it rehearses the CHECK, MITIGATE and VERIFY steps.
FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d && chmod +x /usr/sbin/policy-rc.d
RUN apt-get update && apt-get install -y sudo software-properties-common curl vim-tiny less procps gnupg \
 && add-apt-repository -y ppa:ondrej/php && apt-get update \
 && apt-get install -y php8.3-fpm php8.3-cli
# put the risky setting on, as an app might have it
RUN sed -i 's/^;\?session.upload_progress.enabled.*/session.upload_progress.enabled = On/' /etc/php/8.3/fpm/php.ini
WORKDIR /root
CMD ["sleep","infinity"]
