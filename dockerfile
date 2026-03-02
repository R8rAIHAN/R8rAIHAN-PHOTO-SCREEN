FROM php:8.2-apache
# ডিফল্টভাবে কোড কপি করা
COPY . /var/www/html/
# পোর্ট এক্সপোজ করা
EXPOSE 80
