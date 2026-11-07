# Desenvolvimento e testes

Suba o ambiente oficial GLPI 11 em `http://localhost:8080`:

```sh
docker compose up -d
docker compose exec glpi php bin/console glpi:plugin:install -n --username=glpi escalation
docker compose exec glpi php bin/console glpi:plugin:activate -n escalation
```

Para testes unitários, instale dependências no diretório do plugin e execute `vendor/bin/phpunit`. Para validar a instalação real, acompanhe `docker compose logs -f glpi` e rode `docker compose exec glpi php bin/console system:check_requirements`.

O bind mount do plugin é somente leitura. Dados GLPI e MariaDB ficam em volumes nomeados. `docker compose down` preserva dados; `docker compose down --volumes` destrói apenas o ambiente local de desenvolvimento.
