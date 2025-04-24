#!/bin/bash

cd /var/www/app

if [ ! -f composer.json ]; then
  echo "📦 Symfony-Projekt wird initialisiert..."
  composer create-project symfony/skeleton .
  composer require symfony/ldap nelmio/api-doc-bundle doctrine/annotations
else
  echo "✅ Symfony-Projekt vorhanden."
fi

echo "🚀 Starte Symfony auf 0.0.0.0:8000..."
symfony serve --no-tls --port=8000 --allow-http --no-interaction --allow-all-ip
