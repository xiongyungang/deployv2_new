# Development

- install docker with docker-compose
- install git
- generate ssh rsa key, add to gitlab.oneitfarm.com, use ssh protocal to pull repo (ssh port: 29622)
- execute `docker-compose up -d`, visit http:/127.0.0.1:8000
- use PhpStorm (recommended) or other IDE/Editor to write code
- `docker-compose exec workspace bash` to login workspace container, then you can execute `php` command
