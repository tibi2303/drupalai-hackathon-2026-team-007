#!/usr/bin/env bash
openssl rand -hex 32 > $APP_ROOT/.devpanel/salt.txt
$APP_ROOT/.ddev/commands/web/install
