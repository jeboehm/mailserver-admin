# mailserver-admin

[![Build and Release](https://github.com/jeboehm/mailserver-admin/actions/workflows/create-release.yml/badge.svg)](https://github.com/jeboehm/mailserver-admin/actions/workflows/create-release.yml)

## Description

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
- **DNS Setup Wizard**: A wizard to help you set up your DNS records for your mail server.
- **iOS/MacOS Profile**: Generates iOS/macOS email profiles for your mail server.
- **Observability**: Monitoring and statistics for Dovecot and Rspamd services.
- **MCP Server**: Connect AI agents such as Claude Code, Cursor, VS Code or Codex through the Model Context Protocol. Agents act with your role,
  within the scopes of their token: manage domains, users and aliases, read your mailbox, save drafts and send mail.
- **API Tokens**: Create, list and revoke the tokens agents sign in with. Each token has its own scopes, lifetime and sender: your primary
  address, a fixed address, or any of your addresses.

## Documentation

You can find the documentation for `mailserver-admin` in the [docker-mailserver documentation](https://jeboehm.github.io/docker-mailserver/).

- [Development](https://jeboehm.github.io/docker-mailserver/development/mailserver-admin/)
- [Configuration](https://jeboehm.github.io/docker-mailserver/configuration/mailserver-admin/)
- Administration:
  - [Dashboard](https://jeboehm.github.io/docker-mailserver/administration/dashboard/)
  - [Domain](https://jeboehm.github.io/docker-mailserver/administration/manage-domains/)
  - etc.

## Screenshots

### Dashboard

![Dashboard](https://jeboehm.github.io/docker-mailserver/images/admin/dashboard.png)

### Domain

![Domain](https://jeboehm.github.io/docker-mailserver/images/admin/domain_list.png)

### DNS Validation Wizard

![DNS Validation Wizard](https://jeboehm.github.io/docker-mailserver/images/admin/dns_wizard.png)

### Observability

![Observability for Rspamd](https://jeboehm.github.io/docker-mailserver/images/admin/obs_rspamd.png)

### MCP: Connect an agent

![MCP setup with scopes and sender modes](docs/images/mcp_setup.png)

![MCP client configuration](docs/images/mcp_clients.png)

### API Tokens

![Create an API token](docs/images/api_token_create.png)

![API token list](docs/images/api_token_list.png)
