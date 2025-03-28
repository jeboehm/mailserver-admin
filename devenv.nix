{ pkgs, config, ... }:

{
  languages.javascript.enable = true;
  languages.php.enable = true;
  languages.php.package = pkgs.php83.buildEnv {
    extensions = { all, enabled }: with all; enabled ++ [ redis pdo_mysql xdebug ];
    extraConfig = ''
      memory_limit = -1
      xdebug.mode = debug
      xdebug.client_port = 9003
      xdebug.start_with_request = yes
      max_execution_time = 0
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
  env.REDIS_DSN = "redis://localhost:6379/0";
  env.CORS_ALLOW_ORIGIN = "^https?://localhost:?[0-9]*$";

  enterShell = ''
      if [[ ! -d vendor ]]; then
          cd core
          composer install
          cd ..
      fi
  '';
}
