mailserver-admin
================
[![Application Tests](https://github.com/jeboehm/mailserver-admin/actions/workflows/test.yml/badge.svg)](https://github.com/jeboehm/mailserver-admin/actions/workflows/php.yml)

Description
-----------
This is an administration interface for [docker-mailserver](https://github.com/jeboehm/docker-mailserver). It provides a web-based interface
to manage mail domains, users, aliases, and DKIM settings. The interface is built using Symfony + EasyAdminBundle and integrates with OAuth2 for
authentication.

### Features:

- **Domain Management**: Add, edit, and delete mail domains.
- **User Management**: Create, update, and remove mail users. Set passwords and manage user details.
- **Alias Management**: Define mail aliases to forward emails to different addresses.
- **DKIM Management**: Configure DKIM settings for domains to ensure email authenticity.
- **Fetchmail Configuration**: Set up and manage Fetchmail to retrieve emails from external servers.
- **OAuth2 Integration**: Secure the interface with OAuth2 authentication, allowing you to use your existing OAuth2 provider for login.
- **Responsive Design**: The interface is designed to work on both desktop and mobile devices.

The project is designed to be easily deployable using Docker and can be configured through environment variables.

Configuration
-------------

### User Roles

In `mailserver-admin`, there are three distinct user roles, each with different levels of access and permissions:

1. **Admin**
    - **Permissions**: Can perform all actions within the application.
    - **Capabilities**:
        - Manage all mail domains, users, aliases, and DKIM settings.
        - Full access to all features and configurations.

2. **Domain Admin**
    - **Permissions**: Limited to managing users, aliases, and fetchmail accounts within their own domain.
    - **Capabilities**:
        - Create, update, and remove users within their domain.
        - Define and manage mail aliases within their domain.
        - Configure and manage fetchmail accounts within their domain.
    - **Restrictions**:
        - Cannot add or edit new domains.
        - Cannot manage DKIM settings for any domain.

3. **User**
    - **Permissions**: Limited to managing their own fetchmail accounts.
    - **Capabilities**:
        - Login to the application.
        - Configure and manage their personal fetchmail accounts.
    - **Restrictions**:
        - Cannot manage users, aliases, or domains.
        - No access to DKIM settings or domain configurations.

### OAuth2

To use OAuth2, you need to create a new OAuth2 client in your OAuth2 provider. The redirect URI should be
`https://example.com/login/check-oauth`. The client ID and client secret should be added to the `.env` file.

Depending on your needs, you can configure `mailserver-admin` to give admin rights to a user by testing for a specific group in the groups
field of the OAuth user information. Set the name of your administrator group to the `OAUTH_ADMIN_GROUP` variable in the `.env` file. If you
leave `OAUTH_ADMIN_GROUP` empty, all authenticated users will have admin rights. You must make sure to handle the login permissions in your
OAuth2 provider.

### Environment variables

The following environment variables can be set in the `.env` file or in the environment:

- `APP_ENV`: The environment the application is running in. Default: `prod`
- `APP_SECRET`: A secret key used by Symfony for various purposes (e.g., CSRF tokens).
- `MYSQL_USER`: The MySQL database user.
- `MYSQL_PASSWORD`: The MySQL database password.
- `MYSQL_HOST`: The MySQL database host.
- `MYSQL_DATABASE`: The MySQL database name.
- `REDIS_HOST`: The Redis server host.
- `REDIS_PORT`: The Redis server port.
- `REDIS_PASSWORD`: The Redis server password.
- `TRUSTED_PROXIES`: A list of trusted proxy IP addresses.
- `OAUTH_ENABLED`: Whether OAuth2 is enabled. Default: `false`.
- `OAUTH_CLIENT_ID`: The client ID for the OAuth2 provider.
- `OAUTH_CLIENT_SECRET`: The client secret for the OAuth2 provider.
- `OAUTH_CLIENT_SCOPES`: The scopes requested from the OAuth2 provider. Default: `"email profile groups"`.
- `OAUTH_MAPPING_IDENTIFIER`: The identifier used to map the OAuth2 user to the application user. Default: `"sub"`.
- `OAUTH_AUTHORIZATION_URL`: The authorization URL for the OAuth2 provider.
- `OAUTH_ACCESS_TOKEN_URL`: The access token URL for the OAuth2 provider.
- `OAUTH_INFOS_URL`: The user information URL for the OAuth2 provider.
- `OAUTH_ADMIN_GROUP`: The name of the administrator group in the OAuth2 provider.
- `OAUTH_BUTTON_TEXT`: The text displayed on the OAuth2 login button. Default: `"Login with OIDC"`.

### Database setup

The default schema for `mailserver-admin` is located
in [the docker-mailserver project](https://github.com/jeboehm/docker-mailserver/blob/main/db/rootfs/docker-entrypoint-initdb.d/001_mailserver.sql).
You can use this schema to create the necessary tables in your database.


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
