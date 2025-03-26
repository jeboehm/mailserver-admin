mailserver-admin
================
[![Testing Symfony with MySQL](https://github.com/jeboehm/mailserver-admin/actions/workflows/php.yml/badge.svg)](https://github.com/jeboehm/mailserver-admin/actions/workflows/php.yml)

Description
-----------
This is an administration interface for [docker-mailserver](https://github.com/jeboehm/docker-mailserver).

Configuration
-------------

### OAuth2

To use OAuth2, you need to create a new OAuth2 client in your OAuth2 provider. The redirect URI should be
`https://example.com/login/check-oauth`. The client ID and client secret should be added to the `.env` file.

Depending on your needs, you can configure mailserver-admin to give admin rights to a user by testing for a specific group in the groups
field of the OAuth user information. Set the name of your administrator group to the OAUTH_ADMIN_GROUP variable in the .env file. If you
leave OAUTH_ADMIN_GROUP empty, all authenticated users will have admin rights. You must make sure to handle the login permissions in your
OAuth2 provider.

Screenshots
-----------

### Login screen

![Login screen](/.github/screenshots/login.png?raw=true)

### Start page

![Start page](/.github/screenshots/start.png?raw=true)

### Domain overview

![Domain overview](/.github/screenshots/domain.png?raw=true)

### User overview

![User overview](/.github/screenshots/user.png?raw=true)

### Alias overview

![Alias overview](/.github/screenshots/alias.png?raw=true)

### DKIM overview

![DKIM overview](/.github/screenshots/dkim.png?raw=true)

### DKIM setup

![DKIM setup](/.github/screenshots/dkim_edit.png?raw=true)

### Fetchmail overview

![Fetchmail overview](/.github/screenshots/fetchmail.png?raw=true)
