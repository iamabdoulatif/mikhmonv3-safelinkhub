FROM php:8.2-cli-alpine

LABEL org.opencontainers.image.title="Mikhmon"
LABEL org.opencontainers.image.description="Mikhmon PHP/Apache container for MikroTik RouterOS"

WORKDIR /var/www/html

COPY . /var/www/html/

RUN find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && printf '%s\n' '<?php ' 'if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -10) == "config.php") {' '  header("Location:./");' '  exit;' '}' '$data["mikhmon"] = array ("1"=>"mikhmon<|<mikhmon","2"=>"mikhmon>|>aWNlbA==");' > /var/www/html/include/config.php \
    && printf '%s\n' '<?php' 'if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -19) == "sellers_config.php") {' '  header("Location:./");' '  exit;' '}' '$sellers_data = array();' > /var/www/html/include/sellers_config.php \
    && printf '%s\n' '<?php' 'if (isset($_SERVER["REQUEST_URI"]) && substr($_SERVER["REQUEST_URI"], -20) == "managers_config.php") {' '  header("Location:./");' '  exit;' '}' '$managers_data = array();' > /var/www/html/include/managers_config.php \
    && mkdir -p /var/www/html/logs /var/www/html/img /var/www/html/wireguard-configs \
    && chown -R www-data:www-data /var/www/html/logs /var/www/html/img /var/www/html/wireguard-configs

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "/var/www/html"]
