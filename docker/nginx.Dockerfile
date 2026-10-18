
FROM nginx:alpine

COPY public/ /var/www/public/
COPY nginx/default.conf /etc/nginx/conf.d/default.conf
