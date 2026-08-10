{ pkgs, config, ... }:

{
  languages.php.enable = true;
  languages.php.package = pkgs.php84.buildEnv {
    extensions = { all, enabled }: with all; enabled ++ [ redis pdo_mysql pdo_pgsql xdebug ];
    extraConfig = ''
      memory_limit = -1
      max_execution_time = 0
      xdebug.mode = debug
      xdebug.client_port = 9003
      xdebug.start_with_request = yes
      xdebug.discover_client_host = true
      opcache.enable = false
    '';
  };

  languages.php.fpm.pools.web = {
    settings = {
      "clear_env" = "no";
      "pm" = "dynamic";
      "pm.max_children" = 10;
      "pm.start_servers" = 2;
      "pm.min_spare_servers" = 1;
      "pm.max_spare_servers" = 10;
    };
  };

  services.mysql.enable = true;
  services.mysql.initialDatabases = [
      {
        name = "app";
      }
  ];

  # The application supports PostgreSQL as well. Enable this service and point
  # DATABASE_URL at it from devenv.local.nix to develop against it.
  services.postgres.enable = false;
  services.postgres.package = pkgs.postgresql_18;
  services.postgres.listen_addresses = "127.0.0.1";
  services.postgres.initialDatabases = [
      {
        name = "app";
      }
  ];

  services.redis.enable = true;
  services.caddy.enable = true;
  services.caddy.virtualHosts.":8000" = {
    extraConfig = ''
      root * public
      php_fastcgi unix/${config.languages.php.fpm.pools.web.socket}
      file_server
    '';
  };

  env.DATABASE_URL = "mysql://root@127.0.0.1/app?version=mariadb-10.11.5";
  # PostgreSQL alternative. Enable services.postgres and override this from
  # devenv.local.nix, where both definitions need lib.mkForce. initdb creates a
  # superuser named after your Unix account; spell it out, env values are not
  # shell-expanded:
  # env.DATABASE_URL = "postgresql://youruser@127.0.0.1:5432/app?serverVersion=18";
  env.REDIS_DSN = "redis://localhost:6379/0";

  enterShell = ''
      if [[ ! -d vendor ]]; then
          composer install
      fi
  '';
}
