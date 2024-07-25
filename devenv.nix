{ pkgs, config, ... }:

{
  languages.javascript.enable = true;
  languages.php.enable = true;
  languages.php.version = "8.3";

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
  languages.php.ini = ''
      memory_limit = 1G
      realpath_cache_ttl = 3600
      session.gc_probability = 0
      display_errors = On
      error_reporting = E_ALL
      opcache.memory_consumption = 256M
      opcache.interned_strings_buffer = 20
      zend.assertions = 0
      short_open_tag = 0
      zend.detect_unicode = 0
      realpath_cache_ttl = 3600
      upload_max_filesize = 20M
    '';

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

  env.DATABASE_URL = "mysql://root@localhost/app?version=mariadb-10.11.5";
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
